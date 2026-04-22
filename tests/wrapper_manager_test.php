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
use webservice_mcp\local\wrapper\definition;
use webservice_mcp\local\wrapper\manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for wrapper foundation helpers.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\wrapper\definition
 * @covers      \webservice_mcp\local\wrapper\manager
 */
final class wrapper_manager_test extends advanced_testcase {
    /**
     * Test wrapper definitions can be filtered by capability in discovery.
     */
    public function test_wrapper_manager_filters_discoverable_definitions(): void {
        $this->resetAfterTest(true);

        $manageruser = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/site:config', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $manageruser->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();

        $definition = new definition(
            'wrapper_lti_launch_reconcile',
            'mod_lti',
            'activity',
            'Example wrapper foundation definition.',
            ['moodle/site:config']
        );

        $manager = new manager([$definition], false);

        $discoverable = $manager->describe_discoverable(context_system::instance(), $manageruser);
        $hidden = $manager->describe_discoverable(
            context_system::instance(),
            $this->getDataGenerator()->create_user()
        );

        $this->assertCount(1, $discoverable);
        $this->assertSame('wrapper_lti_launch_reconcile', $discoverable[0]['name']);
        $this->assertSame([], $hidden);
    }

    /**
     * Test built-in wrapper definitions are available by default.
     */
    public function test_wrapper_manager_includes_built_in_definitions(): void {
        $this->resetAfterTest(true);

        $manager = new manager();
        $names = array_map(static fn(definition $definition): string => $definition->get_name(), $manager->all());

        $this->assertContains('wrapper_course_add_section_after', $names);
        $this->assertContains('wrapper_course_set_section_visibility', $names);
        $this->assertContains('wrapper_course_delete_sections', $names);
        $this->assertContains('wrapper_course_create_missing_sections', $names);
        $this->assertContains('wrapper_course_move_module', $names);
        $this->assertContains('wrapper_course_move_section_after', $names);
        $this->assertContains('wrapper_course_set_module_visibility', $names);
        $this->assertContains('wrapper_course_duplicate_modules', $names);
        $this->assertContains('wrapper_course_delete_modules', $names);
        $this->assertContains('wrapper_question_create_category', $names);
        $this->assertContains('wrapper_question_create_question', $names);
        $this->assertContains('wrapper_question_import_questions', $names);
        $this->assertContains('wrapper_gradebook_create_manual_item', $names);
        $this->assertContains('wrapper_gradebook_update_category', $names);
        $this->assertContains('wrapper_badge_create_badge', $names);
        $this->assertContains('wrapper_badge_award_badge', $names);
    }

    /**
     * Test built-in section-add wrapper can execute safely.
     */
    public function test_wrapper_manager_executes_section_add_wrapper(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $coursecontext = context_course::instance($course->id);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/course:update', CAP_ALLOW, $roleid, $coursecontext);
        role_assign($roleid, $user->id, $coursecontext);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);

        $beforecount = $DB->count_records('course_sections', ['course' => $course->id]);

        $manager = new manager();
        $result = $manager->execute(
            'wrapper_course_add_section_after',
            [
                'courseid' => $course->id,
            ],
            $coursecontext,
            $user
        );

        $aftercount = $DB->count_records('course_sections', ['course' => $course->id]);

        $this->assertTrue($result['status']);
        $this->assertNotEmpty($result['statejson']);
        $this->assertSame($beforecount + 1, $aftercount);
    }

    /**
     * Test built-in course section wrapper can execute safely.
     */
    public function test_wrapper_manager_executes_course_section_wrapper(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = context_course::instance($course->id);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/course:update', CAP_ALLOW, $roleid, $coursecontext);
        assign_capability('moodle/course:manageactivities', CAP_ALLOW, $roleid, $coursecontext);
        role_assign($roleid, $user->id, $coursecontext);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);

        $manager = new manager();
        $result = $manager->execute(
            'wrapper_course_create_missing_sections',
            [
                'courseid' => $course->id,
                'sectionnums' => [1, 2],
            ],
            $coursecontext,
            $user
        );

        $this->assertTrue($result['status']);
        $this->assertCount(2, $result['sections']);
    }

    /**
     * Test built-in question category wrapper can execute safely.
     */
    public function test_wrapper_manager_executes_question_category_wrapper(): void {
        global $DB;

        $this->resetAfterTest(true);
        external_api::set_context_restriction(null);

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/question:managecategory', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);

        $manager = new manager();
        $result = $manager->execute(
            'wrapper_question_create_category',
            [
                'contextid' => context_system::instance()->id,
                'name' => 'Wrapper-created category',
            ],
            context_system::instance(),
            $user
        );

        $this->assertSame('Wrapper-created category', $result['name']);
        $this->assertTrue($DB->record_exists('question_categories', ['id' => $result['categoryid']]));
    }

    /**
     * Test built-in badge wrapper can execute safely.
     */
    public function test_wrapper_manager_executes_badge_wrapper(): void {
        $this->resetAfterTest(true);
        external_api::set_context_restriction(null);

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/badges:createbadge', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);

        $manager = new manager();
        $result = $manager->execute(
            'wrapper_badge_create_badge',
            [
                'payload' => [
                    'name' => 'Wrapper Badge',
                    'description' => 'Created through the wrapper manager test.',
                ],
            ],
            context_system::instance(),
            $user
        );

        $this->assertSame('Wrapper Badge', $result['name']);
        $this->assertGreaterThan(0, $result['badgeid']);
    }
}
