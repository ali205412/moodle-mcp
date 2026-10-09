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
use core_external\external_api;
use webservice_mcp\local\wrapper\badge_service;
use webservice_mcp\local\wrapper\gradebook_service;
use webservice_mcp\local\wrapper\question_bank_service;

/**
 * Integration-style tests for the phase 9 parity wrappers.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\wrapper\question_bank_service
 * @covers      \webservice_mcp\local\wrapper\gradebook_service
 * @covers      \webservice_mcp\local\wrapper\badge_service
 * @covers      \webservice_mcp\local\wrapper\badge_record_builder
 * @covers      \webservice_mcp\local\wrapper\question_category_service
 * @covers      \webservice_mcp\local\wrapper\question_service
 * @covers      \webservice_mcp\local\wrapper\question_form_builder
 * @covers      \webservice_mcp\local\wrapper\question_import_service
 * @covers      \webservice_mcp\local\wrapper\manager
 */
final class parity_wrapper_services_test extends advanced_testcase {
    /**
     * Test question-bank wrappers cover category, authoring, preview, move, import, and delete flows.
     */
    public function test_question_bank_service_can_manage_supported_parity_flows(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        external_api::set_context_restriction(null);

        $service = new question_bank_service();
        $systemcontext = context_system::instance();

        $categoryone = $service->create_category($systemcontext->id, 'Wrapper Category One');
        $categorytwo = $service->create_category($systemcontext->id, 'Wrapper Category Two');

        $createdquestion = $service->create_question(
            $categoryone['categoryid'],
            [
                'qtype' => 'shortanswer',
                'name' => 'Wrapper Question',
                'questiontext' => 'Name an amphibian.',
                'answers' => [
                    ['answer' => 'frog', 'fraction' => 1.0, 'feedback' => 'Correct'],
                    ['answer' => 'toad', 'fraction' => 0.5, 'feedback' => 'Partly correct'],
                ],
            ]
        );

        $this->assertSame('shortanswer', $createdquestion['qtype']);
        $this->assertSame(1, $createdquestion['version']);

        $updatedquestion = $service->update_question(
            $createdquestion['questionid'],
            [
                'name' => 'Wrapper Question v2',
                'generalfeedback' => 'Updated by wrapper test.',
            ]
        );

        $this->assertSame($createdquestion['questionid'], $updatedquestion['previousquestionid']);
        $this->assertSame(2, $updatedquestion['version']);

        $preview = $service->preview_question($updatedquestion['questionid']);
        $this->assertStringContainsString('/question/bank/previewquestion/preview.php', $preview['previewurl']);

        $moved = $service->move_questions([$updatedquestion['questionid']], $categorytwo['categoryid']);
        $this->assertTrue($moved['moved']);
        $this->assertSame($categorytwo['categoryid'], $moved['targetcategoryid']);

        $imported = $service->import_questions(
            $categorytwo['categoryid'],
            'gift',
            "::Wrapper imported::The capital of France is {=Paris ~Lyon ~Marseille}\n"
        );
        $this->assertTrue($imported['status']);
        $this->assertNotEmpty($imported['questionids']);

        $deleted = $service->delete_category($categoryone['categoryid']);
        $this->assertTrue($deleted['deleted']);
    }

