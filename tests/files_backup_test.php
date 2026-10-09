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
use context_system;
use context_user;
use core_external\external_api;
use webservice_mcp\local\files\tools;
use webservice_mcp\local\mcp\call_context;

/**
 * Tests for backup/restore/export tools and the tool catalogue.
 *
 * @package     webservice_mcp
 * @category    test
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\files\backup_service
 * @covers      \webservice_mcp\local\files\export_service
 * @covers      \webservice_mcp\local\files\tools
 */
final class files_backup_test extends advanced_testcase {
    /**
     * Reset state.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        external_api::set_context_restriction(context_system::instance());
    }

    /**
     * Call a tool as a user.
     *
     * @param \stdClass $user User.
     * @param string $name Tool.
     * @param array $args Arguments.
     * @param int|null $serviceid Service id.
     * @return array
     */
    private function call(\stdClass $user, string $name, array $args, ?int $serviceid = null): array {
        $this->setUser($user);
        $ctx = new call_context(call_context::ERA_MODERN, '2026-07-28', $user, null, $serviceid, true, 'files', [], 'f_test');
        return tools::execute($name, $args, $ctx);
    }

    /**
     * Backup is queued runs and reports its file.
     */
    public function test_backup_is_queued_runs_and_reports_its_file(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $generator->create_module('page', ['course' => $course->id]);

        $created = $this->call($teacher, 'backup_create', ['courseid' => $course->id])['structuredContent'];
        $this->assertSame('queued', $created['state']);
        $tasks = \core\task\manager::get_adhoc_tasks('\core\task\asynchronous_backup_task');
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertSame($created['backupid'], $task->get_custom_data()->backupid);
        $status = $this->call($teacher, 'backup_status', ['backupid' => $created['backupid']])['structuredContent'];
        $this->assertSame('queued', $status['state']);

        $this->expectOutputRegex('/' . $created['backupid'] . '/');
        $task->execute();

        $status = $this->call($teacher, 'backup_status', ['backupid' => $created['backupid']])['structuredContent'];
        $this->assertSame('finished', $status['state']);
        $this->assertSame($created['filename'], $status['file']['filename']);
        $this->assertStringStartsWith(
            'moodle://file/' . context_user::instance($teacher->id)->id . '/user/backup/',
            $status['file']['uri']
        );
        $this->assertStringContainsString('/webservice/mcp/pluginfile.php?ticket=', $status['file']['download']['url']);

        // Somebody else cannot see it.
        $this->expectException(\moodle_exception::class);
        $this->call($generator->create_user(), 'backup_status', ['backupid' => $created['backupid']]);
    }

    /**
     * Students cannot back up or restore.
     */
    public function test_students_cannot_back_up_or_restore(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');

        try {
            $this->call($student, 'backup_create', ['courseid' => $course->id]);
            $this->fail('Student backup allowed.');
        } catch (\required_capability_exception $e) {
            $this->assertInstanceOf(\required_capability_exception::class, $e);
        }

        $upload = $this->call($student, 'file_upload', ['filename' => 'x.mbz', 'content_text' => 'not really a backup']);
        $draft = $upload['structuredContent']['draftitemid'];
        try {
            $this->call($student, 'restore_from_draft', ['draftitemid' => $draft, 'target' => 'existing_add',
                'courseid' => $course->id]);
            $this->fail('Student restore allowed.');
        } catch (\required_capability_exception $e) {
            $this->assertInstanceOf(\required_capability_exception::class, $e);
        }
        $this->assertSame(0, $DB->count_records('backup_controllers', ['operation' => 'restore']));
    }

    /**
     * Export tools check permissions up front.
     */
    public function test_export_tools_check_permissions_up_front(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');
        $assign = $generator->create_module('assign', ['course' => $course->id]);

        $link = $this->call($teacher, 'export_assignment_submissions', ['cmid' => $assign->cmid])['structuredContent'];
        $this->assertStringContainsString('/webservice/mcp/pluginfile.php?ticket=', $link['url']);

        try {
            $this->call($student, 'export_assignment_submissions', ['cmid' => $assign->cmid]);
            $this->fail('Student export allowed.');
        } catch (\required_capability_exception $e) {
            $this->assertInstanceOf(\required_capability_exception::class, $e);
        }

        set_config('downloadcoursecontentallowed', 0);
        $this->expectException(\moodle_exception::class);
        $this->call($teacher, 'export_course_content', ['courseid' => $course->id]);
    }

    /**
     * Catalogue shape and service flags.
     */
    public function test_catalogue_shape_and_service_flags(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $ctx = new call_context(call_context::ERA_MODERN, '2026-07-28', $user, null, null, true);
        $tools = tools::describe($ctx);
        $names = array_column($tools, 'name');
        $this->assertCount(count(array_unique($names)), $names);
        foreach ($tools as $tool) {
            $this->assertMatchesRegularExpression('/^(file|backup|restore|export)_[a-z_]+$/', $tool['name']);
            $this->assertTrue(tools::handles($tool['name']));
            $this->assertSame('object', $tool['inputSchema']['type']);
            $this->assertLessThanOrEqual(1500, strlen($tool['description']));
            $this->assertSame(!tools::is_mutating($tool['name']), $tool['annotations']['readOnlyHint']);
        }
        $this->assertTrue(tools::is_mutating('file_delete'));
        $this->assertFalse(tools::is_mutating('file_read'));
        $this->assertFalse(tools::handles('core_course_get_contents'));

        $serviceid = (int)$DB->insert_record('external_services', ['name' => 'nofiles', 'shortname' => 'nofiles', 'enabled' => 1,
            'requiredcapability' => '', 'restrictedusers' => 0, 'downloadfiles' => 0, 'uploadfiles' => 0, 'timecreated' => time()]);
        $limited = array_column(tools::describe(new call_context(
            call_context::ERA_MODERN,
            '2026-07-28',
            $user,
            null,
            $serviceid,
            true
        )), 'name');
        $this->assertContains('file_list', $limited);
        $this->assertNotContains('file_upload', $limited);
        $this->assertNotContains('file_get_download_url', $limited);

        $this->setUser($user);
        $this->expectException(\moodle_exception::class);
        $this->call($user, 'file_upload', ['filename' => 'a.txt', 'content_text' => 'a'], $serviceid);
    }
}
