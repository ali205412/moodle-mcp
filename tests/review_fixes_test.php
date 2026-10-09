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
use webservice_mcp\local\auth\connector_service_manager;
use webservice_mcp\local\auth\credential_manager;
use webservice_mcp\local\auth\preapproval_service;
use webservice_mcp\local\auth\service_access;

/**
 * Regression tests for the phase 11 final review findings in the auth layer.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\observer
 * @covers      \webservice_mcp\local\auth\service_access
 * @covers      \webservice_mcp\local\auth\preapproval_service
 * @covers      \webservice_mcp\local\auth\admin_key_service
 */
final class review_fixes_test extends advanced_testcase {
    /**
     * Create a user holding webservice/mcp:use.
     *
     * @return stdClass
     */
    private function create_mcp_user(): stdClass {
        $user = $this->getDataGenerator()->create_user(['auth' => 'manual', 'password' => 'Old-passw0rd!']);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('webservice/mcp:use', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * #4: the token hashing migration runs once; a second run leaves hashes unchanged.
     */
    public function test_token_hashing_migration_is_idempotent(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/webservice/mcp/db/upgradelib.php');

        $this->resetAfterTest(true);
        $this->assertNotEmpty(get_config('webservice_mcp', 'tokenshashed'), 'Fresh installs start hashed.');
        $this->assertFalse(webservice_mcp_hash_stored_tokens());

        // Simulate a pre-0.9 site: plaintext token, no flag.
        unset_config('tokenshashed', 'webservice_mcp');
        $user = $this->getDataGenerator()->create_user();
        $plaintext = bin2hex(random_bytes(32));
        $id = $DB->insert_record('webservice_mcp_credential', (object)[
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => $user->id, 'userid' => $user->id,
            'token' => $plaintext, 'name' => 'Legacy', 'serviceidentifier' => 'webservice_mcp_connector',
            'contextid' => context_system::instance()->id, 'tokentype' => 1, 'revoked' => 0,
        ]);

        $this->assertTrue(webservice_mcp_hash_stored_tokens());
        $hashed = $DB->get_field('webservice_mcp_credential', 'token', ['id' => $id]);
        $this->assertSame(hash('sha256', $plaintext), $hashed);

        $this->assertFalse(webservice_mcp_hash_stored_tokens());
        $this->assertSame($hashed, $DB->get_field('webservice_mcp_credential', 'token', ['id' => $id]));
        $this->assertNotNull((new credential_manager())->find_credential($plaintext));
    }

    /**
     * Fresh installs create the ticket signing secret up front.
     */
    public function test_signing_secret_created_at_install(): void {
        $this->assertGreaterThanOrEqual(64, strlen((string)get_config('webservice_mcp', 'signingsecret')));
    }

    /**
     * #8: a login re-hash (no acting user) keeps connections; site policy, resets and admin changes revoke.
     */
    public function test_password_events_mirror_core(): void {
        global $CFG;

        $this->resetAfterTest(true);
        $user = $this->create_mcp_user();
        $manager = new credential_manager();
        $service = (object)['shortname' => 'webservice_mcp_connector'];
        $issue = fn() => $manager->issue_durable_grant($service, (int)$user->id, context_system::instance());

        $CFG->passwordchangetokendeletion = 0;
        $this->setUser(0);
        $credential = $issue();
        \core\event\user_password_updated::create_from_user($user)->trigger();
        $this->assertNotNull($manager->resolve_credential($credential->token), 'Login re-hash must not disconnect.');

        $this->setUser($user);
        \core\event\user_password_updated::create_from_user($user)->trigger();
        $this->assertNotNull($manager->resolve_credential($credential->token), 'Own change keeps connections by default.');

        $this->setUser(0);
        \core\event\user_password_updated::create_from_user($user, true)->trigger();
        $this->assertNull($manager->resolve_credential($credential->token), 'Forgotten-password reset revokes.');

        $credential = $issue();
        $this->setAdminUser();
        \core\event\user_password_updated::create_from_user($user)->trigger();
        $this->assertNull($manager->resolve_credential($credential->token), 'Reset by someone else revokes.');

        $credential = $issue();
        $CFG->passwordchangetokendeletion = 1;
        $this->setUser(0);
        \core\event\user_password_updated::create_from_user($user)->trigger();
        $this->assertNull($manager->resolve_credential($credential->token), 'passwordchangetokendeletion revokes.');
    }

    /**
     * Pre-approval only for verified clients and same-site or typed navigations.
     */
    public function test_auto_approval_requires_verified_client_and_same_site(): void {
        $this->resetAfterTest(true);
        $service = new preapproval_service();
        $registered = (object)['clientid' => 'mcp_registered', 'isdynamic' => 0];
        $dynamic = (object)['clientid' => 'mcp_dynamic', 'isdynamic' => 1];
        $metadata = (object)['clientid' => 'https://claude.ai/oauth/client.json', 'isdynamic' => 0];

        $this->assertTrue($service->may_auto_approve($registered, 'none'));
        $this->assertTrue($service->may_auto_approve($registered, 'same-origin'));
        $this->assertFalse($service->may_auto_approve($registered, 'cross-site'));
        $this->assertFalse($service->may_auto_approve($registered, 'same-site'));
        $this->assertFalse($service->may_auto_approve($registered, ''));
        $this->assertFalse($service->may_auto_approve($dynamic, 'none'));
        $this->assertFalse($service->may_auto_approve($metadata, 'none'));

        set_config('preapprovalclientids', "https://claude.ai/oauth/client.json\nhttps://other.example/c.json", 'webservice_mcp');
        $this->assertTrue($service->may_auto_approve($metadata, 'none'));
    }

    /**
     * Non-admin issuers cannot mint access for users as privileged as an issuer.
     */
    public function test_issuer_dominance_check(): void {
        $this->resetAfterTest(true);
        $system = context_system::instance();

        $issuer = $this->getDataGenerator()->create_user();
        $issuerrole = $this->getDataGenerator()->create_role();
        assign_capability(admin_key_service::CAPABILITY, CAP_ALLOW, $issuerrole, $system);
        role_assign($issuerrole, $issuer->id, $system);

        $peer = $this->create_mcp_user();
        role_assign($issuerrole, $peer->id, $system);
        $loginas = $this->create_mcp_user();
        $loginasrole = $this->getDataGenerator()->create_role();
        assign_capability('moodle/user:loginas', CAP_ALLOW, $loginasrole, $system);
        role_assign($loginasrole, $loginas->id, $system);
        $plain = $this->create_mcp_user();
        accesslib_clear_all_caches_for_unit_testing();

        $service = new admin_key_service();
        $this->assertSame('privileged', $service->check_target($peer, $system, $issuer));
        $this->assertSame('privileged', $service->check_target($loginas, $system, $issuer));
        $this->assertNull($service->check_target($plain, $system, $issuer));
        $this->assertNull($service->check_target($peer, $system, get_admin()));
    }

    /**
     * The shared access re-check catches every withdrawal.
     */
    public function test_service_access_problems(): void {
        global $DB;

        $this->resetAfterTest(true);
        set_config('oauthenabled', 1, 'webservice_mcp');
        $user = $this->create_mcp_user();
        $connector = (new connector_service_manager())->ensure_service_for_user((int)$user->id);
        $serviceid = (int)$connector->id;
        $manager = new credential_manager();
        $DB->insert_record('webservice_mcp_oauth_client', (object)[
            'timecreated' => time(), 'timemodified' => time(), 'clientid' => 'mcp_c', 'clientname' => 'C',
            'redirecturis' => '[]', 'scope' => 'mcp:read', 'granttypes' => '[]', 'responsetypes' => '[]',
            'tokenauthmethod' => 'none', 'isdynamic' => 0, 'revoked' => 0,
        ]);
        $credential = $manager->issue_oauth_access_token($connector, (int)$user->id, context_system::instance(), [
            'oauthclientid' => 'mcp_c',
            'familyid' => 'fam1',
            'familycreated' => time(),
        ]);
        $family = credential_manager::family_key($credential);

        $this->assertNull(service_access::problem((int)$user->id, $serviceid, $family));
        $manager->assert_service_access((int)$user->id, $serviceid, $family);

        set_config('oauthenabled', 0, 'webservice_mcp');
        $this->assertSame('oauthdisabled', service_access::problem((int)$user->id, $serviceid, $family));
        set_config('oauthenabled', 1, 'webservice_mcp');
        $DB->set_field('webservice_mcp_oauth_client', 'revoked', 1, ['clientid' => 'mcp_c']);
        $this->assertSame('clientrevoked', service_access::problem((int)$user->id, $serviceid, $family));
        $DB->set_field('webservice_mcp_oauth_client', 'revoked', 0, ['clientid' => 'mcp_c']);

        $DB->set_field('external_services_users', 'iprestriction', '10.0.0.0/8', ['userid' => $user->id]);
        $this->assertSame('invalidiptoken', service_access::problem((int)$user->id, $serviceid, $family));
        $this->assertNull(service_access::problem((int)$user->id, $serviceid, $family, false));
        $DB->set_field('external_services_users', 'validuntil', time() - 1, ['userid' => $user->id]);
        $this->assertSame('invalidtimedtoken', service_access::problem((int)$user->id, $serviceid, null, false));
        $DB->delete_records('external_services_users', ['userid' => $user->id]);
        $this->assertSame('usernotallowed', service_access::problem((int)$user->id, $serviceid));

        $DB->set_field('external_services', 'restrictedusers', 0, ['id' => $serviceid]);
        // A family belongs to its user: anyone else presenting it is refused.
        $this->assertSame('credentialrevoked', service_access::problem((int)get_admin()->id, $serviceid, $family, false));
        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        $this->assertSame('userinactive', service_access::problem((int)$user->id, $serviceid));
        $DB->set_field('user', 'suspended', 0, ['id' => $user->id]);
        $DB->set_field('external_services', 'requiredcapability', 'moodle/site:config', ['id' => $serviceid]);
        $this->assertSame('missingrequiredcapability', service_access::problem((int)$user->id, $serviceid));
        $DB->set_field('external_services', 'enabled', 0, ['id' => $serviceid]);
        $this->assertSame('servicenotavailable', service_access::problem((int)$user->id, $serviceid));

        $manager->revoke_family('fam1', 0);
        $DB->set_field('external_services', 'enabled', 1, ['id' => $serviceid]);
        $DB->set_field('external_services', 'requiredcapability', '', ['id' => $serviceid]);
        $this->assertSame('credentialrevoked', service_access::problem((int)$user->id, $serviceid, $family));
        $this->expectException(\webservice_access_exception::class);
        $manager->assert_service_access((int)$user->id, $serviceid, $family);
    }

    /**
     * The upgrade seeds the redirect-host allowlist with active clients' hosts, so they can still authorize.
     */
    public function test_upgrade_seeds_redirect_hosts_of_existing_clients(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/webservice/mcp/db/upgradelib.php');

        $this->resetAfterTest(true);
        set_config('oauthenabled', 1, 'webservice_mcp');
        unset_config('allowedredirecthosts', 'webservice_mcp');
        $client = function (string $clientid, string $uri, int $revoked) use ($DB): void {
            $DB->insert_record('webservice_mcp_oauth_client', (object)[
                'timecreated' => time(), 'timemodified' => time(), 'clientid' => $clientid, 'clientname' => $clientid,
                'redirecturis' => json_encode([$uri]), 'scope' => 'mcp:read mcp:write offline_access',
                'granttypes' => json_encode(['authorization_code', 'refresh_token']),
                'responsetypes' => json_encode(['code']), 'tokenauthmethod' => 'none', 'isdynamic' => 1,
                'revoked' => $revoked,
            ]);
        };
        $client('mcp_mofeed', 'https://mofeed.info/api/remote-mcp/callback', 0);
        $client('mcp_claude', 'https://claude.ai/api/mcp/auth_callback', 0);
        $client('mcp_gone', 'https://evil.example/cb', 1);

        $hosts = webservice_mcp_seed_redirect_hosts();
        $this->assertContains('mofeed.info', $hosts);
        $this->assertContains('claude.com', $hosts);
        $this->assertNotContains('evil.example', $hosts);
        $this->assertSame(1, count(array_keys($hosts, 'claude.ai')));
        $this->assertSame($hosts, webservice_mcp_seed_redirect_hosts(), 'Seeding again changes nothing.');

        $verifier = str_repeat('v', 43);
        $validated = (new \webservice_mcp\local\oauth\service())->authorization()->validate_authorization_request([
            'response_type' => 'code',
            'client_id' => 'mcp_mofeed',
            'redirect_uri' => 'https://mofeed.info/api/remote-mcp/callback',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
        $this->assertSame('https://mofeed.info/api/remote-mcp/callback', $validated['redirecturi']);
    }
}
