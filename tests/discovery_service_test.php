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
use webservice_mcp\local\wrapper\discovery_service;
use webservice_mcp\local\wrapper\manager;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/counting_eligibility_resolver.php');

/**
 * Tests for the Moodle API search, describe and execute wrappers.
 *
 * @package     webservice_mcp
 * @copyright   2025 MohammadReza PourMohammad
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\wrapper\discovery_service
 * @covers      \webservice_mcp\local\wrapper\api_search
 * @covers      \webservice_mcp\local\wrapper\gateway_definitions
 */
final class discovery_service_test extends advanced_testcase {
    /**
     * Reset state and the static context restriction.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        external_api::set_context_restriction(null);
    }

    /**
     * Create an enabled service containing the given functions.
     *
     * @param array $functionnames Function names.
     * @return int
     */
    private function create_service(array $functionnames): int {
        global $DB;

        $serviceid = (int)$DB->insert_record('external_services', (object)[
            'name' => 'Discovery test ' . random_string(6),
            'shortname' => 'discovery_test_' . random_string(6),
            'enabled' => 1,
            'restrictedusers' => 0,
            'timecreated' => time(),
        ]);
        foreach ($functionnames as $functionname) {
            $DB->insert_record('external_services_functions', (object)[
                'externalserviceid' => $serviceid,
                'functionname' => $functionname,
            ]);
        }

        return $serviceid;
    }

    /**
     * Log in as admin with a valid sesskey (call_external_function requires one outside WS_SERVER).
     */
    private function login_admin(): void {
        $this->setAdminUser();
        $_POST['sesskey'] = sesskey();
    }

    /**
     * Search only returns functions in the connector service, ranked by keyword match.
     */
    public function test_search_is_scoped_to_service_and_ranked(): void {
        $this->login_admin();
        $serviceid = $this->create_service(['core_user_get_users', 'core_webservice_get_site_info', 'core_course_get_contents']);

        $result = (new discovery_service())->search_api('get users', 25, context_system::instance(), null, $serviceid);
        $names = array_column($result['results'], 'name');

        $this->assertSame('core_user_get_users', $names[0]);
        $this->assertNotContains('core_user_get_users_by_field', $names);
        $hit = $result['results'][0];
        $this->assertSame('read', $hit['type']);
        $this->assertTrue($hit['readOnly']);
        $this->assertSame('core', $hit['domain']);
        $this->assertLessThanOrEqual(300, \core_text::strlen($hit['description']));
    }

    /**
     * Neither declared capabilities nor risk hide functions; likely-permitted hits rank first.
     */
    public function test_search_never_hides_on_capabilities_or_risk(): void {
        $serviceid = $this->create_service([
            'core_user_create_users',
            'core_user_get_users_by_field',
            'core_course_delete_courses',
            'core_webservice_get_site_info',
        ]);
        $service = new discovery_service();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $hits = array_column(
            $service->search_api('users', 25, context_system::instance(), $user, $serviceid)['results'],
            null,
            'name'
        );
        $this->assertFalse($hits['core_user_create_users']['likelyPermitted']);
        $this->assertContains('moodle/user:create', $hits['core_user_create_users']['missingCapabilities']);
        $likely = array_column($hits, 'likelyPermitted');
        $this->assertSame($likely, array_values(array_merge(array_filter($likely), array_filter($likely, fn($v) => !$v))));

        $this->login_admin();
        set_config('showhighrisktools', 0, 'webservice_mcp');
        $names = array_column(
            $service->search_api('delete courses', 25, context_system::instance(), null, $serviceid)['results'],
            'name'
        );
        $this->assertContains('core_course_delete_courses', $names);
    }

    /**
     * A teacher can find and execute core_user_get_users although it declares capabilities they lack.
     */
    public function test_teacher_can_execute_function_with_missing_declared_capabilities(): void {
        $serviceid = $this->create_service(['core_user_get_users']);
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student', ['lastname' => 'Zzuniquelastname']);
        $this->setUser($teacher);
        $_POST['sesskey'] = sesskey();
        $context = context_course::instance($course->id);
        $service = new discovery_service();

        $hit = $service->search_api('get users', 5, $context, $teacher, $serviceid)['results'][0];
        $this->assertSame('core_user_get_users', $hit['name']);
        $this->assertFalse($hit['likelyPermitted']);

        $result = (new manager())->execute('wrapper_moodle_api_execute', [
            'functionname' => 'core_user_get_users',
            'params' => ['criteria' => [['key' => 'lastname', 'value' => 'Zzuniquelastname']]],
        ], $context, $teacher, $serviceid);

        $this->assertSame('read', $result['type']);
        $this->assertArrayHasKey('users', $result['data']);
        $this->assertSame((int)$student->id, (int)$result['data']['users'][0]['id']);
    }

