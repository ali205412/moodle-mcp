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

use context_system;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use externallib_advanced_testcase;
use ReflectionClass;
use stdClass;
use webservice_mcp\local\tool_provider;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Tests for MCP tool provider class.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\tool_provider
 */
final class tool_provider_test extends externallib_advanced_testcase {
    /**
     * Create a service seeded with the supplied function names.
     *
     * @param array $functionnames External function names to add.
     * @return int
     */
    private function create_test_service(array $functionnames): int {
        global $DB;

        $service = new stdClass();
        $service->name = 'Test MCP Service ' . bin2hex(random_bytes(4));
        $service->enabled = 1;
        $service->restrictedusers = 0;
        $service->component = null;
        $service->timecreated = time();
        $service->timemodified = time();
        $service->shortname = 'test_mcp_service_' . bin2hex(random_bytes(4));
        $service->downloadfiles = 0;
        $service->uploadfiles = 0;
        $serviceid = $DB->insert_record('external_services', $service);

        foreach ($functionnames as $functionname) {
            $DB->insert_record('external_services_functions', (object)[
                'externalserviceid' => $serviceid,
                'functionname' => $functionname,
            ]);
        }

        return $serviceid;
    }

    /**
     * Determine whether the current Moodle install exposes an external function.
     *
     * @param string $functionname External function name.
     * @return bool
     */
    private function moodle_has_external_function(string $functionname): bool {
        global $DB;
        return $DB->record_exists('external_functions', ['name' => $functionname]);
    }

    /**
     * Test schema generation for simple string value.
     */
    public function test_generate_schema_string(): void {
        $this->resetAfterTest(true);

        $param = new external_value(PARAM_TEXT, 'Test parameter', VALUE_REQUIRED);

        $reflection = new ReflectionClass(tool_provider::class);
        $method = $reflection->getMethod('generate_schema');
        $method->setAccessible(true);

        $schema = $method->invoke(null, $param);

        $this->assertEquals('string', $schema['type']);
        $this->assertEquals('Test parameter', $schema['description']);
        $this->assertTrue($schema['_required']);
    }

    /**
     * Test schema generation for integer value.
     */
    public function test_generate_schema_integer(): void {
        $this->resetAfterTest(true);

        $param = new external_value(PARAM_INT, 'Test integer', VALUE_OPTIONAL);

        $reflection = new ReflectionClass(tool_provider::class);
        $method = $reflection->getMethod('generate_schema');
        $method->setAccessible(true);

        $schema = $method->invoke(null, $param);

        $this->assertEquals('number', $schema['type']);
        $this->assertEquals('Test integer', $schema['description']);
        $this->assertArrayNotHasKey('_required', $schema);
    }

    /**
     * Test schema generation for float value.
     */
    public function test_generate_schema_float(): void {
        $this->resetAfterTest(true);

        $param = new external_value(PARAM_FLOAT, 'Test float');

        $reflection = new ReflectionClass(tool_provider::class);
        $method = $reflection->getMethod('generate_schema');
        $method->setAccessible(true);

        $schema = $method->invoke(null, $param);

        $this->assertEquals('number', $schema['type']);
    }

    /**
     * Test schema generation for boolean value.
     */
    public function test_generate_schema_boolean(): void {
        $this->resetAfterTest(true);

        $param = new external_value(PARAM_BOOL, 'Test boolean');

        $reflection = new ReflectionClass(tool_provider::class);
        $method = $reflection->getMethod('generate_schema');
        $method->setAccessible(true);

        $schema = $method->invoke(null, $param);

        $this->assertEquals('boolean', $schema['type']);
    }

