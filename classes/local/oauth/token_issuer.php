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

use context;
use context_system;
use moodle_exception;
use stdClass;
use webservice_mcp\local\auth\bootstrap_service;
use webservice_mcp\local\auth\connector_service_manager;
use webservice_mcp\local\auth\credential_manager;

/**
 * Token endpoint grants: authorization code, refresh rotation, and identity assertions, issuing token families.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_issuer {
    /** OAuth authorization-code table name. */
    private const CODE_TABLE = 'webservice_mcp_oauth_code';

    /** Default access-token lifetime in seconds. */
    private const ACCESS_TTL = 3600;

    /** Default refresh-token lifetime in seconds. */
    private const REFRESH_TTL = 2592000;

    /** Default absolute token-family lifetime in seconds (90 days). */
    private const DEFAULT_FAMILY_LIFETIME = 7776000;

    /** @var service */
    private service $oauth;

    /** @var credential_manager */
    private credential_manager $credentialmanager;

    /** @var connector_service_manager */
    private connector_service_manager $connectormanager;

    /**
     * Constructor.
     *
     * @param service $oauth OAuth service (resource and scope policy).
     * @param credential_manager $credentialmanager Credential manager.
     * @param connector_service_manager $connectormanager Connector service manager.
     */
    public function __construct(
        service $oauth,
        credential_manager $credentialmanager,
        connector_service_manager $connectormanager
    ) {
        $this->oauth = $oauth;
        $this->credentialmanager = $credentialmanager;
        $this->connectormanager = $connectormanager;
    }

    /**
     * Exchange a verified RFC 7523 identity assertion for tokens (Enterprise Managed Authorization).
     *
     * Only metadata-document or pre-registered clients may use it; dynamically registered clients are refused.
     *
     * @param stdClass $client Authenticated OAuth client.
     * @param array $params Token request params (assertion, scope, resource).
     * @param jwt_bearer_grant $grant Assertion verifier.
     * @return array
     */
    public function issue_for_assertion(stdClass $client, array $params, jwt_bearer_grant $grant): array {
        global $DB;

        if (!empty($client->isdynamic)) {
            throw new exception('unauthorized_client', 400, 'Dynamically registered clients cannot use identity assertions.');
        }

        $user = $grant->resolve_user((string)($params['assertion'] ?? ''), (string)$client->clientid, [
            $this->oauth->issuer_url(),
            $this->oauth->token_endpoint_url(),
        ]);
        $resourceuri = $this->oauth->validate_resource((string)($params['resource'] ?? ''));

        $scope = service::normalize_scope_string((string)($params['scope'] ?? ''), (string)$client->scope);
        if (in_array('refresh_token', $client->granttypes, true) && !service::scope_contains($scope, service::SCOPE_OFFLINE)) {
            $scope .= ' ' . service::SCOPE_OFFLINE;
        }
        $this->oauth->assert_scope_subset($scope, (string)$client->scope . ' ' . service::SCOPE_OFFLINE);

        $context = context_system::instance();
        $service = $this->eligible_service((int)$user->id, $context)
            ?? throw new exception('invalid_grant', 400, 'The user is not allowed to use this connector.');

        $transaction = $DB->start_delegated_transaction();
        try {
            $response = $this->issue_oauth_token_pair(
                $client,
                $service,
                (int)$user->id,
                $context,
                $scope,
                $resourceuri,
                bin2hex(random_bytes(16)),
                time()
            );
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        return $response;
    }

    /**
     * Exchange an authorization code for access and refresh tokens.
     *
     * The code is consumed under a lock; any failed or repeated redemption burns it, and a replayed code
     * revokes the token family it already produced (OAuth 2.1 section 4.1.3).
     *
     * @param stdClass $client OAuth client.
     * @param array $params Token request params.
     * @return array
     */
    public function exchange_authorization_code(stdClass $client, array $params): array {
        global $DB;

        if (!in_array('authorization_code', $client->granttypes, true)) {
            throw new exception('unauthorized_client', 400, 'This client cannot use the authorization_code grant.');
        }

        $code = trim((string)($params['code'] ?? ''));
        $redirecturi = trim((string)($params['redirect_uri'] ?? ''));
        $codeverifier = (string)($params['code_verifier'] ?? '');
        if ($code === '' || $redirecturi === '') {
            throw new exception('invalid_request', 400, 'code and redirect_uri are required.');
        }
        if (!preg_match(service::PKCE_PATTERN, $codeverifier)) {
            throw new exception('invalid_request', 400, 'A valid code_verifier is required.');
        }
        $redirecturi = service::normalize_redirect_uri($redirecturi);
        $resourceuri = $this->oauth->validate_resource((string)($params['resource'] ?? ''));

        $codehash = credential_manager::hash_token($code);
        $lock = $this->acquire_lock('code_' . $codehash);
        try {
            $record = $DB->get_record(self::CODE_TABLE, ['code' => $codehash]);
            if (!$record) {
                throw new exception('invalid_grant', 400, 'Authorization code is invalid or expired.');
            }

            if (!empty($record->used)) {
                if (!empty($record->familyid)) {
                    $this->credentialmanager->revoke_family((string)$record->familyid, (int)$record->userid);
                }
                throw new exception('invalid_grant', 400, 'Authorization code was already used.');
            }

            $valid = (int)$record->expiresat >= time()
                && hash_equals((string)$record->clientid, (string)$client->clientid)
                && hash_equals((string)$record->redirecturi, $redirecturi)
                && hash_equals((string)$record->resourceuri, $resourceuri)
                && $this->verify_pkce($codeverifier, (string)$record->codechallenge, (string)$record->codechallengemethod);
            if (!$valid) {
                $DB->update_record(self::CODE_TABLE, (object)['id' => $record->id, 'used' => 1, 'timemodified' => time()]);
                throw new exception('invalid_grant', 400, 'Authorization code is invalid, expired, or failed PKCE verification.');
            }

            // Burn the code before anything else, so a failure below (e.g. the user is no longer eligible) still
            // consumes it: the transaction only covers recording the family and issuing tokens.
            $DB->update_record(self::CODE_TABLE, (object)['id' => $record->id, 'used' => 1, 'timemodified' => time()]);

            $familyid = bin2hex(random_bytes(16));
            $transaction = $DB->start_delegated_transaction();
            try {
                $DB->set_field(self::CODE_TABLE, 'familyid', $familyid, ['id' => $record->id]);
                $context = $this->context_or_fail((int)$record->contextid);
                $service = $this->eligible_service((int)$record->userid, $context)
                    ?? throw new exception('invalid_grant', 400, 'The user is not allowed to use this connector.');
                $response = $this->issue_oauth_token_pair(
                    $client,
                    $service,
                    (int)$record->userid,
                    $context,
                    (string)$record->scope,
                    (string)$record->resourceuri,
                    $familyid,
                    time()
                );
                $transaction->allow_commit();
            } catch (\Throwable $exception) {
                $transaction->rollback($exception);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * Refresh an access token and rotate the refresh token.
     *
     * Presenting an already-rotated (revoked) refresh token revokes the whole family (OAuth 2.1 section 4.3.1).
     *
     * @param stdClass $client OAuth client.
     * @param array $params Token request params.
     * @return array
     */
    public function refresh_access_token(stdClass $client, array $params): array {
        global $DB;

        if (!in_array('refresh_token', $client->granttypes, true)) {
            throw new exception('unauthorized_client', 400, 'This client cannot use refresh_token.');
        }

        $refresh = trim((string)($params['refresh_token'] ?? ''));
        if ($refresh === '') {
            throw new exception('invalid_request', 400, 'refresh_token is required.');
        }
        $resourceuri = $this->oauth->validate_resource((string)($params['resource'] ?? ''));

        $lock = $this->acquire_lock('refresh_' . credential_manager::hash_token($refresh));
        try {
            $record = $this->credentialmanager->find_credential($refresh);
            if (
                !$record || (int)$record->tokentype !== credential_manager::TOKEN_TYPE_REFRESH ||
                    !hash_equals((string)$client->clientid, (string)($record->oauthclientid ?? ''))
            ) {
                throw new exception('invalid_grant', 400, 'Refresh token is invalid or expired.');
            }

            $familyid = (string)($record->familyid ?? '');
            $familycreated = (int)($record->familycreated ?: $record->timecreated);
            $graceperiodreuse = false;
            if (!empty($record->revoked)) {
                // A token rotated moments ago is being reused, typically by concurrent refreshes of one client:
                // mint another pair in the same, still-active family. Any other reuse is treated as theft.
                $grace = $this->refresh_grace_seconds();
                $graceperiodreuse = $grace > 0 && $familyid !== '' && !empty($record->rotatedat)
                    && (int)$record->rotatedat >= time() - $grace
                    && $this->credentialmanager->family_active('f_' . $familyid);
                if (!$graceperiodreuse) {
                    $this->credentialmanager->revoke_family($familyid, (int)$record->userid);
                    throw new exception('invalid_grant', 400, 'Refresh token was already used or revoked.');
                }
            }

            if (
                (!empty($record->validuntil) && (int)$record->validuntil < time()) ||
                    $familycreated + $this->family_lifetime() < time()
            ) {
                $this->credentialmanager->revoke_family($familyid, (int)$record->userid);
                $this->credentialmanager->revoke_credential_by_id((int)$record->id, (int)$record->userid);
                throw new exception('invalid_grant', 400, 'Refresh token is invalid or expired.');
            }

            if (!hash_equals((string)($record->resourceuri ?? ''), $resourceuri)) {
                throw new exception('invalid_grant', 400, 'Refresh token does not match the requested resource.');
            }

            // A user who is no longer active or allowed loses the whole grant, not just this request.
            $context = $this->context_or_fail((int)$record->contextid);
            $service = $this->eligible_service((int)$record->userid, $context);
            if ($service === null) {
                $this->credentialmanager->revoke_family($familyid, (int)$record->userid);
                $this->credentialmanager->revoke_credential_by_id((int)$record->id, (int)$record->userid);
                throw new exception('invalid_grant', 400, 'The user is no longer allowed to use this connector.');
            }

            $transaction = $DB->start_delegated_transaction();
            try {
                $response = $this->issue_oauth_token_pair(
                    $client,
                    $service,
                    (int)$record->userid,
                    $context,
                    (string)($record->scope ?? $this->oauth->default_scope_string()),
                    (string)$record->resourceuri,
                    $familyid !== '' ? $familyid : bin2hex(random_bytes(16)),
                    $familycreated
                );
                if (!$graceperiodreuse) {
                    $this->credentialmanager->mark_rotated((int)$record->id, (int)$record->userid);
                }
                $transaction->allow_commit();
            } catch (\Throwable $exception) {
                $transaction->rollback($exception);
            }
        } finally {
            $lock->release();
        }

        return $response;
    }

    /**
     * Return the connector service if the user may still hold tokens in the context, else null.
     *
     * Re-checks what authorize checked (active account, webservice/mcp:use, site-admin policy) plus
     * the external service authorisation (enabled, allowed user, expiry, IP restriction).
     *
     * @param int $userid Moodle user id.
     * @param context $context Restricted context.
     * @return stdClass|null
     */
    private function eligible_service(int $userid, context $context): ?stdClass {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) {
            return null;
        }

        try {
            bootstrap_service::require_user_eligible($user, $context);
            return $this->connectormanager->ensure_service_for_user($userid);
        } catch (moodle_exception $exception) {
            return null;
        }
    }

    /**
     * Issue access and optional refresh tokens.
     *
     * @param stdClass $client OAuth client.
     * @param stdClass $service Connector service the user is authorised for.
     * @param int $userid Moodle user id.
     * @param context $context Restricted context.
     * @param string $scope Granted scopes.
     * @param string $resourceuri Bound resource URI.
     * @param string $familyid Token family id.
     * @param int $familycreated Token family creation time.
     * @return array
     */
    private function issue_oauth_token_pair(
        stdClass $client,
        stdClass $service,
        int $userid,
        context $context,
        string $scope,
        string $resourceuri,
        string $familyid,
        int $familycreated
    ): array {
        $scope = service::normalize_scope_string($scope, $this->oauth->default_scope_string());
        $familyend = $familycreated + $this->family_lifetime();
        $options = [
            'scope' => $scope,
            'resourceuri' => $resourceuri,
            'oauthclientid' => $client->clientid,
            'familyid' => $familyid,
            'familycreated' => $familycreated,
            'usermodified' => $userid,
        ];

        $accesstoken = $this->credentialmanager->issue_oauth_access_token($service, $userid, $context, $options + [
            'name' => 'OAuth access - ' . $client->clientid,
            'validuntil' => min(time() + self::ACCESS_TTL, $familyend),
        ]);

        $response = [
            'token_type' => 'Bearer',
            'access_token' => $accesstoken->token,
            'expires_in' => max(0, (int)$accesstoken->validuntil - time()),
            'scope' => $scope,
            'resource' => $resourceuri,
        ];

        if (service::scope_contains($scope, service::SCOPE_OFFLINE) && in_array('refresh_token', $client->granttypes, true)) {
            $refreshtoken = $this->credentialmanager->issue_oauth_refresh_token($service, $userid, $context, $options + [
                'name' => 'OAuth refresh - ' . $client->clientid,
                'validuntil' => min(time() + self::REFRESH_TTL, $familyend),
            ]);
            $response['refresh_token'] = $refreshtoken->token;
        }

        return $response;
    }

    /**
     * Return how long a rotated refresh token may still be reused by its client.
     *
     * @return int Seconds; 0 disables the grace window.
     */
    private function refresh_grace_seconds(): int {
        $grace = get_config('webservice_mcp', 'refreshgraceseconds');
        return $grace === false ? 30 : max(0, (int)$grace);
    }

    /**
     * Return the absolute token-family lifetime in seconds.
     *
     * @return int
     */
    private function family_lifetime(): int {
        $lifetime = (int)get_config('webservice_mcp', 'oauthfamilylifetime');
        return $lifetime > 0 ? $lifetime : self::DEFAULT_FAMILY_LIFETIME;
    }

    /**
     * Load a context that a grant is bound to, failing the grant if it was deleted.
     *
     * @param int $contextid Context id.
     * @return context
     */
    private function context_or_fail(int $contextid): context {
        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context) {
            throw new exception('invalid_grant', 400, 'The authorized context no longer exists.');
        }
        return $context;
    }

    /**
     * Acquire a short lock serialising redemption of one code or refresh token.
     *
     * @param string $resource Lock resource name.
     * @return \core\lock\lock
     */
    private function acquire_lock(string $resource): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('webservice_mcp')->get_lock($resource, 5);
        if (!$lock) {
            throw new exception('invalid_grant', 400, 'A concurrent request is redeeming this grant.');
        }
        return $lock;
    }

    /**
     * Verify a PKCE code verifier against the stored challenge.
     *
     * @param string $verifier Code verifier.
     * @param string $challenge Stored code challenge.
     * @param string $method Challenge method.
     * @return bool
     */
    private function verify_pkce(string $verifier, string $challenge, string $method): bool {
        if ($method !== 'S256') {
            return false;
        }

        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return hash_equals($challenge, $expected);
    }
}
