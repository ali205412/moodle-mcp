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
 * Tests for the UI bridge tools (view, action, submit) over a fake loopback transport.
 *
 * @package    webservice_mcp
 * @copyright  2026 Aspire School
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace webservice_mcp;

use advanced_testcase;
use context_system;
use webservice_mcp\local\auth\connector_service_manager;
use webservice_mcp\local\auth\credential_manager;
use webservice_mcp\local\files\transfer_exception;
use webservice_mcp\local\mcp\call_context;
use webservice_mcp\local\ui\rest_tools;
use webservice_mcp\local\ui\session_bridge;
use webservice_mcp\local\ui\tools;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/session_bridge_fake_transport.php');

/**
 * UI bridge tool tests.
 *
 * @package    webservice_mcp
 * @covers     \webservice_mcp\local\ui\tools
 * @covers     \webservice_mcp\local\ui\rest_tools
 */
final class ui_tools_test extends advanced_testcase {
    /** @var string Sesskey the fake site hands out. */
    private const SESSKEY = 'abc123XYZ';

    /** @var session_bridge_fake_transport Transport. */
    private session_bridge_fake_transport $transport;

    /**
     * Reset the bridge seam.
     */
    protected function tearDown(): void {
        tools::$bridge = null;
        parent::tearDown();
    }

    /**
     * Build an HTML page response.
     *
     * @param string $title Title.
     * @param string $main Main region HTML.
     * @return array
     */
    private static function page(string $title, string $main): array {
        return [
            'status' => 200,
            'headers' => ['content-type' => ['text/html; charset=utf-8']],
            'body' => '<html><head><title>' . $title . '</title><script>M.cfg = {"sesskey":"' . self::SESSKEY . '"};</script>'
                . '</head><body><div id="region-main">' . $main . '</div></body></html>',
        ];
    }

    /**
     * Connector user, service, OAuth family and a context; installs a fake site.
     *
     * @param \Closure $pages Page handler.
     * @param \context|null $restricted Context restriction.
     * @return call_context
     */
    private function setup_site(\Closure $pages, ?\context $restricted = null): call_context {
        global $DB;
        set_config('uibridge', 1, 'webservice_mcp');
        set_config('oauthenabled', 1, 'webservice_mcp');
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('webservice/mcp:use', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);

        $service = (new connector_service_manager())->ensure_service_for_user((int)$user->id);
        $DB->insert_record('webservice_mcp_oauth_client', (object)[
            'timecreated' => time(), 'timemodified' => time(), 'clientid' => 'mcp_ui', 'clientname' => 'UI',
            'redirecturis' => '[]', 'scope' => 'mcp:read mcp:write', 'granttypes' => '[]', 'responsetypes' => '[]',
            'tokenauthmethod' => 'none', 'isdynamic' => 0, 'revoked' => 0,
        ]);
        $credential = (new credential_manager())->issue_oauth_access_token($service, (int)$user->id, context_system::instance(), [
            'oauthclientid' => 'mcp_ui', 'familyid' => 'uitools', 'familycreated' => time(),
        ]);

        $this->transport = new session_bridge_fake_transport($pages);
        tools::$bridge = new session_bridge($this->transport, 1);
        return new call_context(
            call_context::ERA_MODERN,
            '2026-07-28',
            $user,
            $restricted ?? context_system::instance(),
            (int)$service->id,
            true,
            'connector',
            [],
            credential_manager::family_key($credential)
        );
    }

    /**
     * Tools are listed only for full-site connector credentials with the setting on.
     */
    public function test_availability(): void {
        $this->resetAfterTest();
        $ctx = $this->setup_site(fn() => self::page('Home', ''));
        $this->assertSame(
            ['moodle_page_view', 'moodle_page_action', 'moodle_page_submit'],
            array_slice(array_column(tools::describe($ctx), 'name'), 0, 3)
        );
        $view = tools::describe($ctx)[0];
        $this->assertTrue($view['annotations']['readOnlyHint']);
        $this->assertTrue(tools::describe($ctx)[2]['annotations']['destructiveHint']);

        $course = $this->getDataGenerator()->create_course();
        $restricted = new call_context(
            call_context::ERA_MODERN,
            '2026-07-28',
            $ctx->user,
            \context_course::instance($course->id),
            $ctx->serviceid,
            true
        );
        $this->assertSame([], tools::describe($restricted), 'Course-restricted tokens cannot browse arbitrary pages.');

        set_config('uibridge', 0, 'webservice_mcp');
        $this->assertSame([], tools::describe($ctx));
    }

