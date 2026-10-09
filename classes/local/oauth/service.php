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
use moodle_url;
use stdClass;
use webservice_mcp\local\auth\bootstrap_service;
use webservice_mcp\local\auth\connector_service_manager;
use webservice_mcp\local\auth\credential_manager;

/**
 * Moodle-native OAuth 2.1 authorization server for remote MCP clients.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service {
    /** RFC 7636 code_verifier / S256 code_challenge syntax. */
    public const PKCE_PATTERN = '/^[A-Za-z0-9\-._~]{43,128}$/';

    /** RFC 7523 JWT bearer grant type (Enterprise Managed Authorization identity assertions). */
    public const GRANT_JWT_BEARER = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    /** OAuth read scope. */
    public const SCOPE_READ = 'mcp:read';

    /** OAuth write scope. */
    public const SCOPE_WRITE = 'mcp:write';

    /** OAuth refresh scope. */
    public const SCOPE_OFFLINE = 'offline_access';

    /** Scope registered for clients that do not ask for one. */
    public const REGISTRATION_DEFAULT_SCOPE = 'mcp:read mcp:write offline_access';

    /** @var credential_manager */
    private credential_manager $credentialmanager;

    /** @var connector_service_manager */
    private connector_service_manager $connectormanager;

    /** @var client_registry */
    private client_registry $clients;

    /** @var jwt_bearer_grant|null Optional JWT bearer verifier override. */
    private ?jwt_bearer_grant $jwtbearer;

    /**
     * Constructor.
     *
     * @param credential_manager|null $credentialmanager Optional credential manager override.
     * @param connector_service_manager|null $connectormanager Optional connector service manager override.
     * @param client_registry|null $clients Optional client registry override.
     * @param jwt_bearer_grant|null $jwtbearer Optional JWT bearer verifier override.
     */
    public function __construct(
        ?credential_manager $credentialmanager = null,
        ?connector_service_manager $connectormanager = null,
        ?client_registry $clients = null,
        ?jwt_bearer_grant $jwtbearer = null
    ) {
        $this->credentialmanager = $credentialmanager ?? new credential_manager();
        $this->connectormanager = $connectormanager ?? new connector_service_manager();
        $this->clients = $clients ?? new client_registry();
        $this->jwtbearer = $jwtbearer;
    }

    /**
     * Return the authorization-request handler (authorize endpoint logic).
     *
     * @return authorization
     */
    public function authorization(): authorization {
        return new authorization($this, $this->clients, $this->connectormanager, $this->credentialmanager);
    }

    /**
     * Whether Enterprise Managed Authorization (RFC 7523 identity assertions) is enabled.
     *
     * @return bool
     */
    public function ema_enabled(): bool {
        return (bool)get_config('webservice_mcp', 'emaenabled');
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
     * Return the RFC 7009 revocation endpoint URL.
     *
     * @return string
     */
    public function revocation_endpoint_url(): string {
        return (new moodle_url('/webservice/mcp/oauth/revoke.php'))->out(false);
    }

    /**
     * Return the (empty) JWKS URL advertised for OpenID discovery parsers.
     *
     * @return string
     */
    public function jwks_url(): string {
        return (new moodle_url('/webservice/mcp/oauth/jwks.php'))->out(false);
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
     * Return the scope set MCP clients should request from the resource (used in Bearer challenges).
     *
     * offline_access is deliberately absent: it is not a resource requirement.
     *
     * @return string
     */
    public function default_scope_string(): string {
        return self::SCOPE_READ . ' ' . self::SCOPE_WRITE;
    }

    /**
     * Return the scopes supported by the authorization server.
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
     * Return human-readable labels for the supported scopes.
     *
     * @return array
     */
    public function scope_labels(): array {
        return [
            self::SCOPE_READ => get_string('oauth:scope_read', 'webservice_mcp'),
            self::SCOPE_WRITE => get_string('oauth:scope_write', 'webservice_mcp'),
            self::SCOPE_OFFLINE => get_string('oauth:scope_offline', 'webservice_mcp'),
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
            $scope = self::normalize_scope_string($scope, $this->default_scope_string());
            $parts[] = 'scope="' . self::escape_header_value($scope) . '"';
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
     * Register an OAuth client dynamically, or pre-register one for an administrator.
     *
     * @param array $metadata Registration metadata.
     * @param bool $isdynamic False for administrator pre-registration (client is treated as verified).
     * @return array
     */
    public function register_dynamic_client(array $metadata, bool $isdynamic = true): array {
        $scope = self::normalize_scope_string((string)($metadata['scope'] ?? ''), self::REGISTRATION_DEFAULT_SCOPE);
        $this->assert_supported_scope_set($scope);

        return $this->clients->register_client($metadata, self::REGISTRATION_DEFAULT_SCOPE, $scope, $isdynamic);
    }

    /**
     * Throttle dynamic registrations per source IP.
     *
     * @param string $ipaddress Remote address.
     * @return void
     */
    public function enforce_registration_rate_limit(string $ipaddress): void {
        $this->clients->enforce_registration_rate_limit($ipaddress);
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

        $issuer = new token_issuer($this, $this->credentialmanager, $this->connectormanager);
        return match ($granttype) {
            'authorization_code' => $issuer->exchange_authorization_code($client, $params),
            'refresh_token' => $issuer->refresh_access_token($client, $params),
            self::GRANT_JWT_BEARER => $this->ema_enabled()
                ? $issuer->issue_for_assertion($client, $params, $this->jwtbearer ?? new jwt_bearer_grant())
                : throw new exception('unsupported_grant_type', 400, 'Unsupported grant_type.'),
            default => throw new exception('unsupported_grant_type', 400, 'Unsupported grant_type.'),
        };
    }

    /**
     * Handle an RFC 7009 token revocation request.
     *
     * Unknown tokens and tokens of other clients are ignored, as the RFC allows.
     *
     * @param array $params Revocation request parameters.
     * @param string|null $clientid Client id from the request.
     * @param string|null $clientsecret Client secret from the request.
     * @return void
     */
    public function revoke_token_request(array $params, ?string $clientid, ?string $clientsecret): void {
        $client = $this->authenticate_client((string)$clientid, $clientsecret);

        $token = trim((string)($params['token'] ?? ''));
        if ($token === '') {
            throw new exception('invalid_request', 400, 'token is required.');
        }

        $record = $this->credentialmanager->find_credential($token);
        if (!$record || !hash_equals((string)$client->clientid, (string)($record->oauthclientid ?? ''))) {
            return;
        }

        if ((int)$record->tokentype === credential_manager::TOKEN_TYPE_REFRESH && !empty($record->familyid)) {
            $this->credentialmanager->revoke_family((string)$record->familyid, (int)$record->userid);
            return;
        }
        $this->credentialmanager->revoke_credential_by_id((int)$record->id, (int)$record->userid);
    }

    /**
     * Resolve client credentials from the current request.
     *
     * @return array
     */
    public function read_client_credentials_from_request(): array {
        $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

        if (stripos($authorization, 'Basic ') === 0) {
            $decoded = base64_decode(trim(substr($authorization, 6)), true);
            if ($decoded === false || !str_contains($decoded, ':')) {
                throw new exception('invalid_client', 401, 'Malformed client credentials.');
            }

            // RFC 6749 section 2.3.1: both parts are form-urlencoded before base64 encoding.
            [$clientid, $clientsecret] = explode(':', $decoded, 2);
            return [urldecode($clientid), urldecode($clientsecret)];
        }

        return [
            isset($_POST['client_id']) ? (string)$_POST['client_id'] : null,
            isset($_POST['client_secret']) ? (string)$_POST['client_secret'] : null,
        ];
    }

    /**
     * Build a redirect URI carrying an authorization response, including the RFC 9207 iss parameter.
     *
     * @param string $redirecturi Registered redirect URI.
     * @param array $params Query params to append.
     * @return string
     */
    public function build_redirect_uri(string $redirecturi, array $params): string {
        $params['iss'] = $this->issuer_url();
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

        if ($scheme !== 'https' && !client_registry::is_loopback_host($host)) {
            throw new exception('invalid_redirect_uri', 400, 'Redirect URIs must use HTTPS or localhost.');
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
        $tokens = self::scope_tokens(trim($scope) !== '' ? $scope : $fallback);

        if (in_array(self::SCOPE_WRITE, $tokens, true) && !in_array(self::SCOPE_READ, $tokens, true)) {
            array_unshift($tokens, self::SCOPE_READ);
        }

        if (
            in_array(self::SCOPE_OFFLINE, $tokens, true) &&
                !in_array(self::SCOPE_READ, $tokens, true) &&
                !in_array(self::SCOPE_WRITE, $tokens, true)
        ) {
            array_unshift($tokens, self::SCOPE_READ);
        }

        return implode(' ', array_values(array_unique($tokens)));
    }

    /**
     * Fetch an active client record by client id (database only, no metadata fetch).
     *
     * @param string $clientid OAuth client id.
     * @return stdClass|null
     */
    public function get_client(string $clientid): ?stdClass {
        return $this->clients->get_client($clientid);
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
     * Ensure the supplied resource URI targets this MCP server.
     *
     * @param string $resource Requested resource URI.
     * @return string
     */
    public function validate_resource(string $resource): string {
        $normalized = trim($resource) === '' ? $this->canonical_resource_uri() : self::normalize_resource_uri($resource);
        if (!hash_equals($this->canonical_resource_uri(), $normalized)) {
            throw new exception('invalid_target', 400, 'resource must match the MCP transport URL.');
        }

        return $normalized;
    }

    /**
     * Authenticate a client at the token or revocation endpoint.
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

        // Database only: these endpoints are anonymous, so they must never fetch metadata documents or create clients.
        // Metadata-document clients are stored when a signed-in user authorizes them.
        $client = $this->clients->get_client($clientid);
        if ($client === null) {
            throw new exception('invalid_client', 401, 'Unknown client.');
        }

        if ($client->tokenauthmethod === 'none') {
            if ($clientsecret !== null && $clientsecret !== '') {
                throw new exception('invalid_client', 401, 'Public clients must not send a client secret.');
            }

            return $client;
        }

        // During a secret rotation overlap the previous secret is still accepted.
        $valid = $clientsecret !== null && $clientsecret !== '' && (
            password_verify($clientsecret, (string)$client->clientsecret) || (
                !empty($client->previoussecret) && (int)$client->previoussecretexpires > time()
                && password_verify($clientsecret, (string)$client->previoussecret)
            )
        );
        if (!$valid) {
            throw new exception('invalid_client', 401, 'Invalid client credentials.');
        }

        return $client;
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
    public function assert_scope_subset(string $requested, string $allowed): void {
        $this->assert_supported_scope_set($requested);
        $diff = array_diff(self::scope_tokens($requested), self::scope_tokens($allowed));
        if ($diff !== []) {
            throw new exception('invalid_scope', 400, 'Requested scope exceeds the client registration.');
        }
    }
}
