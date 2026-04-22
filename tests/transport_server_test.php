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

/**
 * PHPUnit tests for Streamable HTTP transport server lifecycle helpers.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace webservice_mcp;

use advanced_testcase;
use context_course;
use context_system;
use core_external\restricted_context_exception;
use moodle_exception;
use required_capability_exception;
use stdClass;
use webservice_mcp\local\oauth\service as oauth_service;
use webservice_mcp\local\request;
use webservice_mcp\local\transport\protocol_headers;

require_once(__DIR__ . '/fixtures/testable_transport_server.php');

/**
 * Tests for Streamable HTTP transport server lifecycle helpers.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\transport\protocol_headers
 * @covers      \webservice_mcp\local\transport\server
 */
final class transport_server_test extends advanced_testcase {
    /**
     * Test OPTIONS preflight is handled before auth.
     */
    public function test_transport_server_handles_options_before_authentication(): void {
        $this->resetAfterTest(true);

        $server = new testable_transport_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $server->stubauthentication = true;

        $previousmethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';

        $server->run();

        if ($previousmethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $previousmethod;
        }

        $this->assertTrue($server->preflightcalled);
        $this->assertFalse($server->authcalled);
    }

    /**
     * Test HEAD probes without a token return an OAuth challenge instead of 405.
     */
    public function test_transport_server_handles_head_probe_with_oauth_challenge(): void {
        $this->resetAfterTest(true);
        set_config('oauthenabled', 1, 'webservice_mcp');

        $server = new testable_transport_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $previousmethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'HEAD';

        $server->run();

        if ($previousmethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $previousmethod;
        }

        $this->assertSame(401, $server->capturedstatus);
        $challenge = implode("\n", $server->capturedheaders);
        $this->assertStringContainsString('WWW-Authenticate: Bearer realm="Moodle MCP"', $challenge);
        $this->assertStringContainsString('Access-Control-Allow-Methods: POST, OPTIONS, DELETE, HEAD', $challenge);
        $this->assertStringContainsString('/webservice/mcp/.well-known/oauth-protected-resource', $challenge);
    }

    /**
     * Test Mcp-Method is required on POST transport requests.
     */
    public function test_protocol_headers_require_mcp_method_for_post_requests(): void {
        $this->resetAfterTest(true);

        $headers = new protocol_headers();
        $request = new request([
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'id' => 1,
            'params' => [],
        ]);

        $result = $headers->validate('POST', [], $request);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Mcp-Method', $result['message']);
    }

    /**
     * Test stateful requests require protocol and session headers after initialize.
     */
    public function test_protocol_headers_require_session_for_subsequent_requests(): void {
        $this->resetAfterTest(true);

        $headers = new protocol_headers();
        $request = new request([
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 7,
            'params' => [],
        ]);

        $result = $headers->validate('POST', [
            'mcp-method' => 'tools/list',
            'mcp-protocol-version' => protocol_headers::DEFAULT_PROTOCOL_VERSION,
        ], $request);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('MCP-Session-Id', $result['message']);
    }

