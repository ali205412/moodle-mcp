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
use context_course;
use context_system;
use core_external\external_api;
use webservice_mcp\local\auth\credential_manager;
use webservice_mcp\local\files\download_handler;
use webservice_mcp\local\files\tickets;
use webservice_mcp\local\files\transfer_exception;
use webservice_mcp\local\mcp\call_context;
use webservice_mcp\local\signer;

/**
 * Tests for signed file tickets and download-endpoint authorisation.
 *
 * @package     webservice_mcp
 * @category    test
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\files\tickets
 * @covers      \webservice_mcp\local\files\download_handler
 */
final class files_tickets_test extends advanced_testcase {
    /**
     * Reset state and let authenticated users use the connector.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        external_api::set_context_restriction(context_system::instance());
        assign_capability('webservice/mcp:use', CAP_ALLOW, $CFG->defaultuserroleid, context_system::instance()->id, true);
    }

    /**
     * Build a call context.
     *
     * @param \stdClass $user User.
     * @param \context|null $restriction Context restriction.
     * @param int|null $serviceid Service id.
     * @param string|null $credentialid Credential id.
     * @return call_context
     */
    private function ctx(
        \stdClass $user,
        ?\context $restriction = null,
        ?int $serviceid = null,
        ?string $credentialid = null
    ): call_context {
        if ($credentialid === null) {
            $credential = (new credential_manager())->issue_durable_grant(
                (object)['shortname' => 'webservice_mcp_connector'],
                (int)$user->id,
                context_system::instance(),
                ['validuntil' => time() + 3600]
            );
            $credentialid = credential_manager::family_key($credential);
        }
        return new call_context(
            call_context::ERA_MODERN,
            '2026-07-28',
            $user,
            $restriction,
            $serviceid,
            true,
            'files',
            [],
            $credentialid
        );
    }

    /**
     * Extract the ticket from an endpoint URL.
     *
     * @param string $url URL.
     * @return string
     */
    private function ticket(string $url): string {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return $query['ticket'];
    }

    /**
     * Create an external service.
     *
     * @param int $download downloadfiles flag.
     * @param int $upload uploadfiles flag.
     * @return int
     */
    private function service(int $download = 1, int $upload = 1): int {
        global $DB;
        return (int)$DB->insert_record('external_services', ['name' => 'mcpfiles' . random_string(4),
            'shortname' => 'mcpfiles' . random_string(4), 'enabled' => 1, 'requiredcapability' => '', 'restrictedusers' => 0,
            'downloadfiles' => $download, 'uploadfiles' => $upload, 'timecreated' => time()]);
    }

    /**
     * Assert that redeeming fails with an HTTP status.
     *
     * @param int $status Expected status.
     * @param string $purpose Ticket purpose.
     * @param string $ticket Ticket.
     */
    private function assert_redeem_fails(int $status, string $purpose, string $ticket): void {
        try {
            tickets::redeem($purpose, $ticket);
            $this->fail('Expected the ticket to be rejected.');
        } catch (transfer_exception $e) {
            $this->assertSame($status, $e->status);
        }
    }

    /**
     * Download ticket round trip binds user and path.
     */
    public function test_download_ticket_round_trip_binds_user_and_path(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $link = tickets::download_url($this->ctx($user), ['k' => tickets::KIND_FILE, 'rp' => '/5/user/private/0/a.txt']);

        $this->assertStringContainsString('/webservice/mcp/pluginfile.php?ticket=', $link['url']);
        $this->assertGreaterThan(time(), $link['expires']);
        $redeemed = tickets::redeem('dl', $this->ticket($link['url']));
        $this->assertSame((int)$user->id, (int)$redeemed['user']->id);
        $this->assertSame('/5/user/private/0/a.txt', $redeemed['claims']['rp']);
        $this->assertSame(context_system::instance()->id, $redeemed['restriction']->id);
    }

