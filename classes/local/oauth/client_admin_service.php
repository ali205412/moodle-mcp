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

namespace webservice_mcp\local\oauth;

use context_system;
use stdClass;
use webservice_mcp\local\auth\credential_manager;

/**
 * Administration of OAuth clients: pre-registration, listing, and revocation.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client_admin_service {
    /** Capability required to manage OAuth clients. */
    public const CAPABILITY = 'webservice/mcp:manageconnectors';

    /** Client table. */
    private const TABLE = 'webservice_mcp_oauth_client';

    /**
     * Pre-register a client (isdynamic = 0). A confidential client's secret is returned only here.
     *
     * @param array $input name, redirecturis (string[]), confidential (bool), scope (read|write).
     * @param stdClass $actor Acting user.
     * @return array Registration response (client_id, client_secret if confidential, ...).
     */
    public function register(array $input, stdClass $actor): array {
        require_capability(self::CAPABILITY, context_system::instance(), $actor);

        $scope = ($input['scope'] ?? 'write') === 'read'
            ? service::SCOPE_READ . ' ' . service::SCOPE_OFFLINE
            : service::REGISTRATION_DEFAULT_SCOPE;

        return (new service())->register_dynamic_client([
            'client_name' => (string)($input['name'] ?? ''),
            'redirect_uris' => array_values(array_filter(array_map('trim', (array)($input['redirecturis'] ?? [])))),
            'token_endpoint_auth_method' => !empty($input['confidential']) ? 'client_secret_basic' : 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'scope' => $scope,
        ], false);
    }

    /**
     * List clients, newest first, with their number of active credentials.
     *
     * @param string $type '', dynamic, metadata, or registered.
     * @param bool $includerevoked Whether to include revoked clients.
     * @param int $limitfrom Offset.
     * @param int $limitnum Page size (0 = all).
     * @return array
     */
    public function list_clients(string $type = '', bool $includerevoked = false, int $limitfrom = 0, int $limitnum = 0): array {
        global $DB;

        [$where, $params] = $this->filter_sql($type, $includerevoked);
        return $DB->get_records_sql(
            "SELECT oc.id, oc.clientid, oc.clientname, oc.redirecturis, oc.tokenauthmethod, oc.isdynamic, oc.revoked,
                    oc.timecreated,
                    (SELECT COUNT(1) FROM {webservice_mcp_credential} c
                      WHERE c.oauthclientid = oc.clientid AND c.revoked = 0) AS activecredentials
               FROM {" . self::TABLE . "} oc
              WHERE {$where}
           ORDER BY oc.timecreated DESC, oc.id DESC",
            $params,
            $limitfrom,
            $limitnum
        );
    }

    /**
     * Count clients.
     *
     * @param string $type '', dynamic, metadata, or registered.
     * @param bool $includerevoked Whether to include revoked clients.
     * @return int
     */
    public function count_clients(string $type = '', bool $includerevoked = false): int {
        global $DB;

        [$where, $params] = $this->filter_sql($type, $includerevoked);
        return (int)$DB->count_records_sql("SELECT COUNT(1) FROM {" . self::TABLE . "} oc WHERE {$where}", $params);
    }

    /**
     * Revoke a client and every credential and pending code it holds.
     *
     * @param string $clientid OAuth client id.
     * @param stdClass $actor Acting user.
     * @return bool Whether the client existed.
     */
    public function revoke(string $clientid, stdClass $actor): bool {
        global $DB;

        require_capability(self::CAPABILITY, context_system::instance(), $actor);

        $client = $DB->get_record(self::TABLE, ['clientid' => $clientid]);
        if (!$client) {
            return false;
        }
        $DB->update_record(self::TABLE, (object)['id' => $client->id, 'revoked' => 1, 'timemodified' => time()]);
        (new credential_manager())->revoke_where(['oauthclientid' => $clientid], (int)$actor->id);
        $DB->set_field('webservice_mcp_oauth_code', 'used', 1, ['clientid' => $clientid, 'used' => 0]);

        return true;
    }

    /**
     * Replace a confidential client's secret. The new secret is returned only here.
     *
     * With setting secretrotationoverlap > 0 the old secret keeps working for that many seconds.
     *
     * @param string $clientid OAuth client id.
     * @param stdClass $actor Acting user.
     * @return string New plaintext secret.
     */
    public function rotate_secret(string $clientid, stdClass $actor): string {
        global $DB;

        require_capability(self::CAPABILITY, context_system::instance(), $actor);

        $client = $DB->get_record(self::TABLE, ['clientid' => $clientid, 'revoked' => 0]);
        if (!$client || $client->tokenauthmethod === 'none') {
            throw new exception('invalid_client', 400, 'Only active confidential clients have a secret to rotate.');
        }

        $overlap = max(0, (int)get_config('webservice_mcp', 'secretrotationoverlap'));
        $secret = bin2hex(random_bytes(24));
        $DB->update_record(self::TABLE, (object)[
            'id' => $client->id,
            'clientsecret' => password_hash($secret, PASSWORD_DEFAULT),
            'previoussecret' => $overlap > 0 ? $client->clientsecret : null,
            'previoussecretexpires' => $overlap > 0 ? time() + $overlap : null,
            'timemodified' => time(),
        ]);

        return $secret;
    }

    /**
     * Describe how a client was registered.
     *
     * @param stdClass $client Client record.
     * @return string dynamic, metadata, or registered.
     */
    public static function registration_type(stdClass $client): string {
        if (!empty($client->isdynamic)) {
            return 'dynamic';
        }
        return client_registry::is_metadata_document_client_id((string)$client->clientid) ? 'metadata' : 'registered';
    }

    /**
     * Build the client filter.
     *
     * @param string $type '', dynamic, metadata, or registered.
     * @param bool $includerevoked Whether to include revoked clients.
     * @return array [where SQL, params]
     */
    private function filter_sql(string $type, bool $includerevoked): array {
        global $DB;

        $where = $includerevoked ? '1 = 1' : 'oc.revoked = 0';
        $params = ['https' => 'https://%'];
        $where .= match ($type) {
            'dynamic' => ' AND oc.isdynamic = 1',
            'metadata' => ' AND oc.isdynamic = 0 AND ' . $DB->sql_like('oc.clientid', ':https'),
            'registered' => ' AND oc.isdynamic = 0 AND ' . $DB->sql_like('oc.clientid', ':https', true, true, true),
            default => '',
        };
        if (!in_array($type, ['metadata', 'registered'], true)) {
            $params = [];
        }

        return [$where, $params];
    }
}