    /**
     * Viewing returns parsed content with the sesskey masked, and refuses state-changing links.
     */
    public function test_view_masks_sesskey(): void {
        $this->resetAfterTest();
        $ctx = $this->setup_site(fn() => self::page('Course: Biology', '<h2>Week 1</h2><p>Cells</p>'
            . '<a href="https://www.example.com/moodle/course/mod.php?hide=5&amp;sesskey=' . self::SESSKEY . '">Hide</a>'));

        $result = tools::execute('moodle_page_view', ['url' => '/course/view.php?id=2'], $ctx);
        $json = json_encode($result);
        $this->assertStringNotContainsString(self::SESSKEY, $json);
        $this->assertStringContainsString('sesskey={sesskey}', $result['structuredContent']['links'][0]['url']);
        $this->assertStringContainsString('Week 1', $result['content'][0]['text']);
        $this->assertSame('/moodle/course/view.php', $result['_meta']['org.moodle/auditdetail']);

        try {
            tools::execute('moodle_page_view', ['url' => '/course/mod.php?hide=5&sesskey={sesskey}'], $ctx);
            $this->fail('Expected a pointer to moodle_page_action');
        } catch (transfer_exception $e) {
            $this->assertSame('uibridgeneedsaction', $e->errorcode);
        }
    }

    /**
     * Action links get the real sesskey substituted back.
     */
    public function test_action_unmasks_sesskey(): void {
        $this->resetAfterTest();
        $ctx = $this->setup_site(fn() => self::page('Done', '<p>Hidden.</p>'));
        tools::execute('moodle_page_action', ['url' => '/course/mod.php?hide=5&sesskey={sesskey}'], $ctx);
        $last = end($this->transport->requests);
        $this->assertStringContainsString('sesskey=' . self::SESSKEY, $last['url']);
        $this->assertStringContainsString('hide=5', $last['url']);
    }

    /**
     * Submitting re-reads the form, keeps defaults, applies changes and returns the resulting page.
     */
    public function test_submit_form(): void {
        $this->resetAfterTest();
        $posted = null;
        $ctx = $this->setup_site(function (string $method, string $url, array $headers, $body) use (&$posted) {
            if ($method === 'POST') {
                if (is_array($body)) {
                    $posted = $body;
                } else {
                    parse_str((string)$body, $posted);
                }
                return self::page('Saved', '<div class="alert alert-success">Changes saved</div>');
            }
            $action = 'https://www.example.com/moodle/course/edit.php';
            return self::page('Edit course', '<form id="mform1" method="post" action="' . $action . '">'
                . '<input type="hidden" name="sesskey" value="' . self::SESSKEY . '">'
                . '<input type="hidden" name="id" value="2">'
                . '<label for="id_fullname">Course full name</label>'
                . '<input type="text" id="id_fullname" name="fullname" value="Biology">'
                . '<label for="id_shortname">Short name</label><input type="text" id="id_shortname" name="shortname" value="BIO">'
                . '<input type="submit" name="saveanddisplay" value="Save and display"></form>');
        });

        $result = tools::execute('moodle_page_submit', ['url' => '/course/edit.php?id=2', 'form' => 'F1',
            'fields' => ['fullname' => 'Biology 101']], $ctx);
        $this->assertSame('Biology 101', $posted['fullname']);
        $this->assertSame('BIO', $posted['shortname'], 'Unchanged fields keep their values.');
        $this->assertSame(self::SESSKEY, $posted['sesskey']);
        $this->assertSame('F1', $result['structuredContent']['submitted']['form']);
        $this->assertStringContainsString('Changes saved', $result['content'][0]['text']);

        try {
            tools::execute('moodle_page_submit', ['url' => '/course/edit.php?id=2', 'form' => 'F9', 'fields' => []], $ctx);
            $this->fail('Expected uibridgeformnotfound');
        } catch (transfer_exception $e) {
            $this->assertSame('uibridgeformnotfound', $e->errorcode);
            $this->assertStringContainsString('F1', $e->getMessage());
        }
    }