    /**
     * An empty query summarizes domains and components; limit caps hits.
     */
    public function test_search_summary_and_limit(): void {
        $this->login_admin();
        $serviceid = $this->create_service([
            'core_user_get_users',
            'core_user_get_users_by_field',
            'core_webservice_get_site_info',
        ]);
        $service = new discovery_service();

        $summary = $service->search_api('', 25, context_system::instance(), null, $serviceid);
        $this->assertSame(3, $summary['total']);
        $this->assertSame([], $summary['results']);
        $this->assertSame('core', $summary['groups'][0]['domain']);
        $this->assertSame(3, $summary['groups'][0]['count']);

        $limited = $service->search_api('user', 1, context_system::instance(), null, $serviceid);
        $this->assertCount(1, $limited['results']);
        $this->assertGreaterThanOrEqual(2, $limited['total']);
    }

    /**
     * Describe returns schemas and an example skeleton, and reports out-of-scope names as unavailable.
     */
    public function test_describe_returns_schema_and_example(): void {
        $this->login_admin();
        $serviceid = $this->create_service(['core_user_get_users']);

        $result = (new discovery_service())->describe_api(
            ['core_user_get_users', 'core_course_delete_courses'],
            context_system::instance(),
            null,
            $serviceid
        );

        $this->assertCount(1, $result['functions']);
        $function = $result['functions'][0];
        $this->assertSame('object', $function['inputSchema']['type']);
        $this->assertSame(['criteria'], $function['inputSchema']['required']);
        $this->assertArrayHasKey('criteria', $function['exampleArgs']);
        $this->assertSame(['key', 'value'], array_keys($function['exampleArgs']['criteria'][0]));
        $this->assertSame('core_course_delete_courses', $result['unavailable'][0]['name']);
    }

    /**
     * Execute calls the function through call_external_function and returns cleaned data.
     */
    public function test_execute_runs_function_in_service(): void {
        $this->login_admin();
        $serviceid = $this->create_service(['core_user_get_users']);
        $user = $this->getDataGenerator()->create_user(['email' => 'findme@example.com']);

        $result = (new discovery_service())->execute_api(
            'core_user_get_users',
            ['criteria' => [['key' => 'email', 'value' => 'findme@example.com']]],
            context_system::instance(),
            null,
            $serviceid
        );

        $this->assertSame('core_user_get_users', $result['functionname']);
        $this->assertSame('read', $result['type']);
        $this->assertEquals($user->id, $result['data']['users'][0]['id']);
    }

    /**
     * JSON false for PARAM_BOOL parameters is accepted.
     */
    public function test_execute_accepts_boolean_parameters(): void {
        $this->login_admin();
        $serviceid = $this->create_service(['core_course_search_courses']);
        $this->getDataGenerator()->create_course(['fullname' => 'Coercion course']);

        $result = (new discovery_service())->execute_api(
            'core_course_search_courses',
            ['criterianame' => 'search', 'criteriavalue' => 'Coercion', 'limittoenrolled' => false],
            context_system::instance(),
            null,
            $serviceid
        );

        $this->assertSame(1, $result['data']['total']);
    }

    /**
     * Functions outside the connector service cannot be executed.
     */
    public function test_execute_rejects_function_outside_service(): void {
        $this->login_admin();
        $serviceid = $this->create_service(['core_webservice_get_site_info']);

        $this->expectExceptionObject(new \moodle_exception(
            'wrapper:apifunctionunavailable',
            'webservice_mcp',
            '',
            'core_user_get_users'
        ));
        (new discovery_service())->execute_api('core_user_get_users', [], context_system::instance(), null, $serviceid);
    }

    /**
     * Unknown functions are rejected.
     */
    public function test_execute_rejects_unknown_function(): void {
        $this->login_admin();

        $this->expectException(\moodle_exception::class);
        (new discovery_service())->execute_api('this_function_does_not_exist', [], context_system::instance());
    }

    /**
     * Risk never blocks execution; only Moodle permissions do.
     */
    public function test_execute_runs_high_risk_function(): void {
        global $DB;

        $this->login_admin();
        $serviceid = $this->create_service(['core_course_delete_courses']);
        set_config('showhighrisktools', 0, 'webservice_mcp');
        $course = $this->getDataGenerator()->create_course();

        $result = (new discovery_service())->execute_api(
            'core_course_delete_courses',
            ['courseids' => [$course->id]],
            context_system::instance(),
            null,
            $serviceid
        );

        $this->assertSame('write', $result['type']);
        $this->assertFalse($DB->record_exists('course', ['id' => $course->id]));
    }

