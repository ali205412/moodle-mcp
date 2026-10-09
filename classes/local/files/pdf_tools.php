<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace webservice_mcp\local\files;

/**
 * PDF text and page images through the server's poppler (pdftotext/pdftoppm) or Ghostscript binaries.
 *
 * Commands run without a shell (proc_open with an argument array), in a request temp directory, with a
 * timeout and an output cap. Every tool is optional: callers fall back when a binary is missing.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdf_tools {
    /** Seconds a command may run. */
    private const TIMEOUT = 30;

    /** Largest stdout kept from a command. */
    private const MAX_OUTPUT = 52428800;

    /** Resolution of rendered pages, before shrinking to the image budget. */
    private const DPI = 96;

    /** Lowest resolution tried when pages exceed the image budget. */
    private const MIN_DPI = 36;

    /**
     * Path of pdftotext: the plugin setting, else next to $CFG->pathtopdftoppm, else common locations.
     *
     * @return string|null
     */
    public static function pdftotext(): ?string {
        return self::poppler('pdftotext', (string)get_config('webservice_mcp', 'pathtopdftotext'));
    }

    /**
     * Extract the text of a PDF, or null when pdftotext is unavailable or fails.
     *
     * @param string $pdf PDF path.
     * @return string|null
     */
    public static function to_text(string $pdf): ?string {
        $binary = self::pdftotext();
        if ($binary === null) {
            return null;
        }
        $result = self::run([$binary, '-layout', '-enc', 'UTF-8', '-q', $pdf, '-']);
        if ($result['code'] !== 0) {
            return null;
        }
        // The pdftotext output ends each page with a form feed; label pages so offsets and render requests can refer to them.
        $pages = explode("\f", rtrim($result['stdout'], "\f\n"));
        $text = '';
        foreach ($pages as $index => $page) {
            $text .= ($index ? "\n\n" : '') . '## Page ' . ($index + 1) . "\n" . rtrim($page);
        }
        return trim(implode('', $pages)) === '' ? null : $text;
    }

    /**
     * Whether some renderer (pdftoppm or Ghostscript) is available.
     *
     * @return bool
     */
    public static function can_render(): bool {
        return self::pdftoppm() !== null || self::ghostscript() !== null;
    }

    /**
     * Number of pages, from pdfinfo when available, else counted from the page objects.
     *
     * @param string $pdf PDF path.
     * @return int|null
     */
    public static function page_count(string $pdf): ?int {
        if ($pdfinfo = self::poppler('pdfinfo', '')) {
            $result = self::run([$pdfinfo, $pdf]);
            if ($result['code'] === 0 && preg_match('/^Pages:\s+(\d+)/m', $result['stdout'], $m)) {
                return (int)$m[1];
            }
        }
        if (filesize($pdf) <= self::MAX_OUTPUT) {
            $count = preg_match_all('~/Type\s*/Page(?![a-zA-Z])~', (string)file_get_contents($pdf));
            return $count ?: null;
        }
        return null;
    }

    /**
     * Rasterise pages to PNG within a byte budget, lowering the resolution (then dropping pages) to fit.
     *
     * @param string $pdf PDF path.
     * @param int $first First page (1-based).
     * @param int $last Last page.
     * @param int $budget Total PNG bytes allowed.
     * @return array{pages: array<int, string>, dpi: int, truncated: bool} Page number => PNG bytes.
     * @throws transfer_exception When no renderer is available or rendering fails.
     */
    public static function render(string $pdf, int $first, int $last, int $budget): array {
        $dpi = self::DPI;
        while (true) {
            $pages = self::render_at($pdf, $first, $last, $dpi);
            $total = array_sum(array_map('strlen', $pages));
            if ($total <= $budget || $dpi <= self::MIN_DPI) {
                break;
            }
            // Bytes scale roughly with the square of the resolution.
            $dpi = max(self::MIN_DPI, (int)floor($dpi * sqrt($budget / $total) * 0.9));
        }
        $kept = [];
        $used = 0;
        foreach ($pages as $page => $png) {
            if ($used + strlen($png) > $budget && $kept) {
                break;
            }
            $kept[$page] = $png;
            $used += strlen($png);
        }
        return ['pages' => $kept, 'dpi' => $dpi, 'truncated' => count($kept) < count($pages)];
    }

    /**
     * Rasterise pages at one resolution.
     *
     * @param string $pdf PDF path.
     * @param int $first First page.
     * @param int $last Last page.
     * @param int $dpi Resolution.
     * @return array<int, string> Page number => PNG bytes.
     */
    private static function render_at(string $pdf, int $first, int $last, int $dpi): array {
        $dir = make_request_directory();
        if ($pdftoppm = self::pdftoppm()) {
            $command = [$pdftoppm, '-png', '-r', (string)$dpi, '-f', (string)$first, '-l', (string)$last, $pdf, $dir . '/page'];
        } else if ($gs = self::ghostscript()) {
            $command = [$gs, '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dQUIET', '-sDEVICE=png16m', '-dTextAlphaBits=4',
                '-dGraphicsAlphaBits=4', '-r' . $dpi, '-dFirstPage=' . $first, '-dLastPage=' . $last,
                '-sOutputFile=' . $dir . '/page-%04d.png', $pdf];
        } else {
            throw new transfer_exception(501, 'norenderer', 'This server has no PDF renderer (Ghostscript or pdftoppm); '
                . 'a site administrator can install Ghostscript and set its path in Site administration > Server > System paths.');
        }
        $result = self::run($command);
        $files = glob($dir . '/page*.png') ?: [];
        natsort($files);
        if ($result['code'] !== 0 && !$files) {
            throw new transfer_exception(422, 'renderfailed', 'The PDF pages could not be rendered'
                . ($result['timedout'] ? ' in time.' : '; the file may be damaged or encrypted.'));
        }
        $pages = [];
        foreach (array_values($files) as $index => $file) {
            $pages[$first + $index] = (string)file_get_contents($file);
        }
        return $pages;
    }

    /**
     * Path of pdftoppm ($CFG->pathtopdftoppm, else next to pdftotext, else common locations).
     *
     * @return string|null
     */
    private static function pdftoppm(): ?string {
        global $CFG;
        return self::poppler('pdftoppm', (string)($CFG->pathtopdftoppm ?? ''));
    }

    /**
     * Path of Ghostscript ($CFG->pathtogs).
     *
     * @return string|null
     */
    private static function ghostscript(): ?string {
        global $CFG;
        return self::executable([(string)($CFG->pathtogs ?? ''), '/usr/bin/gs', '/usr/local/bin/gs']);
    }

    /**
     * Find a poppler binary: an explicit path, then next to other configured poppler tools, then common locations.
     *
     * @param string $name Binary name.
     * @param string $configured Explicit path, if any.
     * @return string|null
     */
    private static function poppler(string $name, string $configured): ?string {
        global $CFG;
        $dirs = array_filter([
            !empty($CFG->pathtopdftoppm) ? dirname((string)$CFG->pathtopdftoppm) : null,
            ($text = (string)get_config('webservice_mcp', 'pathtopdftotext')) !== '' ? dirname($text) : null,
            '/usr/bin',
            '/usr/local/bin',
        ]);
        return self::executable(array_merge([trim($configured)], array_map(fn($dir) => $dir . '/' . $name, $dirs)));
    }

    /**
     * First executable file among candidates.
     *
     * @param string[] $candidates Paths.
     * @return string|null
     */
    private static function executable(array $candidates): ?string {
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && @is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Run a command without a shell, with a timeout and an output cap.
     *
     * Arguments are passed to the process directly (proc_open array form), so no shell quoting is involved.
     *
     * @param string[] $command Binary and arguments.
     * @return array{code: int, stdout: string, timedout: bool}
     */
    public static function run(array $command): array {
        if (!function_exists('proc_open')) {
            return ['code' => -1, 'stdout' => '', 'timedout' => false];
        }
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            make_request_directory()
        );
        if (!is_resource($process)) {
            return ['code' => -1, 'stdout' => '', 'timedout' => false];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $code = -1;
        $timedout = false;
        $deadline = microtime(true) + self::TIMEOUT;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) > 0) {
                foreach ($read as $pipe) {
                    $chunk = (string)fread($pipe, 65536);
                    if ($pipe === $pipes[1]) {
                        $stdout .= $chunk;
                    }
                }
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $stdout .= (string)stream_get_contents($pipes[1]);
                $code = (int)$status['exitcode'];
                break;
            }
            if (strlen($stdout) > self::MAX_OUTPUT || microtime(true) > $deadline) {
                $timedout = microtime(true) > $deadline;
                proc_terminate($process, 9);
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return ['code' => $code, 'stdout' => substr($stdout, 0, self::MAX_OUTPUT), 'timedout' => $timedout];
    }
}
