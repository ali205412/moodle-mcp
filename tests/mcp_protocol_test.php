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
use webservice_mcp\local\mcp\apps;
use webservice_mcp\local\mcp\call_context;
use webservice_mcp\local\mcp\completions;
use webservice_mcp\local\mcp\dispatcher;
use webservice_mcp\local\mcp\prompts;
use webservice_mcp\local\mcp\protocol_exception;
use webservice_mcp\local\mcp\resources;
use webservice_mcp\local\mcp\server_card;
use webservice_mcp\local\mcp\skills;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/testable_transport_server.php');

/**
 * End-to-end protocol tests for both MCP eras through the transport.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\transport\server
 * @covers      \webservice_mcp\local\transport\protocol_headers
 * @covers      \webservice_mcp\local\mcp\dispatcher
 * @covers      \webservice_mcp\local\mcp\resources
 * @covers      \webservice_mcp\local\mcp\prompts
 * @covers      \webservice_mcp\local\mcp\completions
 * @covers      \webservice_mcp\local\mcp\apps
 * @covers      \webservice_mcp\local\mcp\skills
 * @covers      \webservice_mcp\local\mcp\server_card
 */
final class mcp_protocol_test extends advanced_testcase {
    /** @var array Saved superglobals. */
    private array $savedserver = [];

    /**
     * Save superglobals.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->savedserver = $_SERVER;
    }

    /**
     * Restore superglobals and clean out-of-transaction audit rows.
     */
    protected function tearDown(): void {
        global $DB;
        $_SERVER = $this->savedserver;
        $DB->delete_records('webservice_mcp_audit');
        parent::tearDown();
    }

    /**
     * An enabled service holding every external function, like the synced connector service.
     *
     * @return int Service id.
     */
    private function full_service(): int {
        global $DB;
        $id = $DB->insert_record('external_services', (object)['name' => 'MCP full', 'shortname' => 'mcpfull' . random_string(4),
            'enabled' => 1, 'requiredcapability' => '', 'restrictedusers' => 0, 'timecreated' => time()]);
        $DB->execute("INSERT INTO {external_services_functions} (externalserviceid, functionname)
                      SELECT :sid, name FROM {external_functions}", ['sid' => $id]);
        return $id;
    }

    /**
     * POST a JSON-RPC body through run() and return the decoded response.
     *
     * @param array $body JSON-RPC message.
     * @param array $headers Header name => value.
     * @param bool $authenticated Whether to stub authentication as the current user.
     * @return array{0: testable_transport_server, 1: array|null}
     */
    private function post(array $body, array $headers = [], bool $authenticated = true): array {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        foreach ($headers as $name => $value) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        if ($authenticated) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-token';
        } else {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        }
        $server = new testable_transport_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $server->stubauthentication = true;
        $server->rawbody = json_encode($body);
        $server->run();
        return [$server, json_decode($server->capturedbody, true)];
    }