    /**
     * Test schema generation for single structure (object).
     */
    public function test_generate_schema_single_structure(): void {
        $this->resetAfterTest(true);

        $param = new external_single_structure([
            'name' => new external_value(PARAM_TEXT, 'Name field', VALUE_REQUIRED),
            'age' => new external_value(PARAM_INT, 'Age field', VALUE_OPTIONAL),
            'active' => new external_value(PARAM_BOOL, 'Active status', VALUE_REQUIRED),
        ]);

        $reflection = new ReflectionClass(tool_provider::class);
        $method = $reflection->getMethod('generate_schema');
        $method->setAccessible(true);

        $schema = $method->invoke(null, $param);

        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('name', $schema['properties']);
        $this->assertArrayHasKey('age', $schema['properties']);
        $this->assertArrayHasKey('active', $schema['properties']);

        $this->assertEquals('string', $schema['properties']['name']['type']);
        $this->assertEquals('number', $schema['properties']['age']['type']);
        $this->assertEquals('boolean', $schema['properties']['active']['type']);

        $this->assertArrayHasKey('required', $schema);
        $this->assertContains('name', $schema['required']);
        $this->assertContains('active', $schema['required']);
        $this->assertNotContains('age', $schema['required']);
    }

    /**
     * Test schema generation for multiple structure (array).
     */
    public function test_generate_schema_multiple_structure(): void {
        $this->resetAfterTest(true);

        $param = new external_multiple_structure(
            new external_value(PARAM_TEXT, 'Item description')
        );

        $reflection = new ReflectionClass(tool_provider::class);
        $method = $reflection->getMethod('generate_schema');
        $method->setAccessible(true);

        $schema = $method->invoke(null, $param);

        $this->assertEquals('array', $schema['type']);
        $this->assertArrayHasKey('items', $schema);
        $this->assertEquals('string', $schema['items']['type']);
    }

