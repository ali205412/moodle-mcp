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

namespace webservice_mcp\local\ui;

use context_system;
use moodle_url;
use stdClass;
use webservice_mcp\local\auth\bootstrap_service;
use webservice_mcp\local\auth\credential_manager;
use webservice_mcp\local\files\transfer_exception;
use webservice_mcp\local\mcp\call_context;

/**
 * Session bridge: fetch any page of this site as the connector's user, through a real Moodle session over loopback.
 *
 * Modelled on admin/tool/mobile/autologin.php: a one-time key bound to the loopback IP and valid for 30 seconds is
 * minted here and redeemed by ui/login.php, which completes a normal login. The resulting session cookie lives only
 * in the uibridge_session cache, per credential family, and dies with the family.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session_bridge {
    /** user_private_key script name. */
    public const KEY_SCRIPT = 'webservice_mcp_ui';

    /** Capability needed to browse through the bridge. */
    public const CAPABILITY = 'webservice/mcp:uibridge';

    /** Largest response body accepted. */
    public const MAX_BYTES = 20971520;

    /** Login key lifetime in seconds. */
    private const KEY_TTL = 30;

    /** Redirects followed per fetch. */
    private const MAX_REDIRECTS = 5;

    /** Concurrent bridge fetches site-wide (each one occupies a second PHP worker). */
    private const SLOTS = 2;

    /** Paths (relative to wwwroot) the bridge never fetches, with the reason given to the client. */
    private const DENIED_PATHS = [
        '/login' => 'login and logout pages',
        '/webservice' => 'web service and MCP endpoints',
        '/admin/tool/mobile' => 'mobile app login endpoints',
        '/user/managetoken.php' => 'security key management',
        '/user/personalaccesstokens.php' => 'personal access token management',
        '/r.php/oauth2' => 'OAuth 2 authorization endpoints',
        '/oauth2' => 'OAuth 2 authorization endpoints',
        '/lib/ajax' => 'AJAX endpoints',
        '/pluginfile.php' => 'file downloads (use the file tools instead)',
        '/draftfile.php' => 'draft file downloads (use the file tools instead)',
    ];

    /** @var http_transport|null Network seam; null builds the loopback curl transport. */
    private ?http_transport $transport;

    /** @var int Seconds to wait for a free fetch slot. */
    private int $slotwait;

    /**
     * Constructor.
     *
     * @param http_transport|null $transport Optional transport (tests).
     * @param int $slotwait Seconds to wait for a free slot.
     */
    public function __construct(?http_transport $transport = null, int $slotwait = 20) {
        $this->transport = $transport;
        $this->slotwait = $slotwait;
    }

    /**
     * Fetch a page of this site as the connector's user.
     *
     * A string $fields is a REST API request: it is sent as a JSON body (when not empty) with Accept: application/json,
     * and PUT, PATCH and DELETE are allowed as well.
     *
     * @param call_context $ctx Call context of a connector credential.
     * @param string $method GET or POST (also PUT, PATCH and DELETE for REST API requests).
     * @param string $url Absolute URL on this site, or a path relative to wwwroot.
     * @param array|string $fields Form fields for POST (name => string|array), or a JSON body for a REST API request.
     * @param bool $multipart Send POST fields as multipart/form-data.
     * @return array ['status', 'url', 'contenttype', 'body', 'sesskey', 'filename']
     */
    public function fetch(
        call_context $ctx,
        string $method,
        string $url,
        array|string $fields = [],
        bool $multipart = false
    ): array {
        $method = strtoupper($method);
        $methods = is_string($fields) ? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] : ['GET', 'POST'];
        if (!in_array($method, $methods, true)) {
            throw new transfer_exception(400, 'uibridgemethod', 'Only ' . implode(', ', $methods) . ' are supported.');
        }
        $family = $this->require_access($ctx);
        $url = self::check_url($url);
        $ctx->require_scope($method !== 'GET' || self::query_has_sesskey($url));

        $slot = $this->acquire_slot();
        try {
            return $this->fetch_in_session($ctx, $family, $method, $url, $fields, $multipart);
        } finally {
            $slot->release();
        }
    }

    /**
     * Return the sesskey of the family's bridge session, establishing the session if needed.
     *
     * @param call_context $ctx Call context.
     * @return string
     */
    public function sesskey(call_context $ctx): string {
        $family = $this->require_access($ctx);
        $session = self::load_session($family);
        if ($session && (int)$session['userid'] === (int)$ctx->user->id && !empty($session['sesskey'])) {
            return (string)$session['sesskey'];
        }

        $response = $this->fetch($ctx, 'GET', '/my/');
        if (empty($response['sesskey'])) {
            throw new transfer_exception(502, 'uibridgenosesskey', 'Could not read the session key of the browsing session.');
        }
        return (string)$response['sesskey'];
    }

    /**
     * Check a URL against the bridge policy and return it absolute and without fragment.
     *
     * @param string $url Absolute URL or path relative to wwwroot.
     * @return string
     */
    public static function check_url(string $url): string {
        global $CFG;

        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\\\\]/', $url)) {
            self::deny($url, 'the URL is empty or contains spaces, control characters or backslashes');
        }
        $site = parse_url($CFG->wwwroot);
        $base = rtrim((string)($site['path'] ?? ''), '/');
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $url = $site['scheme'] . '://' . $site['host'] . (isset($site['port']) ? ':' . $site['port'] : '')
                . (str_starts_with($url, $base . '/') ? '' : $base) . $url;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            self::deny($url, 'not an absolute URL on this site');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            self::deny($url, 'URLs with user information are not allowed');
        }
        $scheme = strtolower($parts['scheme']);
        $defaultport = static fn(string $s): int => $s === 'https' ? 443 : 80;
        if (
            $scheme !== strtolower($site['scheme']) || strtolower($parts['host']) !== strtolower($site['host'])
                || (int)($parts['port'] ?? $defaultport($scheme)) !== (int)($site['port'] ?? $defaultport($site['scheme']))
        ) {
            self::deny($url, 'only pages of this Moodle site (' . $CFG->wwwroot . ') can be opened');
        }

        $path = (string)($parts['path'] ?? '/');
        if ($base !== '' && $path !== $base && !str_starts_with($path, $base . '/')) {
            self::deny($url, 'the path is outside this Moodle site');
        }
        if (preg_match('#(^|/)\.\.?(/|$)|//|%2e|%2f|%5c|%00#i', $path)) {
            self::deny($url, 'encoded or relative path segments are not allowed');
        }
        $relative = '/' . ltrim(substr($path, strlen($base)), '/');
        foreach (self::DENIED_PATHS as $prefix => $reason) {
            if ($relative === $prefix || str_starts_with($relative, $prefix . '/')) {
                self::deny($url, $reason . ' cannot be opened through the browsing bridge');
            }
        }

        // Fragments are never sent.
        return $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path
            . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
    }

    /**
     * Validate a login key on ui/login.php and return the user and credential family it was minted for.
     *
     * The key is single-use, bound to the loopback IP and valid for 30 seconds; the family comes from the bridge's
     * cache, never from the request.
     *
     * @param int $userid User id the key must belong to.
     * @param string $keyvalue Key value.
     * @return stdClass {user, family}
     */
    public static function redeem_login_key(int $userid, string $keyvalue): stdClass {
        global $DB;

        $key = validate_user_key($keyvalue, self::KEY_SCRIPT, null);
        $DB->delete_records('user_private_key', ['id' => $key->id]);
        if ((int)$key->userid !== $userid) {
            throw new \moodle_exception('invalidkey');
        }

        $cache = \cache::make('webservice_mcp', 'uibridge_session');
        $family = (string)$cache->get('login_' . sha1($keyvalue));
        $cache->delete('login_' . sha1($keyvalue));
        if ($family === '') {
            throw new \moodle_exception('invalidkey');
        }

        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        \core_user::require_active_user($user, true, true);
        self::require_user_allowed($user);

        return (object)['user' => $user, 'family' => $family];
    }

    /**
     * End the bridge session of a credential family once the family has no active credential left.
     *
     * Called when a credential is revoked; refresh-token rotation keeps the family alive and the session too.
     *
     * @param stdClass $credential Revoked credential record.
     * @return void
     */
    public static function end_session_for_credential(stdClass $credential): void {
        $family = credential_manager::family_key($credential);
        if ((new credential_manager())->family_active($family)) {
            return;
        }
        $session = self::load_session($family);
        if ($session) {
            self::destroy_session((string)$session['sid']);
            \cache::make('webservice_mcp', 'uibridge_session')->delete(sha1($family));
        }
    }

    /**
     * Require the bridge to be usable by this caller and return the credential family.
     *
     * @param call_context $ctx Call context.
     * @return string Family key.
     */
    private function require_access(call_context $ctx): string {
        if (!get_config('webservice_mcp', 'uibridge')) {
            throw new transfer_exception(403, 'uibridgedisabled', 'Browsing Moodle pages is disabled on this site.');
        }
        if (!$ctx->connector || $ctx->user === null || empty($ctx->credentialid) || empty($ctx->serviceid)) {
            throw new transfer_exception(403, 'uibridgenotconnector', 'Browsing Moodle pages needs an MCP connector '
                . 'credential (OAuth, admin key or launch credential), not a raw web service token.');
        }
        if ($ctx->restrictedcontext !== null && $ctx->restrictedcontext->contextlevel !== CONTEXT_SYSTEM) {
            throw new transfer_exception(403, 'uibridgerestrictedcontext', 'This credential is restricted to one context; '
                . 'pages cannot be limited to a context, so browsing needs a credential for the whole site.');
        }
        self::require_user_allowed($ctx->user);
        (new credential_manager())->assert_service_access((int)$ctx->user->id, (int)$ctx->serviceid, $ctx->credentialid, false);

        return (string)$ctx->credentialid;
    }

    /**
     * Require the site policy and capabilities that allow a user to browse through the bridge.
     *
     * @param stdClass $user User.
     * @return void
     */
    private static function require_user_allowed(stdClass $user): void {
        if (!get_config('webservice_mcp', 'uibridge')) {
            throw new transfer_exception(403, 'uibridgedisabled', 'Browsing Moodle pages is disabled on this site.');
        }
        bootstrap_service::require_user_eligible($user, context_system::instance());
        if (!has_capability(self::CAPABILITY, context_system::instance(), $user)) {
            throw new \required_capability_exception(context_system::instance(), self::CAPABILITY, 'nopermissions', '');
        }
    }

    /**
     * Run the request in the family's session, logging in or re-logging in once when needed.
     *
     * @param call_context $ctx Call context.
     * @param string $family Family key.
     * @param string $method GET or POST.
     * @param string $url Checked URL.
     * @param array|string $fields POST fields or REST JSON body.
     * @param bool $multipart Multipart POST.
     * @return array
     */
    private function fetch_in_session(
        call_context $ctx,
        string $family,
        string $method,
        string $url,
        array|string $fields,
        bool $multipart
    ): array {
        $userid = (int)$ctx->user->id;
        $session = self::load_session($family);
        // REST routes answer an expired session as the guest instead of redirecting to the login page, so check first.
        $expired = $session && is_string($fields) && !\core\session\manager::session_exists((string)$session['sid']);
        if (!$session || (int)$session['userid'] !== $userid || $expired) {
            $session = $this->login($userid, $family);
        }

        $response = $this->follow($session, $method, $url, $fields, $multipart);
        if ($response === null) {
            // The session died (redirected to the login page): log in again once.
            self::destroy_session((string)$session['sid']);
            $session = $this->login($userid, $family);
            $response = $this->follow($session, $method, $url, $fields, $multipart);
            if ($response === null) {
                throw new transfer_exception(502, 'uibridgesessionlost', 'The browsing session was rejected by Moodle '
                    . 'twice; try again later.');
            }
        }

        $session['sesskey'] = $response['sesskey'] ?? $session['sesskey'] ?? null;
        self::save_session($family, $session);
        return $response;
    }

    /**
     * Send the request and follow same-origin redirects, re-checking each against the URL policy.
     *
     * @param array $session Session state (cookies are updated in place).
     * @param string $method HTTP method.
     * @param string $url Checked URL.
     * @param array|string $fields POST fields or REST JSON body.
     * @param bool $multipart Multipart POST.
     * @return array|null The response, or null when Moodle sent us to the login page.
     */
    private function follow(array &$session, string $method, string $url, array|string $fields, bool $multipart): ?array {
        $body = null;
        $headers = [];
        if (is_string($fields)) {
            $headers[] = 'Accept: application/json';
            if ($fields !== '') {
                $body = $fields;
                $headers[] = 'Content-Type: application/json';
            }
        } else if ($method === 'POST') {
            $body = $multipart ? self::multipart_fields($fields) : self::urlencode_fields($fields);
            if (!$multipart) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $requestheaders = array_merge($headers, ['Cookie: ' . self::cookie_header($session['cookies'])]);
            $raw = $this->transport()->request($method, $url, $requestheaders, $body, self::MAX_BYTES);
            $session['cookies'] = self::merge_cookies($session['cookies'], $raw['headers']['set-cookie'] ?? []);

            $location = $raw['headers']['location'][0] ?? null;
            if ($raw['status'] < 300 || $raw['status'] >= 400 || $location === null) {
                return self::build_response($raw, $url);
            }

            $next = self::resolve_location($url, $location);
            if (self::is_login_page($next)) {
                return null;
            }
            $url = self::check_url($next);
            if (!in_array($raw['status'], [307, 308], true)) {
                // Browsers turn a redirected POST into a GET.
                $method = 'GET';
                $body = null;
                $headers = [];
            }
        }

        throw new transfer_exception(508, 'uibridgeredirects', 'Too many redirects (more than ' . self::MAX_REDIRECTS . ').');
    }

    /**
     * Log in through ui/login.php with a fresh one-time key and store the new session.
     *
     * @param int $userid User id.
     * @param string $family Family key.
     * @return array Session state.
     */
    private function login(int $userid, string $family): array {
        global $CFG;

        $lock = \core\lock\lock_config::get_lock_factory('webservice_mcp_uibridge')
            ->get_lock('login_' . sha1($family), 20);
        if (!$lock) {
            throw new transfer_exception(503, 'uibridgebusy', 'The browsing session is being set up by another request; '
                . 'retry in a few seconds.');
        }
        try {
            $ip = self::loopback_ip();
            $key = create_user_key(self::KEY_SCRIPT, $userid, null, $ip, time() + self::KEY_TTL);
            \cache::make('webservice_mcp', 'uibridge_session')->set('login_' . sha1($key), $family);

            $url = (new moodle_url('/webservice/mcp/ui/login.php', ['userid' => $userid, 'key' => $key]))->out(false);
            $raw = $this->transport()->request('GET', $url, [], null, 65536);
            $cookies = self::merge_cookies([], $raw['headers']['set-cookie'] ?? []);
            $cookiename = 'MoodleSession' . ($CFG->sessioncookie ?? '');
            if ($raw['status'] !== 204 || empty($cookies[$cookiename])) {
                throw new transfer_exception(502, 'uibridgeloginfailed', 'Could not open a browsing session (login returned '
                    . 'HTTP ' . $raw['status'] . ').');
            }

            $session = ['userid' => $userid, 'sid' => $cookies[$cookiename], 'cookies' => $cookies, 'sesskey' => null];
            self::save_session($family, $session);
            return $session;
        } finally {
            $lock->release();
        }
    }

    /**
     * Wait for one of the site-wide bridge slots.
     *
     * @return \core\lock\lock
     */
    private function acquire_slot(): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory('webservice_mcp_uibridge');
        $deadline = microtime(true) + $this->slotwait;
        do {
            for ($slot = 1; $slot <= self::SLOTS; $slot++) {
                $lock = $factory->get_lock('slot' . $slot, 0);
                if ($lock) {
                    return $lock;
                }
            }
            usleep(250000);
        } while (microtime(true) < $deadline);

        throw new transfer_exception(503, 'uibridgebusy', 'Moodle pages are already being opened by ' . self::SLOTS
            . ' other requests; retry in a few seconds.');
    }

    /**
     * Build the fetch() result from a raw response.
     *
     * @param array $raw Raw transport response.
     * @param string $url Final URL.
     * @return array
     */
    private static function build_response(array $raw, string $url): array {
        $contenttype = (string)($raw['headers']['content-type'][0] ?? '');
        $sesskey = null;
        if (stripos($contenttype, 'html') !== false && preg_match('/"sesskey":"([A-Za-z0-9]+)"/', $raw['body'], $m)) {
            $sesskey = $m[1];
        }

        return [
            'status' => (int)$raw['status'],
            'url' => $url,
            'contenttype' => $contenttype,
            'body' => (string)$raw['body'],
            'sesskey' => $sesskey,
            'filename' => self::filename((string)($raw['headers']['content-disposition'][0] ?? '')),
        ];
    }

    /**
     * Extract the file name from a Content-Disposition header.
     *
     * @param string $disposition Header value.
     * @return string|null
     */
    private static function filename(string $disposition): ?string {
        if (preg_match("/filename\\*\\s*=\\s*(?:UTF-8|utf-8)''([^;]+)/", $disposition, $m)) {
            return clean_param(rawurldecode(trim($m[1])), PARAM_FILE) ?: null;
        }
        if (preg_match('/filename\s*=\s*"?([^";]+)"?/i', $disposition, $m)) {
            return clean_param(trim($m[1]), PARAM_FILE) ?: null;
        }
        return null;
    }

    /**
     * Whether the query string carries a sesskey (a state-changing link).
     *
     * @param string $url URL.
     * @return bool
     */
    public static function query_has_sesskey(string $url): bool {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return array_key_exists('sesskey', $query);
    }

    /**
     * Whether a URL is the site's login page.
     *
     * @param string $url Absolute URL.
     * @return bool
     */
    private static function is_login_page(string $url): bool {
        global $CFG;

        $base = rtrim((string)parse_url($CFG->wwwroot, PHP_URL_PATH), '/');
        return (string)parse_url($url, PHP_URL_PATH) === $base . '/login/index.php';
    }

    /**
     * Resolve a Location header against the current URL.
     *
     * @param string $current Current absolute URL.
     * @param string $location Location header.
     * @return string
     */
    private static function resolve_location(string $current, string $location): string {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($current);
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', (string)($parts['path'] ?? '/'));
        return $origin . $dir . $location;
    }

    /**
     * Flatten POST fields into [name, value] pairs (arrays become name[key]).
     *
     * @param array $fields Fields.
     * @param string $prefix Name prefix for nested arrays.
     * @return array
     */
    private static function field_pairs(array $fields, string $prefix = ''): array {
        $pairs = [];
        foreach ($fields as $name => $value) {
            $key = $prefix === '' ? (string)$name : $prefix . '[' . $name . ']';
            if (is_array($value)) {
                if (str_ends_with($key, '[]')) {
                    $key = substr($key, 0, -2);
                }
                $pairs = array_merge($pairs, self::field_pairs($value, $key));
            } else {
                $pairs[] = [$key, (string)$value];
            }
        }
        return $pairs;
    }

    /**
     * Url-encode POST fields.
     *
     * @param array $fields Fields.
     * @return string
     */
    private static function urlencode_fields(array $fields): string {
        return implode('&', array_map(
            static fn(array $pair): string => rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]),
            self::field_pairs($fields)
        ));
    }

    /**
     * Multipart POST fields for curl (one entry per flattened name).
     *
     * @param array $fields Fields.
     * @return array
     */
    private static function multipart_fields(array $fields): array {
        $multipart = [];
        foreach (self::field_pairs($fields) as [$name, $value]) {
            $multipart[$name] = $value;
        }
        return $multipart;
    }

    /**
     * Merge Set-Cookie headers into a cookie jar (name => value); deleted cookies are dropped.
     *
     * @param array $cookies Current cookies.
     * @param array $setcookies Set-Cookie header values.
     * @return array
     */
    private static function merge_cookies(array $cookies, array $setcookies): array {
        foreach ($setcookies as $line) {
            $pair = explode(';', (string)$line, 2)[0];
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode('=', $pair, 2));
            $expired = preg_match('/max-age=0\b|expires=thu, 01[ -]jan[ -]1970/i', (string)$line);
            if ($value === '' || $value === 'deleted' || $expired) {
                unset($cookies[$name]);
            } else {
                $cookies[$name] = $value;
            }
        }
        return $cookies;
    }

    /**
     * Build the Cookie request header.
     *
     * @param array $cookies Cookie jar.
     * @return string
     */
    private static function cookie_header(array $cookies): string {
        return implode('; ', array_map(
            static fn(string $name, string $value): string => $name . '=' . $value,
            array_keys($cookies),
            array_values($cookies)
        ));
    }

    /**
     * Load a family's cached bridge session.
     *
     * @param string $family Family key.
     * @return array|null
     */
    private static function load_session(string $family): ?array {
        $session = json_decode((string)\cache::make('webservice_mcp', 'uibridge_session')->get(sha1($family)), true);
        return is_array($session) && !empty($session['sid']) ? $session : null;
    }

    /**
     * Store a family's bridge session.
     *
     * @param string $family Family key.
     * @param array $session Session state.
     * @return void
     */
    private static function save_session(string $family, array $session): void {
        \cache::make('webservice_mcp', 'uibridge_session')->set(sha1($family), json_encode($session));
    }

    /**
     * Destroy a Moodle session by id (manager::destroy() from 4.5, kill_session() before).
     *
     * @param string $sid Session id.
     * @return void
     */
    private static function destroy_session(string $sid): void {
        if (method_exists(\core\session\manager::class, 'destroy')) {
            \core\session\manager::destroy($sid);
        } else {
            \core\session\manager::kill_session($sid);
        }
    }

    /**
     * The configured loopback IP (also the IP the login key is bound to).
     *
     * @return string
     */
    public static function loopback_ip(): string {
        $ip = trim((string)get_config('webservice_mcp', 'uibridgeloopbackip'));
        return filter_var(trim($ip, '[]'), FILTER_VALIDATE_IP) ? trim($ip, '[]') : '127.0.0.1';
    }

    /**
     * The transport (loopback curl unless injected).
     *
     * @return http_transport
     */
    private function transport(): http_transport {
        return $this->transport ??= new curl_transport(self::loopback_ip());
    }

    /**
     * Refuse a URL, naming the rule.
     *
     * @param string $url URL.
     * @param string $rule Rule description.
     * @return never
     */
    private static function deny(string $url, string $rule): void {
        throw new transfer_exception(403, 'uibridgeurldenied', 'Cannot open ' . \core_text::substr($url, 0, 200)
            . ': ' . $rule . '.');
    }
}
