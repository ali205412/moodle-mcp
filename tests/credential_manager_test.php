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
use stdClass;
use webservice_mcp\local\auth\credential_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for connector credential lifecycle handling.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\auth\credential_manager
 */
final class credential_manager_test extends advanced_testcase {
    /**
     * Create a service-like stub for credential manager tests.
     *
     * @return stdClass
     */
    private function create_service_stub(): stdClass {
        return (object)[
            'id' => 99,
            'shortname' => 'webservice_mcp_connector',
        ];
    }

    /**
     * Test bootstrap credentials are short-lived and scoped.
     */
    public function test_issue_bootstrap_credential_sets_short_lived_metadata(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manager = new credential_manager();
        $credential = $manager->issue_bootstrap_credential(
            $this->create_service_stub(),
            $user->id,
            context_system::instance()
        );

        $stored = $DB->get_record('webservice_mcp_credential', ['id' => $credential->id], '*', MUST_EXIST);

        $this->assertSame(credential_manager::TOKEN_TYPE_BOOTSTRAP, (int)$stored->tokentype);
        $this->assertSame('webservice_mcp_connector', $stored->serviceidentifier);
        $this->assertSame(context_system::instance()->id, (int)$stored->contextid);
        $this->assertNotEmpty($stored->name);
        $this->assertGreaterThan(time(), (int)$stored->validuntil);
        $this->assertSame(0, (int)$stored->revoked);
    }

    /**
     * Test durable grants are explicit and not session-bound.
     */
    public function test_issue_durable_grant_creates_non_session_bound_record(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manager = new credential_manager();
        $credential = $manager->issue_durable_grant(
            $this->create_service_stub(),
            $user->id,
            context_system::instance()
        );

        $stored = $DB->get_record('webservice_mcp_credential', ['id' => $credential->id], '*', MUST_EXIST);

        $this->assertSame(credential_manager::TOKEN_TYPE_DURABLE, (int)$stored->tokentype);
        $this->assertNotEmpty($stored->name);
        $this->assertNull($stored->sid);
        $this->assertGreaterThan(time(), (int)$stored->validuntil);
    }

    /**
     * Test resolving a credential updates last access.
     */
    public function test_resolve_credential_returns_active_record_and_updates_lastaccess(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manager = new credential_manager();
        $credential = $manager->issue_bootstrap_credential(
            $this->create_service_stub(),
            $user->id,
            context_system::instance(),
            ['sid' => null]
        );

        $resolved = $manager->resolve_credential($credential->token);
        $stored = $DB->get_record('webservice_mcp_credential', ['id' => $credential->id], '*', MUST_EXIST);

        $this->assertNotNull($resolved);
        $this->assertSame((int)$credential->id, (int)$resolved->id);
        $this->assertNotEmpty($stored->lastaccess);
    }

    /**
     * Test revoking a credential marks the record as revoked.
     */
    public function test_revoke_credential_marks_record_revoked(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manager = new credential_manager();
        $credential = $manager->issue_durable_grant(
            $this->create_service_stub(),
            $user->id,
            context_system::instance()
        );

        $this->assertTrue($manager->revoke_credential($credential->token, $user->id));

        $stored = $DB->get_record('webservice_mcp_credential', ['id' => $credential->id], '*', MUST_EXIST);

        $this->assertSame(1, (int)$stored->revoked);
        $this->assertNull($manager->resolve_credential($credential->token));
    }

    /**
     * Test OAuth-originated credentials persist scope, resource, and client metadata.
     */
    public function test_issue_oauth_credentials_persist_scope_and_resource_metadata(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manager = new credential_manager();
        $access = $manager->issue_oauth_access_token(
            $this->create_service_stub(),
            $user->id,
            context_system::instance(),
            [
                'scope' => 'mcp:read mcp:write offline_access',
                'resourceuri' => 'https://example.com/webservice/mcp/server.php',
                'oauthclientid' => 'mcp_test_client',
            ]
        );
        $refresh = $manager->issue_oauth_refresh_token(
            $this->create_service_stub(),
            $user->id,
            context_system::instance(),
            [
                'scope' => 'mcp:read mcp:write offline_access',
                'resourceuri' => 'https://example.com/webservice/mcp/server.php',
                'oauthclientid' => 'mcp_test_client',
            ]
        );

        $storedaccess = $DB->get_record('webservice_mcp_credential', ['id' => $access->id], '*', MUST_EXIST);
        $storedrefresh = $DB->get_record('webservice_mcp_credential', ['id' => $refresh->id], '*', MUST_EXIST);

        $this->assertSame(credential_manager::TOKEN_TYPE_DURABLE, (int)$storedaccess->tokentype);
        $this->assertSame('mcp:read mcp:write offline_access', (string)$storedaccess->scope);
        $this->assertSame('https://example.com/webservice/mcp/server.php', (string)$storedaccess->resourceuri);
        $this->assertSame('mcp_test_client', (string)$storedaccess->oauthclientid);
        $this->assertSame(credential_manager::TOKEN_TYPE_REFRESH, (int)$storedrefresh->tokentype);
        $this->assertSame('mcp_test_client', (string)$storedrefresh->oauthclientid);
    }
}
