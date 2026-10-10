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
use core_external\external_api;
use webservice_mcp\local\wrapper\admin_settings_service;
use webservice_mcp\local\wrapper\report_service;
use webservice_mcp\local\wrapper\role_override_service;

/**
 * Tests for the targeted site tools: admin settings, role overrides and reports.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\wrapper\admin_settings_service
 * @covers      \webservice_mcp\local\wrapper\role_override_service
 * @covers      \webservice_mcp\local\wrapper\report_service
 */
final class ui_parity_site_test extends advanced_testcase {
    /**
     * Reset state and the static context restriction.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        external_api::set_context_restriction(null);
    }

    /**
     * Assert that a call fails with the invalid-input error mentioning a phrase.
     *
     * @param callable $call Call.
     * @param string $phrase Expected message part.
     */
    private function assert_invalid(callable $call, string $phrase): void {
        try {
            $call();
            $this->fail("Expected an invalid input error mentioning: {$phrase}");
        } catch (\moodle_exception $exception) {
            $this->assertSame('wrapper:invalidinput', $exception->errorcode, $exception->getMessage());
            $this->assertStringContainsString($phrase, $exception->getMessage());
        }
    }

    /**
     * Admin settings are searched, read and written through the admin tree with config log entries.
     */
    public function test_admin_settings(): void {
        global $CFG, $DB;

        $this->setAdminUser();
        $service = new admin_settings_service();

        $found = array_column($service->search_settings('navcourselimit')['settings'], null, 'name');
        $this->assertArrayHasKey('navcourselimit', $found);
        $this->assertEquals(10, $found['navcourselimit']['default']);

        $read = $service->get_settings(['navcourselimit', 'enrol_self/defaultenrol', 'nosuchsetting']);
        $this->assertSame(['navcourselimit', 'enrol_self/defaultenrol'], array_column($read['settings'], 'name'));
        $this->assertSame(['nosuchsetting'], $read['unknown']);

        $result = $service->set_settings(['navcourselimit' => 25, 'enrol_self/defaultenrol' => false]);
        $this->assertSame(['navcourselimit', 'enrol_self/defaultenrol'], $result['saved']);
        $this->assertEquals(25, $CFG->navcourselimit);
        $this->assertEquals(0, get_config('enrol_self', 'defaultenrol'));
        $this->assertTrue($DB->record_exists_select(
            'config_log',
            'name = :name AND ' . $DB->sql_compare_text('value') . ' = :value',
            ['name' => 'navcourselimit', 'value' => '25']
        ));

        $this->assert_invalid(fn() => $service->set_settings(['navcourselimit' => 'many']), 'navcourselimit');
        $this->assert_invalid(fn() => $service->set_settings(['nosuchsetting' => 1]), 'Unknown setting');
        $this->assert_invalid(fn() => $service->search_settings('a'), 'at least 2 characters');

        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        $service->search_settings('navcourselimit');
    }

    /**
     * Role overrides follow admin/roles/permissions.php: review to read, override or safeoverride to change.
     */
    public function test_role_overrides(): void {
        global $DB;

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);
        $studentrole = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $service = new role_override_service();

        $set = $service->set_override($context->id, $studentrole, 'moodle/course:viewhiddenactivities', 'allow');
        $this->assertSame('allow', $set['override']);
        $read = $service->get_overrides($context->id, $studentrole, 'viewhiddenactivities', true);
        $this->assertSame('allow', $read['capabilities'][0]['override']);
        $this->assertTrue(has_capability(
            'moodle/course:viewhiddenactivities',
            $context,
            $this->getDataGenerator()->create_and_enrol($course, 'student')
        ));

        $this->assert_invalid(
            fn() => $service->set_override($context->id, $studentrole, 'moodle/course:view', 'maybe'),
            'Unknown permission "maybe"'
        );
        $this->assert_invalid(
            fn() => $service->set_override($context->id, $studentrole, 'moodle/site:nosuch', 'allow'),
            'Unknown capability'
        );
        try {
            $service->get_overrides(context_system::instance()->id, $studentrole);
            $this->fail('System context overrides must be refused.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('cannotoverridebaserole', $exception->errorcode);
        }

        // Editing teachers have only moodle/role:safeoverride: risky capabilities are refused.
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $this->assert_invalid(
            fn() => $service->set_override($context->id, $studentrole, 'moodle/course:managefiles', 'allow'),
            'needs moodle/role:override'
        );
    }

    /**
     * The logs and participation reports return what the report pages show, with their capabilities.
     */
    public function test_reports(): void {
        global $DB;

        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $studentrole = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        $this->setUser($student);
        \mod_forum\event\course_module_viewed::create([
            'objectid' => $forum->id,
            'context' => \context_module::instance($forum->cmid),
        ])->trigger();
        // PHPUnit runs as CLI; the participation report only counts web and web service actions.
        $DB->set_field('logstore_standard_log', 'origin', 'web', ['userid' => $student->id]);

        $this->setAdminUser();
        $service = new report_service();
        $logs = $service->logs($course->id, ['userid' => $student->id]);
        $this->assertGreaterThanOrEqual(1, $logs['total']);
        $this->assertSame('\\mod_forum\\event\\course_module_viewed', $DB->get_field(
            'logstore_standard_log',
            'eventname',
            ['userid' => $student->id, 'courseid' => $course->id],
            IGNORE_MULTIPLE
        ));
        $this->assertSame((int)$student->id, $logs['events'][0]['userid']);

        $participation = $service->participation($course->id, (int)$forum->cmid, $studentrole, 'view');
        $row = array_column($participation['users'], null, 'userid')[(int)$student->id];
        $this->assertSame(1, $row['actions']);
        $this->assert_invalid(
            fn() => $service->participation($course->id, (int)$forum->cmid, $studentrole, 'edit'),
            'Unknown action "edit"'
        );

        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        $service->logs($course->id, []);
    }
}
