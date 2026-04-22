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
use moodle_url;
use stdClass;
use webservice_mcp\local\auth\bootstrap_service;
use webservice_mcp\local\auth\connector_service_manager;
use webservice_mcp\local\auth\credential_manager;

/**
 * Moodle-native OAuth server helpers for Claude-compatible remote MCP auth.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service {
    /** OAuth client table name. */
    private const CLIENT_TABLE = 'webservice_mcp_oauth_client';

    /** OAuth authorization-code table name. */
    private const CODE_TABLE = 'webservice_mcp_oauth_code';

    /** Authorization-code lifetime in seconds. */
    private const AUTH_CODE_TTL = 600;

    /** OAuth read scope. */
    public const SCOPE_READ = 'mcp:read';

    /** OAuth write scope. */
    public const SCOPE_WRITE = 'mcp:write';

    /** OAuth refresh scope. */
    public const SCOPE_OFFLINE = 'offline_access';

    /** Supported OAuth token endpoint auth methods. */
    private const TOKEN_AUTH_METHODS = ['none', 'client_secret_basic', 'client_secret_post'];

    /** @var credential_manager */
    private credential_manager $credentialmanager;

    /** @var connector_service_manager */
    private connector_service_manager $connectormanager;

    /**
     * Constructor.
     *
     * @param credential_manager|null $credentialmanager Optional credential manager override.
     * @param connector_service_manager|null $connectormanager Optional connector service manager override.
     */
    public function __construct(
        ?credential_manager $credentialmanager = null,
        ?connector_service_manager $connectormanager = null
    ) {
        $this->credentialmanager = $credentialmanager ?? new credential_manager();
        $this->connectormanager = $connectormanager ?? new connector_service_manager();
    }

    /**
     * Return the OAuth issuer URL.
     *
     * @return string
     */
    public function issuer_url(): string {
        return (new moodle_url('/webservice/mcp'))->out(false);
    }

    /**
     * Return the protected MCP resource URI.
     *
     * @return string
     */
    public function canonical_resource_uri(): string {
        return self::normalize_resource_uri((new moodle_url('/webservice/mcp/server.php'))->out(false));
    }

    /**
     * Return the authorization endpoint URL.
     *
     * @return string
     */
    public function authorization_endpoint_url(): string {
        return (new moodle_url('/webservice/mcp/oauth/authorize.php'))->out(false);
    }

    /**
     * Return the token endpoint URL.
     *
     * @return string
     */
    public function token_endpoint_url(): string {
        return (new moodle_url('/webservice/mcp/oauth/token.php'))->out(false);
    }

    /**
     * Return the dynamic client registration endpoint URL.
     *
     * @return string
     */
    public function registration_endpoint_url(): string {
        return (new moodle_url('/webservice/mcp/oauth/register.php'))->out(false);
    }

    /**
     * Return the resource-metadata URL used in Bearer challenges.
     *
     * @return string
     */
    public function protected_resource_metadata_url(): string {
        return (new moodle_url('/webservice/mcp/.well-known/oauth-protected-resource'))->out(false);
    }

    /**
     * Return the authorization-server metadata URL.
     *
     * @return string
     */
    public function authorization_server_metadata_url(): string {
        return (new moodle_url('/webservice/mcp/.well-known/oauth-authorization-server'))->out(false);
    }

    /**
     * Return the OpenID configuration URL.
     *
     * @return string
     */
    public function openid_configuration_url(): string {
        return (new moodle_url('/webservice/mcp/.well-known/openid-configuration'))->out(false);
    }

    /**
     * Return the default scope set requested for MCP access.
     *
     * @return string
     */
    public function default_scope_string(): string {
        return self::SCOPE_READ . ' ' . self::SCOPE_WRITE;
    }

    /**
     * Return the supported scopes.
     *
     * @return array
     */
    public function supported_scopes(): array {
        return [
            self::SCOPE_READ,
            self::SCOPE_WRITE,
            self::SCOPE_OFFLINE,
        ];
    }

    /**
     * Whether the plugin OAuth server endpoints are enabled.
     *
     * @return bool
     */
    public function is_enabled(): bool {
        return (bool)get_config('webservice_mcp', 'oauthenabled');
    }

    /**
     * Return display labels for the supported scopes.
     *
     * @return array
     */
    public function scope_labels(): array {
        return [
            self::SCOPE_READ => 'Read Moodle data and list available tools.',
            self::SCOPE_WRITE => 'Create, update, and delete Moodle data through tools the user can access.',
            self::SCOPE_OFFLINE => 'Stay connected without signing in again every session.',
        ];
    }

    /**
     * Build the protected-resource metadata document.
     *
     * @return array
     */
    public function build_protected_resource_metadata(): array {
        return [
            'resource' => $this->canonical_resource_uri(),
            'authorization_servers' => [$this->issuer_url()],
            'scopes_supported' => $this->supported_scopes(),
            'bearer_methods_supported' => ['header'],
        ];
    }

    /**
     * Build the authorization-server metadata document.
     *
     * @return array
     */
    public function build_authorization_server_metadata(): array {
        return [
            'issuer' => $this->issuer_url(),
            'authorization_endpoint' => $this->authorization_endpoint_url(),
            'token_endpoint' => $this->token_endpoint_url(),
            'registration_endpoint' => $this->registration_endpoint_url(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => self::TOKEN_AUTH_METHODS,
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => $this->supported_scopes(),
        ];
    }

    /**
     * Build a Bearer challenge header for MCP OAuth discovery.
     *
     * @param string|null $error Optional OAuth error code.
     * @param string|null $description Optional human-readable detail.
     * @param string|null $scope Optional scope hint.
     * @return string
     */
    public function build_bearer_challenge(?string $error = null, ?string $description = null, ?string $scope = null): string {
        $parts = [
            'Bearer realm="Moodle MCP"',
            'resource_metadata="' . $this->protected_resource_metadata_url() . '"',
        ];

        if ($scope !== null && trim($scope) !== '') {
            $parts[] = 'scope="' . self::escape_header_value(self::normalize_scope_string($scope, $this->default_scope_string())) . '"';
        }

        if ($error !== null && trim($error) !== '') {
            $parts[] = 'error="' . self::escape_header_value($error) . '"';
        }

        if ($description !== null && trim($description) !== '') {
            $parts[] = 'error_description="' . self::escape_header_value($description) . '"';
        }

        return implode(', ', $parts);
    }

    /**
     * Register an OAuth client dynamically.
     *
     * @param array $metadata Registration metadata.
     * @return array
     */
    public function register_dynamic_client(array $metadata): array {
        global $DB;

        $redirecturis = $metadata['redirect_uris'] ?? null;
        if (!is_array($redirecturis) || $redirecturis === []) {
            throw new exception('invalid_client_metadata', 400, 'At least one redirect URI is required.');
        }

        $normalizedredirects = [];
        foreach ($redirecturis as $redirecturi) {
            $normalizedredirects[] = self::normalize_redirect_uri((string)$redirecturi);
        }

        $tokenauthmethod = (string)($metadata['token_endpoint_auth_method'] ?? 'none');
        if (!in_array($tokenauthmethod, self::TOKEN_AUTH_METHODS, true)) {
            throw new exception('invalid_client_metadata', 400, 'Unsupported token endpoint auth method.');
        }

        $granttypes = $this->normalize_client_list(
            $metadata['grant_types'] ?? ['authorization_code', 'refresh_token'],
            ['authorization_code', 'refresh_token'],
            'grant_types'
        );
        if (!in_array('authorization_code', $granttypes, true)) {
            throw new exception('invalid_client_metadata', 400, 'authorization_code must be supported.');
        }

        $responsetypes = $this->normalize_client_list(
            $metadata['response_types'] ?? ['code'],
            ['code'],
            'response_types'
        );
        if ($responsetypes !== ['code']) {
            throw new exception('invalid_client_metadata', 400, 'Only the code response type is supported.');
        }

        $clientscope = self::normalize_scope_string(
            (string)($metadata['scope'] ?? $this->default_scope_string()),
            $this->default_scope_string()
        );
        $this->assert_supported_scope_set($clientscope);

        $clientid = $this->generate_unique_client_id();
        $plaintextsecret = null;
        $storedsecret = null;
        if ($tokenauthmethod !== 'none') {
            $plaintextsecret = $this->generate_secret();
            $storedsecret = password_hash($plaintextsecret, PASSWORD_DEFAULT);
        }

        $record = (object)[
            'timecreated' => time(),
            'timemodified' => time(),
            'clientid' => $clientid,
            'clientsecret' => $storedsecret,
            'clientname' => substr(trim((string)($metadata['client_name'] ?? 'Claude')), 0, 255),
            'redirecturis' => json_encode($normalizedredirects),
            'scope' => $clientscope,
            'granttypes' => json_encode($granttypes),
            'responsetypes' => json_encode($responsetypes),
            'tokenauthmethod' => $tokenauthmethod,
            'isdynamic' => 1,
            'revoked' => 0,
        ];
        $DB->insert_record(self::CLIENT_TABLE, $record);

        $response = [
            'client_id' => $clientid,
            'client_id_issued_at' => $record->timecreated,
            'client_name' => $record->clientname,
            'redirect_uris' => $normalizedredirects,
            'scope' => $clientscope,
            'grant_types' => $granttypes,
            'response_types' => $responsetypes,
            'token_endpoint_auth_method' => $tokenauthmethod,
            'client_secret_expires_at' => 0,
        ];

        if ($plaintextsecret !== null) {
            $response['client_secret'] = $plaintextsecret;
        }

        return $response;
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

        $clientid = trim((string)($params['client_id'] ?? ''));
        if ($clientid === '') {
            throw new exception('invalid_request', 400, 'Missing client_id.');
        }

        $client = $this->get_client($clientid);
        if ($client === null) {
            throw new exception('invalid_client', 401, 'Unknown client.');
        }

        $redirecturi = trim((string)($params['redirect_uri'] ?? ''));
        if ($redirecturi === '') {
            if (count($client->redirecturis) !== 1) {
                throw new exception('invalid_request', 400, 'redirect_uri is required.');
            }
            $redirecturi = $client->redirecturis[0];
        } else {
            $redirecturi = self::normalize_redirect_uri($redirecturi);
        }

        if (!in_array($redirecturi, $client->redirecturis, true)) {
            throw new exception('invalid_request', 400, 'redirect_uri is not registered for this client.');
        }

        $scope = self::normalize_scope_string((string)($params['scope'] ?? $client->scope), $client->scope);
        $this->assert_scope_subset($scope, $client->scope);

        $codechallenge = trim((string)($params['code_challenge'] ?? ''));
        if ($codechallenge === '') {
            throw new exception('invalid_request', 400, 'code_challenge is required.');
        }

        $codechallengemethod = strtoupper(trim((string)($params['code_challenge_method'] ?? 'S256')));
        if ($codechallengemethod !== 'S256') {
            throw new exception('invalid_request', 400, 'Only S256 PKCE challenges are supported.');
        }

        $contextid = isset($params['contextid']) ? (int)$params['contextid'] : 0;

        return [
            'client' => $client,
            'redirecturi' => $redirecturi,
            'scope' => $scope,
            'state' => isset($params['state']) ? (string)$params['state'] : null,
            'resourceuri' => $this->validate_resource((string)($params['resource'] ?? '')),
            'codechallenge' => $codechallenge,
            'codechallengemethod' => $codechallengemethod,
            'contextid' => $contextid,
        ];
    }

    /**
     * Ensure the currently logged-in user may authorize access in the selected context.
     *
     * @param int $contextid Requested context id, or 0 for system.
     * @return context
     */
    public function require_authorization_context(int $contextid = 0): context {
        $context = $contextid > 0 ? context::instance_by_id($contextid) : context_system::instance();
        (new bootstrap_service())->require_bootstrap_access($context);
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
     * @return string
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

        $record = (object)[
            'timecreated' => time(),
            'timemodified' => time(),
            'expiresat' => time() + self::AUTH_CODE_TTL,
            'userid' => $userid,
            'clientid' => $client->clientid,
            'code' => $this->generate_unique_authorization_code(),
            'redirecturi' => $redirecturi,
            'scope' => $scope,
            'resourceuri' => $resourceuri,
            'contextid' => $context->id,
            'serviceidentifier' => $this->connectormanager->service_shortname(),
            'codechallenge' => $codechallenge,
            'codechallengemethod' => $codechallengemethod,
            'used' => 0,
        ];
        $DB->insert_record(self::CODE_TABLE, $record);

        return $record->code;
    }

    /**
     * Exchange an OAuth token request for credentials.
     *
     * @param array $params Token request parameters.
     * @param string|null $clientid Optional client id from the request.
     * @param string|null $clientsecret Optional client secret from the request.
     * @return array
     */
    public function exchange_token_request(array $params, ?string $clientid, ?string $clientsecret): array {
        $granttype = trim((string)($params['grant_type'] ?? ''));
        if ($granttype === '') {
            throw new exception('invalid_request', 400, 'grant_type is required.');
        }

        $client = $this->authenticate_client((string)$clientid, $clientsecret);

        return match ($granttype) {
            'authorization_code' => $this->exchange_authorization_code($client, $params),
            'refresh_token' => $this->refresh_access_token($client, $params),
            default => throw new exception('unsupported_grant_type', 400, 'Unsupported grant_type.'),
        };
    }

    /**
     * Resolve client credentials from the current request.
     *
     * @return array
     */
    public function read_client_credentials_from_request(): array {
        $authorization = $this->read_headers()['authorization'] ?? '';
        if (stripos($authorization, 'Basic ') === 0) {
            $decoded = base64_decode(substr($authorization, 6), true);
            if ($decoded === false || !str_contains($decoded, ':')) {
                throw new exception('invalid_client', 401, 'Malformed client credentials.');
            }

            [$clientid, $clientsecret] = explode(':', $decoded, 2);
            return [$clientid, $clientsecret];
        }

        return [
            isset($_POST['client_id']) ? (string)$_POST['client_id'] : null,
            isset($_POST['client_secret']) ? (string)$_POST['client_secret'] : null,
        ];
    }

    /**
     * Build a redirect URI carrying an authorization response.
     *
     * @param string $redirecturi Registered redirect URI.
     * @param array $params Query params to append.
     * @return string
     */
    public function build_redirect_uri(string $redirecturi, array $params): string {
        return (new moodle_url($redirecturi, $params))->out(false);
    }

    /**
     * Determine whether the supplied scope set contains the required scope.
     *
     * @param string $scope Granted scope string.
     * @param string $requiredscope Required scope.
     * @return bool
     */
    public static function scope_contains(string $scope, string $requiredscope): bool {
        return in_array($requiredscope, self::scope_tokens($scope), true);
    }

    /**
     * Normalize a resource URI for canonical comparisons.
     *
     * @param string $uri Absolute URI.
     * @return string
     */
    public static function normalize_resource_uri(string $uri): string {
        return self::canonicalize_uri($uri, false);
    }

    /**
     * Normalize a redirect URI for registration and comparisons.
     *
     * @param string $uri Absolute redirect URI.
     * @return string
     */
    public static function normalize_redirect_uri(string $uri): string {
        $normalized = self::canonicalize_uri($uri, true);
        $parts = parse_url($normalized);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));

        if ($scheme !== 'https' && !self::is_loopback_host($host)) {
            throw new exception('invalid_client_metadata', 400, 'Redirect URIs must use HTTPS or localhost.');
        }

        return $normalized;
    }

    /**
     * Normalize an OAuth scope string and fill implied scopes.
     *
     * @param string $scope Raw scope string.
     * @param string $fallback Scope set used when the input is empty.
     * @return string
     */
    public static function normalize_scope_string(string $scope, string $fallback = ''): string {
        $tokens = self::scope_tokens($scope !== '' ? $scope : $fallback);

        if (in_array(self::SCOPE_WRITE, $tokens, true) && !in_array(self::SCOPE_READ, $tokens, true)) {
            array_unshift($tokens, self::SCOPE_READ);
        }

        if (in_array(self::SCOPE_OFFLINE, $tokens, true) &&
                !in_array(self::SCOPE_READ, $tokens, true) &&
                !in_array(self::SCOPE_WRITE, $tokens, true)) {
            array_unshift($tokens, self::SCOPE_READ);
        }

        return implode(' ', array_values(array_unique($tokens)));
    }

    /**
     * Return parsed OAuth scope tokens.
     *
     * @param string $scope Scope string.
     * @return array
     */
    private static function scope_tokens(string $scope): array {
        $parts = preg_split('/\s+/', trim($scope)) ?: [];
        $parts = array_filter($parts, static fn(string $token): bool => $token !== '');
        return array_values(array_unique(array_map('strval', $parts)));
    }

    /**
     * Escape header values conservatively.
     *
     * @param string $value Raw header value.
     * @return string
     */
    private static function escape_header_value(string $value): string {
        return addcslashes($value, "\"\\");
    }

    /**
     * Canonicalize an absolute HTTP(S) URI.
     *
     * @param string $uri Absolute URI.
     * @param bool $allowquery Whether query parameters may be preserved.
     * @return string
     */
    private static function canonicalize_uri(string $uri, bool $allowquery): string {
        $parts = parse_url(trim($uri));
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new exception('invalid_request', 400, 'Invalid URI.');
        }

        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new exception('invalid_request', 400, 'Only HTTP and HTTPS URIs are supported.');
        }

        if (isset($parts['fragment'])) {
            throw new exception('invalid_request', 400, 'Fragments are not permitted.');
        }

        $host = strtolower((string)$parts['host']);
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        $path = (string)($parts['path'] ?? '/');
        $path = $path === '' ? '/' : $path;
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $query = '';
        if ($allowquery && isset($parts['query']) && $parts['query'] !== '') {
            $query = '?' . (string)$parts['query'];
        }

        $defaultport = ($scheme === 'https') ? 443 : 80;
        $portsegment = ($port === null || $port === $defaultport) ? '' : ':' . $port;

        return $scheme . '://' . $host . $portsegment . $path . $query;
    }

    /**
     * Determine whether the host is a localhost/loopback host.
     *
     * @param string $host Hostname or IP.
     * @return bool
     */
    private static function is_loopback_host(string $host): bool {
        $normalized = trim($host, '[]');
        return in_array($normalized, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * Ensure the supplied resource URI targets this MCP server.
     *
     * @param string $resource Requested resource URI.
     * @return string
     */
    private function validate_resource(string $resource): string {
        $normalized = trim($resource) === '' ? $this->canonical_resource_uri() : self::normalize_resource_uri($resource);
        if (!hash_equals($this->canonical_resource_uri(), $normalized)) {
            throw new exception('invalid_target', 400, 'resource must match the MCP transport URL.');
        }

        return $normalized;
    }

    /**
     * Fetch a client record by client id.
     *
     * @param string $clientid OAuth client id.
     * @return stdClass|null
     */
    public function get_client(string $clientid): ?stdClass {
        global $DB;

        $record = $DB->get_record(self::CLIENT_TABLE, ['clientid' => $clientid, 'revoked' => 0]);
        return $record ? $this->hydrate_client($record) : null;
    }

    /**
     * Authenticate a client at the token endpoint.
     *
     * @param string $clientid OAuth client id.
     * @param string|null $clientsecret OAuth client secret, if any.
     * @return stdClass
     */
    private function authenticate_client(string $clientid, ?string $clientsecret): stdClass {
        $clientid = trim($clientid);
        if ($clientid === '') {
            throw new exception('invalid_client', 401, 'Missing client credentials.');
        }

        $client = $this->get_client($clientid);
        if ($client === null) {
            throw new exception('invalid_client', 401, 'Unknown client.');
        }

        if ($client->tokenauthmethod === 'none') {
            if ($clientsecret !== null && $clientsecret !== '') {
                throw new exception('invalid_client', 401, 'Public clients must not send a client secret.');
            }

            return $client;
        }

        if ($clientsecret === null || $clientsecret === '' ||
                !password_verify($clientsecret, (string)$client->clientsecret)) {
            throw new exception('invalid_client', 401, 'Invalid client credentials.');
        }

        return $client;
    }

    /**
     * Exchange an authorization code for access and refresh tokens.
     *
     * @param stdClass $client OAuth client.
     * @param array $params Token request params.
     * @return array
     */
    private function exchange_authorization_code(stdClass $client, array $params): array {
        if (!in_array('authorization_code', $client->granttypes, true)) {
            throw new exception('unauthorized_client', 400, 'This client cannot use the authorization_code grant.');
        }

        $code = trim((string)($params['code'] ?? ''));
        if ($code === '') {
            throw new exception('invalid_request', 400, 'code is required.');
        }

        $redirecturi = trim((string)($params['redirect_uri'] ?? ''));
        if ($redirecturi === '') {
            throw new exception('invalid_request', 400, 'redirect_uri is required.');
        }
        $redirecturi = self::normalize_redirect_uri($redirecturi);

        $codeverifier = trim((string)($params['code_verifier'] ?? ''));
        if ($codeverifier === '') {
            throw new exception('invalid_request', 400, 'code_verifier is required.');
        }

        $resourceuri = $this->validate_resource((string)($params['resource'] ?? ''));
        $coderecord = $this->get_authorization_code($code, $client->clientid, $redirecturi, $resourceuri);
        if ($coderecord === null) {
            throw new exception('invalid_grant', 400, 'Authorization code is invalid or expired.');
        }

        if (!$this->verify_pkce($codeverifier, $coderecord->codechallenge, $coderecord->codechallengemethod)) {
            throw new exception('invalid_grant', 400, 'PKCE verification failed.');
        }

        $this->mark_authorization_code_used((int)$coderecord->id);

        return $this->issue_oauth_token_pair(
            $client,
            (int)$coderecord->userid,
            context::instance_by_id((int)$coderecord->contextid),
            (string)$coderecord->scope,
            (string)$coderecord->resourceuri
        );
    }

    /**
     * Refresh an access token and rotate the refresh token.
     *
     * @param stdClass $client OAuth client.
     * @param array $params Token request params.
     * @return array
     */
    private function refresh_access_token(stdClass $client, array $params): array {
        if (!in_array('refresh_token', $client->granttypes, true)) {
            throw new exception('unauthorized_client', 400, 'This client cannot use refresh_token.');
        }

        $refresh = trim((string)($params['refresh_token'] ?? ''));
        if ($refresh === '') {
            throw new exception('invalid_request', 400, 'refresh_token is required.');
        }

        $resourceuri = $this->validate_resource((string)($params['resource'] ?? ''));
        $record = $this->credentialmanager->resolve_credential($refresh);
        if (!$record || (int)$record->tokentype !== credential_manager::TOKEN_TYPE_REFRESH) {
            throw new exception('invalid_grant', 400, 'Refresh token is invalid or expired.');
        }

        if (!hash_equals((string)($record->oauthclientid ?? ''), (string)$client->clientid)) {
            throw new exception('invalid_grant', 400, 'Refresh token does not belong to this client.');
        }

        if (!hash_equals((string)($record->resourceuri ?? ''), $resourceuri)) {
            throw new exception('invalid_grant', 400, 'Refresh token does not match the requested resource.');
        }

        $this->credentialmanager->revoke_credential((string)$record->token, (int)$record->userid);

        return $this->issue_oauth_token_pair(
            $client,
            (int)$record->userid,
            context::instance_by_id((int)$record->contextid),
            (string)($record->scope ?? $this->default_scope_string()),
            (string)$record->resourceuri
        );
    }

    /**
     * Issue access and optional refresh tokens for a validated user/client/resource.
     *
     * @param stdClass $client OAuth client.
     * @param int $userid Moodle user id.
     * @param context $context Restricted context.
     * @param string $scope Granted scopes.
     * @param string $resourceuri Bound resource URI.
     * @return array
     */
    private function issue_oauth_token_pair(
        stdClass $client,
        int $userid,
        context $context,
        string $scope,
        string $resourceuri
    ): array {
        $service = $this->connectormanager->ensure_service_for_user($userid);
        $scope = self::normalize_scope_string($scope, $this->default_scope_string());

        $accesstoken = $this->credentialmanager->issue_oauth_access_token(
            $service,
            $userid,
            $context,
            [
                'scope' => $scope,
                'resourceuri' => $resourceuri,
                'oauthclientid' => $client->clientid,
                'name' => 'OAuth access - ' . $client->clientname,
                'usermodified' => $userid,
            ]
        );

        $response = [
            'token_type' => 'Bearer',
            'access_token' => $accesstoken->token,
            'expires_in' => max(0, (int)$accesstoken->validuntil - time()),
            'scope' => $scope,
            'resource' => $resourceuri,
        ];

        if (self::scope_contains($scope, self::SCOPE_OFFLINE)) {
            $refreshtoken = $this->credentialmanager->issue_oauth_refresh_token(
                $service,
                $userid,
                $context,
                [
                    'scope' => $scope,
                    'resourceuri' => $resourceuri,
                    'oauthclientid' => $client->clientid,
                    'name' => 'OAuth refresh - ' . $client->clientname,
                    'usermodified' => $userid,
                ]
            );
            $response['refresh_token'] = $refreshtoken->token;
        }

        return $response;
    }

    /**
     * Fetch an authorization code record that is still valid.
     *
     * @param string $code Authorization code.
     * @param string $clientid Client id.
     * @param string $redirecturi Redirect URI.
     * @param string $resourceuri Resource URI.
     * @return stdClass|null
     */
    private function get_authorization_code(
        string $code,
        string $clientid,
        string $redirecturi,
        string $resourceuri
    ): ?stdClass {
        global $DB;

        $sql = 'code = :code
            AND clientid = :clientid
            AND ' . $DB->sql_compare_text('redirecturi', 1024) . ' = ' . $DB->sql_compare_text(':redirecturi', 1024) . '
            AND resourceuri = :resourceuri
            AND used = :used';
        $record = $DB->get_record_select(self::CODE_TABLE, $sql, [
            'code' => $code,
            'clientid' => $clientid,
            'redirecturi' => $redirecturi,
            'resourceuri' => $resourceuri,
            'used' => 0,
        ]);
        if (!$record) {
            return null;
        }

        if ((int)$record->expiresat < time()) {
            $DB->set_field(self::CODE_TABLE, 'used', 1, ['id' => $record->id]);
            return null;
        }

        return $record;
    }

    /**
     * Mark an authorization code as consumed.
     *
     * @param int $id Code record id.
     * @return void
     */
    private function mark_authorization_code_used(int $id): void {
        global $DB;

        $DB->update_record(self::CODE_TABLE, (object)[
            'id' => $id,
            'timemodified' => time(),
            'used' => 1,
        ]);
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
        if (strtoupper($method) !== 'S256') {
            return false;
        }

        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return hash_equals($challenge, $expected);
    }

    /**
     * Hydrate JSON-backed client metadata into arrays.
     *
     * @param stdClass $record Raw DB record.
     * @return stdClass
     */
    private function hydrate_client(stdClass $record): stdClass {
        $record->redirecturis = json_decode((string)$record->redirecturis, true) ?: [];
        $record->granttypes = json_decode((string)$record->granttypes, true) ?: [];
        $record->responsetypes = json_decode((string)$record->responsetypes, true) ?: [];
        return $record;
    }

    /**
     * Normalize and validate a list-valued client metadata field.
     *
     * @param mixed $values Candidate field value.
     * @param array $allowedvalues Supported values.
     * @param string $fieldname Metadata field name.
     * @return array
     */
    private function normalize_client_list(mixed $values, array $allowedvalues, string $fieldname): array {
        if (!is_array($values) || $values === []) {
            throw new exception('invalid_client_metadata', 400, $fieldname . ' must be a non-empty array.');
        }

        $normalized = array_values(array_unique(array_map('strval', $values)));
        foreach ($normalized as $value) {
            if (!in_array($value, $allowedvalues, true)) {
                throw new exception('invalid_client_metadata', 400, 'Unsupported value in ' . $fieldname . '.');
            }
        }

        return $normalized;
    }

    /**
     * Ensure a scope set only contains supported scopes.
     *
     * @param string $scope Normalized scope string.
     * @return void
     */
    private function assert_supported_scope_set(string $scope): void {
        $unsupported = array_diff(self::scope_tokens($scope), $this->supported_scopes());
        if ($unsupported !== []) {
            throw new exception('invalid_scope', 400, 'Unsupported scope requested.');
        }
    }

    /**
     * Ensure the requested scope set is within the client's registered scope set.
     *
     * @param string $requested Requested scope set.
     * @param string $allowed Allowed scope set.
     * @return void
     */
    private function assert_scope_subset(string $requested, string $allowed): void {
        $this->assert_supported_scope_set($requested);
        $diff = array_diff(self::scope_tokens($requested), self::scope_tokens($allowed));
        if ($diff !== []) {
            throw new exception('invalid_scope', 400, 'Requested scope exceeds the client registration.');
        }
    }

    /**
     * Generate a unique OAuth client id.
     *
     * @return string
     */
    private function generate_unique_client_id(): string {
        global $DB;

        do {
            $clientid = 'mcp_' . bin2hex(random_bytes(16));
        } while ($DB->record_exists(self::CLIENT_TABLE, ['clientid' => $clientid]));

        return $clientid;
    }

    /**
     * Generate a unique OAuth authorization code.
     *
     * @return string
     */
    private function generate_unique_authorization_code(): string {
        global $DB;

        do {
            $code = bin2hex(random_bytes(24));
        } while ($DB->record_exists(self::CODE_TABLE, ['code' => $code]));

        return $code;
    }

    /**
     * Generate a client secret.
     *
     * @return string
     */
    private function generate_secret(): string {
        return bin2hex(random_bytes(24));
    }

    /**
     * Read request headers into a lower-case-key array.
     *
     * @return array
     */
    private function read_headers(): array {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($name, 'HTTP_')) {
                $headername = strtolower(str_replace('_', '-', substr($name, 5)));
                $headers[$headername] = $value;
            } else if ($name === 'CONTENT_TYPE') {
                $headers['content-type'] = $value;
            } else if ($name === 'CONTENT_LENGTH') {
                $headers['content-length'] = $value;
            }
        }

        return $headers;
    }
}
