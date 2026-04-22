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
use core_external\util;
use core\session\manager;
use dml_exception;
use moodle_exception;
use stdClass;

/**
 * Plugin-managed credential lifecycle for Moodle MCP connector access.
 *
 * This wraps Moodle token concepts (service binding, context restriction,
 * session linkage, expiry, IP restriction) without making raw permanent
 * webservice tokens the public connector contract.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class credential_manager {
    /** Session-linked or short-lived bootstrap credential. */
    public const TOKEN_TYPE_BOOTSTRAP = 0;

    /** Explicit durable remote grant. */
    public const TOKEN_TYPE_DURABLE = 1;

    /** OAuth refresh token. */
    public const TOKEN_TYPE_REFRESH = 2;

    /** Connector credential table name. */
    private const TABLE = 'webservice_mcp_credential';

    /** Default bootstrap lifetime in seconds. */
    private const DEFAULT_BOOTSTRAP_TTL = 900;

    /** Default OAuth access-token lifetime in seconds. */
    private const DEFAULT_OAUTH_ACCESS_TTL = 3600;

    /** Default OAuth refresh-token lifetime in seconds. */
    private const DEFAULT_REFRESH_TTL = 2592000;

    /**
     * Issue a short-lived bootstrap credential.
     *
     * @param stdClass $service Service-like object with id/shortname/name metadata.
     * @param int $userid Moodle user id.
     * @param context|null $context Restricted context for the credential.
     * @param array $options Optional overrides.
     * @return stdClass Stored credential record.
     * @throws dml_exception
     */
    public function issue_bootstrap_credential(stdClass $service, int $userid, ?context $context = null, array $options = []): stdClass {
        $context ??= context_system::instance();
        $sid = $options['sid'] ?? session_id();
        $validuntil = (int)($options['validuntil'] ?? (time() + self::DEFAULT_BOOTSTRAP_TTL));

        return $this->issue_credential(
            self::TOKEN_TYPE_BOOTSTRAP,
            $service,
            $userid,
            $context,
            [
                'sid' => $sid !== '' ? $sid : null,
                'validuntil' => $validuntil,
                'iprestriction' => $options['iprestriction'] ?? null,
                'name' => $options['name'] ?? $this->default_name('Bootstrap'),
                'usermodified' => $options['usermodified'] ?? $userid,
            ]
        );
    }

    /**
     * Issue an explicit durable remote grant.
     *
     * @param stdClass $service Service-like object with id/shortname/name metadata.
     * @param int $userid Moodle user id.
     * @param context|null $context Restricted context for the credential.
     * @param array $options Optional overrides.
     * @return stdClass Stored credential record.
     * @throws dml_exception
     */
    public function issue_durable_grant(stdClass $service, int $userid, ?context $context = null, array $options = []): stdClass {
        global $CFG;

        $context ??= context_system::instance();
        $validuntil = (int)($options['validuntil'] ?? (time() + (int)$CFG->tokenduration));

        return $this->issue_credential(
            self::TOKEN_TYPE_DURABLE,
            $service,
            $userid,
            $context,
            [
                'sid' => null,
                'validuntil' => $validuntil,
                'iprestriction' => $options['iprestriction'] ?? null,
                'name' => $options['name'] ?? $this->default_name('Remote'),
                'scope' => $options['scope'] ?? '',
                'resourceuri' => $options['resourceuri'] ?? null,
                'oauthclientid' => $options['oauthclientid'] ?? null,
                'usermodified' => $options['usermodified'] ?? $userid,
            ]
        );
    }

    /**
     * Issue an OAuth access token backed by the connector credential table.
     *
     * @param stdClass $service Service-like object with id/shortname/name metadata.
     * @param int $userid Moodle user id.
     * @param context|null $context Restricted context for the credential.
     * @param array $options Optional overrides.
     * @return stdClass Stored credential record.
     * @throws dml_exception
     */
    public function issue_oauth_access_token(stdClass $service, int $userid, ?context $context = null, array $options = []): stdClass {
        $context ??= context_system::instance();
        $validuntil = (int)($options['validuntil'] ?? (time() + self::DEFAULT_OAUTH_ACCESS_TTL));

        return $this->issue_credential(
            self::TOKEN_TYPE_DURABLE,
            $service,
            $userid,
            $context,
            [
                'sid' => null,
                'validuntil' => $validuntil,
                'iprestriction' => $options['iprestriction'] ?? null,
                'name' => $options['name'] ?? $this->default_name('OAuth access'),
                'scope' => $options['scope'] ?? '',
                'resourceuri' => $options['resourceuri'] ?? null,
                'oauthclientid' => $options['oauthclientid'] ?? null,
                'usermodified' => $options['usermodified'] ?? $userid,
            ]
        );
    }

    /**
     * Issue an OAuth refresh token backed by the connector credential table.
     *
     * @param stdClass $service Service-like object with id/shortname/name metadata.
     * @param int $userid Moodle user id.
     * @param context|null $context Restricted context for the credential.
     * @param array $options Optional overrides.
     * @return stdClass Stored credential record.
     * @throws dml_exception
     */
    public function issue_oauth_refresh_token(stdClass $service, int $userid, ?context $context = null, array $options = []): stdClass {
        $context ??= context_system::instance();
        $validuntil = (int)($options['validuntil'] ?? (time() + self::DEFAULT_REFRESH_TTL));

        return $this->issue_credential(
            self::TOKEN_TYPE_REFRESH,
            $service,
            $userid,
            $context,
            [
                'sid' => null,
                'validuntil' => $validuntil,
                'iprestriction' => $options['iprestriction'] ?? null,
                'name' => $options['name'] ?? $this->default_name('OAuth refresh'),
                'scope' => $options['scope'] ?? '',
                'resourceuri' => $options['resourceuri'] ?? null,
                'oauthclientid' => $options['oauthclientid'] ?? null,
                'usermodified' => $options['usermodified'] ?? $userid,
            ]
        );
    }

    /**
     * Resolve a credential for transport-time identity loading.
     *
     * @param string $token Opaque connector credential token.
     * @return stdClass|null Resolved credential, or null if invalid.
     * @throws dml_exception
     */
    public function resolve_credential(string $token): ?stdClass {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['token' => $token, 'revoked' => 0]);
        if (!$record) {
            return null;
        }

        if (!empty($record->validuntil) && (int)$record->validuntil < time()) {
            $this->revoke_record((int)$record->id, (int)$record->usermodified);
            return null;
        }

        if (!empty($record->sid) && !manager::session_exists($record->sid)) {
            $this->revoke_record((int)$record->id, (int)$record->usermodified);
            return null;
        }

        $record->lastaccess = time();
        $DB->set_field(self::TABLE, 'lastaccess', $record->lastaccess, ['id' => $record->id]);

        return $record;
    }

    /**
     * Revoke a connector credential by token.
     *
     * @param string $token Opaque connector credential token.
     * @param int|null $usermodified User performing the revocation.
     * @return bool True when a record was revoked.
     * @throws dml_exception
     */
    public function revoke_credential(string $token, ?int $usermodified = null): bool {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['token' => $token]);
        if (!$record) {
            return false;
        }

        return $this->revoke_record((int)$record->id, $usermodified ?? (int)$record->usermodified);
    }

    /**
     * List active connector credentials for a user.
     *
     * @param int $userid Moodle user id.
     * @return array
     * @throws dml_exception
     */
    public function list_credentials_for_user(int $userid): array {
        global $DB;

        return $DB->get_records_select(
            self::TABLE,
            'userid = :userid AND revoked = 0 AND tokentype <> :refreshtype',
            [
                'userid' => $userid,
                'refreshtype' => self::TOKEN_TYPE_REFRESH,
            ],
            'timecreated DESC'
        );
    }

    /**
     * Issue a connector credential record.
     *
     * @param int $tokentype Bootstrap or durable token type.
     * @param stdClass $service Service-like object.
     * @param int $userid Moodle user id.
     * @param context $context Context restriction.
     * @param array $options Record overrides.
     * @return stdClass Stored record.
     * @throws dml_exception
     */
    private function issue_credential(int $tokentype, stdClass $service, int $userid, context $context, array $options): stdClass {
        global $DB;

        $record = (object)[
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => (int)$options['usermodified'],
            'userid' => $userid,
            'token' => $this->generate_unique_token(),
            'name' => (string)$options['name'],
            'serviceidentifier' => $this->normalize_service_identifier($service),
            'contextid' => $context->id,
            'tokentype' => $tokentype,
            'sid' => $options['sid'],
            'validuntil' => $options['validuntil'],
            'iprestriction' => $options['iprestriction'],
            'scope' => (string)($options['scope'] ?? ''),
            'resourceuri' => $options['resourceuri'] ?? null,
            'oauthclientid' => $options['oauthclientid'] ?? null,
            'lastaccess' => null,
            'revoked' => 0,
        ];

        $record->id = $DB->insert_record(self::TABLE, $record);
        return $record;
    }

    /**
     * Revoke a record by id.
     *
     * @param int $id Record id.
     * @param int $usermodified User performing the change.
     * @return bool
     * @throws dml_exception
     */
    private function revoke_record(int $id, int $usermodified): bool {
        global $DB;

        return $DB->update_record(self::TABLE, (object)[
            'id' => $id,
            'timemodified' => time(),
            'usermodified' => $usermodified,
            'revoked' => 1,
        ]);
    }

    /**
     * Normalize service identity into a stable connector identifier.
     *
     * @param stdClass $service Service-like object.
     * @return string
     */
    private function normalize_service_identifier(stdClass $service): string {
        if (!empty($service->shortname)) {
            return (string)$service->shortname;
        }

        if (!empty($service->name)) {
            return (string)$service->name;
        }

        if (isset($service->id)) {
            return 'service:' . (string)$service->id;
        }

        throw new moodle_exception('invalidparameter');
    }

    /**
     * Generate a connector label using Moodle's token naming helper when available.
     *
     * @param string $prefix Label prefix.
     * @return string
     */
    private function default_name(string $prefix): string {
        if (class_exists(util::class) && method_exists(util::class, 'generate_token_name')) {
            return $prefix . ' - ' . util::generate_token_name();
        }

        return $prefix . ' - ' . gmdate('Y-m-d H:i:s');
    }

    /**
     * Generate a unique opaque connector token.
     *
     * @return string
     * @throws dml_exception
     */
    private function generate_unique_token(): string {
        global $DB;

        $attempts = 0;
        do {
            $attempts++;
            $token = bin2hex(random_bytes(32));
            if ($attempts > 5) {
                throw new moodle_exception('tokengenerationfailed');
            }
        } while ($DB->record_exists(self::TABLE, ['token' => $token]));

        return $token;
    }
}
