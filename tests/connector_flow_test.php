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
 * PHPUnit tests for the bootstrap-to-transport connector flow.
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
use stdClass;
use webservice_mcp\local\auth\bootstrap_service;
use webservice_mcp\local\auth\transport_identity;
use webservice_mcp\local\request;
use webservice_mcp\local\transport\protocol_headers;

require_once(__DIR__ . '/fixtures/flow_test_transport_server.php');

/**
 * End-to-end style connector flow coverage.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\auth\bootstrap_service
 * @covers      \webservice_mcp\local\transport\server
 */
final class connector_flow_test extends advanced_testcase {
    /**
     * Test bootstrap credentials can drive discovery and wrapped authoring calls.
     */
    public function test_bootstrap_flow_supports_tools_list_and_wrapper_call(): void {
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
            'name' => 'Connector Flow Service',
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
        $DB->insert_record('external_services_functions', (object)[
            'externalserviceid' => $serviceid,
            'functionname' => 'core_webservice_get_site_info',
        ]);

        $this->setUser($user);

        $bootstrap = new bootstrap_service();
        $credential = $bootstrap->issue_bootstrap_for_current_user($coursecontext);
        $identity = (new transport_identity())->resolve($credential->token);

        $server = new flow_test_transport_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $server->set_public_token_for_test($credential->token);
        $server->apply_identity_for_test($identity);
        $server->set_transport_request_for_test([
            'sessionid' => 'connector-flow-session',
            'protocolversion' => protocol_headers::DEFAULT_PROTOCOL_VERSION,
        ]);
        $server->set_request_for_test(new request([
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 101,
            'params' => ['limit' => 250],
        ]));

        $server->send_tools_list_for_test();
        $listpayload = json_decode($server->capturedbody, true);
        $tools = array_column($listpayload['result']['tools'], null, 'name');
        $harvestedtools = array_filter(
            array_keys($tools),
            static fn(string $toolname): bool => !str_starts_with($toolname, 'wrapper_')
        );

        $this->assertNotEmpty($harvestedtools);
        $this->assertNotEmpty($listpayload['result']['audit']['id']);

        $server->reset_capture_for_test();
        $server->set_request_for_test(new request([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 102,
            'params' => ['name' => 'wrapper_course_create_missing_sections'],
        ]));
        $server->set_tool_call_for_test('wrapper_course_create_missing_sections', [
            'courseid' => $course->id,
            'sectionnums' => [1, 2],
        ]);
        $server->execute_wrapper_tool_for_test();
        $callpayload = json_decode($server->capturedbody, true);

        $this->assertNotEmpty($callpayload['result']['audit']['id']);
        $this->assertTrue($callpayload['result']['structuredContent']['result']['status']);
        $this->assertCount(2, $callpayload['result']['structuredContent']['result']['sections']);
    }
}
