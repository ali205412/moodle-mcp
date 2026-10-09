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
use webservice_mcp\local\auth\admin_key_service;
use webservice_mcp\local\auth\connection_service;
use webservice_mcp\local\auth\connector_service_manager;
use webservice_mcp\local\auth\credential_manager;
use webservice_mcp\local\auth\preapproval_service;
use webservice_mcp\local\auth\transport_identity;

/**
 * Tests for bulk admin-issued MCP keys and OAuth pre-approvals.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\auth\admin_key_service
 * @covers      \webservice_mcp\local\auth\preapproval_service
 * @covers      \webservice_mcp\local\auth\connection_service
 * @covers      \webservice_mcp\event\credential_issued
 * @covers      \webservice_mcp\event\credential_revoked
 * @covers      \webservice_mcp\hook_callbacks
 */
final class admin_key_service_test extends advanced_testcase {
    /** @var int Role granting webservice/mcp:use. */
    private int $mcprole = 0;

    /**
     * Create a user holding webservice/mcp:use at system level.
     *
     * @param array $record Extra user fields.
     * @return stdClass
     */
    private function create_mcp_user(array $record = []): stdClass {
        if (!$this->mcprole) {
            $this->mcprole = $this->getDataGenerator()->create_role();
            assign_capability('webservice/mcp:use', CAP_ALLOW, $this->mcprole, context_system::instance());
        }
        $user = $this->getDataGenerator()->create_user($record);
        role_assign($this->mcprole, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * Create a non-admin issuer holding webservice/mcp:issueforothers.
     *
     * @return stdClass
     */
    private function create_issuer(): stdClass {
        $issuer = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(admin_key_service::CAPABILITY, CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $issuer->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();
        return $issuer;
    }

    /**
     * Test bulk issuance: eligible users get working read-only keys, others are skipped with reasons.
     */
    public function test_issue_bulk_keys_and_skip_ineligible(): void {
        global $DB;

        $this->resetAfterTest(true);
        $admin = get_admin();
        $alice = $this->create_mcp_user(['username' => 'alice']);
        $bob = $this->create_mcp_user(['email' => 'bob@example.com']);
        $suspended = $this->create_mcp_user(['suspended' => 1]);
        $nocap = $this->getDataGenerator()->create_user();

        $service = new admin_key_service();
        $identifiers = ['alice', 'BOB@example.com', (string)$suspended->id, (string)$nocap->id];
        $userids = $service->resolve_targets(['identifiers' => $identifiers]);
        $this->assertEqualsCanonicalizing([$alice->id, $bob->id, $suspended->id, $nocap->id], $userids);

        $sink = $this->redirectEvents();
        $results = $service->issue($userids, ['label' => 'Pilot', 'scope' => 'read', 'expiresdays' => 30], $admin);
        $events = $sink->get_events();
        $sink->close();

        $byuser = array_column($results, null, 'userid');
        $this->assertSame('issued', $byuser[$alice->id]->status);
        $this->assertSame('issued', $byuser[$bob->id]->status);
        $this->assertSame('inactive', $byuser[$suspended->id]->reason);
        $this->assertSame('nocapability', $byuser[$nocap->id]->reason);
        $this->assertCount(2, array_filter($events, static fn($event): bool => $event instanceof event\credential_issued));

        $identity = (new transport_identity())->resolve($byuser[$alice->id]->token);
        $this->assertNotNull($identity);
        $this->assertSame((int)$alice->id, (int)$identity->user->id);
        $this->assertSame('mcp:read', $identity->scope);
        $this->assertSame(credential_manager::TOKEN_TYPE_ADMIN, $identity->tokentype);
        $this->assertSame('c_' . $identity->credentialid, $identity->familyid);
        $this->assertSame((int)$admin->id, (int)$identity->credential->issuerid);
        $this->assertGreaterThan(time() + 29 * DAYSECS, (int)$identity->credential->validuntil);
        $this->assertFalse($DB->record_exists('webservice_mcp_credential', ['token' => $byuser[$alice->id]->token]));

        $csv = admin_key_service::build_csv($results);
        $lines = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $this->assertSame('claude_code_command', $lines[0][9]);
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('Bearer ' . $byuser[$alice->id]->token, $csv);

        $connections = (new connection_service())->list_connections((int)$alice->id);
        $this->assertSame((int)$admin->id, reset($connections)->issuerid);
    }

    /**
     * Test issuer authorisation: capability required, non-admins cannot target admins, fail-on-skip issues nothing.
     */
    public function test_issuer_restrictions(): void {
        global $DB;

        $this->resetAfterTest(true);
        $issuer = $this->create_issuer();
        $user = $this->create_mcp_user();
        $service = new admin_key_service();

        $results = $service->issue([(int)get_admin()->id, (int)$user->id], ['failonskip' => true], $issuer);
        $byuser = array_column($results, null, 'userid');
        $this->assertSame('siteadmin', $byuser[get_admin()->id]->reason);
        $this->assertSame('', $byuser[$user->id]->token);
        $this->assertSame(0, $DB->count_records('webservice_mcp_credential'));

        $this->expectException(\required_capability_exception::class);
        $service->issue([(int)$user->id], [], $this->getDataGenerator()->create_user());
    }

    /**
     * Test admin removal from the restricted service is respected unless the issuer may manage all tokens.
     */
    public function test_restricted_service_requires_token_managers_to_readd(): void {
        global $DB;

        $this->resetAfterTest(true);
        $user = $this->create_mcp_user();
        $connector = (new connector_service_manager())->ensure_service_for_user((int)$user->id);
        $DB->delete_records('external_services_users', ['externalserviceid' => $connector->id, 'userid' => $user->id]);

        $results = (new admin_key_service())->issue([(int)$user->id], [], $this->create_issuer());
        $this->assertSame('usernotallowed', $results[0]->reason);

        $results = (new admin_key_service())->issue([(int)$user->id], [], get_admin());
        $this->assertSame('issued', $results[0]->status);
        $this->assertTrue($DB->record_exists('external_services_users', [
            'externalserviceid' => $connector->id,
            'userid' => $user->id,
        ]));
    }

    /**
     * Test lifetime clamping, listing, and revocation by label.
     */
    public function test_expiry_clamp_list_and_revoke(): void {
        $this->resetAfterTest(true);
        set_config('adminkeymaxdays', 10, 'webservice_mcp');
        $user = $this->create_mcp_user();
        $service = new admin_key_service();

        $results = $service->issue([(int)$user->id], ['label' => 'Batch A', 'expiresdays' => 400, 'scope' => 'write'], get_admin());
        $this->assertLessThanOrEqual(time() + 10 * DAYSECS, $results[0]->expires);
        $this->assertSame('mcp:read mcp:write', $results[0]->scope);
        $this->assertCount(1, $service->list_keys(['label' => 'Batch A']));
        $this->assertCount(0, $service->list_keys(['label' => 'Batch B']));

        $sink = $this->redirectEvents();
        $this->assertSame(1, $service->revoke_keys(['label' => 'Batch A'], get_admin()));
        $this->assertInstanceOf(event\credential_revoked::class, $sink->get_events()[0]);
        $sink->close();
        $this->assertNull((new transport_identity())->resolve($results[0]->token));
        $this->assertSame(0, $service->revoke_keys([], get_admin()));
    }

    /**
     * Test lastaccess is written at most once per five minutes.
     */
    public function test_lastaccess_is_throttled(): void {
        global $DB;

        $this->resetAfterTest(true);
        $user = $this->create_mcp_user();
        $results = (new admin_key_service())->issue([(int)$user->id], [], get_admin());
        $manager = new credential_manager();

        $manager->resolve_credential($results[0]->token);
        $id = $DB->get_field('webservice_mcp_credential', 'id', ['userid' => $user->id]);
        $recent = time() - 60;
        $DB->set_field('webservice_mcp_credential', 'lastaccess', $recent, ['id' => $id]);
        $manager->resolve_credential($results[0]->token);
        $this->assertSame($recent, (int)$DB->get_field('webservice_mcp_credential', 'lastaccess', ['id' => $id]));
    }

    /**
     * Test pre-approvals cover only their hosts, scope, context, and lifetime.
     */
    public function test_preapproval_matching(): void {
        $this->resetAfterTest(true);
        $user = $this->create_mcp_user();
        $service = new preapproval_service();
        $system = (int)context_system::instance()->id;

        $results = $service->preapprove([(int)$user->id], ['scope' => 'read', 'expiresdays' => 5], get_admin());
        $this->assertSame('preapproved', $results[0]->status);

        $this->assertNotNull($service->find((int)$user->id, 'claude.ai', 'mcp:read offline_access', $system));
        $this->assertNotNull($service->find((int)$user->id, '[::1]', 'mcp:read', $system));
        $this->assertNull($service->find((int)$user->id, 'evil.example', 'mcp:read', $system));
        $this->assertNull($service->find((int)$user->id, 'claude.ai', 'mcp:read mcp:write', $system));
        $this->assertNull($service->find((int)$user->id, 'claude.ai', 'mcp:read', $system + 1));

        $this->assertSame(1, $service->unpreapprove([(int)$user->id], get_admin()));
        $this->assertNull($service->find((int)$user->id, 'claude.ai', 'mcp:read', $system));

        $service->preapprove([(int)$user->id], ['redirecthosts' => "claude.ai"], get_admin());
        $this->assertNull($service->find((int)$user->id, 'localhost', 'mcp:read', $system));
    }

    /**
     * Test selecting a cohort and all users with the MCP capability.
     */
    public function test_resolve_targets_by_cohort_and_capability(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $this->resetAfterTest(true);
        $member = $this->create_mcp_user();
        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $member->id);

        $service = new admin_key_service();
        $this->assertSame([(int)$member->id], $service->resolve_targets(['cohortid' => $cohort->id]));
        $this->assertContains((int)$member->id, $service->resolve_targets(['allmcpusers' => true]));
    }

    /**
     * Test users files: header columns, headerless first column, other delimiters, and idnumber selection.
     */
    public function test_parse_users_file_and_idnumbers(): void {
        $this->resetAfterTest(true);

        $this->assertSame(
            ['a@example.com', 'b@example.com'],
            admin_key_service::parse_users_file("\xEF\xBB\xBFname,email\nAnn,a@example.com\r\nBen,b@example.com\n")['identifiers']
        );
        $this->assertSame(['alice', 'bob'], admin_key_service::parse_users_file("alice,x\nbob,y")['identifiers']);
        $this->assertSame([5, 7], admin_key_service::parse_users_file("id;name\n5;A\n7;B")['userids']);
        $this->assertSame(['E1'], admin_key_service::parse_users_file("idnumber\nE1\n")['idnumbers']);

        $user = $this->create_mcp_user(['idnumber' => 'EMP-42']);
        $this->assertSame([(int)$user->id], (new admin_key_service())->resolve_targets(['idnumbers' => ['EMP-42']]));
    }

    /**
     * Test the bulk user action is offered only to issuers.
     */
    public function test_bulk_user_action_requires_capability(): void {
        $this->resetAfterTest(true);

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertSame([], hook_callbacks::bulk_user_actions());

        $this->setAdminUser();
        $this->assertArrayHasKey('webservice_mcp_keys', hook_callbacks::bulk_user_actions());
    }
}