    /**
     * Files the page returns (exports, reports) are saved to the user's draft area.
     */
    public function test_download_saved_as_file(): void {
        $this->resetAfterTest();
        $ctx = $this->setup_site(function (string $method, string $url) {
            if (str_contains($url, 'download=csv')) {
                return ['status' => 200, 'headers' => ['content-type' => ['text/csv'],
                    'content-disposition' => ['attachment; filename="logs.csv"']], 'body' => "time,event\n1,viewed\n"];
            }
            return self::page('Home', '');
        });
        $result = tools::execute('moodle_page_view', ['url' => '/report/log/index.php?id=2&download=csv'], $ctx);
        $this->assertSame('file', $result['structuredContent']['type']);
        $this->assertStringStartsWith('moodle://file/', $result['structuredContent']['file']['uri']);
        $this->assertSame('logs.csv', $result['structuredContent']['file']['filename']);
    }

    /**
     * Skip REST tests where Moodle has no routed REST API.
     */
    private function require_rest_api(): void {
        if (!rest_tools::supported()) {
            $this->markTestSkipped('This Moodle has no routed REST API.');
        }
    }

    /**
     * REST tools are listed with the bridge; mutability and scope follow the method.
     */
    public function test_rest_tools_listed_and_scoped(): void {
        $this->resetAfterTest();
        $this->require_rest_api();
        $ctx = $this->setup_site(fn() => ['status' => 200, 'headers' => ['content-type' => ['application/json']], 'body' => '{}']);
        $tools = array_column(tools::describe($ctx), null, 'name');
        $this->assertTrue($tools['moodle_rest_describe']['annotations']['readOnlyHint']);
        $this->assertTrue($tools['moodle_rest_call']['annotations']['destructiveHint']);
        $this->assertFalse(tools::is_mutating('moodle_rest_call', ['path' => '/x']));
        $this->assertFalse(tools::is_mutating('moodle_rest_call', ['method' => 'get', 'path' => '/x']));
        $this->assertTrue(tools::is_mutating('moodle_rest_call', ['method' => 'DELETE', 'path' => '/x']));

        $readonly = new call_context(
            $ctx->era,
            $ctx->protocolversion,
            $ctx->user,
            $ctx->restrictedcontext,
            $ctx->serviceid,
            true,
            'connector',
            [],
            $ctx->credentialid,
            function (bool $write): void {
                if ($write) {
                    throw new transfer_exception(403, 'insufficient_scope', 'Read only.');
                }
            }
        );
        tools::execute('moodle_rest_call', ['path' => '/user/current/preferences'], $readonly);
        try {
            tools::execute(
                'moodle_rest_call',
                ['method' => 'POST', 'path' => '/user/current/preferences', 'body' => []],
                $readonly
            );
            $this->fail('A read-only credential must not POST.');
        } catch (transfer_exception $e) {
            $this->assertSame('insufficient_scope', $e->errorcode);
        }
    }

    /**
     * Describe lists routes from Moodle's OpenAPI description and details one with references resolved.
     */
    public function test_rest_describe(): void {
        $this->resetAfterTest();
        $this->require_rest_api();
        $ctx = $this->setup_site(fn() => self::page('Home', ''));

        $list = tools::execute('moodle_rest_describe', ['search' => 'preferences'], $ctx)['structuredContent'];
        $this->assertStringEndsWith('/api/rest/v2', $list['base']);
        $routes = array_map(fn($r) => $r['method'] . ' ' . $r['path'], $list['routes']);
        $this->assertContains('GET /user/{user}/preferences', $routes);
        $this->assertContains('POST /user/{user}/preferences/{preference}', $routes);

        $detail = tools::execute('moodle_rest_describe', ['path' => '/r.php/api/rest/v2/user/{user}/preferences',
            'method' => 'GET'], $ctx)['structuredContent'];
        $this->assertCount(1, $detail['routes']);
        $this->assertSame('GET', $detail['routes'][0]['method']);
        $this->assertStringNotContainsString('"$ref"', json_encode($detail['routes'][0]['parameters']));
        $this->assertContains('user', array_column($detail['routes'][0]['parameters'], 'name'));

        $this->expectException(transfer_exception::class);
        tools::execute('moodle_rest_describe', ['path' => '/no/such/route'], $ctx);
    }

