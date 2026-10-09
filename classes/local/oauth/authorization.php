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
use stdClass;
use webservice_mcp\local\auth\bootstrap_service;
use webservice_mcp\local\auth\connector_service_manager;
use webservice_mcp\local\auth\credential_manager;

/**
 * Authorization endpoint logic: request validation, consent description, and authorization codes.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class authorization {
    /** Authorization-code lifetime in seconds. */
    private const AUTH_CODE_TTL = 600;

    /** @var service */
    private service $oauth;

    /** @var client_registry */
    private client_registry $clients;

    /** @var connector_service_manager */
    private connector_service_manager $connectormanager;

    /** @var credential_manager */
    private credential_manager $credentialmanager;

    /**
     * Constructor.
     *
     * @param service $oauth OAuth service.
     * @param client_registry $clients Client registry.
     * @param connector_service_manager $connectormanager Connector service manager.
     * @param credential_manager $credentialmanager Credential manager.
     */
    public function __construct(
        service $oauth,
        client_registry $clients,
        connector_service_manager $connectormanager,
        credential_manager $credentialmanager
    ) {
        $this->oauth = $oauth;
        $this->clients = $clients;
        $this->connectormanager = $connectormanager;
        $this->credentialmanager = $credentialmanager;
    }

    /**
     * Validate an incoming authorize request and return normalized values.
     *
     * @param array $params Raw request parameters.
     * @return array
     */
    public function validate_authorization_request(array $params): array {
        $responsetype = (string)($params['response_type'] ?? '');
        if ($responsetype !== 'code') {
            throw new exception('unsupported_response_type', 400, 'Only the authorization code flow is supported.');
        }

        $client = $this->resolve_authorization_client((string)($params['client_id'] ?? ''));
        $redirecturi = $this->resolve_authorization_redirect($client, (string)($params['redirect_uri'] ?? ''));

        $scope = service::normalize_scope_string((string)($params['scope'] ?? ''), (string)$client->scope);
        // The offline_access scope is granted to any client registered for refresh_token, whether or not it asked for it.
        if (in_array('refresh_token', $client->granttypes, true) && !service::scope_contains($scope, service::SCOPE_OFFLINE)) {
            $scope .= ' ' . service::SCOPE_OFFLINE;
        }
        $this->oauth->assert_scope_subset($scope, (string)$client->scope . ' ' . service::SCOPE_OFFLINE);

        $codechallenge = trim((string)($params['code_challenge'] ?? ''));
        if (!preg_match(service::PKCE_PATTERN, $codechallenge)) {
            throw new exception('invalid_request', 400, 'A valid code_challenge is required.');
        }

        // RFC 7636: an absent method means "plain", which is not supported.
        $codechallengemethod = trim((string)($params['code_challenge_method'] ?? ''));
        if ($codechallengemethod !== 'S256') {
            throw new exception('invalid_request', 400, 'code_challenge_method must be S256.');
        }

        return [
            'client' => $client,
            'redirecturi' => $redirecturi,
            'scope' => $scope,
            'state' => isset($params['state']) ? (string)$params['state'] : null,
            'resourceuri' => $this->oauth->validate_resource((string)($params['resource'] ?? '')),
            'codechallenge' => $codechallenge,
            'codechallengemethod' => $codechallengemethod,
            'contextid' => isset($params['contextid']) ? (int)$params['contextid'] : 0,
        ];
    }

    /**
     * Build the redirect for an authorize error, or null when the client/redirect pair cannot be trusted.
     *
     * @param array $params Raw authorize request params.
     * @param exception $exception OAuth error.
     * @return string|null
     */
    public function authorization_error_redirect(array $params, exception $exception): ?string {
        try {
            $client = $this->clients->get_client(trim((string)($params['client_id'] ?? '')));
            if ($client === null) {
                return null;
            }
            $redirecturi = $this->resolve_authorization_redirect($client, (string)($params['redirect_uri'] ?? ''));
        } catch (exception $ignored) {
            return null;
        }

        $state = (string)($params['state'] ?? '');
        return $this->oauth->build_redirect_uri($redirecturi, array_filter([
            'error' => $exception->oauth_error(),
            'error_description' => $exception->getMessage(),
            'state' => $state !== '' ? $state : null,
        ], static fn($value): bool => $value !== null));
    }

    /**
     * Describe a client for the consent screen.
     *
     * @param stdClass $client Hydrated client.
     * @param string $redirecturi Redirect URI the user will be sent to.
     * @return array name, redirecthost, registration (dynamic|metadata|registered), clienthost
     */
    public function describe_client(stdClass $client, string $redirecturi): array {
        $redirecthost = (string)parse_url($redirecturi, PHP_URL_HOST);
        $ismetadata = client_registry::is_metadata_document_client_id((string)$client->clientid);

        return [
            'name' => trim((string)$client->clientname) !== '' ? (string)$client->clientname : $redirecthost,
            'redirecthost' => $redirecthost,
            'registration' => $ismetadata ? 'metadata' : (!empty($client->isdynamic) ? 'dynamic' : 'registered'),
            'clienthost' => $ismetadata ? (string)parse_url((string)$client->clientid, PHP_URL_HOST) : '',
        ];
    }

    /**
     * Ensure the currently logged-in user may authorize access in the selected context.
     *
     * @param int $contextid Requested context id, or 0 for system.
     * @return context
     */
    public function require_authorization_context(int $contextid = 0): context {
        global $USER;

        $context = $contextid > 0 ? context::instance_by_id($contextid) : context_system::instance();
        (new bootstrap_service($this->credentialmanager, $this->connectormanager))->require_bootstrap_access($context);
        $this->connectormanager->ensure_service_for_user((int)$USER->id);

        return $context;
    }

    /**
     * Create an authorization code for the current user.
     *
     * @param int $userid Moodle user id.
     * @param stdClass $client OAuth client.
     * @param context $context Restricted context.
     * @param string $redirecturi Redirect URI.
     * @param string $scope Normalized scope set.
     * @param string $resourceuri Normalized resource URI.
     * @param string $codechallenge PKCE challenge.
     * @param string $codechallengemethod PKCE challenge method.
     * @return string Plaintext code; only its hash is stored.
     */
    public function create_authorization_code(
        int $userid,
        stdClass $client,
        context $context,
        string $redirecturi,
        string $scope,
        string $resourceuri,
        string $codechallenge,
        string $codechallengemethod
    ): string {
        global $DB;

        $code = bin2hex(random_bytes(32));
        $DB->insert_record('webservice_mcp_oauth_code', (object)[
            'timecreated' => time(),
            'timemodified' => time(),
            'expiresat' => time() + self::AUTH_CODE_TTL,
            'userid' => $userid,
            'clientid' => $client->clientid,
            'code' => credential_manager::hash_token($code),
            'redirecturi' => $redirecturi,
            'scope' => $scope,
            'resourceuri' => $resourceuri,
            'contextid' => $context->id,
            'serviceidentifier' => $this->connectormanager->service_shortname(),
            'codechallenge' => $codechallenge,
            'codechallengemethod' => $codechallengemethod,
            'used' => 0,
        ]);

        return $code;
    }

    /**
     * Resolve and validate the client of an authorization request.
     *
     * @param string $clientid Raw client_id.
     * @return stdClass
     */
    private function resolve_authorization_client(string $clientid): stdClass {
        $clientid = trim($clientid);
        if ($clientid === '') {
            throw new exception('invalid_request', 400, 'Missing client_id.');
        }

        $client = $this->clients->resolve_client_for_authorization($clientid);
        if ($client === null) {
            throw new exception('invalid_client', 401, 'Unknown client.');
        }

        return $client;
    }

    /**
     * Resolve the redirect URI of an authorization request against the client and site policy.
     *
     * @param stdClass $client Hydrated client.
     * @param string $redirecturi Raw redirect_uri parameter.
     * @return string Normalized redirect URI to send the response to.
     */
    private function resolve_authorization_redirect(stdClass $client, string $redirecturi): string {
        $redirecturi = trim($redirecturi);
        if ($redirecturi === '') {
            if (count($client->redirecturis) !== 1) {
                throw new exception('invalid_request', 400, 'redirect_uri is required.');
            }
            $redirecturi = (string)$client->redirecturis[0];
        } else {
            $redirecturi = service::normalize_redirect_uri($redirecturi);
        }

        if (!client_registry::redirect_uri_matches($redirecturi, $client->redirecturis)) {
            throw new exception('invalid_request', 400, 'redirect_uri is not registered for this client.');
        }

        if (!client_registry::is_redirect_host_allowed((string)parse_url($redirecturi, PHP_URL_HOST))) {
            throw new exception('invalid_request', 400, 'redirect_uri host is not allowed by this site.');
        }

        return $redirecturi;
    }
}
