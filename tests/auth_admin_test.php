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

defined('MOODLE_INTERNAL') || die();

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
}
