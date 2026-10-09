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
use context_course;
use context_module;
use context_user;
use core_external\external_api;
use file_exception;
use moodle_exception;
use webservice_mcp\local\mcp\call_context;

/**
 * File writes and reads on behalf of the MCP user: draft uploads, saving drafts into areas, deletes, course images.
 *
 * All writes go through Moodle's draft area and file_browser write checks, never straight to arbitrary areas.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_service {
    /** Max files reported back after saving a draft. */
    private const MAX_REPORTED = 200;

    /**
     * resources/read for moodle://file URIs.
     *
     * @param string $uri moodle://file URI.
     * @param call_context $ctx Request context.
     * @return array{contents: array}
     * @throws moodle_exception filenotfound when missing or unreadable.
     */
    public function read_resource(string $uri, call_context $ctx): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        self::apply_restriction($ctx);
        return (new file_reader($ctx))->read_resource($uri);
    }

    /**
     * Store a local file in the current user's draft area with the same checks as core uploads.
     *
     * @param string $pathname Local file to store (left in place).
     * @param string $filename Requested file name.
     * @param array $options draftitemid (0 = new), filepath, overwrite (bool), maxbytes (null = user's limit), source.
     * @param call_context $ctx Request context.
     * @return array Stored file description including draftitemid and uri.
     * @throws moodle_exception
     */
    public function store_draft_file(string $pathname, string $filename, array $options, call_context $ctx): array {
        global $CFG, $USER;

        if (!isloggedin() || isguestuser()) {
            throw new moodle_exception('noguest');
        }
        $filename = clean_param($filename, PARAM_FILE);
        if ($filename === '') {
            throw self::invalid('A valid file name is required.');
        }
        $filepath = file_correct_filepath(clean_param((string)($options['filepath'] ?? '/'), PARAM_PATH));
        $usercontext = context_user::instance($USER->id);
        $size = (int)filesize($pathname);

        $maxbytes = $options['maxbytes'] ?? limits::max_upload_bytes($usercontext);
        if ((int)$maxbytes !== USER_CAN_IGNORE_FILE_SIZE_LIMITS && $size > (int)$maxbytes) {
            throw new file_exception('maxbytesfile', (object)['file' => $filename, 'size' => display_size((int)$maxbytes)]);
        }
        if (file_is_draft_areas_limit_reached((int)$USER->id)) {
            throw new file_exception('maxdraftitemids');
        }
        \core\antivirus\manager::scan_file($pathname, $filename, true);

        $draftitemid = (int)($options['draftitemid'] ?? 0);
        if ($draftitemid <= 0) {
            $draftitemid = file_get_unused_draft_itemid();
        }

        $fs = get_file_storage();
        $renamedfrom = null;
        if ($existing = $fs->get_file($usercontext->id, 'user', 'draft', $draftitemid, $filepath, $filename)) {
            if (!empty($options['overwrite'])) {
                $existing->delete();
            } else {
                $renamedfrom = $filename;
                $filename = $fs->get_unused_filename($usercontext->id, 'user', 'draft', $draftitemid, $filepath, $filename);
            }
        }

        $file = $fs->create_file_from_pathname([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => $filepath,
            'filename' => $filename,
            'userid' => $USER->id,
            'author' => fullname($USER),
            'license' => $CFG->sitedefaultlicense,
            'source' => serialize((object)['source' => (string)($options['source'] ?? $filename)]),
        ], $pathname);

        \core\event\draft_file_added::create([
            'objectid' => $file->get_id(),
            'context' => $usercontext,
            'other' => [
                'itemid' => $draftitemid,
                'filename' => $filename,
                'filesize' => $file->get_filesize(),
                'filepath' => $filepath,
                'contenthash' => $file->get_contenthash(),
            ],
        ])->trigger();

        return array_filter([
            'draftitemid' => $draftitemid,
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => (int)$file->get_filesize(),
            'mimetype' => (string)$file->get_mimetype(),
            'contenthash' => $file->get_contenthash(),
            'uri' => locator::uri_for($file),
            'renamedfrom' => $renamedfrom,
        ], static fn($v) => $v !== null);
    }

    /**
     * file_upload: store inline base64 or text content in a draft area.
     *
     * @param array $args filename, content_base64|content_text, draftitemid, filepath, overwrite.
     * @param call_context $ctx Request context.
     * @return array
     */
    public function upload_inline(array $args, call_context $ctx): array {
        tickets::require_service_flag($ctx->serviceid, 'uploadfiles');
        $max = limits::get('uploadinlinemaxbytes');
        $hasbase64 = isset($args['content_base64']) && is_string($args['content_base64']);
        $hastext = isset($args['content_text']) && is_string($args['content_text']);
        if ($hasbase64 === $hastext) {
            throw self::invalid('Provide exactly one of content_base64 or content_text.');
        }
        if ($hasbase64) {
            $encoded = preg_replace('/\s+/', '', $args['content_base64']);
            if (strlen($encoded) > intdiv($max, 3) * 4 + 4) {
                throw $this->too_large($max);
            }
            $content = base64_decode($encoded, true);
            if ($content === false) {
                throw self::invalid('content_base64 is not valid base64.');
            }
        } else {
            $content = $args['content_text'];
        }
        if (strlen($content) > $max) {
            throw $this->too_large($max);
        }

        $path = make_request_directory() . '/upload';
        file_put_contents($path, $content);
        unset($content);
        return $this->store_draft_file($path, (string)($args['filename'] ?? ''), [
            'draftitemid' => (int)($args['draftitemid'] ?? 0),
            'filepath' => $args['filepath'] ?? '/',
            'overwrite' => !empty($args['overwrite']),
        ], $ctx);
    }

    /**
     * file_upload_from_url: fetch a public http(s) URL into a draft area, with Moodle's SSRF protection.
     *
     * @param array $args url, filename, draftitemid, filepath, overwrite.
     * @param call_context $ctx Request context.
     * @return array
     */
    public function upload_from_url(array $args, call_context $ctx): array {
        global $USER;

        tickets::require_service_flag($ctx->serviceid, 'uploadfiles');
        $url = clean_param((string)($args['url'] ?? ''), PARAM_URL);
        if ($url === '' || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw self::invalid('url must be an absolute http(s) URL.');
        }
        $max = limits::get('uploadfromurlmaxbytes');
        $usermax = limits::max_upload_bytes(context_user::instance($USER->id));
        if ($usermax !== USER_CAN_IGNORE_FILE_SIZE_LIMITS) {
            $max = min($max, $usermax);
        }

        $path = make_request_directory() . '/download';
        $toolarge = false;
        $curl = new \curl();
        $result = $curl->download_one($url, null, [
            'filepath' => $path,
            'timeout' => 300,
            'followlocation' => true,
            'maxredirs' => 5,
            'CURLOPT_NOPROGRESS' => 0,
            'CURLOPT_PROGRESSFUNCTION' => function ($ch, $dltotal, $dlnow) use ($max, &$toolarge) {
                $toolarge = $dltotal > $max || $dlnow > $max;
                return $toolarge ? 1 : 0;
            },
        ]);
        if ($toolarge) {
            throw $this->too_large($max);
        }
        $info = $curl->get_info();
        if ($result !== true || (int)($info['http_code'] ?? 0) !== 200) {
            throw new moodle_exception('error', 'moodle', '', null, 'Could not fetch the URL: '
                . (is_string($result) ? $result : 'HTTP ' . (int)($info['http_code'] ?? 0)));
        }

        $filename = (string)($args['filename'] ?? '');
        if ($filename === '') {
            foreach ((array)$curl->getResponse() as $name => $value) {
                $disposition = strcasecmp((string)$name, 'Content-Disposition') === 0 ? implode(';', (array)$value) : '';
                if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $m)) {
                    $filename = rawurldecode($m[1]);
                }
            }
        }
        if ($filename === '') {
            $filename = rawurldecode(basename((string)parse_url((string)($info['url'] ?? $url), PHP_URL_PATH))) ?: 'download';
        }

        return $this->store_draft_file($path, $filename, [
            'draftitemid' => (int)($args['draftitemid'] ?? 0),
            'filepath' => $args['filepath'] ?? '/',
            'overwrite' => !empty($args['overwrite']),
            'maxbytes' => $max,
            'source' => $url,
        ], $ctx);
    }

    /**
     * file_save_draft: copy a draft area into a file area the user may write to.
     *
     * @param array $args draftitemid, target (contextid|courseid|cmid, component, filearea, itemid), mode merge|replace.
     * @param call_context $ctx Request context.
     * @return array
     */
    public function save_draft(array $args, call_context $ctx): array {
        global $CFG, $USER;

        self::apply_restriction($ctx);
        $draftitemid = (int)($args['draftitemid'] ?? 0);
        $mode = (string)($args['mode'] ?? 'merge');
        if ($draftitemid <= 0 || !in_array($mode, ['merge', 'replace'], true)) {
            throw self::invalid('draftitemid and mode (merge|replace) are required.');
        }
        $usercontext = context_user::instance($USER->id);
        $component = (string)($args['component'] ?? 'user');
        $filearea = (string)($args['filearea'] ?? 'private');
        $itemid = (int)($args['itemid'] ?? 0);
        $context = self::target_context($args) ?? $usercontext;
        if ($component === 'user' && $filearea === 'draft') {
            throw self::invalid('The target cannot be a draft area.');
        }
        locator::check_restriction($context, $ctx->restrictedcontext);

        $info = locator::file_info(['contextid' => $context->id, 'component' => $component, 'filearea' => $filearea,
            'itemid' => $itemid, 'filepath' => '/', 'filename' => '.']);
        if (!$info || !$info->is_writable()) {
            throw new moodle_exception('nopermissions', 'error', '', 'write files to ' . $component . '/' . $filearea);
        }

        $fs = get_file_storage();
        $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);
        if (!$draftfiles) {
            throw self::invalid('The draft area is empty or not yours.');
        }

        $maxbytes = limits::max_upload_bytes($context);
        $areamaxbytes = FILE_AREA_MAX_BYTES_UNLIMITED;
        if ($context->id == $usercontext->id && $component === 'user' && $filearea === 'private') {
            // Same quota rules as core_user_add_user_private_files.
            require_capability('moodle/user:manageownfiles', $usercontext);
            if (!has_capability('moodle/user:ignoreuserquota', $usercontext)) {
                $maxbytes = $areamaxbytes = (int)$CFG->userquota;
                $used = $mode === 'replace' ? 0
                    : file_get_file_area_info($usercontext->id, 'user', 'private')['filesize_without_references'];
                if (file_get_draft_area_info($draftitemid)['filesize_without_references'] + $used > $areamaxbytes) {
                    throw new file_exception('userquotalimit');
                }
            }
        }

        $options = ['subdirs' => 1, 'maxfiles' => -1, 'maxbytes' => $maxbytes, 'areamaxbytes' => $areamaxbytes];
        if ($mode === 'replace') {
            file_save_draft_area_files($draftitemid, $context->id, $component, $filearea, $itemid, $options);
        } else {
            file_merge_files_from_draft_area_into_filearea($draftitemid, $context->id, $component, $filearea, $itemid, $options);
        }

        $saved = $this->area_files($context->id, $component, $filearea, $itemid);
        $savedkeys = array_map(static fn($f) => $f['filepath'] . $f['filename'], $saved);
        $skipped = [];
        foreach ($draftfiles as $file) {
            if (!in_array($file->get_filepath() . $file->get_filename(), $savedkeys, true)) {
                $skipped[] = $file->get_filepath() . $file->get_filename();
            }
        }
        return ['mode' => $mode, 'contextid' => (int)$context->id, 'component' => $component, 'filearea' => $filearea,
            'itemid' => $itemid, 'files' => array_slice($saved, 0, self::MAX_REPORTED), 'skipped' => $skipped];
    }

    /**
     * file_delete: delete a file or an empty folder the user may write to.
     *
     * @param array $args uri.
     * @param call_context $ctx Request context.
     * @return array
     */
    public function delete(array $args, call_context $ctx): array {
        $uri = (string)($args['uri'] ?? '');
        $params = locator::parse_uri($uri);
        $context = context::instance_by_id($params['contextid'], IGNORE_MISSING);
        if (!$context) {
            throw new moodle_exception('filenotfound', 'error');
        }
        locator::check_restriction($context, $ctx->restrictedcontext, $params['component'], $params['filearea']);
        $info = locator::file_info($params);
        if (!$info || !$info->is_readable()) {
            throw new moodle_exception('filenotfound', 'error');
        }
        if (!$info->is_writable()) {
            throw new moodle_exception('nopermissions', 'error', '', 'delete this file');
        }
        if ($info->is_directory()) {
            if ($params['filepath'] === '/') {
                throw self::invalid('The root folder of an area cannot be deleted.');
            }
            if ($info->get_children()) {
                throw self::invalid('The folder is not empty; delete its files first.');
            }
        }
        if (!$info->delete()) {
            throw new moodle_exception('nopermissions', 'error', '', 'delete this file');
        }
        return ['deleted' => true, 'uri' => $uri];
    }

    /**
     * file_set_course_image: replace a course's overview image(s) from a draft area.
     *
     * @param array $args courseid, draftitemid.
     * @param call_context $ctx Request context.
     * @return array
     */
    public function set_course_image(array $args, call_context $ctx): array {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/course/lib.php');

        self::apply_restriction($ctx);
        $course = get_course((int)($args['courseid'] ?? 0));
        $context = context_course::instance($course->id);
        locator::check_restriction($context, $ctx->restrictedcontext);
        external_api::validate_context($context);
        require_capability('moodle/course:update', $context);

        $options = course_overviewfiles_options($course);
        if ($options === null) {
            throw new moodle_exception('error', 'moodle', '', null, 'Course images are disabled on this site.');
        }
        $draftitemid = (int)($args['draftitemid'] ?? 0);
        $usercontextid = context_user::instance($USER->id)->id;
        $files = get_file_storage()->get_area_files($usercontextid, 'user', 'draft', $draftitemid, 'id', false);
        if (!$files) {
            throw self::invalid('The draft area is empty or not yours.');
        }
        if (count($files) > (int)$options['maxfiles']) {
            throw self::invalid('Too many files: courses allow ' . $options['maxfiles'] . ' overview file(s).');
        }
        $types = new \core_form\filetypes_util();
        foreach ($files as $file) {
            $allowed = $options['accepted_types'] === '*'
                || $types->is_allowed_file_type($file->get_filename(), $options['accepted_types']);
            if (!$allowed) {
                throw new moodle_exception('invalidfiletype', 'repository', '', $file->get_filename());
            }
        }
        file_save_draft_area_files($draftitemid, $context->id, 'course', 'overviewfiles', 0, $options);
        \cache::make('core', 'course_image')->delete($course->id);
        return ['courseid' => (int)$course->id, 'files' => $this->area_files($context->id, 'course', 'overviewfiles', 0)];
    }

    /**
     * Upload limits and private-files usage for the current user.
     *
     * @return array
     */
    public static function user_limits(): array {
        global $CFG, $USER;

        $usercontext = context_user::instance($USER->id);
        $ignorequota = has_capability('moodle/user:ignoreuserquota', $usercontext);
        return [
            'maxuploadbytes' => limits::max_upload_bytes($usercontext),
            'privatefilesquota' => $ignorequota ? -1 : (int)$CFG->userquota,
            'privatefilesused' => (int)file_get_file_area_info($usercontext->id, 'user', 'private')['filesize_without_references'],
            'inlineuploadmaxbytes' => limits::get('uploadinlinemaxbytes'),
        ];
    }

    /**
     * Resolve contextid|courseid|cmid|userid arguments to a context.
     *
     * @param array $args Arguments.
     * @return context|null Null when none were given.
     */
    public static function target_context(array $args): ?context {
        if (!empty($args['contextid'])) {
            return context::instance_by_id((int)$args['contextid']);
        }
        if (!empty($args['cmid'])) {
            return context_module::instance((int)$args['cmid']);
        }
        if (!empty($args['courseid'])) {
            return context_course::instance((int)$args['courseid']);
        }
        if (!empty($args['userid'])) {
            return context_user::instance((int)$args['userid']);
        }
        return null;
    }

    /**
     * Make external_api::validate_context() honour the call's token restriction.
     *
     * @param call_context $ctx Request context.
     * @return void
     */
    public static function apply_restriction(call_context $ctx): void {
        if ($ctx->restrictedcontext !== null) {
            external_api::set_context_restriction($ctx->restrictedcontext);
        }
    }

    /**
     * Files in an area, for reporting.
     *
     * @param int $contextid Context id.
     * @param string $component Component.
     * @param string $filearea File area.
     * @param int $itemid Item id.
     * @return array
     */
    private function area_files(int $contextid, string $component, string $filearea, int $itemid): array {
        $files = [];
        $stored = get_file_storage()->get_area_files($contextid, $component, $filearea, $itemid, 'filepath, filename', false);
        foreach ($stored as $file) {
            $files[] = ['filename' => $file->get_filename(), 'filepath' => $file->get_filepath(),
                'size' => (int)$file->get_filesize(), 'uri' => locator::uri_for($file)];
        }
        return $files;
    }

    /**
     * Size-limit error.
     *
     * @param int $max Limit in bytes.
     * @return transfer_exception
     */
    private function too_large(int $max): transfer_exception {
        return new transfer_exception(413, 'maxbytes', 'The file is larger than the ' . display_size($max)
            . ' limit for this method. Use file_create_upload_url for large files.');
    }

    /**
     * Invalid-argument error.
     *
     * @param string $message Message.
     * @return moodle_exception
     */
    private static function invalid(string $message): moodle_exception {
        return new moodle_exception('invalidparameter', 'debug', '', null, $message);
    }
}