    /**
     * The REST base and route path the tools build are the ones Moodle's router really serves.
     */
    public function test_rest_path_resolves_in_moodle_router(): void {
        $this->resetAfterTest();
        $this->require_rest_api();
        \core\di::set(\core\router::class, \DI\autowire(\core\router::class)->constructorParameter('basepath', '/r.php'));
        $app = \core\di::get(\core\router::class)->get_app();
        $routing = new \Slim\Middleware\RoutingMiddleware(
            $app->getRouteResolver(),
            $app->getRouteCollector()->getRouteParser()
        );

        $path = '/r.php/api/rest/v2/user/current/preferences';
        $request = $routing->performRouting(new \GuzzleHttp\Psr7\ServerRequest('GET', $path));
        $route = $request->getAttribute(\Slim\Routing\RouteContext::ROUTE);
        $this->assertNotNull($route);
        $this->assertSame(\core_user\route\api\preferences::class . '::get_preferences', $route->getName());
        $request = $routing->performRouting(new \GuzzleHttp\Psr7\ServerRequest('GET', '/r.php/api/rest/v2/openapi.json'));
        $this->assertSame(
            \core\router\apidocs::class . '::openapi_docs',
            $request->getAttribute(\Slim\Routing\RouteContext::ROUTE)->getName()
        );
    }

    /**
     * Calls go through the bridge session with JSON in and out.
     */
    public function test_rest_call_through_bridge(): void {
        $this->resetAfterTest();
        $this->require_rest_api();
        $ctx = $this->setup_site(fn(string $method) => ['status' => $method === 'DELETE' ? 404 : 200,
            'headers' => ['content-type' => ['application/json']],
            'body' => $method === 'DELETE' ? '{"message":"Not found"}' : '{"drawers-open-index":"1"}']);

        $result = tools::execute('moodle_rest_call', ['path' => '/user/current/preferences', 'query' => ['a' => 'b c']], $ctx);
        $last = end($this->transport->requests);
        $this->assertSame('GET', $last['method']);
        $this->assertStringEndsWith('/api/rest/v2/user/current/preferences?a=b%20c', $last['url']);
        $this->assertContains('Accept: application/json', $last['headers']);
        $this->assertNull($last['body']);
        $this->assertSame(['drawers-open-index' => '1'], $result['structuredContent']['data']);
        $this->assertSame('GET /user/current/preferences', $result['_meta']['org.moodle/auditdetail']);

        tools::execute('moodle_rest_call', ['method' => 'POST', 'path' => '/user/current/preferences',
            'body' => ['preferences' => ['drawers-open-index' => '1']]], $ctx);
        $last = end($this->transport->requests);
        $this->assertSame('POST', $last['method']);
        $this->assertSame('{"preferences":{"drawers-open-index":"1"}}', $last['body']);
        $this->assertContains('Content-Type: application/json', $last['headers']);

        $result = tools::execute('moodle_rest_call', ['method' => 'DELETE', 'path' => '/x/1'], $ctx);
        $this->assertSame('DELETE', end($this->transport->requests)['method']);
        $this->assertSame(404, $result['structuredContent']['status']);

        foreach (
            [['path' => '/user/current/preferences?x=1'], ['path' => 'user'], ['path' => '/a', 'body' => ['x' => 1]],
                ['path' => '/a', 'method' => 'TRACE']] as $args
        ) {
            try {
                tools::execute('moodle_rest_call', $args, $ctx);
                $this->fail('Expected invalidparameter for ' . json_encode($args));
            } catch (transfer_exception $e) {
                $this->assertSame('invalidparameter', $e->errorcode);
            }
        }
        try {
            tools::execute('moodle_rest_call', ['path' => '/../../login/logout.php'], $ctx);
            $this->fail('Relative segments must be refused.');
        } catch (transfer_exception $e) {
            $this->assertSame('uibridgeurldenied', $e->errorcode);
        }
    }
}
