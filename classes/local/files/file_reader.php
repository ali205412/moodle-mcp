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

use context;
use moodle_exception;
use stored_file;
use webservice_mcp\local\mcp\call_context;

/**
 * Permission-checked file reads that return MCP content blocks or resource contents.
 *
 * Files reachable through file_browser are read from storage after its is_readable() check; activity files
 * the module lists for the user (core_course_get_contents view) are read directly too. Other areas (blocks,
 * questions, grading, ...) only get a signed download link, so file_pluginfile() makes the access decision.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_reader {
    /** Extensions treated as text regardless of the stored mimetype. */
    private const TEXT_EXTENSIONS = ['txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'html', 'htm', 'xhtml', 'svg',
        'css', 'js', 'mjs', 'ts', 'py', 'php', 'java', 'c', 'h', 'cpp', 'cs', 'rb', 'go', 'rs', 'sh', 'sql', 'yml', 'yaml',
        'ini', 'cfg', 'conf', 'log', 'tex', 'bib', 'srt', 'vtt', 'ics', 'gift', 'rtf', 'r', 'm', 'ipynb'];

    /** Non text/* mimetypes that are text. */
    private const TEXT_MIMETYPES = ['application/json', 'application/xml', 'application/javascript', 'application/x-javascript',
        'image/svg+xml', 'application/x-sh', 'application/sql', 'application/x-yaml', 'application/ld+json',
        'application/xhtml+xml', 'application/rtf'];

    /** Image types clients can display. */
    private const IMAGE_MIMETYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** Seconds to wait for a document conversion. */
    private const CONVERSION_WAIT = 10;

    /** @var call_context */
    private call_context $ctx;

    /**
     * Constructor.
     *
     * @param call_context $ctx Request context.
     */
    public function __construct(call_context $ctx) {
        $this->ctx = $ctx;
    }

    /**
     * Resolve a moodle://file URI or on-site file URL to a permission-checked target.
     *
     * @param string|null $uri moodle://file URI.
     * @param string|null $url On-site file URL.
     * @return array{params:?array, relativepath:string, draft:bool, info:?\file_info, export:?stored_file}
     * @throws moodle_exception
     */
    public function resolve(?string $uri, ?string $url): array {
        if ($uri !== null && $uri !== '') {
            $params = locator::parse_uri($uri);
            $relativepath = locator::relativepath($params);
            $draft = $params['component'] === 'user' && $params['filearea'] === 'draft';
            $info = locator::file_info($params);
        } else if ($url !== null && $url !== '') {
            $parsed = locator::parse_site_url($url);
            $relativepath = $parsed['relativepath'];
            $draft = $parsed['draft'];
            $info = null;
            $params = locator::params_from_relativepath($relativepath, true);
            if ($params !== null) {
                $info = locator::file_info($params);
            }
            if ($info === null && !$draft && ($noitem = locator::params_from_relativepath($relativepath, false)) !== null) {
                if ($info = locator::file_info($noitem)) {
                    $params = $noitem;
                }
            }
        } else {
            throw new moodle_exception('invalidparameter', 'debug', '', null, 'Provide uri or url.');
        }

        $contextid = (int)explode('/', ltrim($relativepath, '/'))[0];
        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context) {
            throw new moodle_exception('filenotfound', 'error');
        }
        locator::check_restriction(
            $context,
            $this->ctx->restrictedcontext,
            $params['component'] ?? null,
            $params['filearea'] ?? null
        );
        if ($info !== null && !$info->is_readable()) {
            throw new moodle_exception('filenotfound', 'error');
        }
        if ($info !== null && $info->is_directory()) {
            throw new moodle_exception('invalidparameter', 'debug', '', null, 'That is a folder; use file_list to browse it.');
        }
        // The user's own finished exports are not in file_browser; they are read straight from storage.
        $export = $info === null && $params !== null ? export_service::own_file($params) : null;
        return ['params' => $params, 'relativepath' => $relativepath, 'draft' => $draft, 'info' => $info,
            'export' => $export];
    }

    /**
     * Open a resolved target for reading.
     *
     * @param array $target Result of resolve().
     * @param int $maxbytes Largest file worth copying out when file_info does not map to a stored file.
     * @return array{filename:string, mimetype:string, size:int, uri:?string, file:?stored_file, path:?string, target:array}
     * @throws moodle_exception
     */
    public function open(array $target, int $maxbytes): array {
        if (!empty($target['export'])) {
            $file = $target['export'];
            return $this->source(
                $target,
                $file->get_filename(),
                (string)$file->get_mimetype(),
                (int)$file->get_filesize(),
                $file,
                null
            );
        }
        $info = $target['info'];
        if ($info !== null) {
            $file = locator::stored_file($info);
            $path = null;
            if ($file === null) {
                // Module-specific file_info classes may not map 1:1 to storage; copy through the file_info instead.
                if ((int)$info->get_filesize() > $maxbytes) {
                    return $this->source(
                        $target,
                        (string)$info->get_visible_name(),
                        (string)$info->get_mimetype(),
                        (int)$info->get_filesize(),
                        null,
                        null
                    );
                }
                $path = make_request_directory() . '/content';
                if (!$info->copy_to_pathname($path)) {
                    throw new moodle_exception('filenotfound', 'error');
                }
            }
            return $this->source(
                $target,
                $file ? $file->get_filename() : (string)$info->get_visible_name(),
                $file ? (string)$file->get_mimetype() : (string)$info->get_mimetype(),
                $file ? (int)$file->get_filesize() : (int)filesize($path),
                $file,
                $path
            );
        }
        $context = context::instance_by_id((int)explode('/', ltrim($target['relativepath'], '/'))[0]);
        if ($target['draft'] || !self::context_reachable($context)) {
            throw new moodle_exception('filenotfound', 'error');
        }
        $file = self::exported_activity_file($context, $target['relativepath']);
        if ($file) {
            return $this->source(
                $target,
                $file->get_filename(),
                (string)$file->get_mimetype(),
                (int)$file->get_filesize(),
                $file,
                null
            );
        }
        // Only file_pluginfile() can decide this area, and it ends the request, so offer a download link (size unknown).
        $filename = basename($target['relativepath']);
        return $this->source($target, $filename, mimeinfo('type', $filename), -1, null, null);
    }

    /**
     * file_read tool result.
     *
     * @param array $args Tool arguments: uri|url, offset, length, convert_to.
     * @return array CallToolResult.
     */
    public function read_tool(array $args): array {
        // Returning file bytes is a download, as core webservice/pluginfile.php treats it.
        tickets::require_service_flag($this->ctx->serviceid, 'downloadfiles');
        $textmax = limits::get('inlinetextmaxbytes');
        $binmax = limits::get('inlinebinarymaxbytes');
        $source = $this->open($this->resolve($args['uri'] ?? null, $args['url'] ?? null), max($textmax, $binmax));

        if (!empty($args['convert_to'])) {
            $source = $this->convert($source, (string)$args['convert_to']);
        }

        $header = sprintf(
            "%s — %s, %s%s",
            $source['filename'],
            $source['mimetype'],
            self::size_label($source['size']),
            $source['uri'] ? "\nURI: " . $source['uri'] : ''
        );
        $kind = self::kind($source['mimetype'], $source['filename']);
        $readable = $source['file'] !== null || $source['path'] !== null;

        if ($kind === 'text' && $readable) {
            $offset = max(0, (int)($args['offset'] ?? 0));
            $length = min(max(1, (int)($args['length'] ?? $textmax)), $textmax);
            $raw = $this->read_bytes($source, $offset, $length);
            if ($offset + strlen($raw) < $source['size']) {
                $raw = self::trim_partial_utf8($raw);
            }
            $end = $offset + strlen($raw);
            $text = fix_utf8($raw);
            $blocks = [['type' => 'text', 'text' => $header . ($offset > 0 || $end < $source['size']
                ? "\nShowing bytes {$offset}-{$end} of {$source['size']}." : '')], ['type' => 'text', 'text' => $text]];
            if ($end < $source['size']) {
                $blocks[] = ['type' => 'text', 'text' => "More content follows: call file_read with offset={$end} to continue"
                    . $this->download_hint($source)];
            }
            return ['content' => $blocks];
        }

        if ($readable && $source['size'] <= $binmax) {
            $data = base64_encode($this->read_bytes($source, 0, $source['size']));
            if ($kind === 'image' && in_array($source['mimetype'], self::IMAGE_MIMETYPES, true)) {
                return ['content' => [['type' => 'text', 'text' => $header], ['type' => 'image', 'data' => $data,
                    'mimeType' => $source['mimetype']]]];
            }
            if ($kind === 'audio') {
                return ['content' => [['type' => 'text', 'text' => $header], ['type' => 'audio', 'data' => $data,
                    'mimeType' => $source['mimetype']]]];
            }
            return ['content' => [['type' => 'text', 'text' => $header], ['type' => 'resource', 'resource' => [
                'uri' => $source['uri'] ?? 'moodle://file' . $source['target']['relativepath'],
                'mimeType' => $source['mimetype'], 'blob' => $data]]]];
        }

        if ($kind === 'image' && $source['file'] !== null) {
            $preview = get_file_storage()->get_file_preview($source['file'], 'bigthumb');
            if ($preview) {
                return ['content' => [
                    ['type' => 'text', 'text' => $header . "\nShowing a downscaled preview." . $this->download_hint($source)],
                    ['type' => 'image', 'data' => base64_encode($preview->get_content()), 'mimeType' => $preview->get_mimetype()],
                ]];
            }
        }

        $why = $source['size'] < 0 ? "\nThis area can only be fetched through a download link."
            : "\nToo large to return inline (limit " . display_size($kind === 'text' ? $textmax : $binmax) . ").";
        $blocks = [['type' => 'text', 'text' => $header . $why . $this->download_hint($source)]];
        if ($source['uri']) {
            $blocks[] = array_filter(
                ['type' => 'resource_link', 'uri' => $source['uri'], 'name' => $source['filename'],
                'mimeType' => $source['mimetype'], 'size' => $source['size'] >= 0 ? $source['size'] : null],
                static fn($v) => $v !== null
            );
        }
        return ['content' => $blocks];
    }

    /**
     * resources/read contents for a moodle://file URI.
     *
     * @param string $uri URI.
     * @return array{contents: array}
     */
    public function read_resource(string $uri): array {
        // Returning file bytes is a download, as core webservice/pluginfile.php treats it.
        tickets::require_service_flag($this->ctx->serviceid, 'downloadfiles');
        $textmax = limits::get('inlinetextmaxbytes');
        $binmax = limits::get('inlinebinarymaxbytes');
        $source = $this->open($this->resolve($uri, null), max($textmax, $binmax));
        $istext = self::kind($source['mimetype'], $source['filename']) === 'text';
        $readable = $source['file'] !== null || $source['path'] !== null;

        if ($readable && $istext && $source['size'] <= $textmax) {
            return ['contents' => [['uri' => $uri, 'mimeType' => $source['mimetype'],
                'text' => fix_utf8($this->read_bytes($source, 0, $source['size']))]]];
        }
        if ($readable && !$istext && $source['size'] <= $binmax) {
            return ['contents' => [['uri' => $uri, 'mimeType' => $source['mimetype'],
                'blob' => base64_encode($this->read_bytes($source, 0, $source['size']))]]];
        }
        return ['contents' => [['uri' => $uri, 'mimeType' => 'text/plain', 'text' => sprintf(
            '%s (%s, %s) cannot be returned inline.%s',
            $source['filename'],
            $source['mimetype'],
            self::size_label($source['size']),
            $this->download_hint($source)
        )]]];
    }

    /**
     * Classify a file for inline rendering.
     *
     * @param string $mimetype Mimetype.
     * @param string $filename File name.
     * @return string text, image, audio or binary.
     */
    public static function kind(string $mimetype, string $filename): string {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (
            strpos($mimetype, 'text/') === 0 || in_array($mimetype, self::TEXT_MIMETYPES, true)
                || in_array($extension, self::TEXT_EXTENSIONS, true)
        ) {
            return 'text';
        }
        if (strpos($mimetype, 'image/') === 0) {
            return 'image';
        }
        if (strpos($mimetype, 'audio/') === 0) {
            return 'audio';
        }
        return 'binary';
    }

    /**
     * Download-ticket claims for a resolved target.
     *
     * @param array $target Result of resolve().
     * @param bool $forcedownload Whether to send Content-Disposition: attachment.
     * @param string|null $preview Optional preview mode (thumb, bigthumb, tinyicon).
     * @return array
     */
    public static function download_claims(array $target, bool $forcedownload = true, ?string $preview = null): array {
        if (!empty($target['export'])) {
            return ['k' => tickets::KIND_EXPORT, 'fid' => (int)$target['export']->get_id()];
        }
        $relativepath = $target['relativepath'];
        if ($target['info'] !== null && ($url = $target['info']->get_url())) {
            // The file_info knows whether this area puts the item id in its URLs.
            try {
                $relativepath = locator::parse_site_url($url)['relativepath'];
            } catch (moodle_exception $e) {
                debugging('Unparseable file URL ' . $url . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return array_filter(['k' => tickets::KIND_FILE, 'rp' => $relativepath, 'dr' => $target['draft'] ? 1 : 0,
            'fd' => $forcedownload || $target['draft'] ? 1 : 0, 'pv' => $preview], static fn($v) => $v !== null);
    }

    /**
     * Human-readable size, or "size unknown" for -1.
     *
     * @param int $size Bytes or -1.
     * @return string
     */
    private static function size_label(int $size): string {
        return $size < 0 ? 'size unknown' : display_size($size) . " ({$size} bytes)";
    }

    /**
     * Drop a trailing incomplete UTF-8 sequence so the next page starts on a character boundary.
     *
     * @param string $raw Bytes.
     * @return string
     */
    private static function trim_partial_utf8(string $raw): string {
        $len = strlen($raw);
        for ($i = 1; $i <= min(3, $len); $i++) {
            $byte = ord($raw[$len - $i]);
            if (($byte & 0xC0) === 0x80) {
                continue;
            }
            $need = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : ($byte >= 0xC0 ? 2 : 1));
            return $need > $i ? substr($raw, 0, $len - $i) : $raw;
        }
        return $raw;
    }

    /**
     * Read a byte range from an opened source.
     *
     * @param array $source Opened source.
     * @param int $offset Start offset.
     * @param int $length Maximum bytes.
     * @return string
     */
    private function read_bytes(array $source, int $offset, int $length): string {
        $handle = $source['file'] ? $source['file']->get_content_file_handle() : fopen($source['path'], 'rb');
        if (!$handle) {
            throw new moodle_exception('storedfilecannotread', 'error');
        }
        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                // Non-seekable streams: skip forward.
                stream_get_contents($handle, $offset);
            }
            return (string)stream_get_contents($handle, $length);
        } finally {
            fclose($handle);
        }
    }

    /**
     * " Download: <url>" for a source, or an explanation when downloads are disabled.
     *
     * @param array $source Opened source.
     * @return string
     */
    private function download_hint(array $source): string {
        try {
            $link = tickets::download_url($this->ctx, self::download_claims($source['target']));
            return "\nDownload it (link expires " . userdate($link['expires']) . "): " . $link['url']
                . "\nExample: curl -fL -o " . escapeshellarg($source['filename']) . ' ' . escapeshellarg($link['url']);
        } catch (transfer_exception $e) {
            return "\n" . $e->getMessage();
        }
    }

    /**
     * The stored file behind an activity file the module itself lists for this user.
     *
     * file_browser hides some activity files from students (e.g. mod_resource without managefiles). The module's
     * export_contents callback is what core_course_get_contents shows the user, so a file it lists for a visible
     * activity is one the user may open.
     *
     * @param context $context Module context.
     * @param string $relativepath Requested pluginfile-style path.
     * @return stored_file|null
     */
    private static function exported_activity_file(context $context, string $relativepath): ?stored_file {
        global $CFG;

        if ($context->contextlevel != CONTEXT_MODULE) {
            return null;
        }
        $cm = get_fast_modinfo($context->get_course_context()->instanceid)->get_cm($context->instanceid);
        $callback = $cm->modname . '_export_contents';
        require_once($CFG->dirroot . '/mod/' . $cm->modname . '/lib.php');
        if (!$cm->uservisible || !function_exists($callback)) {
            return null;
        }
        $listed = false;
        foreach ($callback($cm, 'webservice/pluginfile.php') as $content) {
            try {
                $listed = ($content['type'] ?? '') === 'file' && !empty($content['fileurl'])
                    && locator::parse_site_url((string)$content['fileurl'])['relativepath'] === $relativepath;
            } catch (moodle_exception $e) {
                $listed = false;
            }
            if ($listed) {
                break;
            }
        }
        if (!$listed) {
            return null;
        }

        // URLs may carry a revision where storage uses item id 0 (resource, folder, page), or no item id at all.
        $fs = get_file_storage();
        $withitem = locator::params_from_relativepath($relativepath, true);
        $candidates = array_filter([$withitem, $withitem ? ['itemid' => 0] + $withitem : null,
            locator::params_from_relativepath($relativepath, false)]);
        foreach ($candidates as $p) {
            $file = $fs->get_file($context->id, $p['component'], $p['filearea'], $p['itemid'], $p['filepath'], $p['filename']);
            if ($file && !$file->is_directory()) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Whether the user can reach a context at all: own user context, accessible course, visible activity.
     *
     * Other contexts (system, category, blocks) are left to file_pluginfile() when the link is used.
     *
     * @param context $context Context.
     * @return bool
     */
    private static function context_reachable(context $context): bool {
        global $USER;

        if ($context->contextlevel == CONTEXT_USER) {
            return (int)$context->instanceid === (int)$USER->id;
        }
        $coursecontext = $context->get_course_context(false);
        if ($coursecontext && !can_access_course(get_course($coursecontext->instanceid))) {
            return false;
        }
        if ($context->contextlevel == CONTEXT_MODULE) {
            try {
                return get_fast_modinfo($coursecontext->instanceid)->get_cm($context->instanceid)->uservisible;
            } catch (moodle_exception $e) {
                return false;
            }
        }
        return true;
    }

    /**
     * Convert a stored file with Moodle's document converters.
     *
     * @param array $source Opened source.
     * @param string $format pdf or txt.
     * @return array Opened source for the converted file.
     */
    private function convert(array $source, string $format): array {
        $converter = new \core_files\converter();
        if ($source['file'] === null || !$converter->can_convert_storedfile_to($source['file'], $format)) {
            throw new moodle_exception(
                'error',
                'moodle',
                '',
                null,
                "Conversion to {$format} is not available for this file (no enabled document converter supports it)."
            );
        }
        $conversion = $converter->start_conversion($source['file'], $format);
        for ($i = 0; $i < self::CONVERSION_WAIT && $conversion->get('status') != \core_files\conversion::STATUS_COMPLETE; $i++) {
            if ($conversion->get('status') == \core_files\conversion::STATUS_FAILED) {
                break;
            }
            sleep(1);
            $converter->poll_conversion($conversion);
        }
        $dest = $conversion->get('status') == \core_files\conversion::STATUS_COMPLETE ? $conversion->get_destfile() : null;
        if (!$dest) {
            throw new moodle_exception(
                'error',
                'moodle',
                '',
                null,
                "The {$format} conversion did not finish in time or failed; try again later."
            );
        }
        $converted = $this->source(
            $source['target'],
            pathinfo($source['filename'], PATHINFO_FILENAME) . '.' . $format,
            (string)$dest->get_mimetype(),
            (int)$dest->get_filesize(),
            $dest,
            null
        );
        $converted['uri'] = $source['uri'];
        return $converted;
    }

    /**
     * Build an opened-source array.
     *
     * @param array $target Resolved target.
     * @param string $filename File name.
     * @param string $mimetype Mimetype.
     * @param int $size Size in bytes.
     * @param stored_file|null $file Stored file, when readable from storage.
     * @param string|null $path Local copy, when fetched.
     * @return array
     */
    private function source(
        array $target,
        string $filename,
        string $mimetype,
        int $size,
        ?stored_file $file,
        ?string $path
    ): array {
        $params = $target['params'];
        $uri = $file !== null && $file->get_component() !== 'core' ? locator::uri_for($file)
            : ($params !== null ? locator::uri(
                $params['contextid'],
                $params['component'],
                $params['filearea'],
                $params['itemid'],
                $params['filepath'],
                $params['filename']
            ) : null);
        return ['filename' => $filename, 'mimetype' => $mimetype ?: 'application/octet-stream', 'size' => $size,
            'uri' => $uri, 'file' => $file, 'path' => $path, 'target' => $target];
    }
}