    /**
     * Modern request envelope with matching headers.
     *
     * @param string $method Method.
     * @param array $params Params.
     * @param array $capabilities Client capabilities.
     * @param int $id Request id.
     * @return array{0: array, 1: array}
     */
    private function modern(string $method, array $params = [], array $capabilities = [], int $id = 1): array {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => (object)$capabilities,
        ];
        $headers = ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];
        if (isset($params['name']) || isset($params['uri'])) {
            $headers['Mcp-Name'] = $params['name'] ?? $params['uri'];
        }
        return [['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], $headers];
    }

    /**
     * server/discover works without authentication and lists both eras.
     */
    public function test_modern_discover_is_public(): void {
        $this->resetAfterTest();
        [$body, $headers] = $this->modern('server/discover');
        [$server, $response] = $this->post($body, $headers, false);

        $this->assertSame(200, $server->capturedstatus);
        $this->assertSame('complete', $response['result']['resultType']);
        $this->assertContains('2026-07-28', $response['result']['supportedVersions']);
        $this->assertContains('2025-11-25', $response['result']['supportedVersions']);
        $this->assertArrayHasKey('tools', $response['result']['capabilities']);
        $this->assertSame('public', $response['result']['cacheScope']);
        $this->assertSame('moodle', $response['result']['_meta']['io.modelcontextprotocol/serverInfo']['name']);
        $this->assertFalse($server->authcalled);
    }

    /**
     * Unknown modern versions get UnsupportedProtocolVersionError with the supported list.
     */
    public function test_modern_unsupported_version(): void {
        $this->resetAfterTest();
        [$body, $headers] = $this->modern('tools/list');
        $body['params']['_meta']['io.modelcontextprotocol/protocolVersion'] = '2099-01-01';
        $headers['MCP-Protocol-Version'] = '2099-01-01';
        [$server, $response] = $this->post($body, $headers);

        $this->assertSame(400, $server->capturedstatus);
        $this->assertSame(protocol_exception::UNSUPPORTED_PROTOCOL_VERSION, $response['error']['code']);
        $this->assertSame('2099-01-01', $response['error']['data']['requested']);
        $this->assertContains('2026-07-28', $response['error']['data']['supported']);
    }

    /**
     * Header/body mismatches are rejected with HeaderMismatch.
     */
    public function test_modern_header_mismatch(): void {
        $this->resetAfterTest();
        [$body, $headers] = $this->modern('tools/list');
        $headers['Mcp-Method'] = 'tools/call';
        [$server, $response] = $this->post($body, $headers);
        $this->assertSame(400, $server->capturedstatus);
        $this->assertSame(protocol_exception::HEADER_MISMATCH, $response['error']['code']);

        [$body, $headers] = $this->modern('tools/call', ['name' => 'x', 'arguments' => []]);
        unset($headers['Mcp-Name']);
        [$server, $response] = $this->post($body, $headers);
        $this->assertSame(400, $server->capturedstatus);
        $this->assertStringContainsString('Mcp-Name', $response['error']['message']);
    }

    /**
     * Base64-sentinel Mcp-Name values are decoded before comparison.
     */
    public function test_modern_base64_mcp_name(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        [$body, $headers] = $this->modern('prompts/get', ['name' => 'my_todo']);
        $headers['Mcp-Name'] = '=?base64?' . base64_encode('my_todo') . '?=';
        [$server, $response] = $this->post($body, $headers);
        $this->assertSame(200, $server->capturedstatus);
        $this->assertNotEmpty($response['result']['messages']);
    }

    /**
     * Unknown modern methods are 404 with -32601; unauthenticated requests are 401 with a challenge.
     */
    public function test_modern_unknown_method_and_auth(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        [$body, $headers] = $this->modern('nope/nothing');
        [$server, $response] = $this->post($body, $headers);
        $this->assertSame(404, $server->capturedstatus);
        $this->assertSame(-32601, $response['error']['code']);

        set_debugging(DEBUG_NONE);
        [$body, $headers] = $this->modern('tools/list');
        [$server] = $this->post($body, $headers, false);
        $this->resetDebugging();
        $this->assertSame(401, $server->capturedstatus);
        $this->assertStringContainsString('WWW-Authenticate: Bearer', implode("\n", $server->capturedheaders));
    }

    /**
     * Modern tools/list returns spec-shaped tools without null cursors.
     */
    public function test_modern_tools_list_shape(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$body, $headers] = $this->modern('tools/list');
        [$server, $response] = $this->post($body, $headers);

        $this->assertSame(200, $server->capturedstatus);
        $this->assertSame('private', $response['result']['cacheScope']);
        $this->assertArrayNotHasKey('nextCursor', $response['result']);
        foreach ($response['result']['tools'] as $tool) {
            $this->assertArrayNotHasKey('x-moodle', $tool);
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,128}$/', $tool['name']);
            $this->assertSame('object', $tool['inputSchema']['type']);
        }
    }

    /**
     * Unknown tools are protocol errors; failing tools are isError results.
     */
    public function test_tool_errors(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        [$body, $headers] = $this->modern('tools/call', ['name' => 'no_such_function_anywhere', 'arguments' => []]);
        [, $response] = $this->post($body, $headers);
        $this->assertSame(protocol_exception::INVALID_PARAMS, $response['error']['code']);

        // A real function that this user may not call: Moodle refuses, and the model gets an isError result.
        [$body, $headers] = $this->modern('tools/call', ['name' => 'core_user_delete_users', 'arguments' => ['userids' => [2]]]);
        [$server, $response] = $this->post($body, $headers);
        $this->assertSame(200, $server->capturedstatus);
        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('Error [', $response['result']['content'][0]['text']);
    }

    /**
     * Destructive calls from elicitation-capable modern clients go through MRTR confirmation.
     */
    public function test_destructive_call_requires_confirmation(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        set_config('confirmdestructive', 1, 'webservice_mcp');
        $caps = ['elicitation' => ['form' => (object)[]]];
        $args = ['userids' => [2]];

        [$body, $headers] = $this->modern('tools/call', ['name' => 'core_user_delete_users', 'arguments' => $args], $caps);
        [, $response] = $this->post($body, $headers);
        $this->assertSame('input_required', $response['result']['resultType']);
        $request = $response['result']['inputRequests']['confirm'];
        $this->assertSame('elicitation/create', $request['method']);
        $state = $response['result']['requestState'];

        // Declined: no execution, an isError result.
        [$body, $headers] = $this->modern('tools/call', [
            'name' => 'core_user_delete_users', 'arguments' => $args, 'requestState' => $state,
            'inputResponses' => ['confirm' => ['action' => 'decline']],
        ], $caps, 2);
        [, $response] = $this->post($body, $headers);
        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('declined', $response['result']['content'][0]['text']);

        // The state is single-use: replaying it with "accept" after a decline is refused.
        [$body, $headers] = $this->modern('tools/call', [
            'name' => 'core_user_delete_users', 'arguments' => $args, 'requestState' => $state,
            'inputResponses' => ['confirm' => ['action' => 'accept', 'content' => ['confirm' => true]]],
        ], $caps, 4);
        [, $response] = $this->post($body, $headers);
        $this->assertSame(protocol_exception::INVALID_PARAMS, $response['error']['code']);
        $this->assertStringContainsString('already used', $response['error']['message']);

        // Tampered arguments invalidate the state.
        [$body, $headers] = $this->modern('tools/call', [
            'name' => 'core_user_delete_users', 'arguments' => ['userids' => [3]], 'requestState' => $state,
            'inputResponses' => ['confirm' => ['action' => 'accept', 'content' => ['confirm' => true]]],
        ], $caps, 3);
        [, $response] = $this->post($body, $headers);
        $this->assertSame(protocol_exception::INVALID_PARAMS, $response['error']['code']);
    }

    /**
     * Legacy initialize always mints its own session id, then ping/unknown methods behave per spec.
     */
    public function test_legacy_session_flow(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        [$server, $response] = $this->post(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object)[],
                    'clientInfo' => ['name' => 't', 'version' => '1']]],
            ['Mcp-Session-Id' => 'attacker-chosen-session']
        );
        $this->assertSame('2025-06-18', $response['result']['protocolVersion']);
        $this->assertArrayHasKey('prompts', $response['result']['capabilities']);
        $sessionheader = preg_grep('/^MCP-Session-Id: /', $server->capturedheaders);
        $sessionid = substr(reset($sessionheader), strlen('MCP-Session-Id: '));
        $this->assertNotSame('attacker-chosen-session', $sessionid);

        $legacy = ['Mcp-Session-Id' => $sessionid, 'MCP-Protocol-Version' => '2025-06-18'];
        [$server, $response] = $this->post(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'], $legacy + ['Mcp-Method' => 'ping']);
        $this->assertSame(200, $server->capturedstatus);
        $this->assertSame('{"jsonrpc":"2.0","id":2,"result":{}}', $server->capturedbody);

        [$server, $response] = $this->post(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'nope'], $legacy + ['Mcp-Method' => 'nope']);
        $this->assertSame(-32601, $response['error']['code']);

        [$server] = $this->post(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            $legacy + ['Mcp-Method' => 'notifications/initialized']
        );
        $this->assertSame(202, $server->capturedstatus);
    }

    /**
     * Resources: list, templates and permission-respecting course reads.
     */
    public function test_resources(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Biology 101']);
        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        $_POST['sesskey'] = sesskey();

        $ctx = new call_context(
            call_context::ERA_MODERN,
            '2026-07-28',
            $user,
            context_system::instance(),
            $this->full_service()
        );
        $resources = new resources($ctx);

        $uris = array_column($resources->list()['resources'], 'uri');
        $this->assertContains('moodle://course/' . $course->id, $uris);
        $this->assertNotContains('moodle://course/' . $other->id, $uris);
        $this->assertNotEmpty($resources->templates()['resourceTemplates']);

        $read = $resources->read('moodle://course/' . $course->id);
        $data = json_decode($read['contents'][0]['text'], true);
        $this->assertSame('Biology 101', $data['course']['fullname']);

        $this->expectException(protocol_exception::class);
        $resources->read('moodle://nothing/here');
    }

    /**
     * Prompts and completions.
     */
    public function test_prompts_and_completions(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Chemistry']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        $_POST['sesskey'] = sesskey();
        $ctx = new call_context(call_context::ERA_LEGACY, '2025-06-18', $user, context_system::instance());

        $names = array_column((new prompts($ctx))->list()['prompts'], 'name');
        $this->assertContains('course_overview', $names);

        $prompt = (new prompts($ctx))->get('course_overview', ['courseid' => (string)$course->id]);
        $this->assertStringContainsString('moodle://course/' . $course->id, $prompt['messages'][0]['content']['text']);
        $this->assertSame('resource', $prompt['messages'][1]['content']['type']);

        try {
            (new prompts($ctx))->get('course_overview', []);
            $this->fail('Missing required argument accepted');
        } catch (protocol_exception $e) {
            $this->assertSame(protocol_exception::INVALID_PARAMS, $e->rpccode);
        }

        $completion = (new completions($ctx))->complete([
            'ref' => ['type' => 'ref/prompt', 'name' => 'course_overview'],
            'argument' => ['name' => 'courseid', 'value' => 'chem'],
        ]);
        $this->assertSame([(string)$course->id], $completion['completion']['values']);
    }

    /**
     * Dispatcher era finalisation strips modern-only fields for legacy clients.
     */
    public function test_finalisation_per_era(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $legacy = (new dispatcher(new call_context(call_context::ERA_LEGACY, '2025-11-25', $user)))->dispatch('prompts/list', []);
        $this->assertArrayNotHasKey('resultType', $legacy);
        $this->assertArrayNotHasKey('ttlMs', $legacy);

        $modern = (new dispatcher(new call_context(call_context::ERA_MODERN, '2026-07-28', $user)))->dispatch('prompts/list', []);
        $this->assertSame('complete', $modern['resultType']);
        $this->assertArrayHasKey('ttlMs', $modern);
    }

    /**
     * The Explorer MCP App: linked UI resource, courses view and course view.
     */
    public function test_explorer_app(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Physics']);
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Week 1 notes']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        $_POST['sesskey'] = sesskey();
        $ctx = new call_context(
            call_context::ERA_MODERN,
            '2026-07-28',
            $user,
            context_system::instance(),
            $this->full_service()
        );

        $tool = apps::tools()[0];
        $this->assertSame(apps::EXPLORER_URI, $tool['_meta']['ui']['resourceUri']);

        $html = (new resources($ctx))->read(apps::EXPLORER_URI);
        $this->assertSame(apps::MIMETYPE, $html['contents'][0]['mimeType']);
        $this->assertStringContainsString('ui/initialize', $html['contents'][0]['text']);

        $list = apps::execute([], $ctx);
        $this->assertSame('Physics', $list['structuredContent']['courses'][0]['fullname']);

        $view = apps::execute(['view' => 'course', 'courseid' => $course->id], $ctx);
        $this->assertSame('course', $view['structuredContent']['view']);
        $names = [];
        foreach ($view['structuredContent']['sections'] as $section) {
            $names = array_merge($names, array_column($section['modules'], 'name'));
        }
        $this->assertContains('Week 1 notes', $names);
        $this->assertStringContainsString('Week 1 notes', $view['content'][0]['text']);

        // The app gets links, readable activity types and visibility for every module.
        $this->assertStringContainsString('/course/view.php?id=' . $course->id, $view['structuredContent']['course']['url']);
        $page = null;
        foreach ($view['structuredContent']['sections'] as $section) {
            foreach ($section['modules'] as $module) {
                $page = $module['name'] === 'Week 1 notes' ? $module : $page;
            }
        }
        $this->assertSame('Page', $page['modlabel']);
        $this->assertTrue($page['visible']);
        $this->assertStringContainsString('/mod/page/view.php', $page['url']);
        $this->assertStringContainsString('/course/view.php?id=' . $course->id, $list['structuredContent']['courses'][0]['url']);

        // Passing courseid alone opens that course instead of silently returning the course list.
        $this->assertSame('course', apps::execute(['courseid' => $course->id], $ctx)['structuredContent']['view']);
    }

    /**
     * Skills: manifests match the served bytes, lookup works, traversal is refused.
     */
    public function test_skills(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $ctx = new call_context(call_context::ERA_MODERN, '2026-07-28', $user, context_system::instance());

        $list = skills::list();
        $names = array_map(static fn(array $skill): string => $skill['frontmatter']['name'], $list['skills']);
        $this->assertContains('moodle-file-transfer', $names);

        foreach ($list['skills'] as $skill) {
            $this->assertStringEndsWith($skill['frontmatter']['name'] . '/SKILL.md', $skill['uri']);
            foreach ($skill['resources'] as $file) {
                $content = (new resources($ctx))->read($file['uri'])['contents'][0]['text'];
                $this->assertSame($file['size'], strlen($content));
                $this->assertSame($file['digest'], 'sha256:' . hash('sha256', $content));
            }
        }

        $this->assertSame('moodle-grading', skills::get('skill://moodle-grading/SKILL.md')['skill']['frontmatter']['name']);
        $children = array_column(skills::read_directory('skill://moodle-api-gateway')['resources'], 'name');
        $this->assertContains('references', $children);

        $this->assertNull(skills::read('skill://moodle-grading/../../version.php'));
        $this->expectException(protocol_exception::class);
        skills::get('skill://nope/SKILL.md');
    }

    /**
     * The server card is schema-shaped and public.
     */
    public function test_server_card(): void {
        $this->resetAfterTest();
        $card = server_card::build();
        $this->assertMatchesRegularExpression('#^[a-zA-Z0-9.-]+/[a-zA-Z0-9._-]+$#', $card['name']);
        $this->assertNotEmpty($card['version']);
        $this->assertStringEndsWith('/webservice/mcp/server.php', $card['remotes'][0]['url']);
        $this->assertContains('2026-07-28', $card['remotes'][0]['supportedProtocolVersions']);
    }
}