    /**
     * Test gradebook wrappers cover manual items and category setup flows.
     */
    public function test_gradebook_service_can_manage_manual_items_and_categories(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        external_api::set_context_restriction(null);

        $course = $this->getDataGenerator()->create_course();
        $service = new gradebook_service();

        $itemone = $service->create_manual_item(
            $course->id,
            [
                'itemname' => 'Manual Item One',
                'gradetype' => 'value',
                'grademax' => 100,
                'grademin' => 0,
            ]
        );
        $itemtwo = $service->create_manual_item(
            $course->id,
            [
                'itemname' => 'Manual Item Two',
                'gradetype' => 'value',
                'grademax' => 50,
                'grademin' => 0,
            ]
        );

        $gradecategory = $this->getDataGenerator()->create_grade_category([
            'courseid' => $course->id,
            'fullname' => 'Wrapper Grade Category',
        ]);

        $moveditem = $service->move_item($course->id, $itemone['itemid'], $gradecategory->id);
        $this->assertSame((int)$gradecategory->id, (int)$moveditem['parentcategoryid']);

        $updatedcategory = $service->update_category(
            $course->id,
            $gradecategory->id,
            ['name' => 'Updated Wrapper Grade Category']
        );
        $this->assertSame('Updated Wrapper Grade Category', $updatedcategory['name']);

        $moveditemtwo = $service->move_item($course->id, $itemtwo['itemid'], null, $itemone['itemid']);
        $this->assertGreaterThan(0, $moveditemtwo['sortorder']);

        $deleteditems = $service->delete_items($course->id, [$itemtwo['itemid']]);
        $this->assertTrue($deleteditems['deleted']);

        $deletecategory = $this->getDataGenerator()->create_grade_category([
            'courseid' => $course->id,
            'fullname' => 'Delete This Category',
        ]);
        $deletedcategories = $service->delete_categories($course->id, [$deletecategory->id]);
        $this->assertTrue($deletedcategories['deleted']);
    }

    /**
     * Test badge wrappers cover lifecycle, relation, alignment, award, and revoke flows.
     */
    public function test_badge_service_can_manage_relations_and_manual_awards(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        external_api::set_context_restriction(null);

        $service = new badge_service();

        $created = $service->create_badge([
            'name' => 'Wrapper Badge',
            'description' => 'Badge created through the parity wrapper test.',
        ]);
        $this->assertSame(0, $created['status']);

        $updated = $service->update_badge($created['badgeid'], [
            'description' => 'Updated badge description.',
            'tags' => ['wrapper', 'phase9'],
        ]);
        $this->assertSame($created['badgeid'], $updated['badgeid']);

        $messageupdated = $service->update_badge_message($created['badgeid'], [
            'messagesubject' => 'Wrapper subject',
            'message' => 'Wrapper body',
            'notification' => 0,
            'attachment' => 1,
        ]);
        $this->assertSame($created['badgeid'], $messageupdated['badgeid']);

        $duplicate = $service->duplicate_badge($created['badgeid']);
        $this->assertNotSame($created['badgeid'], $duplicate['badgeid']);

        $related = $service->add_related_badges($created['badgeid'], [$duplicate['badgeid']]);
        $this->assertTrue($related['status']);

        $alignment = $service->save_alignment($created['badgeid'], [
            'targetname' => 'MCP Alignment',
            'targeturl' => 'https://example.com/alignment',
            'targetdescription' => 'Alignment created by the wrapper test.',
            'targetframework' => 'Example Framework',
            'targetcode' => 'MCP-1',
        ]);
        $this->assertGreaterThan(0, $alignment['alignmentid']);

        $removedrelated = $service->delete_related_badges($created['badgeid'], [$duplicate['badgeid']]);
        $this->assertTrue($removedrelated['status']);

        $removedalignment = $service->delete_alignments($created['badgeid'], [$alignment['alignmentid']]);
        $this->assertTrue($removedalignment['status']);

        /** @var \core_badges_generator $badgegenerator */
        $badgegenerator = $this->getDataGenerator()->get_plugin_generator('core_badges');
        $awardablebadge = $badgegenerator->create_badge([
            'name' => 'Awardable Wrapper Badge',
            'image' => 'badges/tests/behat/badge.png',
        ]);
        $managerroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        $badgegenerator->create_criteria([
            'badgeid' => $awardablebadge->id,
            'roleid' => $managerroleid,
        ]);

        $recipient = $this->getDataGenerator()->create_user();
        $awarded = $service->award_badge($awardablebadge->id, $recipient->id);
        $this->assertTrue($awarded['awarded']);

        $revoked = $service->revoke_badge($awardablebadge->id, $recipient->id);
        $this->assertTrue($revoked['revoked']);

        $deleted = $service->delete_badges([$created['badgeid'], $duplicate['badgeid'], $awardablebadge->id]);
        $this->assertTrue($deleted['deleted']);
    }

