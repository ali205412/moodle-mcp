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

use webservice_mcp\local\mcp\call_context;

/**
 * Logic behind /webservice/mcp/upload.php: redeem an upload ticket and store the body in the user's draft area.
 *
 * Accepts a raw body (PUT or POST), multipart/form-data with one or more files, or a raw body in
 * resumable chunks using Content-Range. Raw bodies are streamed to disk, never held in memory.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_handler {
    /** Copy buffer size. */
    private const CHUNK = 1048576;

    /** Partial uploads untouched for this long are removed. */
    private const PARTIAL_LIFETIME = 86400;

    /** Temp directory (under $CFG->tempdir) for resumable uploads. */
    private const PARTIAL_DIR = 'webservice_mcp_uploads';

    /**
     * Handle the current request.
     *
     * @return void
     */
    public static function handle(): void {
        try {
            if (!endpoint::cors('PUT, POST, OPTIONS')) {
                return;
            }
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if ($method !== 'PUT' && $method !== 'POST') {
                header('Allow: PUT, POST, OPTIONS');
                throw new transfer_exception(405, 'methodnotallowed', 'Upload with PUT (raw body) or POST (raw or multipart).');
            }
            $ticket = required_param('ticket', PARAM_RAW_TRIMMED);
            $redeemed = tickets::redeem('ul', $ticket);
            endpoint::login($redeemed['user'], $redeemed['restriction']);
            $claims = $redeemed['claims'];
            $ctx = new call_context(
                call_context::ERA_MODERN,
                '',
                $redeemed['user'],
                $redeemed['restriction'],
                isset($claims['s']) ? (int)$claims['s'] : null,
                true,
                'files',
                [],
                $claims['c'] ?? null
            );
            $overwrite = optional_param('overwrite', null, PARAM_BOOL);
            if ($overwrite !== null) {
                $claims['ow'] = (int)$overwrite;
            }

            if (!empty($_FILES)) {
                $result = self::receive_multipart($_FILES, $claims, $ctx);
            } else {
                $declared = (int)($_SERVER['CONTENT_LENGTH'] ?? $_SERVER['HTTP_CONTENT_LENGTH'] ?? 0);
                if ((int)$claims['mb'] !== -1 && $declared > (int)$claims['mb']) {
                    throw self::too_large((int)$claims['mb']);
                }
                $in = fopen('php://input', 'rb');
                $result = self::receive_stream(
                    $in,
                    $_SERVER['HTTP_CONTENT_RANGE'] ?? null,
                    self::filename($claims),
                    $claims,
                    $ctx
                );
                fclose($in);
            }
            endpoint::send_json(empty($result['complete']) ? 202 : 201, $result);
        } catch (\Throwable $e) {
            endpoint::send_error($e);
        }
    }

    /**
     * Store a raw body, whole or as one Content-Range chunk of a resumable upload.
     *
     * @param resource $in Body stream.
     * @param string|null $contentrange Content-Range header, e.g. "bytes 0-1048575/52428800".
     * @param string $filename Target file name.
     * @param array $claims Upload ticket claims.
     * @param call_context $ctx Request context.
     * @return array Response body.
     */
    public static function receive_stream(
        $in,
        ?string $contentrange,
        string $filename,
        array $claims,
        call_context $ctx
    ): array {
        $max = (int)$claims['mb'];
        if ($contentrange === null || $contentrange === '') {
            $path = make_request_directory() . '/upload';
            $out = fopen($path, 'wb');
            try {
                self::copy($in, $out, $max === -1 ? PHP_INT_MAX : $max + 1, $max);
            } finally {
                fclose($out);
            }
            return self::result([self::store($path, $filename, $claims, $ctx)], $claims);
        }

        if (
            !preg_match('~^bytes (\d+)-(\d+)/(\d+)$~', trim($contentrange), $m) || (int)$m[2] < (int)$m[1]
                || (int)$m[2] >= (int)$m[3]
        ) {
            throw new transfer_exception(400, 'invalidrange', 'Content-Range must look like "bytes start-end/total".');
        }
        [$start, $end, $total] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if ($max !== -1 && $total > $max) {
            throw self::too_large($max);
        }

        self::purge_stale(make_temp_directory(self::PARTIAL_DIR));
        $dir = make_temp_directory(self::PARTIAL_DIR . '/' . (int)$claims['u']);
        // Keyed by user and target, not ticket, so a fresh upload URL for the same draft file resumes the upload.
        $key = implode('|', [(int)$claims['u'], (int)$claims['d'], (string)($claims['fp'] ?? '/'), $filename, $total]);
        $path = $dir . '/' . sha1($key) . '-' . $total . '.part';
        if (!file_exists($path)) {
            self::check_partial_quota($dir, $total, $max);
        }
        $out = fopen($path, 'c+b');
        if (!$out || !flock($out, LOCK_EX)) {
            throw new transfer_exception(500, 'cannotwritefile', 'Could not open the upload buffer.');
        }
        try {
            $received = (int)fstat($out)['size'];
            if ($start !== $received) {
                throw new transfer_exception(
                    416,
                    'rangemismatch',
                    "Expected the chunk starting at byte {$received}; resume from there."
                );
            }
            fseek($out, $start);
            $length = $end - $start + 1;
            if (self::copy($in, $out, $length, $max) !== $length) {
                ftruncate($out, $start);
                throw new transfer_exception(400, 'incompletechunk', 'The chunk body is shorter than its Content-Range.');
            }
            fflush($out);
        } finally {
            flock($out, LOCK_UN);
            fclose($out);
        }

        if ($end + 1 < $total) {
            return ['complete' => false, 'received' => $end + 1, 'total' => $total, 'draftitemid' => (int)$claims['d']];
        }
        try {
            return self::result([self::store($path, $filename, $claims, $ctx)], $claims);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Store multipart/form-data files (any field names, single or array fields).
     *
     * @param array $files $_FILES.
     * @param array $claims Upload ticket claims.
     * @param call_context $ctx Request context.
     * @return array Response body.
     */
    public static function receive_multipart(array $files, array $claims, call_context $ctx): array {
        $flat = [];
        foreach ($files as $field) {
            foreach ((array)$field['name'] as $i => $name) {
                $flat[] = [
                    'name' => (string)$name,
                    'tmp_name' => (string)((array)$field['tmp_name'])[$i],
                    'size' => (int)((array)$field['size'])[$i],
                    'error' => (int)((array)$field['error'])[$i],
                ];
            }
        }
        $stored = [];
        foreach ($flat as $file) {
            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                throw new transfer_exception(
                    413,
                    'maxbytes',
                    'The file exceeds the server\'s multipart limit; upload it with PUT (raw body) instead.'
                );
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new transfer_exception(
                    400,
                    'uploaderror',
                    'Upload of ' . $file['name'] . ' failed (code ' . $file['error'] . ').'
                );
            }
            if ((int)$claims['mb'] !== -1 && $file['size'] > (int)$claims['mb']) {
                throw self::too_large((int)$claims['mb']);
            }
            $name = count($flat) === 1 && !empty($claims['fn']) ? (string)$claims['fn'] : $file['name'];
            $stored[] = self::store($file['tmp_name'], $name, $claims, $ctx);
        }
        if (!$stored) {
            throw new transfer_exception(400, 'nofile', 'No file was uploaded.');
        }
        return self::result($stored, $claims);
    }

    /**
     * The file name for a raw body: from the ticket, the filename query parameter or Content-Disposition.
     *
     * @param array $claims Upload ticket claims.
     * @return string
     */
    private static function filename(array $claims): string {
        $name = (string)($claims['fn'] ?? '') ?: (string)optional_param('filename', '', PARAM_FILE);
        if (
            $name === '' && preg_match(
                '/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i',
                (string)($_SERVER['HTTP_CONTENT_DISPOSITION'] ?? ''),
                $m
            )
        ) {
            $name = clean_param(rawurldecode($m[1]), PARAM_FILE);
        }
        if ($name === '') {
            throw new transfer_exception(
                400,
                'filenamerequired',
                'Name the file: add &filename=name.ext to the URL or send Content-Disposition: attachment; filename="name.ext".'
            );
        }
        return $name;
    }

    /**
     * Store one file in the ticket's draft area.
     *
     * @param string $path Local file.
     * @param string $filename File name.
     * @param array $claims Upload ticket claims.
     * @param call_context $ctx Request context.
     * @return array Stored file description.
     */
    private static function store(string $path, string $filename, array $claims, call_context $ctx): array {
        $stored = (new file_service())->store_draft_file($path, $filename, [
            'draftitemid' => (int)$claims['d'],
            'filepath' => (string)($claims['fp'] ?? '/'),
            'overwrite' => !empty($claims['ow']),
            'maxbytes' => (int)$claims['mb'],
        ], $ctx);
        unset($stored['draftitemid']);
        return $stored;
    }

    /**
     * Response body for stored files.
     *
     * @param array $files Stored file descriptions.
     * @param array $claims Upload ticket claims.
     * @return array
     */
    private static function result(array $files, array $claims): array {
        return ['complete' => true, 'draftitemid' => (int)$claims['d'], 'files' => $files];
    }

    /**
     * Copy up to $limit bytes, failing with 413 once more than $max bytes arrive.
     *
     * @param resource $in Source.
     * @param resource $out Destination.
     * @param int $limit Bytes to copy at most.
     * @param int $max Size limit, -1 for none.
     * @return int Bytes copied.
     */
    private static function copy($in, $out, int $limit, int $max): int {
        $copied = 0;
        while ($copied < $limit && !feof($in)) {
            $chunk = fread($in, (int)min(self::CHUNK, $limit - $copied));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $copied += strlen($chunk);
            if ($max !== -1 && $copied > $max) {
                throw self::too_large($max);
            }
            if (fwrite($out, $chunk) !== strlen($chunk)) {
                throw new transfer_exception(507, 'cannotwritefile', 'Could not write the upload to disk.');
            }
        }
        return $copied;
    }

    /**
     * Remove abandoned partial uploads.
     *
     * @param string $dir Partial upload directory.
     * @return void
     */
    private static function purge_stale(string $dir): void {
        foreach (glob($dir . '/*/*.part') ?: [] as $file) {
            if (@filemtime($file) < time() - self::PARTIAL_LIFETIME) {
                @unlink($file);
            }
        }
    }

    /**
     * Refuse a new resumable upload when the user already has too many open, or they would hold too many bytes.
     *
     * Open uploads are counted at their declared totals, which is what they can grow to.
     * ponytail: count-then-create is not atomic, so parallel first chunks can overshoot by a few; a per-user lock
     * would close that if it ever matters.
     *
     * @param string $dir The user's partial upload directory.
     * @param int $total Declared size of the new upload.
     * @param int $max The user's upload limit, -1 when unlimited.
     * @return void
     * @throws transfer_exception
     */
    private static function check_partial_quota(string $dir, int $total, int $max): void {
        $open = glob($dir . '/*.part') ?: [];
        if (count($open) >= limits::get('uploadmaxpartials')) {
            throw new transfer_exception(
                429,
                'toomanypartials',
                'Too many unfinished uploads; finish or abandon one (they expire after a day) before starting another.'
            );
        }
        $cap = limits::partial_bytes($max);
        $held = array_sum(array_map(static fn(string $f) => (int)substr(strrchr(basename($f, '.part'), '-'), 1), $open));
        if ($cap !== USER_CAN_IGNORE_FILE_SIZE_LIMITS && $held + $total > $cap) {
            throw new transfer_exception(413, 'partialquota', 'Unfinished uploads would exceed ' . display_size($cap)
                . '; finish the others first.');
        }
    }

    /**
     * Size-limit error.
     *
     * @param int $max Limit in bytes.
     * @return transfer_exception
     */
    private static function too_large(int $max): transfer_exception {
        return new transfer_exception(413, 'maxbytes', 'The upload exceeds your limit of ' . display_size($max) . '.');
    }
}