    /**
     * Test schema generation for nested structures.
     */
    public function test_generate_schema_nested_structure(): void {
        $this->resetAfterTest(true);

        $param = new external_single_structure([
            'user' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'User ID', VALUE_REQUIRED),
                'name' => new external_value(PARAM_TEXT, 'User name', VALUE_REQUIRED),
            ]),
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Course ID'),
                    'title' => new external_value(PARAM_TEXT, 'Course title'),
                ])
            ),
        ]);

        $reflection = new ReflectionClass(tool_provider::class);
        $method = $reflection->getMethod('generate_schema');
        $method->setAccessible(true);

        $schema = $method->invoke(null, $param);

        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('user', $schema['properties']);
        $this->assertArrayHasKey('courses', $schema['properties']);

        // Test nested user object.
        $userschema = $schema['properties']['user'];
        $this->assertEquals('object', $userschema['type']);
        $this->assertArrayHasKey('id', $userschema['properties']);
        $this->assertArrayHasKey('name', $userschema['properties']);
        $this->assertEquals(['id', 'name'], $userschema['required']);

        // Test nested courses array.
        $coursesschema = $schema['properties']['courses'];
        $this->assertEquals('array', $coursesschema['type']);
        $this->assertEquals('object', $coursesschema['items']['type']);
        $this->assertArrayHasKey('id', $coursesschema['items']['properties']);
        $this->assertArrayHasKey('title', $coursesschema['items']['properties']);
    }

    /**
     * Test schema type conversion.
     */
    public function test_get_schema_type(): void {
        $this->resetAfterTest(true);

        $reflection = new ReflectionClass(tool_provider::class);
        $method = $reflection->getMethod('get_schema_type');
        $method->setAccessible(true);

        $this->assertEquals('string', $method->invoke(null, new external_value(PARAM_TEXT)));
        $this->assertEquals('number', $method->invoke(null, new external_value(PARAM_INT)));
        $this->assertEquals('number', $method->invoke(null, new external_value(PARAM_FLOAT)));
        $this->assertEquals('boolean', $method->invoke(null, new external_value(PARAM_BOOL)));
        $this->assertEquals('object', $method->invoke(null, new external_single_structure([])));
        $this->assertEquals('array', $method->invoke(null, new external_multiple_structure(
            new external_value(PARAM_TEXT)
        )));
    }

    /**
     * Test build_schema with null description.
     */
    public function test_build_schema_null(): void {
        $this->resetAfterTest(true);

        $reflection = new ReflectionClass(tool_provider::class);
        $method = $reflection->getMethod('build_schema');
        $method->setAccessible(true);

        $schema = $method->invoke(null, null);

        $this->assertEquals('object', $schema['type']);
        $this->assertEquals([], $schema['properties']);
    }

    /**
     * Test get_tools retrieves available functions.
     */
    public function test_get_tools(): void {
        global $DB, $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $serviceid = $this->create_test_service(['core_webservice_get_site_info']);

        // Create a token for the service.
        $token = new stdClass();
        $token->token = bin2hex(random_bytes(32));
        $token->userid = $USER->id;
        $token->tokentype = EXTERNAL_TOKEN_PERMANENT;
        $token->contextid = context_system::instance()->id;
        $token->creatorid = $USER->id;
        $token->timecreated = time();
        $token->externalserviceid = $serviceid;
        $DB->insert_record('external_tokens', $token);

        // Test get_tools.
        $tools = tool_provider::get_tools($token->token);

        $this->assertIsArray($tools);
        $this->assertNotEmpty($tools);

        $tool = $tools[0];
        $this->assertArrayHasKey('name', $tool);
        $this->assertArrayHasKey('description', $tool);
        $this->assertArrayHasKey('inputSchema', $tool);
        $this->assertArrayHasKey('outputSchema', $tool);

        $this->assertEquals('core_webservice_get_site_info', $tool['name']);
        $this->assertIsArray($tool['inputSchema']);
        $this->assertIsArray($tool['outputSchema']);
    }

    /**
     * Test normalized list output includes provenance and annotations.
     */
    public function test_list_tools_for_service_ids_includes_annotations_and_provenance(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $serviceid = $this->create_test_service(['core_webservice_get_site_info']);

        $result = tool_provider::list_tools_for_service_ids([$serviceid]);

        $this->assertArrayHasKey('catalogVersion', $result);
        $this->assertNotEmpty($result['catalogVersion']);
        $this->assertArrayHasKey('groups', $result);
        $this->assertArrayHasKey('coverage', $result);
        $this->assertNotEmpty($result['tools']);

        $tool = $result['tools'][0];
        $this->assertArrayHasKey('annotations', $tool);
        $this->assertArrayHasKey('x-moodle', $tool);
        $this->assertSame('core', $tool['x-moodle']['domain']);
        $this->assertSame('moodle', $tool['x-moodle']['component']);
        $this->assertArrayHasKey('provenance', $tool['x-moodle']);
        $this->assertArrayHasKey('transport', $tool['x-moodle']);
    }

    /**
     * Test list output supports cursor pagination.
     */
    public function test_list_tools_for_service_ids_supports_pagination(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $serviceid = $this->create_test_service([
            'core_webservice_get_site_info',
            'mod_assign_get_assignments',
            'mod_assign_get_submissions',
        ]);

        $pageone = tool_provider::list_tools_for_service_ids([$serviceid], ['limit' => 2]);
        $pagetwo = tool_provider::list_tools_for_service_ids([$serviceid], [
            'limit' => 2,
            'cursor' => $pageone['nextCursor'],
        ]);

        $this->assertCount(2, $pageone['tools']);
        $this->assertNotNull($pageone['nextCursor']);
        $this->assertNotEmpty($pagetwo['tools']);
        $this->assertNotEquals($pageone['tools'][0]['name'], $pagetwo['tools'][0]['name']);
    }

    /**
     * Test page-size requests are clamped for large services.
     */
    public function test_list_tools_for_service_ids_clamps_large_page_sizes(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $functions = $DB->get_records('external_functions', null, 'name ASC', 'name', 0, 350);
        $this->assertGreaterThan(250, count($functions));

        $serviceid = $this->create_test_service(array_keys($functions));
        $result = tool_provider::list_tools_for_service_ids([$serviceid], ['limit' => 999]);

        $this->assertCount(250, $result['tools']);
        $this->assertNotNull($result['nextCursor']);
    }

    /**
     * Test list output supports domain grouping/filtering.
     */
    public function test_list_tools_for_service_ids_supports_group_filter(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $serviceid = $this->create_test_service([
            'core_webservice_get_site_info',
            'mod_assign_get_assignments',
        ]);

        $result = tool_provider::list_tools_for_service_ids([$serviceid], ['group' => 'activity']);

        $this->assertCount(1, $result['tools']);
        $this->assertSame('mod_assign_get_assignments', $result['tools'][0]['name']);
        $this->assertSame('activity', $result['tools'][0]['x-moodle']['domain']);
        $this->assertCount(1, $result['coverage']);
        $this->assertSame('activity', $result['coverage'][0]['domain']);
    }

    /**
     * Test explicit system-level capability checks can hide tools at discovery time.
     */
    public function test_list_tools_hides_tools_when_explicit_system_capability_is_missing(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $serviceid = $this->create_test_service(['mod_lti_get_tool_proxies']);
        $result = tool_provider::list_tools_for_service_ids(
            [$serviceid],
            [
                'restrictedcontext' => context_system::instance(),
                'user' => $user,
            ]
        );

        $this->assertSame([], array_column($result['tools'], 'name'));

        $coverage = array_column($result['coverage'], null, 'domain');
        $this->assertArrayHasKey('activity', $coverage);
        $this->assertSame(0, $coverage['activity']['visibleTools']);
    }

    /**
     * Test high-risk discovery policy can hide risky tools.
     */
    public function test_list_tools_respects_high_risk_site_policy(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        set_config('showhighrisktools', 0, 'webservice_mcp');
        $serviceid = $this->create_test_service(['core_course_delete_courses']);

        $result = tool_provider::list_tools_for_service_ids([$serviceid]);

        $this->assertSame([], array_column($result['tools'], 'name'));
    }

    /**
     * Test discovery exposes risk metadata and access-information companions.
     */
    public function test_list_tools_includes_risk_and_access_information_metadata(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $serviceid = $this->create_test_service([
            'mod_forum_add_discussion',
            'mod_forum_get_forum_access_information',
        ]);

        $result = tool_provider::list_tools_for_service_ids([$serviceid]);
        $tools = array_column($result['tools'], null, 'name');

        $this->assertArrayHasKey('mod_forum_add_discussion', $tools);
        $tool = $tools['mod_forum_add_discussion'];
        $this->assertArrayHasKey('eligibility', $tool['x-moodle']);
        $this->assertArrayHasKey('risk', $tool['x-moodle']);
        $this->assertContains('mod_forum_get_forum_access_information', $tool['x-moodle']['eligibility']['accessInformationTools']);
        $this->assertContains('group', $tool['x-moodle']['eligibility']['callTimeChecks']);
        $this->assertTrue($tool['x-moodle']['risk']['confirmationRequired']);
    }

    /**
     * Test core learning, personal, and file tools expose curated surface metadata.
     */
    public function test_list_tools_includes_curated_surface_and_workflow_metadata(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $privatefilewritefunction = $this->moodle_has_external_function('core_user_update_private_files')
            ? 'core_user_update_private_files'
            : 'core_user_add_user_private_files';

        $serviceid = $this->create_test_service([
            'core_course_get_contents',
            'core_completion_get_course_completion_status',
            'core_message_get_conversations',
            'core_files_get_unused_draft_itemid',
            'core_files_upload',
            'core_user_prepare_private_files_for_edition',
            $privatefilewritefunction,
        ]);

        $result = tool_provider::list_tools_for_service_ids([$serviceid], ['limit' => 20]);
        $tools = array_column($result['tools'], null, 'name');

        $this->assertSame('learning', $tools['core_course_get_contents']['x-moodle']['surface']['surface']);
        $this->assertSame('courses', $tools['core_course_get_contents']['x-moodle']['surface']['area']);
        $this->assertSame('learning', $tools['core_completion_get_course_completion_status']['x-moodle']['surface']['surface']);
        $this->assertSame('personal', $tools['core_message_get_conversations']['x-moodle']['surface']['surface']);
        $this->assertSame('files', $tools['core_files_upload']['x-moodle']['surface']['surface']);
        $this->assertNotEmpty($tools['core_files_upload']['x-moodle']['workflow']);
        $this->assertSame('workflow_draft_file_upload', $tools['core_files_upload']['x-moodle']['workflow'][0]['name']);
        $this->assertSame('files', $tools[$privatefilewritefunction]['x-moodle']['surface']['surface']);
        $this->assertSame('workflow_private_files_edit', $tools[$privatefilewritefunction]['x-moodle']['workflow'][0]['name']);
    }

    /**
     * Test activity tools expose curated module areas and workflow metadata.
     */
    public function test_list_tools_includes_activity_surface_and_workflow_metadata(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $serviceid = $this->create_test_service([
            'mod_assign_get_assignments',
            'mod_assign_start_submission',
            'mod_forum_add_discussion',
            'mod_forum_get_forum_access_information',
            'mod_quiz_start_attempt',
            'mod_quiz_get_quiz_access_information',
            'mod_choice_submit_choice_response',
            'mod_choice_get_choices_by_courses',
            'mod_wiki_edit_page',
            'mod_wiki_get_page_contents',
        ]);

        $result = tool_provider::list_tools_for_service_ids([$serviceid], ['limit' => 20]);
        $tools = array_column($result['tools'], null, 'name');

        $this->assertSame('activity', $tools['mod_assign_get_assignments']['x-moodle']['surface']['surface']);
        $this->assertSame('assignments', $tools['mod_assign_get_assignments']['x-moodle']['surface']['area']);
        $this->assertSame('workflow_assignment_submission', $tools['mod_assign_start_submission']['x-moodle']['workflow'][0]['name']);

        $this->assertSame('forums', $tools['mod_forum_add_discussion']['x-moodle']['surface']['area']);
        $this->assertContains('mod_forum_get_forum_access_information', $tools['mod_forum_add_discussion']['x-moodle']['eligibility']['accessInformationTools']);
        $this->assertSame('workflow_forum_participation', $tools['mod_forum_add_discussion']['x-moodle']['workflow'][0]['name']);

        $this->assertSame('quizzes', $tools['mod_quiz_start_attempt']['x-moodle']['surface']['area']);
        $this->assertSame('workflow_quiz_attempt', $tools['mod_quiz_start_attempt']['x-moodle']['workflow'][0]['name']);

        $this->assertSame('choice', $tools['mod_choice_submit_choice_response']['x-moodle']['surface']['area']);
        $this->assertSame('workflow_choice_response', $tools['mod_choice_submit_choice_response']['x-moodle']['workflow'][0]['name']);

        $this->assertSame('wiki', $tools['mod_wiki_edit_page']['x-moodle']['surface']['area']);
        $this->assertSame('workflow_wiki_collaboration', $tools['mod_wiki_edit_page']['x-moodle']['workflow'][0]['name']);
    }

    /**
     * Test operator/admin tools expose curated operator surfaces and execution hints.
     */
    public function test_list_tools_includes_operator_surface_workflow_and_execution_metadata(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $functionnames = [
            'core_user_search_identity',
            'core_enrol_submit_user_enrolment_form',
            'core_group_create_groups',
            'core_cohort_create_cohorts',
            'core_role_assign_roles',
            'core_course_create_categories',
            'core_course_duplicate_course',
            'core_courseformat_update_course',
            'core_competency_create_competency_framework',
        ];
        $hasprivacyexternals = $this->moodle_has_external_function('tool_dataprivacy_create_data_request') &&
            $this->moodle_has_external_function('tool_dataprivacy_get_data_requests');
        if ($hasprivacyexternals) {
            $functionnames[] = 'tool_dataprivacy_create_data_request';
            $functionnames[] = 'tool_dataprivacy_get_data_requests';
        }

        $serviceid = $this->create_test_service($functionnames);

        $result = tool_provider::list_tools_for_service_ids([$serviceid], ['limit' => 30]);
        $tools = array_column($result['tools'], null, 'name');

        $this->assertSame('operator', $tools['core_user_search_identity']['x-moodle']['surface']['surface']);
        $this->assertSame('users', $tools['core_user_search_identity']['x-moodle']['surface']['area']);
        $this->assertSame('workflow_user_management', $tools['core_user_search_identity']['x-moodle']['workflow'][0]['name']);

        $this->assertSame('enrolments', $tools['core_enrol_submit_user_enrolment_form']['x-moodle']['surface']['area']);
        $this->assertSame(
            'workflow_enrolment_management',
            $tools['core_enrol_submit_user_enrolment_form']['x-moodle']['workflow'][0]['name']
        );

        $this->assertSame('groups', $tools['core_group_create_groups']['x-moodle']['surface']['area']);
        $this->assertSame('workflow_group_management', $tools['core_group_create_groups']['x-moodle']['workflow'][0]['name']);

        $this->assertSame('cohorts', $tools['core_cohort_create_cohorts']['x-moodle']['surface']['area']);
        $this->assertSame('workflow_cohort_management', $tools['core_cohort_create_cohorts']['x-moodle']['workflow'][0]['name']);

        $this->assertSame('roles', $tools['core_role_assign_roles']['x-moodle']['surface']['area']);
        $this->assertSame('workflow_role_assignment', $tools['core_role_assign_roles']['x-moodle']['workflow'][0]['name']);

        $this->assertSame('categories', $tools['core_course_create_categories']['x-moodle']['surface']['area']);
        $this->assertSame(
            'workflow_course_catalog_management',
            $tools['core_course_create_categories']['x-moodle']['workflow'][0]['name']
        );

        $this->assertSame('authoring', $tools['core_courseformat_update_course']['x-moodle']['surface']['area']);
        $this->assertSame(
            'workflow_course_editor',
            $tools['core_courseformat_update_course']['x-moodle']['workflow'][0]['name']
        );

        $this->assertSame(
            'competencies',
            $tools['core_competency_create_competency_framework']['x-moodle']['surface']['area']
        );
        $this->assertSame(
            'workflow_competency_management',
            $tools['core_competency_create_competency_framework']['x-moodle']['workflow'][0]['name']
        );

        if ($hasprivacyexternals) {
            $this->assertSame('privacy', $tools['tool_dataprivacy_create_data_request']['x-moodle']['surface']['area']);
            $this->assertSame(
                'workflow_privacy_request_management',
                $tools['tool_dataprivacy_create_data_request']['x-moodle']['workflow'][0]['name']
            );
            $this->assertSame('async_request', $tools['tool_dataprivacy_create_data_request']['x-moodle']['execution']['mode']);
            $this->assertContains(
                'tool_dataprivacy_get_data_requests',
                $tools['tool_dataprivacy_create_data_request']['x-moodle']['execution']['followupTools']
            );
        } else {
            $this->assertArrayNotHasKey('tool_dataprivacy_create_data_request', $tools);
        }

        $this->assertSame('long_running', $tools['core_course_duplicate_course']['x-moodle']['execution']['mode']);
    }

    /**
     * Test gradebook, badges, and question-bank tools expose curated operator metadata.
     */
    public function test_list_tools_includes_gradebook_badge_and_question_bank_metadata(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $functionnames = array_values(array_filter([
            'core_badges_get_badge',
            'core_badges_get_user_badges',
            'core_badges_enable_badges',
            'core_question_update_flag',
            'qbank_managecategories_move_category',
            'qbank_tagquestion_submit_tags_form',
            'grade_get_grade_tree',
            'grade_create_gradecategories',
            'gradereport_user_get_grade_items',
            'gradingform_rubric_grader_gradingpanel_fetch',
        ], fn(string $functionname): bool => $this->moodle_has_external_function($functionname)));

        if ($functionnames === []) {
            $this->markTestSkipped('No gradebook, badge, or question-bank externals are available in this Moodle build.');
        }

        $serviceid = $this->create_test_service($functionnames);
        $result = tool_provider::list_tools_for_service_ids([$serviceid], ['limit' => 50]);
        $tools = array_column($result['tools'], null, 'name');

        foreach (
            [
                'badge' => [
                    'area' => 'badges',
                    'workflow' => 'workflow_badge_management',
                    'candidates' => [
                        'core_badges_get_badge',
                        'core_badges_get_user_badges',
                        'core_badges_enable_badges',
                    ],
                ],
                'question' => [
                    'area' => 'question_bank',
                    'workflow' => 'workflow_question_bank_management',
                    'candidates' => [
                        'qbank_managecategories_move_category',
                        'qbank_tagquestion_submit_tags_form',
                        'core_question_update_flag',
                    ],
                ],
                'grade' => [
                    'area' => 'gradebook',
                    'workflow' => 'workflow_gradebook_management',
                    'candidates' => [
                        'grade_get_grade_tree',
                        'grade_create_gradecategories',
                        'gradereport_user_get_grade_items',
                        'gradingform_rubric_grader_gradingpanel_fetch',
                    ],
                ],
            ] as $group
        ) {
            $toolname = null;
            foreach ($group['candidates'] as $candidate) {
                if (isset($tools[$candidate])) {
                    $toolname = $candidate;
                    break;
                }
            }

            if ($toolname === null) {
                continue;
            }

            $this->assertSame('operator', $tools[$toolname]['x-moodle']['surface']['surface']);
            $this->assertSame($group['area'], $tools[$toolname]['x-moodle']['surface']['area']);
            $this->assertSame($group['workflow'], $tools[$toolname]['x-moodle']['workflow'][0]['name']);
        }
    }

    /**
     * Test wrapper tools can be exposed in connector discovery.
     */
    public function test_list_tools_can_include_wrapper_tools(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/course:update', CAP_ALLOW, $roleid, context_system::instance());
        assign_capability('moodle/course:manageactivities', CAP_ALLOW, $roleid, context_system::instance());
        assign_capability('moodle/course:movesections', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();

        $serviceid = $this->create_test_service(['core_course_get_contents']);
        $result = tool_provider::list_tools_for_service_ids(
            [$serviceid],
            [
                'allow_wrappers' => true,
                'restrictedcontext' => context_system::instance(),
                'user' => $user,
            ]
        );

        $names = array_column($result['tools'], 'name');
        $this->assertContains('wrapper_course_add_section_after', $names);
        $this->assertContains('wrapper_course_set_section_visibility', $names);
        $this->assertContains('wrapper_course_delete_sections', $names);
        $this->assertContains('wrapper_course_create_missing_sections', $names);
        $this->assertContains('wrapper_course_move_module', $names);
        $this->assertContains('wrapper_course_move_section_after', $names);
        $this->assertContains('wrapper_course_set_module_visibility', $names);
        $this->assertContains('wrapper_course_duplicate_modules', $names);
        $this->assertContains('wrapper_course_delete_modules', $names);
    }

    /**
     * Test wrapper tools expose operator authoring metadata.
     */
    public function test_list_tools_projects_wrapper_surface_metadata(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/course:update', CAP_ALLOW, $roleid, context_system::instance());
        assign_capability('moodle/course:manageactivities', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();

        $serviceid = $this->create_test_service(['core_course_get_contents']);
        $result = tool_provider::list_tools_for_service_ids(
            [$serviceid],
            [
                'allow_wrappers' => true,
                'restrictedcontext' => context_system::instance(),
                'user' => $user,
            ]
        );

        $tools = array_column($result['tools'], null, 'name');
        $wrapper = $tools['wrapper_course_create_missing_sections'];

        $this->assertSame('operator', $wrapper['x-moodle']['surface']['surface']);
        $this->assertSame('authoring', $wrapper['x-moodle']['surface']['area']);
        $this->assertSame('wrapper', $wrapper['x-moodle']['provenance']['source']);
        $this->assertSame('high', $wrapper['x-moodle']['risk']['level']);
    }

    /**
     * Test wrapper tools project domain-specific surface metadata for parity wrappers.
     */
    public function test_list_tools_projects_parity_wrapper_surface_metadata(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/question:add', CAP_ALLOW, $roleid, context_system::instance());
        assign_capability('moodle/question:managecategory', CAP_ALLOW, $roleid, context_system::instance());
        assign_capability('moodle/grade:manage', CAP_ALLOW, $roleid, context_system::instance());
        assign_capability('moodle/badges:createbadge', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();

        $serviceid = $this->create_test_service(['core_course_get_contents']);
        $result = tool_provider::list_tools_for_service_ids(
            [$serviceid],
            [
                'allow_wrappers' => true,
                'restrictedcontext' => context_system::instance(),
                'user' => $user,
            ]
        );

        $tools = array_column($result['tools'], null, 'name');

        $this->assertSame('question_bank', $tools['wrapper_question_create_question']['x-moodle']['surface']['area']);
        $this->assertSame('gradebook', $tools['wrapper_gradebook_create_manual_item']['x-moodle']['surface']['area']);
        $this->assertSame('badges', $tools['wrapper_badge_create_badge']['x-moodle']['surface']['area']);
    }
}
