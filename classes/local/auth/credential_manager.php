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

    /** Durable per-user key minted by an administrator (not OAuth, no refresh). */
    public const TOKEN_TYPE_ADMIN = 3;

    /** Minimum seconds between lastaccess writes for one credential. */
    private const LASTACCESS_THROTTLE = 300;

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
    public function issue_bootstrap_credential(
        stdClass $service,
        int $userid,
        ?context $context = null,
        array $options = []
    ): stdClass {
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
    public function issue_oauth_access_token(
        stdClass $service,
        int $userid,
        ?context $context = null,
        array $options = []
    ): stdClass {
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
                'familyid' => $options['familyid'] ?? null,
                'familycreated' => $options['familycreated'] ?? null,
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
    public function issue_oauth_refresh_token(
        stdClass $service,
        int $userid,
        ?context $context = null,
        array $options = []
    ): stdClass {
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
                'familyid' => $options['familyid'] ?? null,
                'familycreated' => $options['familycreated'] ?? null,
                'usermodified' => $options['usermodified'] ?? $userid,
            ]
        );
    }

    /**
     * Issue a durable key for another user on an administrator's authority.
     *
     * Callers (admin_key_service) are responsible for authorising the issuer and target.
     *
     * @param int $targetuserid User the key acts for.
     * @param stdClass $issuer Issuing user.
     * @param array $options service (required), context, scope, validuntil, label, resourceuri.
     * @return stdClass Stored record carrying the plaintext token in ->token (returned once only).
     */
    public function issue_admin_credential(int $targetuserid, stdClass $issuer, array $options): stdClass {
        return $this->issue_credential(
            self::TOKEN_TYPE_ADMIN,
            $options['service'],
            $targetuserid,
            $options['context'] ?? context_system::instance(),
            [
                'sid' => null,
                'validuntil' => (int)$options['validuntil'],
                'iprestriction' => null,
                'name' => (string)($options['label'] ?? $this->default_name('Admin key')),
                'scope' => (string)($options['scope'] ?? 'mcp:read'),
                'resourceuri' => $options['resourceuri'] ?? null,
                'oauthclientid' => null,
                'issuerid' => (int)$issuer->id,
                'usermodified' => (int)$issuer->id,
            ]
        );
    }

    /**
     * Return the stable family key of a credential: its OAuth family, or its own id for standalone credentials.
     *
     * @param stdClass $credential Credential record.
     * @return string
     */
    public static function family_key(stdClass $credential): string {
        return !empty($credential->familyid) ? 'f_' . $credential->familyid : 'c_' . (int)$credential->id;
    }

    /**
     * Whether a family key (see family_key()) still has an active, unexpired credential.
     *
     * @param string $familykey Family key.
     * @return bool
     */
    public function family_active(string $familykey): bool {
        return $this->find_active_in_family($familykey) !== null;
    }

    /**
     * Return one active, unexpired credential of a family, or null.
     *
     * @param string $familykey Family key (f_<familyid> or c_<credential id>).
     * @return stdClass|null
     */
    public function find_active_in_family(string $familykey): ?stdClass {
        global $DB;

        $where = 'revoked = 0 AND (validuntil IS NULL OR validuntil = 0 OR validuntil >= :now)';
        $params = ['now' => time()];
        if (str_starts_with($familykey, 'f_') && strlen($familykey) > 2) {
            $where .= ' AND familyid = :familyid';
            $params['familyid'] = substr($familykey, 2);
        } else if (str_starts_with($familykey, 'c_') && ctype_digit(substr($familykey, 2))) {
            $where .= ' AND id = :id';
            $params['id'] = (int)substr($familykey, 2);
        } else {
            return null;
        }

        $records = $DB->get_records_select(self::TABLE, $where, $params, 'id DESC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * Re-check that a user may still act through a connector service (and, if given, a credential family).
     *
     * For anything that outlives the request that authenticated it: file tickets, cron tasks. See
     * service_access::problem() for the checks.
     *
     * @param int $userid User id.
     * @param int $serviceid External service id.
     * @param string|null $familykey Credential family key (transport identity ->familyid), or null for none.
     * @param bool $checkip Whether to enforce the allowed-user IP restriction (false in cron).
     * @return void
     * @throws \webservice_access_exception With the problem code when access is no longer allowed.
     */
    public function assert_service_access(int $userid, int $serviceid, ?string $familykey = null, bool $checkip = true): void {
        service_access::assert($userid, $serviceid, $familykey, $checkip);
    }

    /**
     * Hash an opaque token for storage and lookup; plaintext tokens are never persisted.
     *
     * @param string $token Opaque token.
     * @return string
     */
    public static function hash_token(string $token): string {
        return hash('sha256', $token);
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

        $record = $this->find_credential($token);
        if (!$record || !empty($record->revoked)) {
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

        if (!empty($record->iprestriction) && !address_in_subnet(getremoteaddr(), $record->iprestriction)) {
            return null;
        }

        if ((int)$record->lastaccess < time() - self::LASTACCESS_THROTTLE) {
            $record->lastaccess = time();
            $DB->set_field(self::TABLE, 'lastaccess', $record->lastaccess, ['id' => $record->id]);
        }

        return $record;
    }

    /**
     * Look up a credential by its plaintext token regardless of state, without side effects.
     *
     * @param string $token Opaque connector credential token.
     * @return stdClass|null
     */
    public function find_credential(string $token): ?stdClass {
        global $DB;

        if ($token === '') {
            return null;
        }

        return $DB->get_record(self::TABLE, ['token' => self::hash_token($token)]) ?: null;
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
        $record = $this->find_credential($token);
        if (!$record) {
            return false;
        }

        return $this->revoke_record((int)$record->id, $usermodified ?? (int)$record->usermodified);
    }

    /**
     * Retire a refresh token that was just replaced, recording when, for the reuse grace window.
     *
     * @param int $id Credential id.
     * @param int $usermodified User performing the change.
     * @return bool
     */
    public function mark_rotated(int $id, int $usermodified): bool {
        return $this->revoke_record($id, $usermodified, true);
    }

    /**
     * Revoke a single credential by id.
     *
     * @param int $id Credential id.
     * @param int $usermodified User performing the revocation.
     * @return bool
     */
    public function revoke_credential_by_id(int $id, int $usermodified): bool {
        return $this->revoke_record($id, $usermodified);
    }

    /**
     * Revoke every credential belonging to an OAuth token family.
     *
     * @param string $familyid Token family id.
     * @param int $usermodified User performing the revocation.
     * @return void
     */
    public function revoke_family(string $familyid, int $usermodified): void {
        if ($familyid !== '') {
            $this->revoke_where(['familyid' => $familyid], $usermodified);
        }
    }

    /**
     * Revoke every active credential of a user (password change, suspension, deletion).
     *
     * @param int $userid Moodle user id.
     * @param int $usermodified User performing the revocation.
     * @return void
     */
    public function revoke_all_for_user(int $userid, int $usermodified): void {
        $this->revoke_where(['userid' => $userid], $usermodified);
    }

    /**
     * Revoke every active credential matching simple field conditions.
     *
     * @param array $conditions Field => value conditions.
     * @param int $usermodified User performing the revocation.
     * @return int Number of credentials revoked.
     */
    public function revoke_where(array $conditions, int $usermodified): int {
        global $DB;

        $ids = $DB->get_fieldset_select(
            self::TABLE,
            'id',
            implode(' AND ', array_map(static fn(string $field): string => $field . ' = :' . $field, array_keys($conditions)))
                . ' AND revoked = 0',
            $conditions
        );
        foreach ($ids as $id) {
            $this->revoke_record((int)$id, $usermodified);
        }

        return count($ids);
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
     * @return stdClass Stored record carrying the plaintext token in ->token.
     * @throws dml_exception
     */
    private function issue_credential(int $tokentype, stdClass $service, int $userid, context $context, array $options): stdClass {
        global $DB;

        $token = $this->generate_unique_token();
        $record = (object)[
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => (int)$options['usermodified'],
            'userid' => $userid,
            'token' => self::hash_token($token),
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
            'familyid' => $options['familyid'] ?? null,
            'familycreated' => $options['familycreated'] ?? null,
            'issuerid' => $options['issuerid'] ?? null,
        ];

        $record->id = $DB->insert_record(self::TABLE, $record);
        \webservice_mcp\event\credential_issued::create_from_credential($record)->trigger();
        // The plaintext token is only ever handed back to the caller at issue time.
        $record->token = $token;
        return $record;
    }

    /**
     * Revoke a record by id.
     *
     * @param int $id Record id.
     * @param int $usermodified User performing the change.
     * @param bool $rotated Whether a refresh rotation replaced it (records rotatedat for the grace window).
     * @return bool
     * @throws dml_exception
     */
    private function revoke_record(int $id, int $usermodified, bool $rotated = false): bool {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        if (!$record) {
            return false;
        }
        if (empty($record->revoked)) {
            $record->revoked = 1;
            $record->timemodified = time();
            if ($rotated) {
                $record->rotatedat = time();
            }
            $record->usermodified = $usermodified;
            $DB->update_record(self::TABLE, $record);
            \webservice_mcp\event\credential_revoked::create_from_credential($record, $usermodified)->trigger();
        }

        return true;
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
        } while ($DB->record_exists(self::TABLE, ['token' => self::hash_token($token)]));

        return $token;
    }
}
