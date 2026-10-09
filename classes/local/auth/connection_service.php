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

/**
 * Connected-apps view over connector credentials: OAuth token families, admin keys, and bootstrap credentials.
 *
 * Access checks are the caller's job (connections.php, admin pages).
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connection_service {
    /** Connector credential table name. */
    private const TABLE = 'webservice_mcp_credential';

    /** @var credential_manager */
    private credential_manager $credentialmanager;

    /**
     * Constructor.
     *
     * @param credential_manager|null $credentialmanager Optional manager override.
     */
    public function __construct(?credential_manager $credentialmanager = null) {
        $this->credentialmanager = $credentialmanager ?? new credential_manager();
    }

    /**
     * Rows per page on admin and connected-apps listings (setting adminlistperpage).
     *
     * @return int
     */
    public static function per_page(): int {
        $perpage = (int)get_config('webservice_mcp', 'adminlistperpage');
        return $perpage > 0 ? min($perpage, 1000) : 50;
    }

    /**
     * List active connections (OAuth token families or standalone credentials), newest first.
     *
     * @param int|null $userid Restrict to one user, or null for all users.
     * @param int $limitfrom Offset in connections.
     * @param int $limitnum Page size in connections (0 = all).
     * @return array Connection objects keyed by connection key.
     */
    public function list_connections(?int $userid, int $limitfrom = 0, int $limitnum = 0): array {
        global $DB;

        [$groupkey, $where, $params] = $this->grouping_sql($userid);
        $records = $DB->get_records_sql(
            "SELECT {$groupkey} AS connectionkey,
                    MAX(c.userid) AS userid, MAX(c.oauthclientid) AS clientid, MAX(c.name) AS name, MAX(c.label) AS keylabel,
                    MAX(oc.clientname) AS clientname, MAX(c.tokentype) AS tokentype, MAX(c.issuerid) AS issuerid,
                    MAX(c.contextid) AS contextid, MAX(c.scope) AS scope,
                    MIN(COALESCE(c.familycreated, c.timecreated)) AS timecreated,
                    MAX(COALESCE(c.lastaccess, 0)) AS lastaccess, MAX(COALESCE(c.validuntil, 0)) AS validuntil
               FROM {" . self::TABLE . "} c
          LEFT JOIN {webservice_mcp_oauth_client} oc ON oc.clientid = c.oauthclientid
              WHERE {$where}
           GROUP BY {$groupkey}
           ORDER BY MIN(COALESCE(c.familycreated, c.timecreated)) DESC, {$groupkey} ASC",
            $params,
            $limitfrom,
            $limitnum
        );

        $connections = [];
        foreach ($records as $record) {
            $connections[$record->connectionkey] = (object)[
                'key' => $record->connectionkey,
                'userid' => (int)$record->userid,
                'clientid' => (string)($record->clientid ?? ''),
                'label' => (string)($record->keylabel ?: ($record->clientname ?: ($record->clientid ?: $record->name))),
                'tokentype' => (int)$record->tokentype,
                'issuerid' => (int)$record->issuerid,
                'contextid' => (int)$record->contextid,
                'scope' => (string)($record->scope ?? ''),
                'timecreated' => (int)$record->timecreated,
                'lastaccess' => (int)$record->lastaccess,
                'validuntil' => (int)$record->validuntil,
            ];
        }

        return $connections;
    }

    /**
     * Count active connections.
     *
     * @param int|null $userid Restrict to one user, or null for all users.
     * @return int
     */
    public function count_connections(?int $userid): int {
        global $DB;

        [$groupkey, $where, $params] = $this->grouping_sql($userid);
        return (int)$DB->count_records_sql(
            "SELECT COUNT(1) FROM (SELECT {$groupkey} AS connectionkey
                                     FROM {" . self::TABLE . "} c
                                    WHERE {$where}
                                 GROUP BY {$groupkey}) conns",
            $params
        );
    }

    /**
     * Build the connection key expression and filter for active credentials.
     *
     * @param int|null $userid Restrict to one user, or null for all users.
     * @return array [group key SQL, where SQL, params]
     */
    private function grouping_sql(?int $userid): array {
        global $DB;

        $groupkey = "CASE WHEN c.familyid IS NULL OR c.familyid = '' THEN " . $DB->sql_concat("'c_'", 'c.id')
            . ' ELSE ' . $DB->sql_concat("'f_'", 'c.familyid') . ' END';
        $where = 'c.revoked = 0 AND (c.validuntil IS NULL OR c.validuntil = 0 OR c.validuntil >= :now)';
        $params = ['now' => time()];
        if ($userid !== null) {
            $where .= ' AND c.userid = :userid';
            $params['userid'] = $userid;
        }

        return [$groupkey, $where, $params];
    }

    /**
     * Revoke a connection returned by list_connections().
     *
     * @param string $key Connection key.
     * @param int|null $userid Owning user the connection must belong to, or null for any user.
     * @param int $usermodified User performing the revocation.
     * @return bool True if anything was revoked.
     */
    public function revoke_connection(string $key, ?int $userid, int $usermodified): bool {
        if (str_starts_with($key, 'f_') && strlen($key) > 2) {
            $conditions = ['familyid' => substr($key, 2)];
        } else if (str_starts_with($key, 'c_') && ctype_digit(substr($key, 2))) {
            $conditions = ['id' => (int)substr($key, 2)];
        } else {
            return false;
        }
        if ($userid !== null) {
            $conditions['userid'] = $userid;
        }

        return $this->credentialmanager->revoke_where($conditions, $usermodified) > 0;
    }
}
