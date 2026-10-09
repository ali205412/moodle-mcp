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
use moodle_exception;
use stored_file;
use webservice_mcp\local\mcp\call_context;

/**
 * Asynchronous zip exports (course content, all assignment submissions).
 *
 * The tool checks permissions and queues an export_task that runs as the user in cron, re-checks the same
 * permissions and writes the zip to the user's own export area. The zip is then downloaded as a stored file
 * (send_stored_file, so X-Sendfile/X-Accel-Redirect applies) instead of being built while a web worker streams it.
 * State lives in a small JSON stored file next to the zip, so no tables are needed. Exports expire after a day.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export_service {
    /** Component of the export areas. */
    public const COMPONENT = 'webservice_mcp';

    /** File area holding finished zips (itemid = export id). */
    public const AREA = 'exports';

    /** File area holding each export's state.json (itemid = export id). */
    private const STATEAREA = 'exportstate';

    /** Handle prefix, so backup_status can tell exports from backup ids. */
    public const HANDLE_PREFIX = 'export';

    /** Course content export. */
    private const KIND_COURSE = 'course_content';

    /** Assignment submissions export. */
    private const KIND_ASSIGN = 'assign_all';

    /** Exports are deleted after this many seconds. */
    public const LIFETIME = DAYSECS;

    /**
     * export_course_content: queue a zip of the course content (as "Download course content").
     *
     * @param array $args courseid.
     * @param call_context $ctx Request context.
     * @return array
     */
    public function course_content(array $args, call_context $ctx): array {
        file_service::apply_restriction($ctx);
        $context = context_course::instance((int)($args['courseid'] ?? 0));
        locator::check_restriction($context, $ctx->restrictedcontext);
        external_api::validate_context($context);
        self::check_course_content($context);
        return $this->queue(self::KIND_COURSE, ['courseid' => (int)$context->instanceid], $ctx, !empty($args['refresh']));
    }

    /**
     * export_assignment_submissions: queue a zip of all (or one group's) submissions.
     *
     * @param array $args cmid, groupid.
     * @param call_context $ctx Request context.
     * @return array
     */
    public function assign_submissions(array $args, call_context $ctx): array {
        file_service::apply_restriction($ctx);
        $cmid = (int)($args['cmid'] ?? 0);
        $context = context_module::instance($cmid);
        locator::check_restriction($context, $ctx->restrictedcontext);
        external_api::validate_context($context);
        $groupid = (int)($args['groupid'] ?? 0);
        self::check_assign($cmid, $groupid);
        return $this->queue(self::KIND_ASSIGN, ['cmid' => $cmid, 'groupid' => $groupid], $ctx, !empty($args['refresh']));
    }

    /**
     * State of one of the current user's exports; when finished, its file uri and a fresh download link.
     *
     * @param string $handle Export handle (export123).
     * @param call_context $ctx Request context.
     * @return array
     */
    public function status(string $handle, call_context $ctx): array {
        global $USER;

        $exportid = self::exportid($handle);
        $usercontext = context_user::instance($USER->id);
        $state = $exportid ? self::read_state((int)$usercontext->id, $exportid) : null;
        if ($state === null) {
            throw new transfer_exception(400, 'invalidparameter', 'No export with that id belongs to you.');
        }
        $result = ['backupid' => $handle, 'operation' => 'export', 'type' => $state['kind'], 'state' => $state['state'],
            'timecreated' => $state['created'], 'expires' => $state['created'] + self::LIFETIME];
        if ($state['state'] === 'failed') {
            $result['error'] = $state['error'];
        } else if ($state['state'] === 'queued') {
            $result['next'] = 'Waiting for cron to run the adhoc task.';
        }
        $file = $state['state'] === 'finished' ? self::export_file((int)$usercontext->id, $exportid) : null;
        if ($file) {
            $result['file'] = ['filename' => $file->get_filename(), 'size' => (int)$file->get_filesize(),
                'uri' => locator::uri_for($file)];
            try {
                $link = tickets::download_url($ctx, ['k' => tickets::KIND_EXPORT, 'fid' => (int)$file->get_id()]);
                $result['file']['download'] = $link;
                $result['file']['curl'] = 'curl -fL -o ' . escapeshellarg($file->get_filename()) . ' '
                    . escapeshellarg($link['url']);
            } catch (moodle_exception $e) {
                $result['file']['downloaderror'] = $e->getMessage();
            }
        }
        return $result;
    }

    /**
     * Build an export (called by export_task in cron, as the export's owner). Failures are recorded, not thrown.
     *
     * @param array $data Task data: exportid, kind, params, rc.
     * @return void
     */
    public function build(array $data): void {
        global $USER;

        $usercontextid = (int)context_user::instance($USER->id)->id;
        $exportid = (int)($data['exportid'] ?? 0);
        $state = self::read_state($usercontextid, $exportid);
        if ($state === null || $state['state'] !== 'queued') {
            // Purged, or already handled by an earlier run.
            return;
        }
        self::write_state($usercontextid, $exportid, ['state' => 'running'] + $state);

        $dir = null;
        try {
            $restriction = empty($data['rc']) ? null : context::instance_by_id((int)$data['rc']);
            $params = (array)$data['params'];
            [$path, $filename] = $state['kind'] === self::KIND_COURSE
                ? $this->build_course_content((int)$params['courseid'], $restriction)
                : $this->build_assign((int)$params['cmid'], (int)$params['groupid'], $restriction);
            $dir = dirname($path);
            get_file_storage()->create_file_from_pathname(['contextid' => $usercontextid, 'component' => self::COMPONENT,
                'filearea' => self::AREA, 'itemid' => $exportid, 'filepath' => '/', 'filename' => $filename,
                'userid' => $USER->id], $path);
            self::write_state($usercontextid, $exportid, ['state' => 'finished'] + $state);
        } catch (\Throwable $e) {
            $message = $e instanceof moodle_exception ? $e->getMessage() : 'Internal error while building the export.';
            if (!($e instanceof moodle_exception)) {
                debugging('MCP export ' . $exportid . ' failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            self::write_state($usercontextid, $exportid, ['state' => 'failed', 'error' => $message] + $state);
        } finally {
            if ($dir !== null) {
                remove_dir($dir);
            }
        }
    }

    /**
     * Delete exports (zips and state) created more than LIFETIME seconds ago. Called by the cleanup task.
     *
     * @param int|null $now Current time, for tests.
     * @return int Number of exports deleted.
     */
    public static function purge(?int $now = null): int {
        global $DB;

        $cutoff = ($now ?? time()) - self::LIFETIME;
        $rows = $DB->get_records_select(
            'files',
            'component = :component AND filearea = :filearea AND filename = :filename AND timecreated < :cutoff',
            ['component' => self::COMPONENT, 'filearea' => self::STATEAREA, 'filename' => 'state.json', 'cutoff' => $cutoff],
            '',
            'id, contextid, itemid'
        );
        $fs = get_file_storage();
        foreach ($rows as $row) {
            $fs->delete_area_files((int)$row->contextid, self::COMPONENT, self::AREA, (int)$row->itemid);
            $fs->delete_area_files((int)$row->contextid, self::COMPONENT, self::STATEAREA, (int)$row->itemid);
        }
        return count($rows);
    }

    /**
     * The current user's finished export file for given file params, if it is one.
     *
     * @param array $params File params (contextid, component, filearea, itemid, filepath, filename).
     * @return stored_file|null
     */
    public static function own_file(array $params): ?stored_file {
        global $USER;

        if (
            ($params['component'] ?? '') !== self::COMPONENT || ($params['filearea'] ?? '') !== self::AREA
                || (int)$params['contextid'] !== (int)context_user::instance($USER->id)->id
        ) {
            return null;
        }
        $file = get_file_storage()->get_file(
            $params['contextid'],
            self::COMPONENT,
            self::AREA,
            $params['itemid'],
            $params['filepath'],
            $params['filename']
        );
        return $file && !$file->is_directory() ? $file : null;
    }

    /**
     * Throw unless the user may export the course content.
     *
     * @param context $context Course context.
     * @return void
     */
    private static function check_course_content(context $context): void {
        global $USER;

        if (!can_access_course(get_course($context->instanceid)) || !\core\content::can_export_context($context, $USER)) {
            throw new moodle_exception(
                'nopermissions',
                'error',
                '',
                'download course content (it may be disabled for this site or course)'
            );
        }
    }

    /**
     * Throw unless the user may download all submissions (and see the group, when given).
     *
     * @param int $cmid Assignment course module id.
     * @param int $groupid Group id or 0.
     * @return array [\assign, int[]|null user ids]
     */
    private static function check_assign(int $cmid, int $groupid): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'assign');
        if (!$cm->uservisible || !can_access_course($course)) {
            throw new moodle_exception('nopermissions', 'error', '', 'access this assignment');
        }
        $assign = new \assign(context_module::instance($cm->id), $cm, $course);
        $assign->require_view_grades();
        return [$assign, $groupid ? self::group_userids($cm, $groupid) : null];
    }

    /**
     * Members of a group the user may see in this activity.
     *
     * @param \cm_info|\stdClass $cm Course module.
     * @param int $groupid Group id.
     * @return int[] User ids ([0] when the group is empty, so nothing matches).
     */
    public static function group_userids($cm, int $groupid): array {
        $group = groups_get_group($groupid, 'id, courseid', MUST_EXIST);
        if ((int)$group->courseid !== (int)$cm->course) {
            throw new transfer_exception(400, 'invalidparameter', 'The group is not in this course.');
        }
        $context = context_module::instance($cm->id);
        if (groups_get_activity_groupmode($cm) == SEPARATEGROUPS && !groups_is_member($groupid)) {
            require_capability('moodle/site:accessallgroups', $context);
        }
        $ids = array_map('intval', array_keys(groups_get_members($groupid, 'u.id')));
        return $ids ?: [0];
    }

    /**
     * Record a queued export and queue its adhoc task as the current user.
     *
     * @param string $kind Export kind.
     * @param array $params Kind parameters.
     * @param call_context $ctx Request context.
     * @param bool $refresh Build a new export even if an identical one exists.
     * @return array Tool result.
     */
    private function queue(string $kind, array $params, call_context $ctx, bool $refresh): array {
        global $USER;

        // The finished zip is only useful through a download link, which needs the service's download flag.
        tickets::require_service_flag($ctx->serviceid, 'downloadfiles');
        $usercontextid = (int)context_user::instance($USER->id)->id;
        // Idempotent: the same request again returns the export already queued, running or finished (until it expires).
        if (!$refresh && ($existing = self::find_existing($usercontextid, $kind, $params)) !== null) {
            $result = $this->status(self::HANDLE_PREFIX . $existing, $ctx);
            $result['reused'] = true;
            $result['next'] = trim(($result['next'] ?? '') . ' This export was already requested; pass refresh=true to '
                . 'build a new one.');
            return $result;
        }
        do {
            $exportid = random_int(1, 2147483647);
        } while (self::read_state($usercontextid, $exportid) !== null);
        self::write_state($usercontextid, $exportid, ['state' => 'queued', 'kind' => $kind, 'params' => $params,
            'created' => time()]);

        $task = new export_task();
        $task->set_custom_data(['exportid' => $exportid, 'params' => $params,
            'rc' => $ctx->restrictedcontext ? (int)$ctx->restrictedcontext->id : null]);
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task);

        return [
            'backupid' => self::HANDLE_PREFIX . $exportid,
            'state' => 'queued',
            'next' => 'The zip is built in the background on the next cron run. Poll backup_status with this backupid; '
                . 'when finished it returns a download link. Exports are deleted after 24 hours.',
        ];
    }

    /**
     * The newest unexpired, not failed export of the same kind and parameters by this user.
     *
     * @param int $usercontextid User context id.
     * @param string $kind Export kind.
     * @param array $params Kind parameters.
     * @return int|null Export id.
     */
    private static function find_existing(int $usercontextid, string $kind, array $params): ?int {
        $files = get_file_storage()->get_area_files($usercontextid, self::COMPONENT, self::STATEAREA, false, 'itemid', false);
        $best = null;
        foreach ($files as $file) {
            $state = json_decode($file->get_content(), true);
            if (
                is_array($state) && ($state['kind'] ?? '') === $kind && ($state['params'] ?? null) == $params
                    && ($state['state'] ?? '') !== 'failed' && (int)$state['created'] > time() - self::LIFETIME
                    && ($best === null || [(int)$state['created'], (int)$file->get_id()] > [$best[1], $best[2]])
            ) {
                $best = [(int)$file->get_itemid(), (int)$state['created'], (int)$file->get_id()];
            }
        }
        return $best[0] ?? null;
    }

    /**
     * Zip the course content into a temporary file.
     *
     * @param int $courseid Course id.
     * @param context|null $restriction Token context restriction at queue time.
     * @return array [path, filename]
     */
    private function build_course_content(int $courseid, ?context $restriction): array {
        global $CFG, $USER;

        $context = context_course::instance($courseid);
        locator::check_restriction($context, $restriction);
        self::check_course_content($context);
        $course = get_course($courseid);
        $filename = clean_filename(str_replace(' ', '_', $course->shortname) . '_' . time() . '.zip');
        $options = empty($CFG->maxsizeperdownloadcoursefile) ? null
            : (object)['maxfilesize' => $CFG->maxsizeperdownloadcoursefile];
        $writer = \core\content\export\zipwriter::get_file_writer($filename, $options);
        \core\content::export_context($context, $USER, $writer);
        return [$writer->get_file_path(), $filename];
    }

    /**
     * Zip the assignment submissions into a temporary file (same contents as "Download all submissions").
     *
     * @param int $cmid Assignment course module id.
     * @param int $groupid Group id or 0.
     * @param context|null $restriction Token context restriction at queue time.
     * @return array [path, filename]
     */
    private function build_assign(int $cmid, int $groupid, ?context $restriction): array {
        locator::check_restriction(context_module::instance($cmid), $restriction);
        [$assign, $userids] = self::check_assign($cmid, $groupid);
        $downloader = new assign_export_downloader($assign, $userids);
        if (!$downloader->load_filelist()) {
            throw new moodle_exception('nosubmission', 'mod_assign');
        }
        $cm = $assign->get_course_module();
        $filename = clean_filename($assign->get_course()->shortname . '-' . $assign->get_instance()->name . '-' . $cm->id
            . '.zip');
        $zip = \core_files\archive_writer::get_file_writer($filename, \core_files\archive_writer::ZIP_WRITER);
        foreach ($downloader->files() as $pathinzip => $file) {
            if ($file instanceof stored_file) {
                $zip->add_file_from_stored_file($pathinzip, $file);
            } else if (is_array($file)) {
                // Online text and similar plugins provide content instead of a file.
                $zip->add_file_from_string($pathinzip, (string)reset($file));
            }
        }
        $zip->finish();
        \mod_assign\event\all_submissions_downloaded::create_from_assign($assign)->trigger();
        return [$zip->get_path_to_zip(), $filename];
    }

    /**
     * Export id from a handle.
     *
     * @param string $handle Handle such as export123.
     * @return int Export id, 0 when malformed.
     */
    private static function exportid(string $handle): int {
        return preg_match('/^' . self::HANDLE_PREFIX . '(\d{1,10})$/', $handle, $m) ? (int)$m[1] : 0;
    }

    /**
     * The finished zip of an export.
     *
     * @param int $usercontextid Owner's user context id.
     * @param int $exportid Export id.
     * @return stored_file|null
     */
    private static function export_file(int $usercontextid, int $exportid): ?stored_file {
        $files = get_file_storage()->get_area_files($usercontextid, self::COMPONENT, self::AREA, $exportid, 'id', false);
        return $files ? reset($files) : null;
    }

    /**
     * Read an export's state.
     *
     * @param int $usercontextid Owner's user context id.
     * @param int $exportid Export id.
     * @return array|null
     */
    private static function read_state(int $usercontextid, int $exportid): ?array {
        $file = get_file_storage()->get_file($usercontextid, self::COMPONENT, self::STATEAREA, $exportid, '/', 'state.json');
        $state = $file ? json_decode($file->get_content(), true) : null;
        return is_array($state) ? $state : null;
    }

    /**
     * Replace an export's state; timecreated stays the queue time so purge() ages exports from creation.
     *
     * @param int $usercontextid Owner's user context id.
     * @param int $exportid Export id.
     * @param array $state State (state, kind, created, error).
     * @return void
     */
    private static function write_state(int $usercontextid, int $exportid, array $state): void {
        $fs = get_file_storage();
        $fs->delete_area_files($usercontextid, self::COMPONENT, self::STATEAREA, $exportid);
        $fs->create_file_from_string(['contextid' => $usercontextid, 'component' => self::COMPONENT,
            'filearea' => self::STATEAREA, 'itemid' => $exportid, 'filepath' => '/', 'filename' => 'state.json',
            'timecreated' => (int)$state['created']], json_encode($state));
    }
}
