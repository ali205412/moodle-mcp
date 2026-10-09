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

declare(strict_types=1);

namespace webservice_mcp\local\auth;

use context;
use context_system;
use stdClass;
use webservice_mcp\local\oauth\service as oauth_service;

/**
 * Administrator pre-approval of OAuth access, letting users skip the consent screen for trusted redirect hosts.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preapproval_service {
    /** Pre-approval table. */
    private const TABLE = 'webservice_mcp_preapproval';

    /** Redirect hosts covered when none are given. */
    public const DEFAULT_HOSTS = "claude.ai\nclaude.com\nlocalhost\n127.0.0.1\n[::1]";

    /** @var admin_key_service */
    private admin_key_service $keys;

    /**
     * Constructor.
     *
     * @param admin_key_service|null $keys Optional admin key service override (target checks).
     */
    public function __construct(?admin_key_service $keys = null) {
        $this->keys = $keys ?? new admin_key_service();
    }

    /**
     * Pre-approve many users, replacing any earlier pre-approval for the same user and context.
     *
     * @param int[] $userids Target user ids.
     * @param array $options scope (read|write), redirecthosts (string|string[]), contextid, expiresdays (0 = none), failonskip.
     * @param stdClass $issuer Issuing user.
     * @return stdClass[] Result rows as admin_key_service::check_targets(), status 'preapproved' when stored.
     */
    public function preapprove(array $userids, array $options, stdClass $issuer): array {
        global $DB;

        admin_key_service::require_issuer($issuer);

        $context = !empty($options['contextid']) ? context::instance_by_id((int)$options['contextid']) : context_system::instance();
        $scope = admin_key_service::scope_from_option((string)($options['scope'] ?? 'read'));
        $hosts = self::normalize_hosts($options['redirecthosts'] ?? self::DEFAULT_HOSTS);
        $days = (int)($options['expiresdays'] ?? 0);
        $expiry = $days > 0 ? time() + $days * DAYSECS : 0;

        $results = $this->keys->check_targets($userids, $context, $issuer);
        if (!empty($options['failonskip']) && array_filter($results, static fn(stdClass $row): bool => $row->reason !== '')) {
            return $results;
        }

        foreach ($results as $row) {
            if ($row->reason !== '') {
                continue;
            }
            $DB->delete_records(self::TABLE, ['userid' => $row->userid, 'contextid' => $context->id]);
            $DB->insert_record(self::TABLE, (object)[
                'userid' => $row->userid,
                'scope' => $scope,
                'redirecthosts' => implode("\n", $hosts),
                'contextid' => $context->id,
                'issuerid' => (int)$issuer->id,
                'expiry' => $expiry,
                'timecreated' => time(),
            ]);
            $row->status = 'preapproved';
            $row->scope = $scope;
            $row->expires = $expiry;
        }

        return $results;
    }

    /**
     * Remove pre-approvals.
     *
     * @param int[] $userids Users to remove (empty with an issuer id removes everything that issuer created).
     * @param stdClass $actor User performing the change.
     * @param int $issuerid Optional issuer filter.
     * @return int Number removed.
     */
    public function unpreapprove(array $userids, stdClass $actor, int $issuerid = 0): int {
        global $DB;

        admin_key_service::require_issuer($actor);

        $conditions = $issuerid > 0 ? ['issuerid' => $issuerid] : [];
        if (!$userids) {
            if (!$conditions) {
                return 0;
            }
            $count = $DB->count_records(self::TABLE, $conditions);
            $DB->delete_records(self::TABLE, $conditions);
            return $count;
        }

        $count = 0;
        foreach (array_map('intval', $userids) as $userid) {
            $count += $DB->count_records(self::TABLE, $conditions + ['userid' => $userid]);
            $DB->delete_records(self::TABLE, $conditions + ['userid' => $userid]);
        }
        return $count;
    }

    /**
     * Find a valid pre-approval covering an authorization request.
     *
     * @param int $userid Logged-in user.
     * @param string $redirecthost Host of the validated redirect URI.
     * @param string $scope Requested scope (offline_access is always allowed).
     * @param int $contextid Requested context id.
     * @return stdClass|null
     */
    public function find(int $userid, string $redirecthost, string $scope, int $contextid): ?stdClass {
        global $DB;

        $records = $DB->get_records_select(
            self::TABLE,
            'userid = :userid AND contextid = :contextid AND (expiry = 0 OR expiry > :now)',
            ['userid' => $userid, 'contextid' => $contextid, 'now' => time()]
        );

        $host = self::normalize_host($redirecthost);
        $requested = array_diff(preg_split('/\s+/', trim($scope)) ?: [], [oauth_service::SCOPE_OFFLINE, '']);
        foreach ($records as $record) {
            $allowed = preg_split('/\s+/', trim((string)$record->scope)) ?: [];
            if (in_array($host, self::normalize_hosts($record->redirecthosts), true) && !array_diff($requested, $allowed)) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Whether a request may skip consent at all, before looking for a matching pre-approval.
     *
     * Self-registered (dynamic) clients never qualify. Metadata-document clients qualify only when listed in the
     * preapprovalclientids setting. The browser must report Sec-Fetch-Site none or same-origin, so a page on
     * another site cannot silently mint a code; a missing header means the consent page is shown.
     *
     * @param stdClass $client Hydrated OAuth client.
     * @param string $secfetchsite Value of the Sec-Fetch-Site request header ('' when absent).
     * @return bool
     */
    public function may_auto_approve(stdClass $client, string $secfetchsite): bool {
        if (!in_array(strtolower(trim($secfetchsite)), ['none', 'same-origin'], true) || !empty($client->isdynamic)) {
            return false;
        }
        if (!\webservice_mcp\local\oauth\client_registry::is_metadata_document_client_id((string)$client->clientid)) {
            return true;
        }

        $allowed = preg_split('/\s+/', trim((string)get_config('webservice_mcp', 'preapprovalclientids'))) ?: [];
        return in_array((string)$client->clientid, $allowed, true);
    }

    /**
     * Record an automatic approval in the audit trail.
     *
     * @param int $userid User.
     * @param string $clientid OAuth client id.
     * @param int $contextid Context id.
     * @return void
     */
    public function record_auto_approval(int $userid, string $clientid, int $contextid): void {
        global $DB;

        $DB->insert_record('webservice_mcp_audit', (object)[
            'timecreated' => time(),
            'userid' => $userid,
            'contextid' => $contextid,
            'action' => 'oauth_preapproved',
            'toolname' => \core_text::substr($clientid, 0, 255),
            'mutating' => 0,
            'outcome' => 'success',
            'detailcode' => 'preapproval',
            'auditid' => bin2hex(random_bytes(16)),
        ]);
    }

    /**
     * List pre-approvals, newest first.
     *
     * @param int $limitfrom Offset.
     * @param int $limitnum Page size (0 = all).
     * @return array
     */
    public function list_preapprovals(int $limitfrom = 0, int $limitnum = 0): array {
        global $DB;

        return $DB->get_records_sql(
            'SELECT p.*, u.username, u.email
               FROM {' . self::TABLE . '} p
               JOIN {user} u ON u.id = p.userid
           ORDER BY p.timecreated DESC, p.id DESC',
            [],
            $limitfrom,
            $limitnum
        );
    }

    /**
     * Count pre-approvals.
     *
     * @return int
     */
    public function count_preapprovals(): int {
        global $DB;

        return $DB->count_records(self::TABLE);
    }

    /**
     * Normalize a host list.
     *
     * @param string|array $hosts Newline/comma separated string or array.
     * @return string[]
     */
    public static function normalize_hosts(string|array $hosts): array {
        $list = is_array($hosts) ? $hosts : (preg_split('/[\s,]+/', $hosts) ?: []);
        return array_values(array_unique(array_filter(array_map([self::class, 'normalize_host'], $list))));
    }

    /**
     * Normalize one host for comparison.
     *
     * @param string $host Host.
     * @return string
     */
    public static function normalize_host(string $host): string {
        return trim(strtolower(trim($host)), '[]');
    }
}
