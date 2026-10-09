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
use webservice_mcp\local\auth\connection_service;
use webservice_mcp\local\auth\credential_manager;
use webservice_mcp\local\oauth\client_admin_service;
use webservice_mcp\local\oauth\exception as oauth_exception;
use webservice_mcp\local\oauth\service as oauth_service;

/**
 * Tests for OAuth client administration, listing pagination, and retention settings.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\oauth\client_admin_service
 * @covers      \webservice_mcp\local\auth\connection_service
 * @covers      \webservice_mcp\task\cleanup
 */
final class client_admin_service_test extends advanced_testcase {
    /**
     * Test pre-registering, listing, and revoking clients.
     */
    public function test_register_list_and_revoke_clients(): void {
        global $DB;

        $this->resetAfterTest(true);
        $admin = get_admin();
        $service = new client_admin_service();

        $confidential = $service->register([
            'name' => 'Acme agent',
            'redirecturis' => ['https://claude.ai/api/mcp/auth_callback'],
            'confidential' => true,
        ], $admin);
        $public = $service->register(['name' => 'CLI', 'redirecturis' => ['http://localhost/cb'], 'scope' => 'read'], $admin);

        $record = $DB->get_record('webservice_mcp_oauth_client', ['clientid' => $confidential['client_id']], '*', MUST_EXIST);
        $this->assertSame(0, (int)$record->isdynamic);
        $this->assertSame('client_secret_basic', $record->tokenauthmethod);
        $this->assertTrue(password_verify($confidential['client_secret'], $record->clientsecret));
        $this->assertArrayNotHasKey('client_secret', $public);
        $this->assertSame('mcp:read offline_access', $public['scope']);

        $this->assertSame(2, $service->count_clients('registered'));
        $this->assertSame(0, $service->count_clients('dynamic'));
        $this->assertCount(1, $service->list_clients('registered', false, 1, 1));

        $credential = (new credential_manager())->issue_oauth_access_token(
            (object)['shortname' => 'webservice_mcp_connector'],
            (int)$admin->id,
            context_system::instance(),
            ['oauthclientid' => $public['client_id'], 'scope' => 'mcp:read']
        );
        $this->assertTrue($service->revoke($public['client_id'], $admin));
        $this->assertNull((new credential_manager())->resolve_credential($credential->token));
        $this->assertSame(1, $service->count_clients('registered'));
        $this->assertSame(2, $service->count_clients('registered', true));
        $this->assertFalse($service->revoke('nope', $admin));

        $this->expectException(\required_capability_exception::class);
        $service->register(['name' => 'x', 'redirecturis' => ['https://claude.ai/cb']], $this->getDataGenerator()->create_user());
    }

    /**
     * Test connected-app listing is paginated by connection (an OAuth family counts once).
     */
    public function test_connections_are_paginated_by_family(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $manager = new credential_manager();
        $service = (object)['shortname' => 'webservice_mcp_connector'];
        foreach (['f1', 'f1', 'f2'] as $family) {
            $manager->issue_oauth_access_token($service, (int)$user->id, context_system::instance(), [
                'familyid' => $family,
                'familycreated' => time(),
            ]);
        }
        $manager->issue_durable_grant($service, (int)$user->id, context_system::instance());

        $connections = new connection_service($manager);
        $this->assertSame(3, $connections->count_connections((int)$user->id));
        $this->assertCount(2, $connections->list_connections((int)$user->id, 0, 2));
        $this->assertCount(1, $connections->list_connections((int)$user->id, 2, 2));
        $this->assertArrayHasKey('f_f1', $connections->list_connections((int)$user->id));
    }

    /**
     * Test retention periods come from settings.
     */
    public function test_cleanup_retention_settings(): void {
        global $DB;

        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $credential = (new credential_manager())->issue_durable_grant(
            (object)['shortname' => 'webservice_mcp_connector'],
            (int)$user->id,
            context_system::instance()
        );
        $DB->update_record('webservice_mcp_credential', (object)[
            'id' => $credential->id,
            'revoked' => 1,
            'timemodified' => time() - 60 * DAYSECS,
        ]);
        $DB->insert_record('webservice_mcp_jti', (object)['keyhash' => str_repeat('a', 64), 'expiresat' => time() - DAYSECS]);

        set_config('credentialretentiondays', 90, 'webservice_mcp');
        (new task\cleanup())->execute();
        $this->assertTrue($DB->record_exists('webservice_mcp_credential', ['id' => $credential->id]));
        $this->assertSame(0, $DB->count_records('webservice_mcp_jti'));

        set_config('credentialretentiondays', 30, 'webservice_mcp');
        (new task\cleanup())->execute();
        $this->assertFalse($DB->record_exists('webservice_mcp_credential', ['id' => $credential->id]));
    }

    /**
     * Whether a client authenticates with a secret (via the revocation endpoint, which authenticates first).
     *
     * @param string $clientid Client id.
     * @param string $secret Client secret.
     * @return bool
     */
    private function authenticates(string $clientid, string $secret): bool {
        try {
            (new oauth_service())->revoke_token_request(['token' => 'unknown'], $clientid, $secret);
            return true;
        } catch (oauth_exception $exception) {
            $this->assertSame('invalid_client', $exception->oauth_error());
            return false;
        }
    }

    /**
     * Test secret rotation: immediate by default, with an optional overlap for the old secret.
     */
    public function test_rotate_secret(): void {
        global $DB;

        $this->resetAfterTest(true);
        set_config('oauthenabled', 1, 'webservice_mcp');
        $admin = get_admin();
        $service = new client_admin_service();
        $client = $service->register([
            'name' => 'Acme',
            'redirecturis' => ['https://claude.ai/cb'],
            'confidential' => true,
        ], $admin);
        $clientid = $client['client_id'];

        $second = $service->rotate_secret($clientid, $admin);
        $this->assertFalse($this->authenticates($clientid, $client['client_secret']));
        $this->assertTrue($this->authenticates($clientid, $second));

        set_config('secretrotationoverlap', HOURSECS, 'webservice_mcp');
        $third = $service->rotate_secret($clientid, $admin);
        $this->assertTrue($this->authenticates($clientid, $second));
        $this->assertTrue($this->authenticates($clientid, $third));
        $this->assertFalse($this->authenticates($clientid, $client['client_secret']));

        $DB->set_field('webservice_mcp_oauth_client', 'previoussecretexpires', time() - 1, ['clientid' => $clientid]);
        $this->assertFalse($this->authenticates($clientid, $second));

        $public = $service->register(['name' => 'CLI', 'redirecturis' => ['http://localhost/cb']], $admin);
        $this->expectException(oauth_exception::class);
        $service->rotate_secret($public['client_id'], $admin);
    }
}
