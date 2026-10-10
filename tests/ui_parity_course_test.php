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
use core_external\external_api;
use webservice_mcp\local\wrapper\course_reset_service;
use webservice_mcp\local\wrapper\enrol_instance_service;
use webservice_mcp\local\wrapper\manager;
use webservice_mcp\local\wrapper\module_settings_service;

/**
 * Tests for the targeted course tools: activity and section settings, enrolment methods and course reset.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\wrapper\module_settings_service
 * @covers      \webservice_mcp\local\wrapper\module_form
 * @covers      \webservice_mcp\local\wrapper\form_submission
 * @covers      \webservice_mcp\local\wrapper\enrol_instance_service
 * @covers      \webservice_mcp\local\wrapper\course_reset_service
 * @covers      \webservice_mcp\local\wrapper\ui_parity_tools
 */
final class ui_parity_course_test extends advanced_testcase {
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
     * Activity settings round-trip through the activity's own edit form.
     */
    public function test_module_settings_get_and_update(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => 'Essay']);
        $service = new module_settings_service();

        $current = $service->get_module_settings((int)$assign->cmid);
        $this->assertSame('assign', $current['modulename']);
        $this->assertSame('Essay', $current['settings']['name']);
        $this->assertArrayHasKey('duedate', $current['settings']);
        $this->assertArrayNotHasKey('sesskey', $current['settings']);

        // Date fields have minute precision, as on the edit page.
        $due = intdiv(time() + WEEKSECS, 60) * 60;
        $updated = $service->update_module((int)$assign->cmid, ['name' => 'Essay v2', 'duedate' => $due]);
        $this->assertSame('Essay v2', $updated['name']);
        $this->assertEquals($due, $this->get_assign_duedate((int)$assign->id));

        // The assignment form's own validation applies.
        $this->assert_invalid(fn() => $service->update_module((int)$assign->cmid, [
            'allowsubmissionsfromdate' => $due + DAYSECS,
            'duedate' => $due,
        ]), 'Invalid module settings');
        $this->assert_invalid(
            fn() => $service->update_module((int)$assign->cmid, ['nosuchsetting' => 1]),
            'Unknown settings for this assign: nosuchsetting'
        );
        $this->assert_invalid(fn() => $service->update_module((int)$assign->cmid, ['course' => 1]), 'identifies the module');
    }

    /**
     * Reading or changing activity settings needs moodle/course:manageactivities.
     */
    public function test_module_settings_require_manageactivities(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        (new module_settings_service())->get_module_settings((int)$page->cmid);
    }

    /**
     * Section name, summary, visibility and availability change like the section edit page.
     */
    public function test_update_section(): void {
        global $DB;

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1], '*', MUST_EXIST);
        $service = new module_settings_service();

        $result = $service->update_section((int)$section->id, ['name' => 'Week one', 'summary' => '<p>Intro</p>',
            'visible' => false]);
        $this->assertSame('Week one', $result['name']);
        $this->assertFalse($result['visible']);
        $this->assertSame('<p>Intro</p>', $DB->get_field('course_sections', 'summary', ['id' => $section->id]));

        set_config('enableavailability', 1);
        $this->assert_invalid(fn() => $service->update_section((int)$section->id, ['availability' => '{"op":"&","c":'
            . '[{"type":"date","d":">=","t":"notanumber"}],"showc":[true]}']), 'Invalid availability restriction');
        $this->assert_invalid(
            fn() => $service->update_section((int)$section->id, ['colour' => 'red']),
            'Unknown section setting "colour"'
        );

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $this->setUser($teacher);
        $this->expectException(\required_capability_exception::class);
        $service->update_section((int)$section->id, ['name' => 'Not allowed']);
    }

    /**
     * Enrolment methods are added, changed, disabled and deleted with the plugin's own rules.
     */
    public function test_enrolment_instance_lifecycle(): void {
        global $DB;

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $service = new enrol_instance_service();

        $added = $service->add_instance($course->id, 'self', ['name' => 'Join here', 'password' => 'Secret-123']);
        $this->assertSame('self', $added['plugin']);
        $this->assertSame('Join here', $added['name']);
        $this->assertSame('Secret-123', $DB->get_field('enrol', 'password', ['id' => $added['instanceid']]));

        $this->assertSame('Renamed', $service->update_instance($added['instanceid'], ['name' => 'Renamed'])['name']);
        $this->assertFalse($service->set_status($added['instanceid'], false)['enabled']);
        $this->assertTrue($service->delete_instance($added['instanceid'])['deleted']);
        $this->assertFalse($DB->record_exists('enrol', ['id' => $added['instanceid']]));

        set_config('requirepassword', 1, 'enrol_self');
        $this->assert_invalid(fn() => $service->add_instance($course->id, 'self', ['name' => 'No key']), 'password');
        $this->assert_invalid(fn() => $service->add_instance($course->id, 'self', ['colour' => 'red']), 'Unknown settings');
        $this->assert_invalid(fn() => $service->add_instance($course->id, 'paypal', []), 'disabled');

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        $service->add_instance($course->id, 'guest', []);
    }

    /**
     * Course reset runs reset_course_userdata() with validated options and needs moodle/course:reset.
     */
    public function test_course_reset(): void {
        global $DB;

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $studentrole = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $service = new course_reset_service();

        $this->assert_invalid(
            fn() => $service->reset_course($course->id, ['reset_everything' => 1]),
            'Unknown reset options: reset_everything'
        );

        $result = $service->reset_course($course->id, ['unenrol_users' => [$studentrole], 'reset_events' => true]);
        $this->assertNotEmpty($result['results']);
        $this->assertFalse(is_enrolled(context_course::instance($course->id), $student));

        $this->setUser($student);
        $this->expectException(\moodle_exception::class);
        (new manager())->execute(
            'wrapper_course_reset',
            ['courseid' => $course->id, 'usedefaults' => true],
            context_course::instance($course->id),
            $student
        );
    }

    /**
     * Read an assignment's due date.
     *
     * @param int $assignid Assignment id.
     * @return int
     */
    private function get_assign_duedate(int $assignid): int {
        global $DB;
        return (int)$DB->get_field('assign', 'duedate', ['id' => $assignid], MUST_EXIST);
    }
}