    /**
     * Test initialize/session creation carries session metadata and release hook.
     */
    public function test_transport_server_creates_session_and_calls_write_close(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('webservice/mcp:use', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();

        $service = (object)[
            'name' => 'Test MCP Service',
            'enabled' => 1,
            'requiredcapability' => null,
            'restrictedusers' => 0,
            'component' => null,
            'timecreated' => time(),
            'timemodified' => time(),
            'shortname' => 'webservice_mcp_connector',
            'downloadfiles' => 0,
            'uploadfiles' => 0,
        ];
        $serviceid = $DB->insert_record('external_services', $service);

        $server = new testable_transport_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $server->set_public_token_for_test('phase2-public-token');
        $server->apply_identity_for_test((object)[
            'user' => $user,
            'restrictedcontext' => context_system::instance(),
            'restrictedservice' => 'webservice_mcp_connector',
        ]);

        $server->set_transport_request_for_test([
            'protocolversion' => protocol_headers::DEFAULT_PROTOCOL_VERSION,
        ]);
        $sessionid = $server->create_transport_session_for_test();
        $session = $server->get_session_for_test($sessionid);

        $this->assertNotEmpty($sessionid);
        $this->assertSame((int)$user->id, (int)$session['userid']);
        $this->assertSame((int)context_system::instance()->id, (int)$session['contextid']);
        $this->assertSame((int)$serviceid, (int)$session['serviceid']);

        $server->release_transport_session_for_test();
        $this->assertTrue($server->writeclosecalled);
    }

    /**
     * Test tools/list transport response includes catalog pagination and coverage metadata.
     */
    public function test_transport_server_tools_list_includes_pagination_and_coverage(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('webservice/mcp:use', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();

        $service = (object)[
            'name' => 'Phase 3 Transport Service',
            'enabled' => 1,
            'requiredcapability' => null,
            'restrictedusers' => 0,
            'component' => null,
            'timecreated' => time(),
            'timemodified' => time(),
            'shortname' => 'phase3_transport_service',
            'downloadfiles' => 0,
            'uploadfiles' => 0,
        ];
        $serviceid = $DB->insert_record('external_services', $service);

        foreach (
            [
                'core_webservice_get_site_info',
                'mod_assign_get_assignments',
                'mod_assign_get_submissions',
            ] as $functionname
        ) {
            $DB->insert_record('external_services_functions', (object)[
                'externalserviceid' => $serviceid,
                'functionname' => $functionname,
            ]);
        }

        $server = new testable_transport_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $server->set_public_token_for_test('phase3-public-token');
        $server->apply_identity_for_test((object)[
            'user' => $user,
            'restrictedcontext' => context_system::instance(),
            'restrictedservice' => 'phase3_transport_service',
        ]);
        $server->set_transport_request_for_test([
            'sessionid' => 'phase3-session',
            'protocolversion' => protocol_headers::DEFAULT_PROTOCOL_VERSION,
        ]);
        $server->set_request_for_test(new request([
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 55,
            'params' => ['limit' => 2],
        ]));

        $server->send_tools_list_for_test();
        $payload = json_decode($server->capturedbody, true);

        $this->assertSame(200, $server->capturedstatus);
        $this->assertCount(2, $payload['result']['tools']);
        $this->assertArrayHasKey('nextCursor', $payload['result']);
        $this->assertArrayHasKey('coverage', $payload['result']);
        $this->assertArrayHasKey('groups', $payload['result']);
        $this->assertNotEmpty($payload['result']['audit']['id']);
        $this->assertArrayHasKey('eligibility', $payload['result']['tools'][0]['x-moodle']);
        $this->assertArrayHasKey('risk', $payload['result']['tools'][0]['x-moodle']);
        $this->assertArrayHasKey('surface', $payload['result']['tools'][0]['x-moodle']);
        $this->assertArrayHasKey('workflow', $payload['result']['tools'][0]['x-moodle']);
        $this->assertArrayHasKey('execution', $payload['result']['tools'][0]['x-moodle']);
        $this->assertTrue($DB->record_exists('webservice_mcp_audit', ['auditid' => $payload['result']['audit']['id']]));
    }

    /**
     * Test connector transport can execute wrapper tools with structured responses.
     */
    public function test_transport_server_can_execute_wrapper_tool(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = context_course::instance($course->id);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $systemroleid = $this->getDataGenerator()->create_role();
        assign_capability('webservice/mcp:use', CAP_ALLOW, $systemroleid, context_system::instance());
        role_assign($systemroleid, $user->id, context_system::instance());

        $courseroleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/course:update', CAP_ALLOW, $courseroleid, $coursecontext);
        assign_capability('moodle/course:manageactivities', CAP_ALLOW, $courseroleid, $coursecontext);
        role_assign($courseroleid, $user->id, $coursecontext);
        accesslib_clear_all_caches_for_unit_testing();

        $service = (object)[
            'name' => 'Phase 6 Wrapper Service',
            'enabled' => 1,
            'requiredcapability' => null,
            'restrictedusers' => 0,
            'component' => null,
            'timecreated' => time(),
            'timemodified' => time(),
            'shortname' => 'phase6_wrapper_service',
            'downloadfiles' => 0,
            'uploadfiles' => 0,
        ];
        $DB->insert_record('external_services', $service);

        $server = new testable_transport_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $server->set_public_token_for_test('phase6-wrapper-token');
        $server->apply_identity_for_test((object)[
            'user' => $user,
            'restrictedcontext' => $coursecontext,
            'restrictedservice' => 'phase6_wrapper_service',
        ]);
        $server->set_transport_request_for_test([
            'sessionid' => 'phase6-wrapper-session',
            'protocolversion' => protocol_headers::DEFAULT_PROTOCOL_VERSION,
        ]);
        $server->set_request_for_test(new request([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 77,
            'params' => ['name' => 'wrapper_course_create_missing_sections'],
        ]));
        $server->set_tool_call_for_test('wrapper_course_create_missing_sections', [
            'courseid' => $course->id,
            'sectionnums' => [1, 2],
        ]);

        $server->execute_wrapper_tool_for_test();
        $payload = json_decode($server->capturedbody, true);

        $this->assertSame(200, $server->capturedstatus);
        $this->assertNotEmpty($payload['result']['audit']['id']);
        $this->assertTrue($payload['result']['structuredContent']['result']['status']);
        $this->assertCount(2, $payload['result']['structuredContent']['result']['sections']);
        $this->assertTrue($DB->record_exists('webservice_mcp_audit', ['auditid' => $payload['result']['audit']['id']]));
    }

    /**
     * Test generated transport errors include structured restriction metadata.
     */
    public function test_transport_server_error_includes_restriction_metadata(): void {
        $this->resetAfterTest(true);

        $server = new testable_transport_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $server->set_request_for_test(new request([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 999,
            'params' => ['name' => 'core_webservice_get_site_info'],
        ]));

        $caperror = $server->generate_error_for_test(
            new required_capability_exception(context_system::instance(), 'moodle/site:config', 'nopermissions', '')
        );
        $contexterror = $server->generate_error_for_test(new restricted_context_exception());

        $this->assertSame('missing_capability', $caperror['error']['data']['restriction']['code']);
        $this->assertSame('capability', $caperror['error']['data']['restriction']['category']);
        $this->assertSame('restricted_context', $contexterror['error']['data']['restriction']['code']);
        $this->assertSame('context', $contexterror['error']['data']['restriction']['category']);
        $this->assertNotEmpty($caperror['error']['data']['auditId']);
        $this->assertNotEmpty($contexterror['error']['data']['auditId']);
    }

    /**
     * Test invalid-token errors emit an OAuth Bearer challenge header.
     */
    public function test_transport_server_send_error_emits_bearer_challenge_for_invalid_token(): void {
        $this->resetAfterTest(true);
        set_config('oauthenabled', 1, 'webservice_mcp');

        $server = new testable_transport_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $server->set_request_for_test(new request([
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'id' => 12,
            'params' => [
                'protocolVersion' => protocol_headers::DEFAULT_PROTOCOL_VERSION,
            ],
        ]));

        set_debugging(DEBUG_NONE);
        $server->send_error_for_test(new moodle_exception('invalidtoken', 'webservice'));
        $this->resetDebugging();

        $this->assertSame(401, $server->capturedstatus);
        $challenge = implode("\n", $server->capturedheaders);
        $this->assertStringContainsString('WWW-Authenticate: Bearer realm="Moodle MCP"', $challenge);
        $this->assertStringContainsString('resource_metadata="', $challenge);
        $this->assertStringContainsString('/webservice/mcp/.well-known/oauth-protected-resource', $challenge);
    }

    /**
     * Test write wrappers are blocked when an OAuth token only grants read scope.
     */
    public function test_transport_server_blocks_write_wrapper_without_write_scope(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = context_course::instance($course->id);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $systemroleid = $this->getDataGenerator()->create_role();
        assign_capability('webservice/mcp:use', CAP_ALLOW, $systemroleid, context_system::instance());
        role_assign($systemroleid, $user->id, context_system::instance());

        $courseroleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/course:update', CAP_ALLOW, $courseroleid, $coursecontext);
        assign_capability('moodle/course:manageactivities', CAP_ALLOW, $courseroleid, $coursecontext);
        role_assign($courseroleid, $user->id, $coursecontext);
        accesslib_clear_all_caches_for_unit_testing();

        $service = (object)[
            'name' => 'OAuth Wrapper Scope Service',
            'enabled' => 1,
            'requiredcapability' => null,
            'restrictedusers' => 0,
            'component' => null,
            'timecreated' => time(),
            'timemodified' => time(),
            'shortname' => 'oauth_wrapper_scope_service',
            'downloadfiles' => 0,
            'uploadfiles' => 0,
        ];
        $DB->insert_record('external_services', $service);

        $server = new testable_transport_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $server->set_public_token_for_test('oauth-readonly-token');
        $server->apply_identity_for_test((object)[
            'user' => $user,
            'restrictedcontext' => $coursecontext,
            'restrictedservice' => 'oauth_wrapper_scope_service',
            'scope' => oauth_service::SCOPE_READ,
            'resourceuri' => (new oauth_service())->canonical_resource_uri(),
            'oauthclientid' => 'mcp_readonly_client',
        ]);
        $server->set_transport_request_for_test([
            'sessionid' => 'oauth-wrapper-session',
            'protocolversion' => protocol_headers::DEFAULT_PROTOCOL_VERSION,
        ]);
        $server->set_request_for_test(new request([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 314,
            'params' => ['name' => 'wrapper_course_create_missing_sections'],
        ]));
        $server->set_tool_call_for_test('wrapper_course_create_missing_sections', [
            'courseid' => $course->id,
            'sectionnums' => [1, 2],
        ]);

        $server->execute_wrapper_tool_for_test();
        $payload = json_decode($server->capturedbody, true);

        $this->assertSame(403, $server->capturedstatus);
        $this->assertSame('mcp:write', $payload['error']['data']['requiredScope']);
        $this->assertStringContainsString('insufficient_scope', implode("\n", $server->capturedheaders));
    }
}
