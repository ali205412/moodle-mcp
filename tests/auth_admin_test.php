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
use webservice_mcp\local\auth\companion_contract;
use webservice_mcp\local\auth\credential_admin_service;
use webservice_mcp\local\auth\credential_manager;
use webservice_mcp\local\auth\transport_identity;

/**
 * Tests for connector auth admin services and companion seam invariants.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\auth\credential_admin_service
 * @covers      \webservice_mcp\local\auth\transport_identity
 * @covers      \webservice_mcp\local\auth\companion_contract
 * @covers      \webservice_mcp\observer
 * @covers      \webservice_mcp\task\cleanup
 */
final class auth_admin_test extends advanced_testcase {
    /**
     * Create a service-like stub.
     *
     * @return \stdClass
     */
    private function create_service_stub(): \stdClass {
        return (object)[
            'id' => 99,
            'shortname' => 'webservice_mcp_connector',
        ];
    }

    /**
     * Test admin service lists and revokes credentials.
     */
    public function test_admin_service_can_inspect_and_revoke_credentials(): void {
        $this->resetAfterTest(true);

        $manageruser = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('webservice/mcp:manageconnectors', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $manageruser->id, context_system::instance());

        $subjectuser = $this->getDataGenerator()->create_user();

        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($manageruser);

        $manager = new credential_manager();
        $credential = $manager->issue_durable_grant(
            $this->create_service_stub(),
            $subjectuser->id,
            context_system::instance(),
            ['usermodified' => $manageruser->id]
        );

        $adminservice = new credential_admin_service($manager);
        $credentials = $adminservice->list_user_credentials($subjectuser->id);

        $this->assertCount(1, $credentials);
        $description = $adminservice->describe_credential(reset($credentials));
        $this->assertSame((int)$subjectuser->id, $description['userid']);
        $this->assertTrue($adminservice->revoke_token($credential->token));
        $this->assertCount(0, $adminservice->list_user_credentials($subjectuser->id));
    }

    /**
     * Test transport identity resolves restricted context and service metadata.
     */
    public function test_transport_identity_resolves_plugin_authoritative_metadata(): void {
        global $DB;

        $this->resetAfterTest(true);
        $DB->insert_record('external_services', (object)[
            'name' => 'Connector',
            'enabled' => 1,
            'restrictedusers' => 0,
            'shortname' => 'webservice_mcp_connector',
            'timecreated' => time(),
        ]);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manager = new credential_manager();
        $credential = $manager->issue_bootstrap_credential(
            $this->create_service_stub(),
            $user->id,
            context_system::instance(),
            ['sid' => null]
        );

        $resolver = new transport_identity($manager);
        $identity = $resolver->resolve($credential->token);

        $this->assertNotNull($identity);
        $this->assertSame((int)$user->id, (int)$identity->user->id);
        $this->assertSame('webservice_mcp_connector', $identity->restrictedservice);
        $this->assertSame((int)context_system::instance()->id, (int)$identity->restrictedcontext->id);
    }

    /**
     * Test the companion seam is an interface, not the authority for resolution.
     */
    public function test_companion_contract_is_interface_only(): void {
        $this->assertTrue(interface_exists(companion_contract::class));
        $this->assertFalse(is_subclass_of(transport_identity::class, companion_contract::class));
    }

    /**
     * Test a password change revokes every credential of the user.
     */
    public function test_password_change_revokes_credentials(): void {
        $this->resetAfterTest(true);
        // An administrator resets the password: someone else changed it, so connections end.
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user(['auth' => 'manual', 'password' => 'Old-passw0rd!']);
        $other = $this->getDataGenerator()->create_user();
        $manager = new credential_manager();
        $credential = $manager->issue_durable_grant($this->create_service_stub(), $user->id, context_system::instance());
        $othercredential = $manager->issue_durable_grant($this->create_service_stub(), $other->id, context_system::instance());

        update_internal_user_password($user, 'New-passw0rd!');

        $this->assertNull($manager->resolve_credential($credential->token));
        $this->assertNotNull($manager->resolve_credential($othercredential->token));
    }

    /**
     * Test suspending a user revokes credentials, and deleting a user removes them.
     */
    public function test_suspension_and_deletion_revoke_credentials(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $manager = new credential_manager();
        $credential = $manager->issue_durable_grant($this->create_service_stub(), $user->id, context_system::instance());

        // Ordinary profile updates leave credentials alone.
        user_update_user((object)['id' => $user->id, 'firstname' => 'Renamed'], false, true);
        $this->assertNotNull($manager->resolve_credential($credential->token));

        user_update_user((object)['id' => $user->id, 'suspended' => 1], false, true);
        $this->assertNull($manager->resolve_credential($credential->token));

        delete_user($DB->get_record('user', ['id' => $user->id]));
        $this->assertFalse($DB->record_exists('webservice_mcp_credential', ['userid' => $user->id]));
    }

    /**
     * Test the cleanup task purges old revoked credentials, spent codes, old audit rows, and unused DCR clients.
     */
    public function test_cleanup_task_purges_stale_rows(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $manager = new credential_manager();
        $old = $manager->issue_durable_grant($this->create_service_stub(), $user->id, context_system::instance());
        $fresh = $manager->issue_durable_grant($this->create_service_stub(), $user->id, context_system::instance());
        $DB->update_record('webservice_mcp_credential', (object)[
            'id' => $old->id,
            'revoked' => 1,
            'timemodified' => time() - 60 * DAYSECS,
        ]);

        $DB->insert_record('webservice_mcp_audit', (object)[
            'timecreated' => time() - 100 * DAYSECS,
            'userid' => $user->id,
            'action' => 'tool_call',
            'outcome' => 'success',
            'auditid' => 'old-audit',
        ]);
        $DB->insert_record('webservice_mcp_oauth_client', (object)[
            'timecreated' => time() - 60 * DAYSECS,
            'timemodified' => time() - 60 * DAYSECS,
            'clientid' => 'mcp_abandoned',
            'clientname' => '',
            'redirecturis' => '[]',
            'scope' => 'mcp:read',
            'granttypes' => '[]',
            'responsetypes' => '[]',
            'tokenauthmethod' => 'none',
            'isdynamic' => 1,
            'revoked' => 0,
        ]);

        (new \webservice_mcp\task\cleanup())->execute();

        $this->assertFalse($DB->record_exists('webservice_mcp_credential', ['id' => $old->id]));
        $this->assertTrue($DB->record_exists('webservice_mcp_credential', ['id' => $fresh->id]));
        $this->assertFalse($DB->record_exists('webservice_mcp_audit', ['auditid' => 'old-audit']));
        $this->assertFalse($DB->record_exists('webservice_mcp_oauth_client', ['clientid' => 'mcp_abandoned']));
    }
}
