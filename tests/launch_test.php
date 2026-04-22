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
use moodle_url;
use required_capability_exception;
use webservice_mcp\local\auth\bootstrap_service;
use webservice_mcp\local\auth\connector_service_manager;
use webservice_mcp\local\auth\oauth_bridge;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for MCP launch/bootstrap auth helpers.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\auth\bootstrap_service
 * @covers      \webservice_mcp\local\auth\connector_service_manager
 * @covers      \webservice_mcp\local\auth\oauth_bridge
 */
final class launch_test extends advanced_testcase {
    /**
     * Test bootstrap access requires capability.
     */
    public function test_bootstrap_requires_capability(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $service = new bootstrap_service();

        $this->expectException(required_capability_exception::class);
        $service->require_bootstrap_access(context_system::instance());
    }

    /**
     * Test bootstrap payload includes issued token data for authorised user.
     */
    public function test_bootstrap_for_current_user_returns_payload(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('webservice/mcp:use', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($user);

        $service = new bootstrap_service();
        $credential = $service->issue_bootstrap_for_current_user();
        $payload = $service->build_bootstrap_payload($credential);

        $this->assertNotEmpty($payload['token']);
        $this->assertSame('webservice_mcp_connector', $payload['serviceidentifier']);
        $this->assertSame((int)context_system::instance()->id, (int)$payload['contextid']);
        $this->assertTrue($DB->record_exists('external_services', [
            'shortname' => 'webservice_mcp_connector',
            'enabled' => 1,
            'requiredcapability' => 'webservice/mcp:use',
            'restrictedusers' => 1,
        ]));
    }

    /**
     * Test bootstrap provisions the connector service, user allow-list, and function links.
     */
    public function test_bootstrap_provisions_connector_service_and_authorised_user(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('webservice/mcp:use', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($user);

        $bootstrap = new bootstrap_service();
        $bootstrap->issue_bootstrap_for_current_user();

        $shortname = (new connector_service_manager())->service_shortname();
        $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', MUST_EXIST);
        $alloweduser = $DB->get_record('external_services_users', [
            'externalserviceid' => $service->id,
            'userid' => $user->id,
        ]);

        $this->assertSame('webservice/mcp:use', $service->requiredcapability);
        $this->assertSame(1, (int)$service->restrictedusers);
        $this->assertSame(1, (int)$service->downloadfiles);
        $this->assertSame(1, (int)$service->uploadfiles);
        $this->assertSame('webservice_mcp', (string)$service->component);
        $this->assertNotEmpty($alloweduser);
        $this->assertTrue($DB->record_exists('external_services_functions', [
            'externalserviceid' => $service->id,
            'functionname' => 'core_webservice_get_site_info',
        ]));
    }

    /**
     * Test OAuth bridge builds Moodle-native login URL.
     */
    public function test_oauth_bridge_builds_login_url(): void {
        $this->resetAfterTest(true);

        $returnurl = new moodle_url('/webservice/mcp/launch.php', ['format' => 'json']);
        $bridge = new oauth_bridge();

        try {
            $url = $bridge->build_login_url(1, $returnurl);
            $this->assertStringContainsString('/auth/oauth2/login.php', $url->out(false));
        } catch (\moodle_exception $exception) {
            $this->assertTrue(
                in_array($exception->errorcode, ['notenabled', 'issuernologin'], true)
            );
        }
    }
}