    /**
     * Ticket rejected for other purpose tamper and expiry.
     */
    public function test_ticket_rejected_for_other_purpose_tamper_and_expiry(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $claims = ['d' => 5, 'fp' => '/', 'fn' => null, 'mb' => -1, 'ow' => 0];
        $ul = $this->ticket(tickets::upload_url($this->ctx($user), $claims)['url']);

        $this->assert_redeem_fails(401, 'dl', $ul);
        $this->assert_redeem_fails(401, 'ul', substr($ul, 0, -2) . 'xx');
        $this->assert_redeem_fails(401, 'dl', signer::sign('dl', ['u' => (int)$user->id, 'k' => 'file'], -10));
        $this->assert_redeem_fails(401, 'dl', '');
        $this->assertSame(5, tickets::redeem('ul', $ul)['claims']['d']);
    }

    /**
     * Ticket rejected when user suspended or lacks capability.
     */
    public function test_ticket_rejected_when_user_suspended_or_lacks_capability(): void {
        global $DB, $CFG;
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $ticket = $this->ticket(tickets::download_url($this->ctx($user), ['k' => tickets::KIND_FILE, 'rp' => '/1/a/b/0/c'])['url']);

        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        $this->assert_redeem_fails(403, 'dl', $ticket);
        $DB->set_field('user', 'suspended', 0, ['id' => $user->id]);

        unassign_capability('webservice/mcp:use', $CFG->defaultuserroleid, context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assert_redeem_fails(403, 'dl', $ticket);
    }

    /**
     * Ticket dies with its credential.
     */
    public function test_ticket_dies_with_its_credential(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $credential = (new credential_manager())->issue_durable_grant(
            (object)['shortname' => 'webservice_mcp_connector'],
            (int)$user->id,
            context_system::instance(),
            ['validuntil' => time() + 3600]
        );
        $ticket = $this->ticket(tickets::download_url(
            $this->ctx($user, null, null, 'c_' . $credential->id),
            ['k' => tickets::KIND_FILE, 'rp' => '/1/a/b/0/c']
        )['url']);

        $this->assertSame('c_' . $credential->id, tickets::redeem('dl', $ticket)['claims']['c']);
        (new credential_manager())->revoke_credential_by_id((int)$credential->id, (int)$user->id);
        $this->assert_redeem_fails(401, 'dl', $ticket);
    }

    /**
     * Ticket survives token rotation but not family revocation.
     */
    public function test_ticket_survives_token_rotation_but_not_family_revocation(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $manager = new credential_manager();
        $service = (object)['shortname' => 'webservice_mcp_connector'];
        $first = $manager->issue_oauth_access_token(
            $service,
            (int)$user->id,
            context_system::instance(),
            ['familyid' => 'fam1', 'validuntil' => time() + 3600]
        );
        $link = tickets::download_url($this->ctx($user, null, null, 'f_fam1'), ['k' => tickets::KIND_FILE, 'rp' => '/1/a/b/0/c']);
        $ticket = $this->ticket($link['url']);

        // Refresh: a new access token joins the family and the old one is revoked.
        $manager->issue_oauth_access_token(
            $service,
            (int)$user->id,
            context_system::instance(),
            ['familyid' => 'fam1', 'validuntil' => time() + 3600]
        );
        $manager->revoke_credential_by_id((int)$first->id, (int)$user->id);
        $this->assertSame('f_fam1', tickets::redeem('dl', $ticket)['claims']['c']);

        $manager->revoke_family('fam1', (int)$user->id);
        $this->assert_redeem_fails(401, 'dl', $ticket);
    }

    /**
     * Tickets require a credential family.
     */
    public function test_tickets_require_a_credential_family(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        try {
            tickets::download_url($this->ctx($user, null, null, ''), ['k' => tickets::KIND_FILE, 'rp' => '/1/a/b/0/c']);
            $this->fail('Ticket minted without a credential family.');
        } catch (transfer_exception $e) {
            $this->assertSame('nocredential', $e->errorcode);
        }
        // A validly signed ticket without the family claim (as raw-token calls used to mint) is refused.
        $this->assert_redeem_fails(401, 'dl', signer::sign('dl', ['u' => (int)$user->id, 'c' => null, 'k' => 'file',
            'rp' => '/1/a/b/0/c'], 60));
    }

    /**
     * Drafts always download as attachments with safe headers.
     */
    public function test_drafts_always_download_as_attachments_with_safe_headers(): void {
        $this->assertTrue(download_handler::forcedownload(['k' => 'file', 'dr' => 1, 'fd' => 0]));
        $this->assertTrue(download_handler::forcedownload(['k' => 'file', 'fd' => 1]));
        $this->assertFalse(download_handler::forcedownload(['k' => 'file', 'fd' => 0]));
        $this->assertSame(
            ['X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox'],
            download_handler::security_headers()
        );

        // Asking for an inline draft link still mints an attachment link.
        $target = ['relativepath' => '/5/user/draft/7/x.html', 'draft' => true, 'info' => null, 'params' => null];
        $this->assertSame(1, \webservice_mcp\local\files\file_reader::download_claims($target, false)['fd']);
    }

    /**
     * Service file flags are enforced.
     */
    public function test_service_file_flags_are_enforced(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $serviceid = $this->service(1, 0);
        $ctx = $this->ctx($user, null, $serviceid);

        try {
            tickets::upload_url($ctx, ['d' => 1, 'fp' => '/', 'fn' => null, 'mb' => -1, 'ow' => 0]);
            $this->fail('Uploads should be refused.');
        } catch (transfer_exception $e) {
            $this->assertSame(403, $e->status);
        }
        $ticket = $this->ticket(tickets::download_url($ctx, ['k' => tickets::KIND_FILE, 'rp' => '/1/a/b/0/c'])['url']);
        $this->assertNotEmpty(tickets::redeem('dl', $ticket));

        $DB->set_field('external_services', 'downloadfiles', 0, ['id' => $serviceid]);
        $this->assert_redeem_fails(403, 'dl', $ticket);
    }

    /**
     * Redeem rechecks service user restrictions.
     */
    public function test_redeem_rechecks_service_user_restrictions(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $serviceid = $this->service();
        $ticket = $this->ticket(tickets::download_url(
            $this->ctx($user, null, $serviceid),
            ['k' => tickets::KIND_FILE, 'rp' => '/1/a/b/0/c']
        )['url']);
        $this->assertNotEmpty(tickets::redeem('dl', $ticket));

        // The admin restricts the service to listed users after the link was issued.
        $DB->set_field('external_services', 'restrictedusers', 1, ['id' => $serviceid]);
        try {
            tickets::redeem('dl', $ticket);
            $this->fail('Ticket redeemed by a user no longer allowed on the service.');
        } catch (transfer_exception $e) {
            $this->assertSame(403, $e->status);
            $this->assertSame('usernotallowed', $e->errorcode);
        }
        $DB->insert_record('external_services_users', ['externalserviceid' => $serviceid, 'userid' => $user->id,
            'timecreated' => time()]);
        $this->assertNotEmpty(tickets::redeem('dl', $ticket));
    }

    /**
     * Ttl can be shortened but not extended.
     */
    public function test_ttl_can_be_shortened_but_not_extended(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        set_config('downloadticketttl', 600, 'webservice_mcp');

        $short = tickets::download_url($this->ctx($user), ['k' => tickets::KIND_FILE, 'rp' => '/1/a/b/0/c'], 120);
        $long = tickets::download_url($this->ctx($user), ['k' => tickets::KIND_FILE, 'rp' => '/1/a/b/0/c'], 80000);
        $this->assertLessThanOrEqual(time() + 120, $short['expires']);
        $this->assertLessThanOrEqual(time() + 600, $long['expires']);
        $this->assertGreaterThan(time() + 500, $long['expires']);
    }

    /**
     * Authorize enforces context restriction.
     */
    public function test_authorize_enforces_context_restriction(): void {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $course1 = $generator->create_course();
        $course2 = $generator->create_course();
        $this->setUser($user);
        $restriction = context_course::instance($course1->id);
        $ctx = $this->ctx($user, $restriction);

        $inside = '/' . context_course::instance($course1->id)->id . '/course/overviewfiles/0/a.png';
        $outside = '/' . context_course::instance($course2->id)->id . '/course/overviewfiles/0/a.png';
        $ok = download_handler::authorize($this->ticket(tickets::download_url(
            $ctx,
            ['k' => tickets::KIND_FILE, 'rp' => $inside]
        )['url']));
        $this->assertSame($restriction->id, $ok['context']->id);

        $this->expectException(\core_external\restricted_context_exception::class);
        $link = tickets::download_url($ctx, ['k' => tickets::KIND_FILE, 'rp' => $outside]);
        download_handler::authorize($this->ticket($link['url']));
    }
}