    /**
     * Function errors are thrown with Moodle's errorcode and message.
     */
    public function test_execute_throws_function_errors(): void {
        $this->login_admin();
        $serviceid = $this->create_service(['core_user_get_users']);

        try {
            (new discovery_service())->execute_api('core_user_get_users', [], context_system::instance(), null, $serviceid);
            $this->fail('Expected an invalid parameter error.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('wrapper:apiexecutefailed', $exception->errorcode);
            $this->assertSame('invalidparameter', $exception->a->errorcode);
            $this->assertStringContainsString('core_user_get_users', $exception->getMessage());
        }
    }

    /**
     * Native context validation enforces the connector's restricted context through the manager.
     */
    public function test_execute_enforces_restricted_context(): void {
        $this->login_admin();
        $serviceid = $this->create_service(['core_course_get_contents']);
        $allowed = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $manager = new manager();

        $result = $manager->execute(
            'wrapper_moodle_api_execute',
            ['functionname' => 'core_course_get_contents', 'params' => ['courseid' => $allowed->id]],
            context_course::instance($allowed->id),
            null,
            $serviceid
        );
        $this->assertIsArray($result['data']);

        try {
            $manager->execute(
                'wrapper_moodle_api_execute',
                ['functionname' => 'core_course_get_contents', 'params' => ['courseid' => $other->id]],
                context_course::instance($allowed->id),
                null,
                $serviceid
            );
            $this->fail('Expected a restricted context error.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('wrapper:apiexecutefailed', $exception->errorcode);
            // The core_course_get_contents function rethrows the restricted_context_exception from validate_context().
            $this->assertSame('errorcoursecontextnotvalid', $exception->a->errorcode);
        }
    }

    /**
     * Execution must run as the authenticated session user.
     */
    public function test_execute_requires_session_user(): void {
        $this->login_admin();
        $other = $this->getDataGenerator()->create_user();

        $this->expectException(\coding_exception::class);
        (new discovery_service())->execute_api('core_webservice_get_site_info', [], context_system::instance(), $other);
    }

    /**
     * Function type drives the mutating flag.
     */
    public function test_function_type(): void {
        $this->assertSame('read', discovery_service::function_type('core_webservice_get_site_info'));
        $this->assertSame('write', discovery_service::function_type('core_course_delete_courses'));
        $this->assertSame('write', discovery_service::function_type('no_such_function'));
    }

    /**
     * Search matches required capabilities and parameter names, and filters by component and type.
     */
    public function test_search_by_capability_parameter_and_filters(): void {
        $this->login_admin();
        $serviceid = $this->create_service([
            'core_user_get_users',
            'core_user_create_users',
            'core_webservice_get_site_info',
            'core_course_get_contents',
        ]);
        $service = new discovery_service();
        $context = context_system::instance();

        $bycapability = $service->search_api('moodle/user:viewhiddendetails', 10, $context, null, $serviceid);
        $this->assertSame('core_user_get_users', $bycapability['results'][0]['name']);

        $byparameter = $service->search_api('criteria', 10, $context, null, $serviceid);
        $hit = array_column($byparameter['results'], null, 'name')['core_user_get_users'];
        $this->assertSame(['criteria:array'], $hit['requiredParams']);
        $this->assertArrayHasKey('criteria', $hit['exampleArgs']);

        $bycomponent = $service->search_api('', 25, $context, null, $serviceid, 'core_user');
        $this->assertEqualsCanonicalizing(
            ['core_user_get_users', 'core_user_create_users'],
            array_column($bycomponent['results'], 'name')
        );
        $this->assertArrayNotHasKey('requiredParams', $bycomponent['results'][0]);

        $writes = $service->search_api('user', 25, $context, null, $serviceid, '', 'write');
        $this->assertSame(['core_user_create_users'], array_column($writes['results'], 'name'));
    }

    /**
     * Repeated searches are served from the visibility cache.
     */
    public function test_search_results_are_cached(): void {
        $this->login_admin();
        $serviceid = $this->create_service(['core_user_get_users']);
        $resolver = new counting_eligibility_resolver();
        $service = new discovery_service(new \webservice_mcp\local\discovery\visibility_cache($resolver));

        $first = $service->search_api('users', 5, context_system::instance(), null, $serviceid);
        $service->search_api('users', 5, context_system::instance(), null, $serviceid);
        $service->describe_api(['core_user_get_users'], context_system::instance(), null, $serviceid);

        $this->assertSame(1, $resolver->calls);
        $this->assertSame('core_user_get_users', $first['results'][0]['name']);
    }
}
