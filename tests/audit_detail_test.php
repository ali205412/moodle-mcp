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
use moodle_exception;
use webservice_mcp\local\audit\logger;
use webservice_mcp\local\request;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/testable_transport_server.php');

/**
 * Audit rows keep the error message of failed requests.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\audit\logger
 * @covers      \webservice_mcp\local\transport\server
 */
final class audit_detail_test extends advanced_testcase {
    /**
     * The logger stores the detail it is given as a single, truncated line (callers decide what to pass).
     */
    public function test_logger_stores_detail_for_failures_only(): void {
        global $DB;

        $this->resetAfterTest(true);
        $logger = new logger();
        $failed = $logger->record([
            'userid' => 2,
            'outcome' => 'error',
            'detailcode' => 'invalidparameter',
            'detail' => "Line one\nline two\r\n" . str_repeat('x', 400),
        ]);
        $ok = $logger->record(['userid' => 2, 'outcome' => 'success']);

        $detail = $DB->get_field('webservice_mcp_audit', 'detail', ['auditid' => $failed]);
        $this->assertStringStartsWith('Line one line two xxx', $detail);
        $this->assertSame(255, \core_text::strlen($detail));
        $this->assertNull($DB->get_field('webservice_mcp_audit', 'detail', ['auditid' => $ok]));
        $this->assertNull(logger::clean_detail("  \n "));
    }

    /**
     * A failed tool call is audited with the user-facing message, never the debug info carrying argument values.
     */
    public function test_transport_error_audit_records_message_without_debuginfo(): void {
        global $DB;

        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('webservice/mcp:use', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();
        $DB->insert_record('external_services', (object)[
            'name' => 'Audit service',
            'enabled' => 1,
            'restrictedusers' => 0,
            'shortname' => 'audit_detail_service',
            'timecreated' => time(),
        ]);

        $server = new testable_transport_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $server->apply_identity_for_test((object)[
            'user' => $user,
            'restrictedcontext' => context_system::instance(),
            'restrictedservice' => 'audit_detail_service',
            'scope' => '',
            'resourceuri' => null,
            'oauthclientid' => null,
        ]);
        $server->set_request_for_test(new request([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 7,
            'params' => ['name' => 'core_course_get_courses'],
        ]));

        $error = $server->generate_error_for_test(
            new moodle_exception('invalidparameter', 'debug', '', null, 'secret argument hunter2')
        );

        $row = $DB->get_record('webservice_mcp_audit', ['auditid' => $error['error']['data']['auditId']], '*', MUST_EXIST);
        $this->assertSame('error', $row->outcome);
        $this->assertSame('invalidparameter', $row->detailcode);
        $this->assertSame(get_string('invalidparameter', 'debug'), $row->detail);
        $this->assertStringNotContainsString('hunter2', (string)$row->detail);
    }

    /**
     * A request with an unknown bearer token produces exactly one audit row with detailcode invalid_token; expired
     * tokens are attributed to their credential; requests without any token are not audited.
     */
    public function test_rejected_tokens_are_audited(): void {
        global $DB;

        $this->resetAfterTest(true);
        set_debugging(DEBUG_NONE);
        $DB->delete_records('webservice_mcp_audit');

        $server = new testable_transport_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $server->set_public_token_for_test('unknown-token-' . random_string(16));
        $server->send_error_for_test(new moodle_exception('invalidtoken', 'webservice'));
        $this->assertSame(401, $server->capturedstatus);
        $rows = $DB->get_records('webservice_mcp_audit');
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('invalid_token', $row->detailcode);
        $this->assertSame('request', $row->action);
        $this->assertSame('error', $row->outcome);
        $this->assertNull($row->userid);
        $this->assertSame(get_string('invalidtoken', 'webservice'), $row->detail);

        $user = $this->getDataGenerator()->create_user();
        $credential = (new \webservice_mcp\local\auth\credential_manager())->issue_durable_grant(
            (object)['shortname' => 'webservice_mcp_connector'],
            (int)$user->id,
            context_system::instance(),
            ['validuntil' => time() - 60]
        );
        $server = new testable_transport_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $server->set_public_token_for_test($credential->token);
        $server->send_error_for_test(new moodle_exception('invalidtoken', 'webservice'));
        $row = $DB->get_record('webservice_mcp_audit', ['credentialid' => $credential->id], '*', MUST_EXIST);
        $this->assertSame('token_expired', $row->detailcode);
        $this->assertSame((int)$user->id, (int)$row->userid);

        $server = new testable_transport_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $server->send_error_for_test(new moodle_exception('invalidtoken', 'webservice'));
        $this->assertSame(2, $DB->count_records('webservice_mcp_audit'));
        $this->resetDebugging();
    }

    /**
     * A successful UI bridge tool result stores its page path as the audit detail; the _meta key never reaches the
     * client.
     */
    public function test_bridge_audit_detail_is_stored_and_stripped(): void {
        global $DB;

        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('webservice/mcp:use', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();
        $DB->insert_record('external_services', (object)[
            'name' => 'Bridge audit service',
            'enabled' => 1,
            'restrictedusers' => 0,
            'shortname' => 'bridge_audit_service',
            'timecreated' => time(),
        ]);

        $server = new testable_transport_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $server->apply_identity_for_test((object)[
            'user' => $user,
            'restrictedcontext' => context_system::instance(),
            'restrictedservice' => 'bridge_audit_service',
            'scope' => '',
            'resourceuri' => null,
            'oauthclientid' => null,
        ]);
        $server->set_request_for_test(new request([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 11,
            'params' => ['name' => 'moodle_page_view'],
        ]));
        $server->set_tool_call_for_test('moodle_page_view', ['url' => '/course/view.php?id=4']);

        $audit = new \ReflectionMethod($server, 'audit_dispatch_result');
        $audit->setAccessible(true);
        $result = $audit->invoke($server, 'tools/call', [
            'content' => [['type' => 'text', 'text' => 'Course 4']],
            '_meta' => ['org.moodle/auditdetail' => '/moodle/course/view.php'],
        ]);

        $this->assertArrayNotHasKey('org.moodle/auditdetail', $result['_meta']);
        $row = $DB->get_record('webservice_mcp_audit', ['auditid' => $result['_meta']['org.moodle/auditId']], '*', MUST_EXIST);
        $this->assertSame('success', $row->outcome);
        $this->assertSame('moodle_page_view', $row->toolname);
        $this->assertSame('/moodle/course/view.php', $row->detail);

        // Without a detail, a success stores none, and an emptied _meta disappears.
        $plain = $audit->invoke($server, 'resources/list', ['_meta' => ['org.moodle/auditdetail' => '/x']]);
        $this->assertArrayNotHasKey('_meta', $plain);
    }
}