    /**
     * Test question wrappers validate every touched context and report hidden (in-use) questions.
     */
    public function test_question_bank_service_enforces_contexts_and_reports_hidden_questions(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $this->resetAfterTest(true);
        $this->setAdminUser();
        external_api::set_context_restriction(null);

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $systemcategory = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('shortanswer', null, ['category' => $systemcategory->id]);

        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);
        $coursecategory = $questiongenerator->create_question_category(['contextid' => $coursecontext->id]);
        $service = new question_bank_service();

        external_api::set_context_restriction($coursecontext);
        $cases = [
            fn() => $service->delete_questions([$question->id]),
            fn() => $service->move_questions([$question->id], (int)$coursecategory->id),
        ];
        foreach ($cases as $call) {
            try {
                $call();
                $this->fail('A system-context question must not be reachable from a course-restricted token.');
            } catch (\core_external\restricted_context_exception $exception) {
                $this->assertInstanceOf(\core_external\restricted_context_exception::class, $exception);
            }
        }

        external_api::set_context_restriction(null);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        \quiz_add_quiz_question($question->id, $quiz);
        $unused = $questiongenerator->create_question('shortanswer', null, ['category' => $systemcategory->id]);

        $result = $service->delete_questions([$question->id, $unused->id]);
        $this->assertFalse($result['deleted']);
        $this->assertSame([(int)$question->id], $result['hiddenquestionids']);
        $this->assertSame([(int)$unused->id], $result['deletedquestionids']);
    }

    /**
     * Test badge wrappers refuse to edit active badges and validate award recipients.
     */
    public function test_badge_service_guards_active_badges_and_recipients(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        external_api::set_context_restriction(null);

        /** @var \core_badges_generator $badgegenerator */
        $badgegenerator = $this->getDataGenerator()->get_plugin_generator('core_badges');
        $service = new badge_service();
        $managerroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);

        $active = $badgegenerator->create_badge(['name' => 'Active badge']);
        try {
            $service->update_badge($active->id, ['name' => 'Renamed']);
            $this->fail('Active badges must not be edited.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('wrapper:badgelocked', $exception->errorcode);
        }

        $badgegenerator->create_criteria(['badgeid' => $active->id, 'roleid' => $managerroleid]);
        $deleted = $this->getDataGenerator()->create_user();
        delete_user($deleted);
        $course = $this->getDataGenerator()->create_course();
        $coursebadge = $badgegenerator->create_badge([
            'name' => 'Course badge',
            'type' => BADGE_TYPE_COURSE,
            'courseid' => $course->id,
        ]);
        $badgegenerator->create_criteria(['badgeid' => $coursebadge->id, 'roleid' => $managerroleid]);
        $notenrolled = $this->getDataGenerator()->create_user();

        $cases = [[$active->id, $deleted->id], [$active->id, (int)guest_user()->id], [$coursebadge->id, $notenrolled->id]];
        foreach ($cases as [$badgeid, $recipientid]) {
            try {
                $service->award_badge($badgeid, $recipientid);
                $this->fail("User {$recipientid} must not receive badge {$badgeid}.");
            } catch (\moodle_exception $exception) {
                $this->assertSame('wrapper:badgerecipientinvalid', $exception->errorcode);
            }
        }
    }

    /**
     * Test gradebook wrappers keep items inside the course and parse string booleans.
     */
    public function test_gradebook_service_rejects_foreign_parent_and_parses_booleans(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        external_api::set_context_restriction(null);

        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $foreigncategory = $this->getDataGenerator()->create_grade_category(['courseid' => $othercourse->id]);
        $service = new gradebook_service();

        $item = $service->create_manual_item($course->id, ['itemname' => 'Item', 'hidden' => 'false', 'locked' => 'false']);
        $gradeitem = \grade_item::fetch(['id' => $item['itemid']]);
        $this->assertEquals(0, $gradeitem->hidden);
        $this->assertEquals(0, $gradeitem->locked);

        $this->expectException(\moodle_exception::class);
        $service->move_item($course->id, $item['itemid'], (int)$foreigncategory->id);
    }

    /**
     * Test badge and alignment fields are cleaned like the core forms: no stored script, no javascript: URLs.
     */
    public function test_badge_fields_are_cleaned_like_core_forms(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        external_api::set_context_restriction(null);
        $service = new badge_service();

        $created = $service->create_badge([
            'name' => '<b>Bold</b> badge',
            'description' => '<script>alert(1)</script>Real description',
            'imagecaption' => '<img src=x onerror=alert(1)>Caption',
            'tags' => ['<i>tag</i>'],
        ]);
        $record = $DB->get_record('badge', ['id' => $created['badgeid']], '*', MUST_EXIST);
        $this->assertStringNotContainsString('<script', $record->description);
        $this->assertStringContainsString('Real description', $record->description);
        $this->assertStringNotContainsString('<', $record->name);
        $this->assertStringNotContainsString('onerror', $record->imagecaption);

        $cases = [
            ['imageauthorurl' => 'javascript:alert(1)'],
            ['issuercontact' => 'not-an-email'],
            ['imageauthoremail' => 'also-not-an-email'],
        ];
        foreach ($cases as $payload) {
            try {
                $service->update_badge($created['badgeid'], $payload);
                $this->fail('Invalid badge field accepted: ' . json_encode($payload));
            } catch (\moodle_exception $exception) {
                $this->assertSame('wrapper:invalidinput', $exception->errorcode);
            }
        }

        try {
            $service->save_alignment($created['badgeid'], ['targetname' => 'X', 'targeturl' => 'javascript:alert(1)']);
            $this->fail('javascript: alignment URL accepted.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('wrapper:invalidinput', $exception->errorcode);
        }
        $alignment = $service->save_alignment($created['badgeid'], [
            'targetname' => '<script>x</script>Target',
            'targeturl' => 'https://example.com/standard',
            'targetdescription' => '<script>alert(1)</script>About',
        ]);
        $stored = $DB->get_record('badge_alignment', ['id' => $alignment['alignmentid']], '*', MUST_EXIST);
        $this->assertStringNotContainsString('<script', $stored->targetname . $stored->targetdescription);

        // Updating without tags keeps the existing tags (exercises the Moodle 4.2 get_badge_tags() fallback).
        $service->update_badge($created['badgeid'], ['description' => 'Updated']);
        $this->assertNotEmpty(\core_tag_tag::get_item_tags_array('core_badges', 'badge', $created['badgeid']));
    }

    /**
     * Test the site badge switches apply to every badge operation, and relation/alignment ids are scoped.
     */
    public function test_badge_switches_and_relation_scoping(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        external_api::set_context_restriction(null);
        $service = new badge_service();

        $courseone = $this->getDataGenerator()->create_course();
        $coursetwo = $this->getDataGenerator()->create_course();
        $badgeone = $service->create_badge(['name' => 'One'], (int)$courseone->id);
        $badgetwo = $service->create_badge(['name' => 'Two'], (int)$coursetwo->id);
        $sitebadge = $service->create_badge(['name' => 'Site']);

        try {
            $service->add_related_badges($badgeone['badgeid'], [$badgetwo['badgeid']]);
            $this->fail('A badge from another course was related.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('wrapper:invalidinput', $exception->errorcode);
        }
        $this->assertTrue($service->add_related_badges($badgeone['badgeid'], [$sitebadge['badgeid']])['status']);

        $foreign = $service->save_alignment($badgetwo['badgeid'], ['targetname' => 'T', 'targeturl' => 'https://example.com']);
        try {
            $service->save_alignment(
                $badgeone['badgeid'],
                ['targetname' => 'T', 'targeturl' => 'https://example.com'],
                $foreign['alignmentid']
            );
            $this->fail('Another badge\'s alignment was overwritten.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('wrapper:invalidinput', $exception->errorcode);
        }

        set_config('badges_allowcoursebadges', 0);
        try {
            $service->update_badge($badgeone['badgeid'], ['description' => 'x']);
            $this->fail('Course badges were editable while disabled.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('coursebadgesdisabled', $exception->errorcode);
        }

        set_config('enablebadges', 0);
        $cases = [
            fn() => $service->update_badge($sitebadge['badgeid'], ['description' => 'x']),
            fn() => $service->delete_badges([$sitebadge['badgeid']]),
            fn() => $service->award_badge($sitebadge['badgeid'], (int)get_admin()->id),
        ];
        foreach ($cases as $call) {
            try {
                $call();
                $this->fail('Badge operation ran while badges are disabled.');
            } catch (\moodle_exception $exception) {
                $this->assertSame('badgesdisabled', $exception->errorcode);
            }
        }
    }

    /**
     * Test grade item scales must be site or course scales.
     */
    public function test_gradebook_rejects_foreign_scale(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        external_api::set_context_restriction(null);

        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $foreignscale = $this->getDataGenerator()->create_scale(['courseid' => $othercourse->id]);
        $sitescale = $this->getDataGenerator()->create_scale(['courseid' => 0]);
        $service = new gradebook_service();

        $item = $service->create_manual_item($course->id, ['itemname' => 'Scaled', 'gradetype' => 'scale',
            'scaleid' => $sitescale->id]);
        $this->assertGreaterThan(0, $item['itemid']);

        $this->expectExceptionMessageMatches('/scaleid must be a site scale/');
        $service->create_manual_item($course->id, ['itemname' => 'Foreign', 'gradetype' => 'scale',
            'scaleid' => $foreignscale->id]);
    }

    /**
     * Test question categories cannot be moved under themselves or their descendants.
     */
    public function test_question_category_cycle_is_rejected(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        external_api::set_context_restriction(null);

        $service = new question_bank_service();
        $systemcontext = context_system::instance();
        // A sibling keeps Parent from being the only child of the top category, whose parent cannot change.
        $service->create_category($systemcontext->id, 'Sibling');
        $parent = $service->create_category($systemcontext->id, 'Parent');
        $child = $service->create_category($systemcontext->id, 'Child', $parent['categoryid']);

        foreach ([$parent['categoryid'], $child['categoryid']] as $newparent) {
            try {
                $service->update_category($parent['categoryid'], 'Parent', '', FORMAT_HTML, $newparent);
                $this->fail('A category cycle was created.');
            } catch (\moodle_exception $exception) {
                $this->assertSame('wrapper:invalidinput', $exception->errorcode);
            }
        }
    }

    /**
     * Test saving a new question version keeps embedded files.
     */
    public function test_update_question_preserves_embedded_files(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        external_api::set_context_restriction(null);

        $service = new question_bank_service();
        $category = $service->create_category(context_system::instance()->id, 'Files');
        $created = $service->create_question($category['categoryid'], [
            'qtype' => 'essay',
            'name' => 'With image',
            'questiontext' => '<p><img src="@@PLUGINFILE@@/diagram.png" alt="d"></p>',
        ]);
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => $created['contextid'],
            'component' => 'question',
            'filearea' => 'questiontext',
            'itemid' => $created['questionid'],
            'filepath' => '/',
            'filename' => 'diagram.png',
        ], 'png-bytes');

        $updated = $service->update_question($created['questionid'], ['name' => 'With image v2']);

        $files = $fs->get_area_files($updated['contextid'], 'question', 'questiontext', $updated['questionid'], '', false);
        $this->assertSame(['diagram.png'], array_values(array_map(fn($file) => $file->get_filename(), $files)));
    }

    /**
     * Test a wrapper that fails inside its own transaction never leaves it open.
     */
    public function test_failed_wrapper_aborts_open_transactions(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $failing = new class extends gradebook_service {
            /**
             * Open a transaction and fail before committing it.
             *
             * @param int $courseid Course id.
             * @param array $itemids Item ids.
             * @return array
             */
            public function delete_items(int $courseid, array $itemids): array {
                global $DB;
                $DB->start_delegated_transaction();
                throw new \moodle_exception('error');
            }
        };
        $manager = new \webservice_mcp\local\wrapper\manager([], true, null, null, $failing);

        try {
            $manager->execute(
                'wrapper_gradebook_delete_items',
                ['courseid' => SITEID, 'itemids' => [1]],
                context_system::instance(),
                get_admin()
            );
            $this->fail('Expected the wrapper to fail.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('error', $exception->errorcode);
        }
        $this->assertFalse($DB->is_transaction_started());
    }
}
