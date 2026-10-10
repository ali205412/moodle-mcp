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
        $this->assertSame(['moodle_page_view', 'moodle_page_action', 'moodle_page_submit'],
            array_column(tools::describe($ctx), 'name'));
        $view = tools::describe($ctx)[0];
        $this->assertTrue($view['annotations']['readOnlyHint']);
        $this->assertTrue(tools::describe($ctx)[2]['annotations']['destructiveHint']);

        $course = $this->getDataGenerator()->create_course();
        $restricted = new call_context(call_context::ERA_MODERN, '2026-07-28', $ctx->user,
            \context_course::instance($course->id), $ctx->serviceid, true);
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
}
