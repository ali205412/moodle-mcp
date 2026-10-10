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
use webservice_mcp\local\wrapper\activity_service;

/**
 * Tests for the activity wrapper service.
 *
 * @package     webservice_mcp
 * @copyright   2025 MohammadReza PourMohammad
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\wrapper\activity_service
 */
final class activity_service_test extends advanced_testcase {
    /**
     * Reset state and the static context restriction.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        external_api::set_context_restriction(null);
    }

    /**
     * A module is created with dedicated section, visibility and intro arguments.
     */
    public function test_add_module_provisions_module(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);

        $module = (new activity_service())->add_module(
            $course->id,
            'url',
            'Test URL',
            ['externalurl' => 'https://moodle.org', 'display' => 0],
            1,
            false,
            'Test intro'
        );

        $cm = get_coursemodule_from_id('url', $module['coursemodule'], 0, false, MUST_EXIST);
        $this->assertEquals('Test URL', $cm->name);
        $this->assertSame(1, $module['section']);
        $this->assertFalse($module['visible']);
        $this->assertSame('url', $module['modulename']);
    }

    /**
     * Reserved keys cannot be smuggled through options.
     */
    public function test_add_module_rejects_reserved_options(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();

        $this->expectExceptionObject(new \moodle_exception('wrapper:reservedmoduleoption', 'webservice_mcp', '', 'course'));
        (new activity_service())->add_module($course->id, 'url', 'Escape', ['course' => $other->id,
            'externalurl' => 'https://moodle.org']);
    }

    /**
     * The restricted context applies.
     */
    public function test_add_module_respects_restricted_context(): void {
        $this->setAdminUser();
        $allowed = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        external_api::set_context_restriction(context_course::instance($allowed->id));

        $this->expectException(\core_external\restricted_context_exception::class);
        (new activity_service())->add_module($other->id, 'url', 'Escape', ['externalurl' => 'https://moodle.org']);
    }

    /**
     * Duplicate idnumbers are rejected.
     */
    public function test_add_module_rejects_duplicate_idnumber(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'idnumber' => 'DUP1']);

        $this->expectExceptionObject(new \moodle_exception('idnumbertaken'));
        (new activity_service())->add_module($course->id, 'url', 'Dup', ['externalurl' => 'https://moodle.org',
            'cmidnumber' => 'DUP1']);
    }

    /**
     * Forcing a language requires moodle/course:setforcedlanguage.
     */
    public function test_add_module_lang_requires_capability(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $context = context_course::instance($course->id);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/course:manageactivities', CAP_ALLOW, $roleid, $context);
        assign_capability('mod/url:addinstance', CAP_ALLOW, $roleid, $context);
        role_assign($roleid, $user->id, $context);
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        (new activity_service())->add_module($course->id, 'url', 'Lang', ['externalurl' => 'https://moodle.org',
            'lang' => 'en']);
    }

    /**
     * Managers see settings without secrets; students see only cm info and intro.
     */
    public function test_read_module_data(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $course->id, 'password' => 'topsecret',
            'intro' => '<p>Quiz intro</p>']);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $service = new activity_service();

        $this->setAdminUser();
        $data = $service->read_module_data((int)$quiz->cmid);
        $this->assertTrue($data['canmanage']);
        $this->assertSame((int)$quiz->cmid, $data['cm']['id']);
        $this->assertSame('quiz', $data['cm']['modname']);
        $this->assertArrayHasKey('timeopen', $data['instance']);
        $this->assertArrayNotHasKey('password', $data['instance']);
        $this->assertStringContainsString('Quiz intro', $data['intro']);

        $this->setUser($student);
        $data = $service->read_module_data((int)$quiz->cmid);
        $this->assertFalse($data['canmanage']);
        $this->assertNull($data['instance']);
        $this->assertSame([], $data['fileareas']);
        $this->assertTrue($data['cm']['uservisible']);
    }

    /**
     * Hidden modules cannot be inspected by students.
     */
    public function test_read_module_data_rejects_hidden_module(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id, 'visible' => 0]);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $this->expectException(\moodle_exception::class);
        (new activity_service())->read_module_data((int)$page->cmid);
    }

    /**
     * Core modules are created with their form defaults and pass their own mod_form validation.
     */
    public function test_add_module_core_modules_use_form_defaults(): void {
        global $CFG, $USER, $SITE;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'handout.txt',
        ], 'Handout');
        $typeid = lti_add_type((object)[
            'name' => 'Example tool',
            'baseurl' => 'https://tool.example.com/launch',
            'coursevisible' => LTI_COURSEVISIBLE_ACTIVITYCHOOSER,
            'state' => LTI_TOOL_STATE_CONFIGURED,
            'course' => $SITE->id,
        ], (object)[]);

        $service = new activity_service();
        $created = [
            'page' => ['page' => ['text' => '<p>Page body</p>', 'format' => FORMAT_HTML]],
            'url' => ['externalurl' => 'https://moodle.org'],
            'label' => [],
            'resource' => ['files' => $draftitemid],
            'assign' => [],
            'forum' => [],
            'quiz' => [],
            'lti' => ['typeid' => $typeid],
        ];
        foreach ($created as $modulename => $options) {
            $module = $service->add_module($course->id, $modulename, "Test {$modulename}", $options, 0, true, 'Intro');
            $this->assertSame($modulename, $module['modulename']);
        }

        $modinfo = get_fast_modinfo($course->id);
        $this->assertCount(count($created), $modinfo->get_cms());
    }

    /**
     * The module's mod_form validation rejects invalid settings.
     */
    public function test_add_module_runs_form_validation(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $service = new activity_service();

        foreach (
            [
            ['resource', []],
            ['assign', ['allowsubmissionsfromdate' => time() + 7200, 'duedate' => time() + 3600]],
            ] as [$modulename, $options]
        ) {
            try {
                $service->add_module($course->id, $modulename, 'Invalid', $options);
                $this->fail("{$modulename} with invalid settings must be rejected.");
            } catch (\moodle_exception $exception) {
                $this->assertSame('wrapper:invalidinput', $exception->errorcode);
                $this->assertStringContainsString('Invalid module settings', $exception->getMessage());
            }
        }
    }

    /**
     * LTI activities must use a tool type available in the course.
     */
    public function test_add_module_rejects_unavailable_lti_type(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $this->expectExceptionMessageMatches('/typeid must be an LTI tool type/');
        (new activity_service())->add_module($course->id, 'lti', 'Bogus tool', ['typeid' => 999999]);
    }
}
