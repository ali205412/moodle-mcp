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

namespace webservice_mcp;

use advanced_testcase;
use context_course;
use context_system;
use context_user;
use core_external\external_api;
use webservice_mcp\local\files\download_handler;
use webservice_mcp\local\files\export_service;
use webservice_mcp\local\files\tools;
use webservice_mcp\local\files\transfer_exception;
use webservice_mcp\local\mcp\call_context;

/**
 * Tests for asynchronous zip exports.
 *
 * @package     webservice_mcp
 * @category    test
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\files\export_service
 * @covers      \webservice_mcp\local\files\export_task
 * @covers      \webservice_mcp\local\files\assign_export_downloader
 */
final class files_export_test extends advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass */
    private $teacher;

    /** @var \stdClass */
    private $student;

    /** @var \stdClass Assignment module record. */
    private $assign;

    /**
     * Course with an online-text assignment, a submission, and course content downloads enabled.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        external_api::set_context_restriction(context_system::instance());
        set_config('downloadcoursecontentallowed', 1);
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['downloadcontent' => DOWNLOAD_COURSE_CONTENT_ENABLED]);
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $this->student = $generator->create_and_enrol($this->course, 'student');
        $generator->create_module('page', ['course' => $this->course->id, 'content' => 'Week one notes']);
        $this->assign = $generator->create_module('assign', ['course' => $this->course->id,
            'assignsubmission_onlinetext_enabled' => 1]);
        $generator->get_plugin_generator('mod_assign')->create_submission(['userid' => $this->student->id,
            'cmid' => $this->assign->cmid, 'onlinetext' => 'My essay']);
    }

    /**
     * Call a tool as a user.
     *
     * @param \stdClass $user User.
     * @param string $name Tool.
     * @param array $args Arguments.
     * @return array Structured result.
     */
    private function call(\stdClass $user, string $name, array $args): array {
        $this->setUser($user);
        // Family key is only needed to mint links here; redeeming is covered in files_tickets_test.
        $ctx = new call_context(call_context::ERA_MODERN, '2026-07-28', $user, null, null, true, 'files', [], 'f_test');
        return tools::execute($name, $args, $ctx)['structuredContent'];
    }

    /**
     * Run the queued export tasks as their owners, as cron does.
     *
     * @return int Tasks run.
     */
    private function run_exports(): int {
        $tasks = \core\task\manager::get_adhoc_tasks('\\webservice_mcp\\local\\files\\export_task');
        foreach ($tasks as $task) {
            $this->setUser($task->get_userid());
            $task->execute();
        }
        return count($tasks);
    }

    /**
     * Assignment exports are queued, built in cron and downloaded as a stored file.
     */
    public function test_assignment_export_builds_in_cron(): void {
        $queued = $this->call($this->teacher, 'export_assignment_submissions', ['cmid' => $this->assign->cmid]);
        $this->assertSame('queued', $queued['state']);
        $this->assertStringStartsWith('export', $queued['backupid']);
        $this->assertSame('queued', $this->call($this->teacher, 'backup_status', ['backupid' => $queued['backupid']])['state']);

        $this->assertSame(1, $this->run_exports());
        $status = $this->call($this->teacher, 'backup_status', ['backupid' => $queued['backupid']]);
        $this->assertSame('finished', $status['state']);
        $this->assertStringStartsWith('moodle://file/' . context_user::instance($this->teacher->id)->id
            . '/webservice_mcp/exports/', $status['file']['uri']);
        $this->assertStringContainsString('/webservice/mcp/pluginfile.php?t=', $status['file']['download']['url']);

        $file = download_handler::export_file($this->ticket_fid($status), context_user::instance($this->teacher->id));
        $entries = $file->list_files(get_file_packer('application/zip'));
        $this->assertNotEmpty($entries);

        // The zip is also readable through its uri, by its owner only.
        $this->setUser($this->teacher);
        $this->assertNotEmpty(tools::execute(
            'file_read',
            ['uri' => $status['file']['uri']],
            new call_context(call_context::ERA_MODERN, '2026-07-28', $this->teacher, null, null, true, 'files', [], 'f_test')
        ));
    }

    /**
     * Asking again returns the user's existing export (queued or finished); refresh or other arguments build a new one.
     */
    public function test_repeat_requests_reuse_the_export(): void {
        $args = ['cmid' => $this->assign->cmid];
        $first = $this->call($this->teacher, 'export_assignment_submissions', $args);
        $again = $this->call($this->teacher, 'export_assignment_submissions', $args);
        $this->assertSame($first['backupid'], $again['backupid']);
        $this->assertTrue($again['reused']);
        $this->assertSame('queued', $again['state']);

        $this->run_exports();
        $finished = $this->call($this->teacher, 'export_assignment_submissions', $args);
        $this->assertSame($first['backupid'], $finished['backupid']);
        $this->assertSame('finished', $finished['state']);
        $this->assertArrayHasKey('download', $finished['file']);

        // Different arguments and refresh=true give new exports; another user never gets this one.
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->assertNotSame($first['backupid'], $this->call(
            $this->teacher,
            'export_assignment_submissions',
            $args + ['groupid' => $group->id]
        )['backupid']);
        $fresh = $this->call($this->teacher, 'export_assignment_submissions', $args + ['refresh' => true]);
        $this->assertNotSame($first['backupid'], $fresh['backupid']);
        $this->assertSame('queued', $fresh['state']);
        $this->assertArrayNotHasKey('reused', $fresh);
        $this->assertSame($fresh['backupid'], $this->call($this->teacher, 'export_assignment_submissions', $args)['backupid']);
        $teacher2 = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->assertArrayNotHasKey('reused', $this->call($teacher2, 'export_assignment_submissions', $args));
    }

    /**
     * Course content exports are built in cron.
     */
    public function test_course_content_export_builds_in_cron(): void {
        $queued = $this->call($this->student, 'export_course_content', ['courseid' => $this->course->id]);
        $this->run_exports();
        $status = $this->call($this->student, 'backup_status', ['backupid' => $queued['backupid']]);
        $this->assertSame('finished', $status['state'], $status['error'] ?? '');
        $this->assertGreaterThan(0, $status['file']['size']);
    }

    /**
     * Permissions are checked again when the export is built; losing access fails the export.
     */
    public function test_permissions_rechecked_at_build_time(): void {
        global $DB;
        $queued = $this->call($this->teacher, 'export_assignment_submissions', ['cmid' => $this->assign->cmid]);
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        role_unassign($roleid, $this->teacher->id, context_course::instance($this->course->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->run_exports();
        $status = $this->call($this->teacher, 'backup_status', ['backupid' => $queued['backupid']]);
        $this->assertSame('failed', $status['state']);
        $this->assertNotEmpty($status['error']);
        $this->assertArrayNotHasKey('file', $status);
    }

    /**
     * Exports are visible and downloadable by their owner only.
     */
    public function test_owner_isolation(): void {
        $queued = $this->call($this->teacher, 'export_assignment_submissions', ['cmid' => $this->assign->cmid]);
        $this->run_exports();
        $status = $this->call($this->teacher, 'backup_status', ['backupid' => $queued['backupid']]);
        $other = $this->getDataGenerator()->create_user();

        try {
            download_handler::export_file($this->ticket_fid($status), context_user::instance($other->id));
            $this->fail('Another user could download the export.');
        } catch (transfer_exception $e) {
            $this->assertSame(404, $e->status);
        }
        try {
            $this->call($other, 'file_read', ['uri' => $status['file']['uri']]);
            $this->fail('Another user could read the export.');
        } catch (\moodle_exception $e) {
            $this->assertSame('filenotfound', $e->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        $this->call($other, 'backup_status', ['backupid' => $queued['backupid']]);
    }

    /**
     * Exports older than a day are purged, and a purged queued export builds nothing.
     */
    public function test_purge(): void {
        $finished = $this->call($this->teacher, 'export_assignment_submissions', ['cmid' => $this->assign->cmid]);
        $this->run_exports();
        $this->assertSame(0, export_service::purge());

        $pending = $this->call($this->teacher, 'export_assignment_submissions', ['cmid' => $this->assign->cmid,
            'refresh' => true]);
        $this->assertSame(2, export_service::purge(time() + export_service::LIFETIME + 1));
        $this->assertSame(0, $this->count_export_files());
        $this->run_exports();
        $this->assertSame(0, $this->count_export_files());

        foreach ([$finished, $pending] as $gone) {
            try {
                $this->call($this->teacher, 'backup_status', ['backupid' => $gone['backupid']]);
                $this->fail('Purged export still reported.');
            } catch (\moodle_exception $e) {
                $this->assertSame('invalidparameter', $e->errorcode);
            }
        }
    }

    /**
     * Export tools check permissions before queueing anything.
     */
    public function test_export_tools_check_permissions_up_front(): void {
        try {
            $this->call($this->student, 'export_assignment_submissions', ['cmid' => $this->assign->cmid]);
            $this->fail('Student export allowed.');
        } catch (\required_capability_exception $e) {
            $this->assertInstanceOf(\required_capability_exception::class, $e);
        }
        set_config('downloadcoursecontentallowed', 0);
        try {
            $this->call($this->teacher, 'export_course_content', ['courseid' => $this->course->id]);
            $this->fail('Disabled course content export allowed.');
        } catch (\moodle_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->assertSame(0, $this->run_exports());
    }

    /**
     * Stored file id inside a status result's download ticket.
     *
     * @param array $status backup_status result.
     * @return int
     */
    private function ticket_fid(array $status): int {
        parse_str((string)parse_url($status['file']['download']['url'], PHP_URL_QUERY), $query);
        $body = explode('.', \webservice_mcp\local\files\tickets::lookup('dl', $query['t']))[0];
        return (int)json_decode(base64_decode(strtr($body, '-_', '+/')), true)['fid'];
    }

    /**
     * Number of stored files in the export areas.
     *
     * @return int
     */
    private function count_export_files(): int {
        global $DB;
        return $DB->count_records_select('files', "component = 'webservice_mcp' AND filename <> '.'");
    }
}
