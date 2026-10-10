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
 * Tests for the UI session bridge.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace webservice_mcp;

use advanced_testcase;
use context_course;
use context_system;
use stdClass;
use webservice_mcp\local\auth\connector_service_manager;
use webservice_mcp\local\auth\credential_manager;
use webservice_mcp\local\files\transfer_exception;
use webservice_mcp\local\mcp\call_context;
use webservice_mcp\local\ui\session_bridge;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/session_bridge_fake_transport.php');

/**
 * Tests for the UI session bridge (policy, scope, sessions, revocation) with a fake loopback transport.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\ui\session_bridge
 * @covers      \webservice_mcp\observer
 */
final class session_bridge_test extends advanced_testcase {
    /**
     * An HTML page carrying a sesskey like Moodle's M.cfg.
     *
     * @param string $title Title.
     * @return array
     */
    private static function page(string $title = 'Dashboard'): array {
        return [
            'status' => 200,
            'headers' => ['content-type' => ['text/html; charset=utf-8']],
            'body' => '<html><head><title>' . $title . '</title><script>M.cfg = {"sesskey":"abc123XYZ","wwwroot":"x"};'
                . '</script></head><body>' . $title . '</body></html>',
        ];
    }

    /**
     * Create a user, connector service and OAuth credential family, and a call context for them.
     *
     * @param \Closure|null $scopecheck Scope check.
     * @param \context|null $restricted Restricted context.
     * @return array [call_context, stdClass user, string family]
     */
    private function setup_bridge(?\Closure $scopecheck = null, ?\context $restricted = null): array {
        set_config('uibridge', 1, 'webservice_mcp');
        set_config('oauthenabled', 1, 'webservice_mcp');
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('webservice/mcp:use', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();

        $service = (new connector_service_manager())->ensure_service_for_user((int)$user->id);
        global $DB;
        if (!$DB->record_exists('webservice_mcp_oauth_client', ['clientid' => 'mcp_ui'])) {
            $DB->insert_record('webservice_mcp_oauth_client', (object)[
            'timecreated' => time(), 'timemodified' => time(), 'clientid' => 'mcp_ui', 'clientname' => 'UI',
            'redirecturis' => '[]', 'scope' => 'mcp:read mcp:write', 'granttypes' => '[]', 'responsetypes' => '[]',
            'tokenauthmethod' => 'none', 'isdynamic' => 0, 'revoked' => 0,
            ]);
        }
        $credential = (new credential_manager())->issue_oauth_access_token($service, (int)$user->id, context_system::instance(), [
            'oauthclientid' => 'mcp_ui',
            'familyid' => 'uifamily',
            'familycreated' => time(),
        ]);
        $family = credential_manager::family_key($credential);

        $ctx = new call_context(
            call_context::ERA_MODERN,
            '2026-07-28',
            $user,
            $restricted ?? context_system::instance(),
            (int)$service->id,
            true,
            'connector',
            [],
            $family,
            $scopecheck
        );
        return [$ctx, $user, $family];
    }

    /**
     * The URL policy: same origin under wwwroot, deny list, no userinfo, no tricks; fragments dropped.
     */
    public function test_url_policy(): void {
        $this->resetAfterTest(true);

        $this->assertSame(
            'https://www.example.com/moodle/course/view.php?id=4',
            session_bridge::check_url('/course/view.php?id=4#section-2')
        );
        $this->assertSame('https://www.example.com/moodle/my/', session_bridge::check_url('https://www.example.com/moodle/my/'));

        $denied = [
            'https://evil.example/moodle/my/',
            'http://www.example.com/moodle/my/',
            'https://www.example.com:8443/moodle/my/',
            'https://user:pass@www.example.com/moodle/my/',
            'https://www.example.com/other/index.php',
            '/login/index.php',
            '/login/logout.php?sesskey=x',
            '/login',
            '/webservice/mcp/server.php',
            '/webservice/rest/server.php',
            '/admin/tool/mobile/launch.php',
            '/user/managetoken.php',
            '/lib/ajax/service.php',
            '/pluginfile.php/1/user/private/a.txt',
            '/draftfile.php/5/user/draft/1/a.txt',
            '/course/../login/index.php',
            '/course/%2e%2e/login/index.php',
            '/course//view.php',
            '/course/view.php?id=1 2',
            'javascript:alert(1)',
        ];
        foreach ($denied as $url) {
            try {
                session_bridge::check_url($url);
                $this->fail('Allowed ' . $url);
            } catch (transfer_exception $exception) {
                $this->assertSame('uibridgeurldenied', $exception->errorcode, $url);
                $this->assertSame(403, $exception->status);
            }
        }
    }

    /**
     * A first fetch logs in once with a loopback-bound key; later fetches reuse the session cookie.
     */
    public function test_fetch_logs_in_once_and_reuses_session(): void {
        global $DB;

        $this->resetAfterTest(true);
        [$ctx, $user] = $this->setup_bridge();
        $transport = new session_bridge_fake_transport(fn() => self::page());
        $bridge = new session_bridge($transport, 0);

        $first = $bridge->fetch($ctx, 'GET', '/my/');
        $second = $bridge->fetch($ctx, 'GET', '/course/index.php');

        $this->assertSame(1, $transport->logins);
        $this->assertSame(200, $first['status']);
        $this->assertSame('https://www.example.com/moodle/course/index.php', $second['url']);
        $this->assertSame('abc123XYZ', $second['sesskey']);
        $this->assertStringStartsWith('text/html', $second['contenttype']);
        $this->assertContains('Cookie: MoodleSession=sid1', $transport->requests[2]['headers']);
        $this->assertSame('abc123XYZ', $bridge->sesskey($ctx));
        $this->assertCount(3, $transport->requests, 'sesskey() reuses the cached session key.');
        $this->assertSame(
            0,
            $DB->count_records('user_private_key', ['script' => session_bridge::KEY_SCRIPT]),
            'Login keys are single-use.'
        );
    }

    /**
     * Login keys are bound to the loopback IP, single-use, short-lived, and tied to the bridge's own handoff.
     */
    public function test_login_key_binding(): void {
        $this->resetAfterTest(true);
        [$ctx, $user, $family] = $this->setup_bridge();

        $transport = new session_bridge_fake_transport(fn() => self::page());
        $transport->remoteaddr = '203.0.113.9';
        try {
            (new session_bridge($transport, 0))->fetch($ctx, 'GET', '/my/');
            $this->fail('A key redeemed from another address must not log in.');
        } catch (transfer_exception $exception) {
            $this->assertSame('uibridgeloginfailed', $exception->errorcode);
        }

        $savedaddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        try {
            $this->check_bad_keys((int)$user->id, $family);
        } finally {
            if ($savedaddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $savedaddr;
            }
        }
    }

    /**
     * Redeem expired, orphaned, wrong-user and reused keys; all must fail.
     *
     * @param int $userid User id.
     * @param string $family Family key.
     * @return void
     */
    private function check_bad_keys(int $userid, string $family): void {
        $user = (object)['id' => $userid];
        $expired = create_user_key(session_bridge::KEY_SCRIPT, (int)$user->id, null, '127.0.0.1', time() - 1);
        \cache::make('webservice_mcp', 'uibridge_session')->set('login_' . sha1($expired), $family);
        $orphan = create_user_key(session_bridge::KEY_SCRIPT, (int)$user->id, null, '127.0.0.1', time() + 30);
        $valid = create_user_key(session_bridge::KEY_SCRIPT, (int)$user->id, null, '127.0.0.1', time() + 30);
        \cache::make('webservice_mcp', 'uibridge_session')->set('login_' . sha1($valid), $family);

        foreach ([[$expired, (int)$user->id], [$orphan, (int)$user->id], [$valid, (int)$user->id + 1]] as [$key, $userid]) {
            try {
                session_bridge::redeem_login_key($userid, $key);
                $this->fail('Redeemed an invalid key.');
            } catch (\moodle_exception $exception) {
                $this->assertContains($exception->errorcode, ['expiredkey', 'invalidkey']);
            }
        }
        // The valid key was consumed by the wrong-user attempt.
        try {
            session_bridge::redeem_login_key((int)$user->id, $valid);
            $this->fail('Redeemed a used key.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('invalidkey', $exception->errorcode);
        }
    }

    /**
     * GET needs read scope; POST and sesskey links need write scope.
     */
    public function test_scope_rules(): void {
        $this->resetAfterTest(true);
        $needed = [];
        $scopecheck = function (bool $write) use (&$needed): void {
            $needed[] = $write;
            if ($write) {
                throw new transfer_exception(403, 'insufficient_scope', 'write scope needed');
            }
        };
        [$ctx] = $this->setup_bridge($scopecheck);
        $bridge = new session_bridge(new session_bridge_fake_transport(fn() => self::page()), 0);

        $bridge->fetch($ctx, 'GET', '/my/');
        foreach ([['POST', '/course/edit.php'], ['GET', '/course/view.php?id=2&sesskey=abc123XYZ&hide=1']] as [$method, $url]) {
            try {
                $bridge->fetch($ctx, $method, $url, ['a' => 'b']);
                $this->fail("{$method} {$url} needs write scope.");
            } catch (transfer_exception $exception) {
                $this->assertSame('insufficient_scope', $exception->errorcode);
            }
        }
        $this->assertSame([false, true, true], $needed);
    }

    /**
     * Restricted-context tokens, raw tokens, the site switch, the capability and withdrawn service access are refused.
     */
    public function test_access_refusals(): void {
        global $DB;

        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        [$restricted] = $this->setup_bridge(null, context_course::instance($course->id));
        $bridge = new session_bridge(new session_bridge_fake_transport(fn() => self::page()), 0);
        $this->assert_refused('uibridgerestrictedcontext', fn() => $bridge->fetch($restricted, 'GET', '/my/'));

        [$ctx, $user] = $this->setup_bridge();
        $raw = new call_context(call_context::ERA_MODERN, '2026-07-28', $user, null, $ctx->serviceid, false);
        $this->assert_refused('uibridgenotconnector', fn() => $bridge->fetch($raw, 'GET', '/my/'));

        set_config('uibridge', 0, 'webservice_mcp');
        $this->assert_refused('uibridgedisabled', fn() => $bridge->fetch($ctx, 'GET', '/my/'));
        set_config('uibridge', 1, 'webservice_mcp');

        $DB->set_field('external_services', 'enabled', 0, ['id' => $ctx->serviceid]);
        $this->assert_refused('webservice_access_exception', fn() => $bridge->fetch($ctx, 'GET', '/my/'));
        $DB->set_field('external_services', 'enabled', 1, ['id' => $ctx->serviceid]);

        $userrole = $DB->get_field('role', 'id', ['shortname' => 'user']);
        assign_capability(session_bridge::CAPABILITY, CAP_PROHIBIT, $userrole, context_system::instance(), true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assert_refused('required_capability_exception', fn() => $bridge->fetch($ctx, 'GET', '/my/'));
    }

    /**
     * Redirects are followed same-origin only and re-checked; a POST becomes a GET; a login redirect re-logs in once.
     */
    public function test_redirects(): void {
        $this->resetAfterTest(true);
        [$ctx] = $this->setup_bridge();

        $redirect = fn(string $location, int $status = 303) => ['status' => $status, 'headers' => ['location' => [$location]],
            'body' => ''];
        $transport = new session_bridge_fake_transport(function (string $method, string $url) use ($redirect): array {
            if (str_contains($url, '/course/edit.php')) {
                return $redirect('view.php?id=9');
            }
            if (str_contains($url, '/goaway.php')) {
                return $redirect('https://evil.example/x', 302);
            }
            if (str_contains($url, '/toservice.php')) {
                return $redirect('/moodle/webservice/rest/server.php', 302);
            }
            return self::page('Course 9');
        });
        $bridge = new session_bridge($transport, 0);

        $result = $bridge->fetch($ctx, 'POST', '/course/edit.php', ['name' => ['a', 'b'], 'x' => 'y z']);
        $this->assertSame('https://www.example.com/moodle/course/view.php?id=9', $result['url']);
        $posted = $transport->requests[1];
        $this->assertSame('POST', $posted['method']);
        $this->assertSame('name%5B0%5D=a&name%5B1%5D=b&x=y%20z', $posted['body']);
        $this->assertSame('GET', $transport->requests[2]['method']);

        $this->assert_refused('uibridgeurldenied', fn() => $bridge->fetch($ctx, 'GET', '/goaway.php'));
        $this->assert_refused('uibridgeurldenied', fn() => $bridge->fetch($ctx, 'GET', '/toservice.php'));

        // The session died once: re-login and succeed; dying again fails.
        $deaths = 1;
        $transport->pages = function () use (&$deaths, $redirect): array {
            return $deaths-- > 0 ? $redirect('https://www.example.com/moodle/login/index.php', 303) : self::page();
        };
        $before = $transport->logins;
        $this->assertSame(200, $bridge->fetch($ctx, 'GET', '/my/')['status']);
        $this->assertSame($before + 1, $transport->logins);

        $transport->pages = fn() => $redirect('/moodle/login/index.php', 303);
        $this->assert_refused('uibridgesessionlost', fn() => $bridge->fetch($ctx, 'GET', '/my/'));
    }

    /**
     * Revoking a credential family ends its bridge session; rotating inside a live family does not.
     */
    public function test_family_revocation_ends_session(): void {
        $this->resetAfterTest(true);
        [$ctx, $user] = $this->setup_bridge();
        $transport = new session_bridge_fake_transport(fn() => self::page());
        $bridge = new session_bridge($transport, 0);
        $bridge->fetch($ctx, 'GET', '/my/');
        $cache = \cache::make('webservice_mcp', 'uibridge_session');
        $key = sha1((string)$ctx->credentialid);
        $this->assertNotFalse($cache->get($key));

        // A second token in the family is revoked (e.g. rotation): the family lives on, so does the session.
        $manager = new credential_manager();
        $other = $manager->issue_oauth_access_token((object)['shortname' => 'x'], (int)$user->id, context_system::instance(), [
            'oauthclientid' => 'mcp_ui',
            'familyid' => 'uifamily',
            'familycreated' => time(),
        ]);
        $manager->revoke_credential_by_id((int)$other->id, (int)$user->id);
        $this->assertNotFalse($cache->get($key));

        $manager->revoke_family('uifamily', (int)$user->id);
        $this->assertFalse($cache->get($key));
        $this->assert_refused('webservice_access_exception', fn() => $bridge->fetch($ctx, 'GET', '/my/'));
    }

    /**
     * Download metadata: Content-Disposition file names, non-HTML content gets no sesskey.
     */
    public function test_download_metadata(): void {
        $this->resetAfterTest(true);
        [$ctx] = $this->setup_bridge();
        $bridge = new session_bridge(new session_bridge_fake_transport(fn() => [
            'status' => 200,
            'headers' => [
                'content-type' => ['text/csv'],
                'content-disposition' => ["attachment; filename=\"x.csv\"; filename*=UTF-8''Grades%20report.csv"],
            ],
            'body' => '"sesskey":"notreally"',
        ]), 0);

        $response = $bridge->fetch($ctx, 'GET', '/grade/export/txt/export.php?id=2');
        $this->assertSame('Grades report.csv', $response['filename']);
        $this->assertNull($response['sesskey']);
        $this->assertSame('text/csv', $response['contenttype']);
    }

    /**
     * Assert a callable is refused with an error code or exception class.
     *
     * @param string $expected Error code, or exception short class name.
     * @param callable $callable Callable.
     * @return void
     */
    private function assert_refused(string $expected, callable $callable): void {
        try {
            $callable();
            $this->fail('Expected refusal ' . $expected);
        } catch (\moodle_exception $exception) {
            $this->assertContains(
                $expected,
                [$exception->errorcode, (new \ReflectionClass($exception))->getShortName()],
                $exception->getMessage()
            );
        }
    }
}
