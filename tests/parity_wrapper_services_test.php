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

defined('MOODLE_INTERNAL') || die();

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
}
