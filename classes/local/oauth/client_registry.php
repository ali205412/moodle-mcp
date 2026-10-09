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

use Closure;
use stdClass;

/**
 * OAuth client registry: dynamic registration, Client ID Metadata Documents, and redirect URI policy.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client_registry {
    /** OAuth client table name. */
    private const CLIENT_TABLE = 'webservice_mcp_oauth_client';

    /** Supported OAuth token endpoint auth methods. */
    public const TOKEN_AUTH_METHODS = ['none', 'client_secret_basic', 'client_secret_post'];

    /** Maximum redirect URIs per client. */
    private const MAX_REDIRECT_URIS = 10;

    /** Maximum length of a redirect URI. */
    private const MAX_URI_LENGTH = 1024;

    /** Maximum dynamic registrations per IP address per hour. */
    private const REGISTRATIONS_PER_HOUR = 20;

    /** Maximum size of a client metadata document. */
    private const METADATA_MAX_BYTES = 65536;

    /** Redirect hosts allowed when the admin setting is unset. */
    public const DEFAULT_REDIRECT_HOSTS = "claude.ai\nclaude.com\nlocalhost\n127.0.0.1\n[::1]";

    /** @var Closure|null Fetches a client metadata document body: fn(string $url): string. */
    private ?Closure $metadatafetcher;

    /**
     * Constructor.
     *
     * @param Closure|null $metadatafetcher Optional HTTP seam returning the document body or throwing.
     */
    public function __construct(?Closure $metadatafetcher = null) {
        $this->metadatafetcher = $metadatafetcher;
    }

    /**
     * Register an OAuth client: dynamically (RFC 7591) or pre-registered by an administrator.
     *
     * @param array $metadata Registration metadata.
     * @param string $defaultscope Scope registered when the client asks for none.
     * @param string $scope Normalized requested scope.
     * @param bool $isdynamic False for administrator pre-registration.
     * @return array Registration response (client_secret, if any, is only ever returned here).
     */
    public function register_client(array $metadata, string $defaultscope, string $scope, bool $isdynamic = true): array {
        global $DB;

        $redirecturis = $this->validate_redirect_uris($metadata['redirect_uris'] ?? null, true);

        $tokenauthmethod = (string)($metadata['token_endpoint_auth_method'] ?? 'none');
        if (!in_array($tokenauthmethod, self::TOKEN_AUTH_METHODS, true)) {
            throw new exception('invalid_client_metadata', 400, 'Unsupported token endpoint auth method.');
        }

        $applicationtype = $metadata['application_type'] ?? null;
        if ($applicationtype !== null && !in_array($applicationtype, ['native', 'web'], true)) {
            throw new exception('invalid_client_metadata', 400, 'application_type must be native or web.');
        }

        [$granttypes, $responsetypes] = $this->validate_grant_and_response_types($metadata);

        $plaintextsecret = null;
        $storedsecret = null;
        if ($tokenauthmethod !== 'none') {
            $plaintextsecret = bin2hex(random_bytes(24));
            $storedsecret = password_hash($plaintextsecret, PASSWORD_DEFAULT);
        }

        $record = (object)[
            'timecreated' => time(),
            'timemodified' => time(),
            'clientid' => $this->generate_unique_client_id(),
            'clientsecret' => $storedsecret,
            'clientname' => self::clean_client_name($metadata['client_name'] ?? ''),
            'redirecturis' => json_encode($redirecturis),
            'scope' => $scope !== '' ? $scope : $defaultscope,
            'granttypes' => json_encode($granttypes),
            'responsetypes' => json_encode($responsetypes),
            'tokenauthmethod' => $tokenauthmethod,
            'isdynamic' => $isdynamic ? 1 : 0,
            'revoked' => 0,
        ];
        $DB->insert_record(self::CLIENT_TABLE, $record);

        $response = [
            'client_id' => $record->clientid,
            'client_id_issued_at' => $record->timecreated,
            'client_name' => $record->clientname,
            'redirect_uris' => $redirecturis,
            'scope' => $record->scope,
            'grant_types' => $granttypes,
            'response_types' => $responsetypes,
            'token_endpoint_auth_method' => $tokenauthmethod,
            'client_secret_expires_at' => 0,
        ];
        if ($applicationtype !== null) {
            $response['application_type'] = $applicationtype;
        }
        if ($plaintextsecret !== null) {
            $response['client_secret'] = $plaintextsecret;
        }

        return $response;
    }

    /**
     * Throttle dynamic registrations per source IP.
     *
     * @param string $ipaddress Remote address.
     * @return void
     */
    public function enforce_registration_rate_limit(string $ipaddress): void {
        $cache = \cache::make('webservice_mcp', 'oauth_ratelimit');
        $key = 'reg_' . sha1($ipaddress);
        $now = time();

        $window = $cache->get($key);
        if (!is_array($window) || (int)$window['start'] + HOURSECS < $now) {
            $window = ['start' => $now, 'count' => 0];
        }
        if ((int)$window['count'] >= self::REGISTRATIONS_PER_HOUR) {
            throw new exception('invalid_request', 429, 'Too many client registrations from this address. Try again later.');
        }
        $window['count'] = (int)$window['count'] + 1;
        $cache->set($key, $window);
    }

    /**
     * Fetch an active (non-revoked) client from the database.
     *
     * @param string $clientid OAuth client id.
     * @return stdClass|null
     */
    public function get_client(string $clientid): ?stdClass {
        global $DB;

        if ($clientid === '') {
            return null;
        }
        $record = $DB->get_record(self::CLIENT_TABLE, ['clientid' => $clientid, 'revoked' => 0]);
        return $record ? self::hydrate_client($record) : null;
    }

    /**
     * Resolve a client for an authorization request, fetching its metadata document for URL client ids.
     *
     * @param string $clientid OAuth client id.
     * @return stdClass|null
     */
    public function resolve_client_for_authorization(string $clientid): ?stdClass {
        if (!self::is_metadata_document_client_id($clientid)) {
            return $this->get_client($clientid);
        }

        return $this->load_metadata_document_client($clientid);
    }

    /**
     * Whether a client id is a Client ID Metadata Document URL (https with a path).
     *
     * @param string $clientid OAuth client id.
     * @return bool
     */
    public static function is_metadata_document_client_id(string $clientid): bool {
        if (strlen($clientid) > 255 || !str_starts_with($clientid, 'https://')) {
            return false;
        }

        $parts = parse_url($clientid);
        if (
            $parts === false || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
                isset($parts['fragment'])
        ) {
            return false;
        }

        $path = (string)($parts['path'] ?? '');
        return $path !== '' && $path !== '/' && !preg_match('#(^|/)\.\.?(/|$)#', $path);
    }

    /**
     * Whether a redirect host is allowed by the allowedredirecthosts setting.
     *
     * @param string $host Redirect URI host.
     * @return bool
     */
    public static function is_redirect_host_allowed(string $host): bool {
        $configured = get_config('webservice_mcp', 'allowedredirecthosts');
        $list = ($configured === false || trim((string)$configured) === '') ? self::DEFAULT_REDIRECT_HOSTS : (string)$configured;

        $host = trim(strtolower($host), '[]');
        foreach (preg_split('/[\s,]+/', $list) ?: [] as $allowed) {
            $allowed = trim(strtolower($allowed), '[]');
            if ($allowed === '*' || ($allowed !== '' && $allowed === $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a requested (normalized) redirect URI matches one of the registered URIs.
     *
     * Loopback http redirect URIs match regardless of port (RFC 8252 section 7.3).
     *
     * @param string $requested Normalized requested redirect URI.
     * @param array $registered Normalized registered redirect URIs.
     * @return bool
     */
    public static function redirect_uri_matches(string $requested, array $registered): bool {
        if (in_array($requested, $registered, true)) {
            return true;
        }

        $req = parse_url($requested);
        if (($req['scheme'] ?? '') !== 'http' || !self::is_loopback_host((string)($req['host'] ?? ''))) {
            return false;
        }

        foreach ($registered as $candidate) {
            $reg = parse_url((string)$candidate);
            if (
                ($reg['scheme'] ?? '') === 'http' &&
                    ($reg['host'] ?? '') === ($req['host'] ?? '') &&
                    ($reg['path'] ?? '/') === ($req['path'] ?? '/') &&
                    ($reg['query'] ?? '') === ($req['query'] ?? '')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the host is a localhost/loopback host.
     *
     * @param string $host Hostname or IP.
     * @return bool
     */
    public static function is_loopback_host(string $host): bool {
        return in_array(trim(strtolower($host), '[]'), ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * Load, validate, and record a Client ID Metadata Document client.
     *
     * @param string $clientid Metadata document URL.
     * @return stdClass|null Null when an admin revoked the client.
     */
    private function load_metadata_document_client(string $clientid): ?stdClass {
        global $DB;

        $existing = $DB->get_record(self::CLIENT_TABLE, ['clientid' => $clientid]);
        if ($existing && !empty($existing->revoked)) {
            return null;
        }

        $cache = \cache::make('webservice_mcp', 'oauth_client_metadata');
        $cachekey = sha1($clientid);
        $document = json_decode((string)$cache->get($cachekey), true);
        if (!is_array($document)) {
            $document = $this->validate_metadata_document($clientid, $this->fetch_metadata_document($clientid));
            $cache->set($cachekey, json_encode($document));
        }

        $record = (object)[
            'timemodified' => time(),
            'clientid' => $clientid,
            'clientsecret' => null,
            'clientname' => $document['client_name'],
            'redirecturis' => json_encode($document['redirect_uris']),
            'scope' => $document['scope'],
            'granttypes' => json_encode($document['grant_types']),
            'responsetypes' => json_encode($document['response_types']),
            'tokenauthmethod' => 'none',
            'isdynamic' => 0,
            'revoked' => 0,
        ];
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record(self::CLIENT_TABLE, $record);
        } else {
            $record->timecreated = time();
            $record->id = $DB->insert_record(self::CLIENT_TABLE, $record);
        }

        return self::hydrate_client($record);
    }

    /**
     * Fetch a client metadata document body over HTTPS with Moodle's SSRF-protected curl.
     *
     * @param string $url Metadata document URL.
     * @return string
     */
    private function fetch_metadata_document(string $url): string {
        if ($this->metadatafetcher !== null) {
            return ($this->metadatafetcher)($url);
        }

        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        // The default constructor applies \core\files\curl_security_helper (blocked hosts, ports, private ranges).
        $curl = new \curl();
        $body = $curl->get($url, [], [
            'CURLOPT_TIMEOUT' => 5,
            'CURLOPT_CONNECTTIMEOUT' => 3,
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_MAXFILESIZE' => self::METADATA_MAX_BYTES,
            'CURLOPT_HTTPHEADER' => ['Accept: application/json'],
        ]);
        $info = $curl->get_info();

        if ($curl->get_errno() || (int)($info['http_code'] ?? 0) !== 200 || !is_string($body)) {
            throw new exception('invalid_client', 400, 'The client metadata document could not be retrieved.');
        }

        return $body;
    }

    /**
     * Validate a client metadata document and reduce it to the fields this server uses.
     *
     * @param string $clientid Metadata document URL the document was fetched from.
     * @param string $body Raw document body.
     * @return array
     */
    public function validate_metadata_document(string $clientid, string $body): array {
        if (strlen($body) > self::METADATA_MAX_BYTES) {
            throw new exception('invalid_client', 400, 'The client metadata document is too large.');
        }

        $document = json_decode($body, true);
        if (!is_array($document) || !str_starts_with(ltrim($body), '{')) {
            throw new exception('invalid_client', 400, 'The client metadata document is not a JSON object.');
        }

        if (($document['client_id'] ?? null) !== $clientid) {
            throw new exception('invalid_client', 400, 'The client metadata document client_id does not match its URL.');
        }

        $method = $document['token_endpoint_auth_method'] ?? 'none';
        if ($method !== 'none') {
            throw new exception('invalid_client', 400, 'Metadata document clients must use token_endpoint_auth_method none.');
        }

        try {
            $redirecturis = $this->validate_redirect_uris($document['redirect_uris'] ?? null, false);
            [$granttypes, $responsetypes] = $this->validate_grant_and_response_types(
                $document + ['grant_types' => ['authorization_code']]
            );
        } catch (exception $exception) {
            throw new exception('invalid_client', 400, $exception->getMessage());
        }

        return [
            'client_name' => self::clean_client_name($document['client_name'] ?? ''),
            'redirect_uris' => $redirecturis,
            'grant_types' => $granttypes,
            'response_types' => $responsetypes,
            'scope' => service::REGISTRATION_DEFAULT_SCOPE,
        ];
    }

    /**
     * Validate and normalize a redirect_uris list.
     *
     * @param mixed $redirecturis Candidate list.
     * @param bool $enforcehosts Whether every host must be on the allowed list.
     * @return array
     */
    private function validate_redirect_uris(mixed $redirecturis, bool $enforcehosts): array {
        if (!is_array($redirecturis) || $redirecturis === []) {
            throw new exception('invalid_redirect_uri', 400, 'At least one redirect URI is required.');
        }
        if (count($redirecturis) > self::MAX_REDIRECT_URIS) {
            throw new exception('invalid_redirect_uri', 400, 'Too many redirect URIs.');
        }

        $normalized = [];
        foreach ($redirecturis as $redirecturi) {
            if (!is_string($redirecturi) || strlen($redirecturi) > self::MAX_URI_LENGTH) {
                throw new exception('invalid_redirect_uri', 400, 'Invalid redirect URI.');
            }
            $uri = service::normalize_redirect_uri($redirecturi);
            if ($enforcehosts && !self::is_redirect_host_allowed((string)parse_url($uri, PHP_URL_HOST))) {
                throw new exception('invalid_redirect_uri', 400, 'Redirect URI host is not allowed by this site.');
            }
            $normalized[] = $uri;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Validate grant_types/response_types client metadata.
     *
     * @param array $metadata Client metadata.
     * @return array [grant types, response types]
     */
    private function validate_grant_and_response_types(array $metadata): array {
        $granttypes = self::normalize_list(
            $metadata['grant_types'] ?? ['authorization_code', 'refresh_token'],
            ['authorization_code', 'refresh_token'],
            'grant_types'
        );
        if (!in_array('authorization_code', $granttypes, true)) {
            throw new exception('invalid_client_metadata', 400, 'authorization_code must be supported.');
        }

        $responsetypes = self::normalize_list($metadata['response_types'] ?? ['code'], ['code'], 'response_types');

        return [$granttypes, $responsetypes];
    }

    /**
     * Normalize and validate a list-valued client metadata field.
     *
     * @param mixed $values Candidate field value.
     * @param array $allowedvalues Supported values.
     * @param string $fieldname Metadata field name.
     * @return array
     */
    private static function normalize_list(mixed $values, array $allowedvalues, string $fieldname): array {
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
     * Clean a client-supplied display name. No default is invented; the consent page falls back to the host.
     *
     * @param mixed $name Raw client_name.
     * @return string
     */
    private static function clean_client_name(mixed $name): string {
        if (!is_string($name)) {
            return '';
        }
        $name = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        return \core_text::substr($name, 0, 100);
    }

    /**
     * Hydrate JSON-backed client metadata into arrays.
     *
     * @param stdClass $record Raw DB record.
     * @return stdClass
     */
    private static function hydrate_client(stdClass $record): stdClass {
        $record->redirecturis = json_decode((string)$record->redirecturis, true) ?: [];
        $record->granttypes = json_decode((string)$record->granttypes, true) ?: [];
        $record->responsetypes = json_decode((string)$record->responsetypes, true) ?: [];
        return $record;
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
}
