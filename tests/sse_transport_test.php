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
 * PHPUnit tests for SSE transport compatibility behavior.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace webservice_mcp;

use advanced_testcase;
use context_system;
use stdClass;
use webservice_mcp\local\auth\credential_manager;
use webservice_mcp\local\auth\transport_identity;
use webservice_mcp\local\transport\protocol_headers;

require_once(__DIR__ . '/fixtures/testable_sse_controller.php');

/**
 * Tests for SSE transport compatibility behavior.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\transport\sse_controller
 */
final class sse_transport_test extends advanced_testcase {
    /**
     * Test SSE compatibility mode must be enabled explicitly.
     */
    public function test_sse_transport_requires_enabled_compatibility_mode(): void {
        $this->resetAfterTest(true);

        set_config('enablelegacysse', 0, 'webservice_mcp');

        $controller = new testable_sse_controller(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $previousget = $_GET;
        $previousserver = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $controller->run();

        $_GET = $previousget;
        $_SERVER = $previousserver;

        $this->assertSame(404, $controller->capturedstatus);
        $this->assertStringContainsString('disabled', $controller->capturedbody);
        $this->assertFalse($controller->authcalled);
    }

    /**
     * Test SSE compatibility replays buffered events for a valid session.
     */
    public function test_sse_transport_replays_session_events_for_valid_compatibility_request(): void {
        global $DB;

        $this->resetAfterTest(true);

        set_config('enablelegacysse', 1, 'webservice_mcp');

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
        $DB->insert_record('external_services', $service);

        $manager = new credential_manager();
        $credential = $manager->issue_bootstrap_credential(
            (object)['shortname' => 'webservice_mcp_connector'],
            $user->id,
            context_system::instance(),
            ['sid' => null]
        );
        $identity = (new transport_identity($manager))->resolve($credential->token);

        $controller = new testable_sse_controller(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $controller->set_public_token_for_test($credential->token);
        $controller->apply_identity_for_test($identity);
        $controller->set_transport_request_for_test([
            'protocolversion' => protocol_headers::DEFAULT_PROTOCOL_VERSION,
        ]);

        $sessionid = $controller->create_transport_session_for_test();
        $controller->append_replay_event_for_test($sessionid, [
            'type' => 'message',
            'payload' => [
                'jsonrpc' => '2.0',
                'id' => 99,
                'result' => ['ok' => true],
            ],
        ]);

        $previousget = $_GET;
        $previousserver = $_SERVER;

        $_GET['wstoken'] = $credential->token;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_MCP_SESSION_ID'] = $sessionid;
        $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] = protocol_headers::DEFAULT_PROTOCOL_VERSION;

        $controller->run();

        $_GET = $previousget;
        $_SERVER = $previousserver;

        $this->assertTrue($controller->authcalled);
        $this->assertSame(200, $controller->capturedstatus);
        $this->assertStringContainsString('Content-Type: text/event-stream; charset=utf-8', implode("\n", $controller->capturedheaders));
        $this->assertStringContainsString('event: message', $controller->capturedbody);
        $this->assertStringContainsString('id: 1', $controller->capturedbody);
        $this->assertStringContainsString('"result":{"ok":true}', $controller->capturedbody);
    }
}
