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

use backup;
use context;
use context_course;
use context_coursecat;
use context_module;
use context_user;
use core_external\external_api;
use moodle_exception;
use stored_file;
use webservice_mcp\local\mcp\call_context;

/**
 * Asynchronous course/section/activity backups and restores, run as adhoc tasks under the requesting user.
 *
 * Permission checks mirror backup/backup.php, backup/restorefile.php and backup/restore.php; the
 * backup and restore controllers re-check capabilities for the user when they run.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_service {
    /**
     * Constructor: load the backup library.
     */
    public function __construct() {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
    }

    /**
     * Restore targets accepted by restore_from_draft.
     *
     * A method, not a class constant: PHP evaluates constant expressions when the object is created, before the
     * constructor has loaded the backup library, so a constant naming backup:: fatals on a cold request.
     *
     * @return array<string, int>
     */
    private static function targets(): array {
        return [
            'new_course' => backup::TARGET_NEW_COURSE,
            'existing_add' => backup::TARGET_EXISTING_ADDING,
            'existing_delete' => backup::TARGET_EXISTING_DELETING,
        ];
    }

    /**
     * backup_create: queue an asynchronous backup.
     *
     * @param array $args courseid|sectionid|cmid, include_users, anonymize.
     * @param call_context $ctx Request context.
     * @return array
     */
    public function create(array $args, call_context $ctx): array {
        global $DB, $USER;

        file_service::apply_restriction($ctx);
        if (!empty($args['cmid'])) {
            $cm = get_coursemodule_from_id('', (int)$args['cmid'], 0, false, MUST_EXIST);
            [$type, $id, $context, $cap] = [backup::TYPE_1ACTIVITY, (int)$cm->id, context_module::instance($cm->id),
                'moodle/backup:backupactivity'];
        } else if (!empty($args['sectionid'])) {
            $section = $DB->get_record('course_sections', ['id' => (int)$args['sectionid']], 'id, course', MUST_EXIST);
            [$type, $id, $context, $cap] = [backup::TYPE_1SECTION, (int)$section->id, context_course::instance($section->course),
                'moodle/backup:backupsection'];
        } else if (!empty($args['courseid'])) {
            [$type, $id, $context, $cap] = [backup::TYPE_1COURSE, (int)$args['courseid'],
                context_course::instance((int)$args['courseid']), 'moodle/backup:backupcourse'];
        } else {
            throw new transfer_exception(400, 'invalidparameter', 'Provide courseid, sectionid or cmid.');
        }
        locator::check_restriction($context, $ctx->restrictedcontext);
        external_api::validate_context($context);
        require_capability($cap, $context);

        $users = !empty($args['include_users']);
        $anonymize = $users && !empty($args['anonymize']);
        if ($users) {
            require_capability('moodle/backup:userinfo', $context);
        }
        if ($anonymize) {
            require_capability('moodle/backup:anonymise', $context);
        }

        $bc = new \backup_controller($type, $id, backup::FORMAT_MOODLE, backup::INTERACTIVE_YES, backup::MODE_ASYNC, $USER->id);
        try {
            $plan = $bc->get_plan();
            self::set_setting($plan, 'users', $users);
            if ($users) {
                self::set_setting($plan, 'anonymize', $anonymize);
            }
            $files = !$plan->setting_exists('files') || (bool)$plan->get_setting('files')->get_value();
            $filename = \backup_plan_dbops::get_default_backup_filename(
                backup::FORMAT_MOODLE,
                $type,
                $id,
                $users,
                $anonymize,
                false,
                $files
            );
            // A backup id suffix lets backup_status find the file after the controller is cleaned up.
            $filename = preg_replace('/\.mbz$/', '', $filename) . self::suffix($bc->get_backupid());
            $plan->get_setting('filename')->set_value($filename);
            $bc->finish_ui();
            $backupid = $bc->get_backupid();
        } finally {
            $bc->destroy();
        }

        $task = new \core\task\asynchronous_backup_task();
        $task->set_custom_data(['backupid' => $backupid]);
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task);

        return [
            'backupid' => $backupid,
            'filename' => $filename,
            'state' => 'queued',
            'next' => 'Runs in the background on the next cron run. Poll backup_status with this backupid.',
        ];
    }

    /**
     * backup_status: progress of the caller's own backup or restore, with the result when finished.
     *
     * @param array $args backupid (a backup or restore id).
     * @param call_context $ctx Request context.
     * @return array
     */
    public function status(array $args, call_context $ctx): array {
        global $DB, $USER;

        $backupid = clean_param((string)($args['backupid'] ?? ''), PARAM_ALPHANUM);
        if (strpos($backupid, export_service::HANDLE_PREFIX) === 0) {
            return (new export_service())->status($backupid, $ctx);
        }
        $record = $backupid === '' ? false : $DB->get_record(
            'backup_controllers',
            ['backupid' => $backupid],
            'id, backupid, operation, type, itemid, userid, status, progress, timecreated, timemodified'
        );
        if (!$record || (int)$record->userid !== (int)$USER->id) {
            throw new transfer_exception(400, 'invalidparameter', 'No backup or restore with that id belongs to you.');
        }

        $status = (int)$record->status;
        $state = $status >= backup::STATUS_FINISHED_OK ? 'finished'
            : ($status >= backup::STATUS_FINISHED_ERR ? 'failed' : ($status >= backup::STATUS_EXECUTING ? 'running' : 'queued'));
        $result = [
            'backupid' => $record->backupid,
            'operation' => $record->operation,
            'type' => $record->type,
            'state' => $state,
            'statuscode' => $status,
            'progress' => round((float)$record->progress, 3),
            'timecreated' => (int)$record->timecreated,
            'timemodified' => (int)$record->timemodified,
        ];

        if ($state === 'finished' && $record->operation === 'backup' && ($file = $this->find_backup_file($record))) {
            $result['file'] = ['filename' => $file->get_filename(), 'size' => (int)$file->get_filesize(),
                'uri' => locator::uri_for($file)];
            try {
                $reader = new file_reader($ctx);
                $result['file']['download'] = tickets::download_url(
                    $ctx,
                    file_reader::download_claims($reader->resolve(locator::uri_for($file), null))
                );
            } catch (moodle_exception $e) {
                $result['file']['downloaderror'] = $e->getMessage();
            }
            $result['next'] = 'Restore it with restore_from_draft (uri parameter) or download it.';
        } else if ($state === 'finished' && $record->operation === 'restore') {
            $result['courseid'] = (int)$record->itemid;
            $result['courseurl'] = (new \moodle_url('/course/view.php', ['id' => $record->itemid]))->out(false);
        } else if ($state === 'queued') {
            $result['next'] = 'Waiting for cron to run the adhoc task.';
        }
        return $result;
    }

    /**
     * restore_from_draft: queue an asynchronous restore of a .mbz from the caller's draft area (or a readable file URI).
     *
     * @param array $args draftitemid|uri, filename, target, courseid, categoryid, fullname, shortname, include_users.
     * @param call_context $ctx Request context.
     * @return array
     */
    public function restore(array $args, call_context $ctx): array {
        global $USER;

        file_service::apply_restriction($ctx);
        $target = (string)($args['target'] ?? '');
        if (!isset(self::targets()[$target])) {
            throw new transfer_exception(400, 'invalidparameter', 'target must be new_course, existing_add or existing_delete.');
        }
        $users = !empty($args['include_users']);

        if ($target === 'new_course') {
            $context = context_coursecat::instance((int)($args['categoryid'] ?? 0));
            $caps = ['moodle/course:create', 'moodle/restore:restorecourse'];
        } else {
            $context = context_course::instance((int)($args['courseid'] ?? 0));
            $caps = ['moodle/restore:restorecourse'];
        }
        locator::check_restriction($context, $ctx->restrictedcontext);
        external_api::validate_context($context);
        $file = $this->source_file($args, $ctx);
        if ($file->get_component() === 'user' && $file->get_filearea() === 'draft') {
            $caps[] = 'moodle/restore:uploadfile';
        }
        if ($users) {
            $caps[] = 'moodle/restore:userinfo';
        }
        foreach ($caps as $cap) {
            require_capability($cap, $context);
        }

        $tempdir = \restore_controller::get_tempdir_name($context->instanceid, $USER->id);
        $path = make_backup_temp_directory($tempdir);
        if (!get_file_packer('application/vnd.moodle.backup')->extract_to_pathname($file, $path)) {
            fulldelete($path);
            throw new moodle_exception('invalidfiletype', 'error', '', $file->get_filename());
        }

        $newcourse = $target === 'new_course';
        $courseid = $newcourse
            ? (int)\restore_dbops::create_new_course(
                (string)($args['fullname'] ?? '') ?: get_string('restoringcourse', 'backup'),
                (string)($args['shortname'] ?? '') ?: get_string('restoringcourseshortname', 'backup'),
                (int)$context->instanceid
            )
            : (int)$context->instanceid;

        try {
            $rc = new \restore_controller(
                $tempdir,
                $courseid,
                backup::INTERACTIVE_YES,
                backup::MODE_ASYNC,
                $USER->id,
                self::targets()[$target]
            );
            if ($rc->get_status() == backup::STATUS_REQUIRE_CONV) {
                $rc->convert();
            }
            $plan = $rc->get_plan();
            if ($plan->setting_exists('users')) {
                self::set_setting($plan, 'users', $users && (bool)$plan->get_setting('users')->get_value());
            }
            foreach (['fullname' => 'course_fullname', 'shortname' => 'course_shortname'] as $arg => $setting) {
                if ($newcourse && !empty($args[$arg]) && $plan->setting_exists($setting)) {
                    self::set_setting($plan, $setting, (string)$args[$arg]);
                }
            }
            $rc->finish_ui();
            $rc->execute_precheck();
            $precheck = $rc->get_precheck_results();
            $restoreid = $rc->get_restoreid();
            $rc->destroy();
        } catch (\Throwable $e) {
            $this->abandon_restore($newcourse ? $courseid : 0, $path);
            throw $e;
        }
        if (!empty($precheck['errors'])) {
            $this->abandon_restore($newcourse ? $courseid : 0, $path);
            throw new transfer_exception(400, 'error', 'Restore prechecks failed: ' . implode(' ', $precheck['errors']));
        }

        $task = new \core\task\asynchronous_restore_task();
        $task->set_custom_data(['backupid' => $restoreid]);
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task);

        return [
            'restoreid' => $restoreid,
            'backupid' => $restoreid,
            'courseid' => $courseid,
            'target' => $target,
            'state' => 'queued',
            'warnings' => array_values($precheck['warnings'] ?? []),
            'next' => 'Runs in the background on the next cron run. Poll backup_status with this id.',
        ];
    }

    /**
     * Locate the .mbz to restore: a draft file of the caller, or any file URI they can read.
     *
     * @param array $args draftitemid, filename, uri.
     * @param call_context $ctx Request context.
     * @return stored_file
     */
    private function source_file(array $args, call_context $ctx): stored_file {
        global $USER;

        if (!empty($args['uri'])) {
            $source = (new file_reader($ctx))->open((new file_reader($ctx))->resolve((string)$args['uri'], null), 0);
            if ($source['file'] === null) {
                throw new moodle_exception('filenotfound', 'error');
            }
            return $source['file'];
        }
        $files = get_file_storage()->get_area_files(
            context_user::instance($USER->id)->id,
            'user',
            'draft',
            (int)($args['draftitemid'] ?? 0),
            'id DESC',
            false
        );
        $wanted = (string)($args['filename'] ?? '');
        $files = array_values(array_filter($files, static fn(stored_file $f) => $wanted !== ''
            ? $f->get_filename() === $wanted : substr(strtolower($f->get_filename()), -4) === '.mbz'));
        if (count($files) !== 1) {
            throw new transfer_exception(400, 'invalidparameter', $files
                ? 'Several .mbz files are in that draft area; pass filename.' : 'No matching .mbz file in that draft area.');
        }
        return $files[0];
    }

    /**
     * Find the .mbz written by a finished backup (see the suffix added in create()).
     *
     * @param \stdClass $record backup_controllers record.
     * @return stored_file|null
     */
    private function find_backup_file(\stdClass $record): ?stored_file {
        global $DB;

        $select = 'userid = :userid AND ' . $DB->sql_like('filename', ':pattern')
            . " AND ((component = 'user' AND filearea = 'backup') OR component = 'backup')";
        $rows = $DB->get_records_select('files', $select, ['userid' => $record->userid,
            'pattern' => '%' . $DB->sql_like_escape(self::suffix($record->backupid))], 'id DESC', '*', 0, 1);
        return $rows ? get_file_storage()->get_file_instance(reset($rows)) : null;
    }

    /**
     * Change a plan setting, refusing settings locked by configuration or permission.
     *
     * @param \base_plan $plan Plan.
     * @param string $name Setting name.
     * @param mixed $value Value.
     * @return void
     */
    private static function set_setting(\base_plan $plan, string $name, $value): void {
        $setting = $plan->get_setting($name);
        if ($setting->get_value() == $value) {
            return;
        }
        if ($setting->get_status() !== \base_setting::NOT_LOCKED) {
            throw new transfer_exception(400, 'settinglocked', "The backup setting '{$name}' is locked by site configuration "
                . 'or your permissions.');
        }
        $setting->set_value($value);
    }

    /**
     * File name suffix that ties a backup file to its backup id.
     *
     * @param string $backupid Backup id.
     * @return string
     */
    private static function suffix(string $backupid): string {
        return '-' . substr($backupid, 0, 10) . '.mbz';
    }

    /**
     * Clean up after a restore that could not be queued.
     *
     * @param int $newcourseid Skeleton course created for the restore, or 0.
     * @param string $path Extracted backup directory.
     * @return void
     */
    private function abandon_restore(int $newcourseid, string $path): void {
        global $DB;

        fulldelete($path);
        if ($newcourseid && ($course = $DB->get_record('course', ['id' => $newcourseid]))) {
            $course->deletesource = 'restore';
            delete_course($course, false);
        }
    }
}
