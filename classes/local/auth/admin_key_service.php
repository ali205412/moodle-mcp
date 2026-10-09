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
use context_course;
use context_system;
use moodle_exception;
use moodle_url;
use required_capability_exception;
use stdClass;
use webservice_mcp\local\oauth\service as oauth_service;

/**
 * Bulk administration of per-user MCP keys: target selection, eligibility, issuance, listing, revocation.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_key_service {
    /** Capability required to issue keys or pre-approvals for other users. */
    public const CAPABILITY = 'webservice/mcp:issueforothers';

    /** Users loaded per database round trip. */
    private const CHUNK = 500;

    /** Default key lifetime in days. */
    private const DEFAULT_DAYS = 365;

    /** @var credential_manager */
    private credential_manager $credentialmanager;

    /** @var connector_service_manager */
    private connector_service_manager $connectormanager;

    /**
     * Constructor.
     *
     * @param credential_manager|null $credentialmanager Optional manager override.
     * @param connector_service_manager|null $connectormanager Optional service manager override.
     */
    public function __construct(
        ?credential_manager $credentialmanager = null,
        ?connector_service_manager $connectormanager = null
    ) {
        $this->credentialmanager = $credentialmanager ?? new credential_manager();
        $this->connectormanager = $connectormanager ?? new connector_service_manager();
    }

    /**
     * Require the issuer to hold webservice/mcp:issueforothers.
     *
     * @param stdClass $issuer Issuing user.
     * @return void
     */
    public static function require_issuer(stdClass $issuer): void {
        if (!has_capability(self::CAPABILITY, context_system::instance(), $issuer)) {
            throw new required_capability_exception(context_system::instance(), self::CAPABILITY, 'nopermissions', '');
        }
    }

    /**
     * Resolve bulk selectors to a unique list of user ids.
     *
     * @param array $selector userids (int[]), identifiers (usernames, emails or ids), idnumbers, cohortid, courseid, roleid,
     *     allmcpusers.
     * @return int[]
     */
    public function resolve_targets(array $selector): array {
        global $CFG, $DB;

        $ids = array_map('intval', $selector['userids'] ?? []);

        foreach ($selector['identifiers'] ?? [] as $identifier) {
            $identifier = trim((string)$identifier);
            if ($identifier === '') {
                continue;
            }
            if (ctype_digit($identifier)) {
                $ids[] = (int)$identifier;
                continue;
            }
            $field = str_contains($identifier, '@') ? 'email' : 'username';
            $value = $field === 'username' ? \core_text::strtolower($identifier) : $identifier;
            $select = $field === 'email' ? $DB->sql_equal('email', ':value', false) : 'username = :value';
            $ids = array_merge($ids, array_map('intval', $DB->get_fieldset_select(
                'user',
                'id',
                $select . ' AND deleted = 0 AND mnethostid = :mnethostid',
                ['value' => $value, 'mnethostid' => $CFG->mnet_localhost_id]
            )));
        }

        $idnumbers = array_values(array_filter(array_map('trim', $selector['idnumbers'] ?? [])));
        if ($idnumbers) {
            [$insql, $params] = $DB->get_in_or_equal($idnumbers, SQL_PARAMS_NAMED);
            $ids = array_merge($ids, array_map('intval', $DB->get_fieldset_select(
                'user',
                'id',
                "idnumber {$insql} AND deleted = 0 AND mnethostid = :mnethostid",
                $params + ['mnethostid' => $CFG->mnet_localhost_id]
            )));
        }

        if (!empty($selector['cohortid'])) {
            $ids = array_merge($ids, array_map('intval', $DB->get_fieldset_select(
                'cohort_members',
                'userid',
                'cohortid = ?',
                [(int)$selector['cohortid']]
            )));
        }

        if (!empty($selector['courseid'])) {
            $context = context_course::instance((int)$selector['courseid']);
            $users = !empty($selector['roleid'])
                ? get_role_users((int)$selector['roleid'], $context, false, 'u.id')
                : get_enrolled_users($context, '', 0, 'u.id', null, 0, 0, true);
            $ids = array_merge($ids, array_map('intval', array_keys($users)));
        }

        if (!empty($selector['allmcpusers'])) {
            $users = get_users_by_capability(context_system::instance(), 'webservice/mcp:use', 'u.id');
            $ids = array_merge($ids, array_map('intval', array_keys($users)));
        }

        return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    }

    /**
     * Return why a user cannot receive access issued by this issuer, or null when they can.
     *
     * @param stdClass|null $user Target user record.
     * @param context $context Context the access is restricted to.
     * @param stdClass $issuer Issuing user.
     * @return string|null Reason code (lang string adminkeys:reason_*).
     */
    public function check_target(?stdClass $user, context $context, stdClass $issuer): ?string {
        if (!$user || !empty($user->deleted)) {
            return 'notfound';
        }
        if (isguestuser($user)) {
            return 'guest';
        }
        if (!empty($user->suspended) || empty($user->confirmed) || $user->auth === 'nologin') {
            return 'inactive';
        }
        if (is_siteadmin($user)) {
            if (!is_siteadmin($issuer)) {
                return 'siteadmin';
            }
            $allowsiteadmins = get_config('webservice_mcp', 'allowsiteadmins');
            if ($allowsiteadmins !== false && empty($allowsiteadmins)) {
                return 'siteadminsdisallowed';
            }
        }
        // Only site administrators may mint access for users at least as privileged as an issuer.
        if (!is_siteadmin($issuer)) {
            foreach ([self::CAPABILITY, 'moodle/site:config', 'moodle/user:loginas'] as $capability) {
                if (has_capability($capability, context_system::instance(), $user)) {
                    return 'privileged';
                }
            }
        }
        if (!has_capability('webservice/mcp:use', $context, $user)) {
            return 'nocapability';
        }

        return null;
    }

    /**
     * Issue admin keys to many users.
     *
     * @param int[] $userids Target user ids.
     * @param array $options label, expiresdays, scope (read|write), contextid, failonskip.
     * @param stdClass $issuer Issuing user.
     * @return array Result rows (userid, username, email, fullname, status, reason, label, scope, expires, token).
     */
    public function issue(array $userids, array $options, stdClass $issuer): array {
        self::require_issuer($issuer);

        $label = trim((string)($options['label'] ?? ''))
            ?: get_string('adminkeys:defaultlabel', 'webservice_mcp', userdate(time(), '%Y-%m-%d'));
        $label = \core_text::substr($label, 0, 255);
        $scope = self::scope_from_option((string)($options['scope'] ?? 'read'));
        $context = !empty($options['contextid']) ? context::instance_by_id((int)$options['contextid']) : context_system::instance();
        $validuntil = time() + self::clamp_days((int)($options['expiresdays'] ?? self::DEFAULT_DAYS)) * DAYSECS;
        $service = $this->connectormanager->ensure_connector_service();
        $mayadd = is_siteadmin($issuer) ||
            has_capability('moodle/webservice:managealltokens', context_system::instance(), $issuer);

        $lock = \core\lock\lock_config::get_lock_factory('webservice_mcp')->get_lock('adminkeys', 60);
        if (!$lock) {
            throw new moodle_exception('locktimeout');
        }
        try {
            $servicecheck = fn(stdClass $user): ?string =>
                $this->connectormanager->admin_authorisation_problem($service, (int)$user->id, $mayadd);
            $results = $this->check_targets($userids, $context, $issuer, $servicecheck);
            $failed = array_filter($results, static fn(stdClass $row): bool => $row->reason !== '');
            if ($failed && !empty($options['failonskip'])) {
                return $results;
            }

            $resourceuri = (new oauth_service())->canonical_resource_uri();
            foreach ($results as $row) {
                if ($row->reason !== '') {
                    continue;
                }
                $this->connectormanager->authorise_user_explicitly($service, $row->userid);
                $credential = $this->credentialmanager->issue_admin_credential($row->userid, $issuer, [
                    'service' => $service,
                    'context' => $context,
                    'scope' => $scope,
                    'validuntil' => $validuntil,
                    'label' => $label,
                    'resourceuri' => $resourceuri,
                ]);
                $row->status = 'issued';
                $row->token = $credential->token;
                $row->label = $label;
                $row->scope = $scope;
                $row->expires = $validuntil;
                $this->notify($row, $issuer);
            }
        } finally {
            $lock->release();
        }

        return $results;
    }

    /**
     * Load users in chunks and run the shared and extra eligibility checks.
     *
     * @param int[] $userids Target user ids.
     * @param context $context Restricted context.
     * @param stdClass $issuer Issuing user.
     * @param callable|null $extracheck fn(stdClass $user): ?string extra problem code.
     * @return stdClass[] Result rows with status 'skipped' or 'pending' and reason '' when eligible.
     */
    public function check_targets(array $userids, context $context, stdClass $issuer, ?callable $extracheck = null): array {
        global $DB;

        $results = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $userids))), self::CHUNK) as $chunk) {
            $users = $DB->get_records_list('user', 'id', $chunk);
            foreach ($chunk as $userid) {
                $user = $users[$userid] ?? null;
                $reason = $this->check_target($user, $context, $issuer) ?? ($extracheck ? $extracheck($user) : null);
                $results[] = (object)[
                    'userid' => $userid,
                    'username' => $user->username ?? '',
                    'email' => $user->email ?? '',
                    'fullname' => $user ? fullname($user) : '',
                    'status' => $reason === null ? 'pending' : 'skipped',
                    'reason' => (string)$reason,
                    'label' => '',
                    'scope' => '',
                    'expires' => 0,
                    'token' => '',
                ];
            }
        }

        return $results;
    }

    /**
     * List active admin-issued keys, newest first.
     *
     * @param array $filters label, issuerid, userid.
     * @param int $limitfrom Offset.
     * @param int $limitnum Page size (0 = all).
     * @return array Key records with the target's username and email.
     */
    public function list_keys(array $filters, int $limitfrom = 0, int $limitnum = 0): array {
        global $DB;

        [$where, $params] = self::filter_sql($filters);
        return $DB->get_records_sql(
            "SELECT c.id, c.userid, c.issuerid, c.name, c.scope, c.timecreated, c.validuntil, c.lastaccess,
                    u.username, u.email
               FROM {webservice_mcp_credential} c
               JOIN {user} u ON u.id = c.userid
              WHERE {$where}
           ORDER BY c.timecreated DESC, c.id DESC",
            $params,
            $limitfrom,
            $limitnum
        );
    }

    /**
     * Count active admin-issued keys.
     *
     * @param array $filters label, issuerid, userid.
     * @return int
     */
    public function count_keys(array $filters): int {
        global $DB;

        [$where, $params] = self::filter_sql($filters);
        return (int)$DB->count_records_sql("SELECT COUNT(1) FROM {webservice_mcp_credential} c WHERE {$where}", $params);
    }

    /**
     * Revoke active admin-issued keys by id or by filters.
     *
     * @param array $filters ids (int[]), label, issuerid, userids (int[]).
     * @param stdClass $actor User performing the revocation.
     * @return int Number of keys revoked.
     */
    public function revoke_keys(array $filters, stdClass $actor): int {
        self::require_issuer($actor);

        $base = ['tokentype' => credential_manager::TOKEN_TYPE_ADMIN];
        if (!empty($filters['label'])) {
            $base['name'] = (string)$filters['label'];
        }
        if (!empty($filters['issuerid'])) {
            $base['issuerid'] = (int)$filters['issuerid'];
        }

        $sets = [];
        foreach ($filters['ids'] ?? [] as $id) {
            $sets[] = $base + ['id' => (int)$id];
        }
        foreach ($filters['userids'] ?? [] as $userid) {
            $sets[] = $base + ['userid' => (int)$userid];
        }
        if (!$sets && count($base) > 1) {
            $sets[] = $base;
        }

        $count = 0;
        foreach ($sets as $conditions) {
            $count += $this->credentialmanager->revoke_where($conditions, (int)$actor->id);
        }
        return $count;
    }

    /**
     * Build the one-time CSV for issued keys. Plaintext tokens exist only in this output.
     *
     * @param stdClass[] $results Result rows from issue().
     * @return string
     */
    public static function build_csv(array $results): string {
        $serverurl = (new oauth_service())->canonical_resource_uri();
        $handle = fopen('php://temp', 'w+');
        $columns = ['userid', 'username', 'email', 'fullname', 'label', 'scope', 'expires', 'token', 'server_url',
            'claude_code_command', 'mcp_json'];
        fputcsv($handle, $columns, ',', '"', '');
        foreach ($results as $row) {
            if ($row->token === '') {
                continue;
            }
            fputcsv($handle, [
                $row->userid,
                $row->username,
                $row->email,
                $row->fullname,
                $row->label,
                $row->scope,
                gmdate('c', (int)$row->expires),
                $row->token,
                $serverurl,
                'claude mcp add --transport http moodle ' . $serverurl . ' --header "Authorization: Bearer ' . $row->token . '"',
                json_encode([
                    'type' => 'http',
                    'url' => $serverurl,
                    'headers' => ['Authorization' => 'Bearer ' . $row->token],
                ], JSON_UNESCAPED_SLASHES),
            ], ',', '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string)$csv;
    }

    /**
     * Split pasted or CSV text into user identifiers (usernames, emails, or ids).
     *
     * @param string $text Raw text.
     * @return string[]
     */
    public static function parse_identifiers(string $text): array {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $text) ?: [])));
    }

    /**
     * Parse an uploaded CSV/text file of users.
     *
     * A header row naming userid (or id), username, email, or idnumber selects that column; otherwise the first
     * column is read as usernames, emails, or ids.
     *
     * @param string $content File content.
     * @return array Selector parts: userids, identifiers, idnumbers.
     */
    public static function parse_users_file(string $content): array {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\R/', (string)$content) ?: [];
        $lines = array_values(array_filter($lines, static fn($line): bool => trim($line) !== ''));
        $selector = ['userids' => [], 'identifiers' => [], 'idnumbers' => []];
        if (!$lines) {
            return $selector;
        }

        $delimiter = ',';
        foreach (["\t", ';'] as $candidate) {
            if (!str_contains($lines[0], ',') && str_contains($lines[0], $candidate)) {
                $delimiter = $candidate;
            }
        }
        $rows = array_map(static fn(string $line): array => str_getcsv($line, $delimiter, '"', ''), $lines);

        $header = array_map(static fn($cell): string => strtolower(trim((string)$cell)), $rows[0]);
        $column = 0;
        $target = 'identifiers';
        foreach (
            ['userid' => 'userids', 'id' => 'userids', 'username' => 'identifiers', 'email' => 'identifiers',
                'idnumber' => 'idnumbers'] as $name => $key
        ) {
            $index = array_search($name, $header, true);
            if ($index !== false) {
                $column = (int)$index;
                $target = $key;
                array_shift($rows);
                break;
            }
        }

        foreach ($rows as $row) {
            $value = trim((string)($row[$column] ?? ''));
            if ($value !== '') {
                $selector[$target][] = $target === 'userids' ? (int)$value : $value;
            }
        }

        return $selector;
    }

    /**
     * Map a read/write option to a scope string.
     *
     * @param string $option read, write, or a scope string.
     * @return string
     */
    public static function scope_from_option(string $option): string {
        return in_array($option, ['write', oauth_service::SCOPE_WRITE, 'mcp:read mcp:write'], true)
            ? oauth_service::SCOPE_READ . ' ' . oauth_service::SCOPE_WRITE
            : oauth_service::SCOPE_READ;
    }

    /**
     * Clamp a requested lifetime to 1..adminkeymaxdays.
     *
     * @param int $days Requested days.
     * @return int
     */
    public static function clamp_days(int $days): int {
        $max = (int)get_config('webservice_mcp', 'adminkeymaxdays') ?: self::DEFAULT_DAYS;
        return max(1, min($days > 0 ? $days : $max, $max));
    }

    /**
     * Build the WHERE clause for admin-key listing.
     *
     * @param array $filters label, issuerid, userid.
     * @return array [sql, params]
     */
    private static function filter_sql(array $filters): array {
        $where = 'c.tokentype = :tokentype AND c.revoked = 0 AND (c.validuntil IS NULL OR c.validuntil >= :now)';
        $params = ['tokentype' => credential_manager::TOKEN_TYPE_ADMIN, 'now' => time()];
        foreach (['label' => 'c.name', 'issuerid' => 'c.issuerid', 'userid' => 'c.userid'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where .= " AND {$column} = :{$key}";
                $params[$key] = $filters[$key];
            }
        }
        return [$where, $params];
    }

    /**
     * Tell the target user a key was created for them, when the site enables it.
     *
     * @param stdClass $row Result row.
     * @param stdClass $issuer Issuing user.
     * @return void
     */
    private function notify(stdClass $row, stdClass $issuer): void {
        if (!get_config('webservice_mcp', 'notifyonadminkey')) {
            return;
        }

        $a = (object)['label' => $row->label, 'issuer' => fullname($issuer), 'expires' => userdate($row->expires)];
        $message = new \core\message\message();
        $message->component = 'webservice_mcp';
        $message->name = 'adminkeyissued';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $row->userid;
        $message->subject = get_string('adminkeys:notifysubject', 'webservice_mcp');
        $message->fullmessage = get_string('adminkeys:notifybody', 'webservice_mcp', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = text_to_html($message->fullmessage);
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        $message->contexturl = (new moodle_url('/webservice/mcp/connections.php'))->out(false);
        $message->contexturlname = get_string('connections:heading', 'webservice_mcp');
        message_send($message);
    }
}
