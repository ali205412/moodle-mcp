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

namespace webservice_mcp;

use advanced_testcase;
use context_system;
use stdClass;
use webservice_mcp\local\auth\credential_manager;
use webservice_mcp\local\auth\transport_identity;
use webservice_mcp\local\oauth\client_registry;
use webservice_mcp\local\oauth\exception as oauth_exception;
use webservice_mcp\local\oauth\service as oauth_service;

/**
 * Tests for the plugin OAuth server used by Claude-compatible MCP clients.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\oauth\service
 * @covers      \webservice_mcp\local\oauth\client_registry
 * @covers      \webservice_mcp\local\oauth\exception
 */
final class oauth_service_test extends advanced_testcase {
    /** Redirect URI registered by claude.ai. */
    private const CLAUDE_REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    /** A valid RFC 7636 code verifier. */
    private const VERIFIER = 'verifier-for-claude-connector-0123456789abcdefghij';

    /**
     * Create an S256 PKCE challenge from a verifier.
     *
     * @param string $verifier Code verifier.
     * @return string
     */
    private function pkce_challenge(string $verifier): string {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Create and log in a user holding webservice/mcp:use.
     *
     * @return stdClass
     */
    private function create_mcp_user(): stdClass {
        set_config('oauthenabled', 1, 'webservice_mcp');

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('webservice/mcp:use', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);

        return $user;
    }

    /**
     * Register a public DCR client.
     *
     * @param oauth_service $oauth OAuth service.
     * @param array $overrides Metadata overrides.
     * @return array
     */
    private function register(oauth_service $oauth, array $overrides = []): array {
        return $oauth->register_dynamic_client($overrides + [
            'client_name' => 'Claude',
            'redirect_uris' => [self::CLAUDE_REDIRECT],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ]);
    }

    /**
     * Run authorize validation for the current user and return a fresh code.
     *
     * @param oauth_service $oauth OAuth service.
     * @param string $clientid Client id.
     * @param string $redirecturi Redirect URI.
     * @return string
     */
    private function authorize(oauth_service $oauth, string $clientid, string $redirecturi = self::CLAUDE_REDIRECT): string {
        global $USER;

        $validated = $oauth->authorization()->validate_authorization_request([
            'response_type' => 'code',
            'client_id' => $clientid,
            'redirect_uri' => $redirecturi,
            'scope' => 'mcp:read mcp:write',
            'state' => 'opaque-state',
            'resource' => $oauth->canonical_resource_uri(),
            'code_challenge' => $this->pkce_challenge(self::VERIFIER),
            'code_challenge_method' => 'S256',
        ]);
        $context = $oauth->authorization()->require_authorization_context();

        return $oauth->authorization()->create_authorization_code(
            (int)$USER->id,
            $validated['client'],
            $context,
            $validated['redirecturi'],
            $validated['scope'],
            $validated['resourceuri'],
            $validated['codechallenge'],
            $validated['codechallengemethod']
        );
    }

    /**
     * Exchange an authorization code.
     *
     * @param oauth_service $oauth OAuth service.
     * @param string $clientid Client id.
     * @param string $code Authorization code.
     * @param string $verifier Code verifier.
     * @param string $redirecturi Redirect URI.
     * @return array
     */
    private function exchange(
        oauth_service $oauth,
        string $clientid,
        string $code,
        string $verifier = self::VERIFIER,
        string $redirecturi = self::CLAUDE_REDIRECT
    ): array {
        return $oauth->exchange_token_request([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirecturi,
            'code_verifier' => $verifier,
            'resource' => $oauth->canonical_resource_uri(),
        ], $clientid, null);
    }

    /**
     * Refresh tokens.
     *
     * @param oauth_service $oauth OAuth service.
     * @param string $clientid Client id.
     * @param string $refreshtoken Refresh token.
     * @return array
     */
    private function refresh(oauth_service $oauth, string $clientid, string $refreshtoken): array {
        return $oauth->exchange_token_request([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshtoken,
            'resource' => $oauth->canonical_resource_uri(),
        ], $clientid, null);
    }

    /**
     * Assert a callable fails with the given OAuth error.
     *
     * @param string $error Expected OAuth error code.
     * @param callable $callable Code under test.
     * @return void
     */
    private function assert_oauth_error(string $error, callable $callable): void {
        try {
            $callable();
            $this->fail('Expected OAuth error ' . $error);
        } catch (oauth_exception $exception) {
            $this->assertSame($error, $exception->oauth_error(), $exception->getMessage());
        }
    }

    /**
     * Test DCR, authorize, code exchange, refresh rotation, and hashed storage.
     */
    public function test_oauth_service_supports_dynamic_registration_code_exchange_and_refresh(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->create_mcp_user();

        $oauth = new oauth_service();
        $registration = $this->register($oauth, ['scope' => 'mcp:read mcp:write offline_access']);
        $this->assertNotEmpty($registration['client_id']);
        $this->assertArrayNotHasKey('client_secret', $registration);

        $initialtokens = $this->exchange($oauth, $registration['client_id'], $this->authorize($oauth, $registration['client_id']));

        $this->assertSame('Bearer', $initialtokens['token_type']);
        $this->assertSame($oauth->canonical_resource_uri(), $initialtokens['resource']);
        $this->assertNotEmpty($initialtokens['refresh_token']);
        // The offline_access scope is granted to refresh-capable clients even when not requested.
        $this->assertStringContainsString('offline_access', $initialtokens['scope']);

        // Only hashes are stored.
        $this->assertFalse($DB->record_exists('webservice_mcp_credential', ['token' => $initialtokens['access_token']]));
        $accesstoken = $DB->get_record(
            'webservice_mcp_credential',
            ['token' => credential_manager::hash_token($initialtokens['access_token'])],
            '*',
            MUST_EXIST
        );
        $refreshtoken = $DB->get_record(
            'webservice_mcp_credential',
            ['token' => credential_manager::hash_token($initialtokens['refresh_token'])],
            '*',
            MUST_EXIST
        );

        $this->assertSame(credential_manager::TOKEN_TYPE_DURABLE, (int)$accesstoken->tokentype);
        $this->assertSame(credential_manager::TOKEN_TYPE_REFRESH, (int)$refreshtoken->tokentype);
        $this->assertSame($registration['client_id'], (string)$accesstoken->oauthclientid);
        $this->assertNotEmpty($accesstoken->familyid);
        $this->assertSame($accesstoken->familyid, $refreshtoken->familyid);

        $refreshedtokens = $this->refresh($oauth, $registration['client_id'], $initialtokens['refresh_token']);

        $this->assertNotSame($initialtokens['access_token'], $refreshedtokens['access_token']);
        $this->assertNotSame($initialtokens['refresh_token'], $refreshedtokens['refresh_token']);
        $this->assertNull((new credential_manager())->resolve_credential($initialtokens['refresh_token']));
        $this->assertNotNull((new transport_identity())->resolve($refreshedtokens['access_token']));
    }

    /**
     * Test metadata documents advertise the expected endpoints and capabilities.
     */
    public function test_oauth_service_metadata_exposes_registration_and_token_endpoints(): void {
        $this->resetAfterTest(true);

        $oauth = new oauth_service();
        $documents = new \webservice_mcp\local\oauth\metadata($oauth);
        $resource = $documents->build_protected_resource_metadata();
        $metadata = $documents->build_authorization_server_metadata();
        $openid = $documents->build_openid_configuration();

        $this->assertSame($oauth->canonical_resource_uri(), $resource['resource']);
        $this->assertSame([$oauth->issuer_url()], $resource['authorization_servers']);
        $this->assertNotContains('offline_access', $resource['scopes_supported']);
        $this->assertSame($oauth->issuer_url(), $metadata['issuer']);
        $this->assertSame($oauth->registration_endpoint_url(), $metadata['registration_endpoint']);
        $this->assertSame($oauth->revocation_endpoint_url(), $metadata['revocation_endpoint']);
        $this->assertSame(['S256'], $metadata['code_challenge_methods_supported']);
        $this->assertContains('offline_access', $metadata['scopes_supported']);
        $this->assertTrue($metadata['client_id_metadata_document_supported']);
        $this->assertTrue($metadata['authorization_response_iss_parameter_supported']);
        $this->assertSame($oauth->issuer_url(), $openid['issuer']);
        $this->assertNotEmpty($openid['jwks_uri']);
        $this->assertNotEmpty($openid['subject_types_supported']);
        $this->assertNotEmpty($openid['id_token_signing_alg_values_supported']);
    }

    /**
     * Test authorization responses carry the RFC 9207 iss parameter.
     */
    public function test_redirect_includes_issuer(): void {
        $this->resetAfterTest(true);

        $oauth = new oauth_service();
        $url = $oauth->build_redirect_uri(self::CLAUDE_REDIRECT, ['code' => 'abc']);

        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame($oauth->issuer_url(), $query['iss']);
    }

    /**
     * Test replaying an authorization code fails and revokes the tokens it produced.
     */
    public function test_code_reuse_revokes_issued_family(): void {
        $this->resetAfterTest(true);
        $this->create_mcp_user();

        $oauth = new oauth_service();
        $registration = $this->register($oauth);
        $code = $this->authorize($oauth, $registration['client_id']);
        $tokens = $this->exchange($oauth, $registration['client_id'], $code);

        $this->assert_oauth_error('invalid_grant', fn() => $this->exchange($oauth, $registration['client_id'], $code));

        $manager = new credential_manager();
        $this->assertNull($manager->resolve_credential($tokens['access_token']));
        $this->assertNull($manager->resolve_credential($tokens['refresh_token']));
    }

    /**
     * Test presenting an already-rotated refresh token revokes the whole family.
     */
    public function test_refresh_replay_revokes_family(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->create_mcp_user();

        $oauth = new oauth_service();
        $registration = $this->register($oauth);
        $first = $this->exchange($oauth, $registration['client_id'], $this->authorize($oauth, $registration['client_id']));
        $second = $this->refresh($oauth, $registration['client_id'], $first['refresh_token']);

        // Reuse after the grace window is theft: the whole family goes.
        $DB->set_field(
            'webservice_mcp_credential',
            'rotatedat',
            time() - 31,
            ['token' => credential_manager::hash_token($first['refresh_token'])]
        );
        $clientid = $registration['client_id'];
        $this->assert_oauth_error('invalid_grant', fn() => $this->refresh($oauth, $clientid, $first['refresh_token']));

        $manager = new credential_manager();
        $this->assertNull($manager->resolve_credential($second['access_token']));
        $this->assertNull($manager->resolve_credential($second['refresh_token']));
    }

    /**
     * Test a just-rotated refresh token reused by the same client within the grace window gets a new pair.
     */
    public function test_refresh_reuse_within_grace_window(): void {
        $this->resetAfterTest(true);
        $this->create_mcp_user();

        $oauth = new oauth_service();
        $registration = $this->register($oauth);
        $clientid = $registration['client_id'];
        $first = $this->exchange($oauth, $clientid, $this->authorize($oauth, $clientid));
        $second = $this->refresh($oauth, $clientid, $first['refresh_token']);
        $concurrent = $this->refresh($oauth, $clientid, $first['refresh_token']);

        $manager = new credential_manager();
        $this->assertNotSame($second['refresh_token'], $concurrent['refresh_token']);
        $this->assertNotNull($manager->resolve_credential($second['access_token']));
        $this->assertNotNull($manager->resolve_credential($concurrent['access_token']));
        $this->assertSame(
            $manager->find_credential($second['access_token'])->familyid,
            $manager->find_credential($concurrent['access_token'])->familyid
        );

        // Another client presenting the token gets nothing and does not disturb the family.
        $other = $this->register($oauth);
        $this->assert_oauth_error('invalid_grant', fn() => $this->refresh($oauth, $other['client_id'], $first['refresh_token']));
        $this->assertNotNull($manager->resolve_credential($second['access_token']));

        // A family the user disconnected is not revived by the grace window.
        $manager->revoke_family((string)$manager->find_credential($second['access_token'])->familyid, 0);
        $this->assert_oauth_error('invalid_grant', fn() => $this->refresh($oauth, $clientid, $first['refresh_token']));

        // With the grace window disabled any reuse revokes the family.
        set_config('refreshgraceseconds', 0, 'webservice_mcp');
        $fresh = $this->exchange($oauth, $clientid, $this->authorize($oauth, $clientid));
        $next = $this->refresh($oauth, $clientid, $fresh['refresh_token']);
        $this->assert_oauth_error('invalid_grant', fn() => $this->refresh($oauth, $clientid, $fresh['refresh_token']));
        $this->assertNull($manager->resolve_credential($next['access_token']));
    }

    /**
     * Test a wrong code verifier fails and burns the code for later correct attempts.
     */
    public function test_wrong_verifier_burns_code(): void {
        $this->resetAfterTest(true);
        $this->create_mcp_user();

        $oauth = new oauth_service();
        $registration = $this->register($oauth);
        $code = $this->authorize($oauth, $registration['client_id']);

        $this->assert_oauth_error(
            'invalid_grant',
            fn() => $this->exchange($oauth, $registration['client_id'], $code, str_repeat('w', 43))
        );
        $this->assert_oauth_error('invalid_grant', fn() => $this->exchange($oauth, $registration['client_id'], $code));
    }

    /**
     * Test plain or missing PKCE methods and malformed challenges/verifiers are rejected.
     */
    public function test_plain_or_malformed_pkce_rejected(): void {
        $this->resetAfterTest(true);
        $this->create_mcp_user();

        $oauth = new oauth_service();
        $registration = $this->register($oauth);
        $params = [
            'response_type' => 'code',
            'client_id' => $registration['client_id'],
            'redirect_uri' => self::CLAUDE_REDIRECT,
            'code_challenge' => $this->pkce_challenge(self::VERIFIER),
        ];

        $this->assert_oauth_error('invalid_request', fn() => $oauth->authorization()->validate_authorization_request($params));
        $this->assert_oauth_error(
            'invalid_request',
            fn() => $oauth->authorization()->validate_authorization_request($params + ['code_challenge_method' => 'plain'])
        );
        $this->assert_oauth_error('invalid_request', fn() => $oauth->authorization()->validate_authorization_request(
            ['code_challenge' => 'short', 'code_challenge_method' => 'S256'] + $params
        ));

        $code = $this->authorize($oauth, $registration['client_id']);
        $clientid = $registration['client_id'];
        $this->assert_oauth_error('invalid_request', fn() => $this->exchange($oauth, $clientid, $code, 'tooshort'));
    }

    /**
     * Test redirect hosts outside the allowed list are refused at registration and authorize.
     */
    public function test_redirect_host_not_allowed(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->create_mcp_user();

        $oauth = new oauth_service();
        $this->assert_oauth_error(
            'invalid_redirect_uri',
            fn() => $this->register($oauth, ['redirect_uris' => ['https://evil.example/callback']])
        );
        $this->assert_oauth_error('invalid_redirect_uri', fn() => $this->register($oauth, [
            'redirect_uris' => array_map(static fn(int $i): string => 'https://claude.ai/cb' . $i, range(1, 11)),
        ]));

        // A client registered before the admin narrowed the list can no longer use the removed host.
        $registration = $this->register($oauth);
        set_config('allowedredirecthosts', "localhost\n127.0.0.1", 'webservice_mcp');
        $this->assert_oauth_error('invalid_request', fn() => $this->authorize($oauth, $registration['client_id']));
        $this->assertNull($oauth->authorization()->authorization_error_redirect(
            ['client_id' => $registration['client_id'], 'redirect_uri' => self::CLAUDE_REDIRECT],
            new oauth_exception('invalid_request', 400, 'x')
        ));
        $this->assertTrue($DB->record_exists('webservice_mcp_oauth_client', ['clientid' => $registration['client_id']]));
    }

    /**
     * Test loopback redirect URIs match regardless of port.
     */
    public function test_loopback_redirect_matches_any_port(): void {
        $this->resetAfterTest(true);
        $this->create_mcp_user();

        $oauth = new oauth_service();
        $registration = $this->register($oauth, ['redirect_uris' => ['http://localhost:3118/callback']]);
        $code = $this->authorize($oauth, $registration['client_id'], 'http://localhost:51234/callback');
        $tokens = $this->exchange($oauth, $registration['client_id'], $code, self::VERIFIER, 'http://localhost:51234/callback');

        $this->assertNotEmpty($tokens['access_token']);
        $this->assert_oauth_error(
            'invalid_request',
            fn() => $this->authorize($oauth, $registration['client_id'], 'http://localhost:51234/other')
        );
        $this->assertFalse(client_registry::redirect_uri_matches('https://claude.ai:8443/cb', ['https://claude.ai/cb']));
    }

    /**
     * Test registration is rate limited per IP address.
     */
    public function test_registration_rate_limited(): void {
        $this->resetAfterTest(true);

        $oauth = new oauth_service();
        for ($i = 0; $i < 20; $i++) {
            $oauth->enforce_registration_rate_limit('203.0.113.7');
        }
        $this->assert_oauth_error('invalid_request', fn() => $oauth->enforce_registration_rate_limit('203.0.113.7'));
        $oauth->enforce_registration_rate_limit('203.0.113.8');
    }

    /**
     * Test refresh re-checks the user's capability and revokes the family when it is gone.
     */
    public function test_refresh_rechecks_capability(): void {
        global $DB;

        $this->resetAfterTest(true);
        $user = $this->create_mcp_user();

        $oauth = new oauth_service();
        $registration = $this->register($oauth);
        $tokens = $this->exchange($oauth, $registration['client_id'], $this->authorize($oauth, $registration['client_id']));

        $DB->delete_records('role_assignments', ['userid' => $user->id]);
        accesslib_clear_all_caches_for_unit_testing();

        $clientid = $registration['client_id'];
        $this->assert_oauth_error('invalid_grant', fn() => $this->refresh($oauth, $clientid, $tokens['refresh_token']));
        $this->assertNull((new credential_manager())->resolve_credential($tokens['access_token']));
    }

    /**
     * Test refresh fails once the absolute family lifetime has passed.
     */
    public function test_family_lifetime_enforced(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->create_mcp_user();

        $oauth = new oauth_service();
        $registration = $this->register($oauth);
        $tokens = $this->exchange($oauth, $registration['client_id'], $this->authorize($oauth, $registration['client_id']));

        $DB->set_field('webservice_mcp_credential', 'familycreated', time() - 91 * DAYSECS, []);

        $clientid = $registration['client_id'];
        $this->assert_oauth_error('invalid_grant', fn() => $this->refresh($oauth, $clientid, $tokens['refresh_token']));
    }

    /**
     * Test RFC 7009 revocation of a refresh token revokes its family, and other clients cannot revoke it.
     */
    public function test_revocation_endpoint_revokes_family(): void {
        $this->resetAfterTest(true);
        $this->create_mcp_user();

        $oauth = new oauth_service();
        $registration = $this->register($oauth);
        $other = $this->register($oauth);
        $tokens = $this->exchange($oauth, $registration['client_id'], $this->authorize($oauth, $registration['client_id']));

        $oauth->revoke_token_request(['token' => $tokens['refresh_token']], $other['client_id'], null);
        $this->assertNotNull((new credential_manager())->resolve_credential($tokens['access_token']));

        $oauth->revoke_token_request(['token' => $tokens['refresh_token']], $registration['client_id'], null);
        $this->assertNull((new credential_manager())->resolve_credential($tokens['access_token']));
        $oauth->revoke_token_request(['token' => 'unknown'], $registration['client_id'], null);
    }

    /**
     * Test transport identity rejects tokens of revoked clients and when OAuth is disabled.
     */
    public function test_transport_identity_rejects_revoked_client_and_disabled_oauth(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->create_mcp_user();

        $oauth = new oauth_service();
        $registration = $this->register($oauth);
        $tokens = $this->exchange($oauth, $registration['client_id'], $this->authorize($oauth, $registration['client_id']));
        $resolver = new transport_identity();
        $this->assertNotNull($resolver->resolve($tokens['access_token']));

        set_config('oauthenabled', 0, 'webservice_mcp');
        $this->assertNull($resolver->resolve($tokens['access_token']));
        set_config('oauthenabled', 1, 'webservice_mcp');

        $DB->set_field('webservice_mcp_oauth_client', 'revoked', 1, ['clientid' => $registration['client_id']]);
        $this->assertNull($resolver->resolve($tokens['access_token']));
    }

    /**
     * Test HTTP Basic client credentials are form-urldecoded.
     */
    public function test_basic_client_credentials_are_urldecoded(): void {
        $this->resetAfterTest(true);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode(urlencode('client:id') . ':' . urlencode('s3cr+t/='));
        try {
            [$clientid, $secret] = (new oauth_service())->read_client_credentials_from_request();
        } finally {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        }

        $this->assertSame('client:id', $clientid);
        $this->assertSame('s3cr+t/=', $secret);
    }

    /**
     * Test a Client ID Metadata Document client can authorize and redeem a code.
     */
    public function test_client_id_metadata_document_flow(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->create_mcp_user();

        $clientid = 'https://claude.ai/oauth/claude-code-client-metadata';
        $fetches = 0;
        $registry = new client_registry(function (string $url) use ($clientid, &$fetches): string {
            $fetches++;
            $this->assertSame($clientid, $url);
            return json_encode([
                'client_id' => $clientid,
                'client_name' => 'Claude Code',
                'redirect_uris' => ['http://localhost/callback', 'http://127.0.0.1/callback'],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'],
                'token_endpoint_auth_method' => 'none',
            ]);
        });
        $oauth = new oauth_service(null, null, $registry);

        $code = $this->authorize($oauth, $clientid, 'http://localhost:40123/callback');
        $tokens = $this->exchange($oauth, $clientid, $code, self::VERIFIER, 'http://localhost:40123/callback');
        $this->authorize($oauth, $clientid, 'http://localhost:40124/callback');

        $this->assertNotEmpty($tokens['refresh_token']);
        $this->assertSame(1, $fetches, 'Metadata documents are cached.');
        $client = $DB->get_record('webservice_mcp_oauth_client', ['clientid' => $clientid], '*', MUST_EXIST);
        $this->assertSame('Claude Code', $client->clientname);
        $description = $oauth->authorization()->describe_client($oauth->get_client($clientid), 'http://localhost/callback');
        $this->assertSame('metadata', $description['registration']);
    }

    /**
     * Test metadata documents are validated (client_id mismatch, shared secrets, redirect URIs).
     */
    public function test_client_id_metadata_document_validation(): void {
        $this->resetAfterTest(true);

        $url = 'https://app.example/client.json';
        $registry = new client_registry();
        $valid = ['client_id' => $url, 'redirect_uris' => ['http://localhost/cb']];

        $document = $registry->validate_metadata_document($url, json_encode($valid));
        $this->assertSame(['http://localhost/cb'], $document['redirect_uris']);

        $invalid = [
            'mismatch' => ['client_id' => 'https://app.example/other.json'] + $valid,
            'secret' => ['token_endpoint_auth_method' => 'client_secret_basic'] + $valid,
            'noredirect' => ['redirect_uris' => []] + $valid,
            'httpredirect' => ['redirect_uris' => ['http://app.example/cb']] + $valid,
            'implicit' => ['response_types' => ['token']] + $valid,
        ];
        foreach ($invalid as $name => $document) {
            try {
                $registry->validate_metadata_document($url, json_encode($document));
                $this->fail('Accepted invalid document: ' . $name);
            } catch (oauth_exception $exception) {
                $this->assertSame('invalid_client', $exception->oauth_error(), $name);
            }
        }
        $this->assert_oauth_error('invalid_client', fn() => $registry->validate_metadata_document($url, '[1,2]'));
        $this->assert_oauth_error(
            'invalid_client',
            fn() => $registry->validate_metadata_document($url, str_repeat(' ', 70000) . json_encode($valid))
        );

        $this->assertTrue(client_registry::is_metadata_document_client_id($url));
        $this->assertFalse(client_registry::is_metadata_document_client_id('https://app.example/'));
        $this->assertFalse(client_registry::is_metadata_document_client_id('http://app.example/client.json'));
        $this->assertFalse(client_registry::is_metadata_document_client_id('https://user@app.example/client.json'));
        $this->assertFalse(client_registry::is_metadata_document_client_id('https://app.example/a/../client.json'));
    }

    /**
     * Test an admin-revoked metadata document client is refused without fetching.
     */
    public function test_revoked_metadata_document_client_refused(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->create_mcp_user();

        $clientid = 'https://app.example/client.json';
        $DB->insert_record('webservice_mcp_oauth_client', (object)[
            'timecreated' => time(),
            'timemodified' => time(),
            'clientid' => $clientid,
            'clientname' => 'Blocked',
            'redirecturis' => json_encode(['http://localhost/cb']),
            'scope' => oauth_service::REGISTRATION_DEFAULT_SCOPE,
            'granttypes' => json_encode(['authorization_code']),
            'responsetypes' => json_encode(['code']),
            'tokenauthmethod' => 'none',
            'isdynamic' => 0,
            'revoked' => 1,
        ]);
        $registry = new client_registry(function (): string {
            $this->fail('Revoked clients must not be fetched.');
        });

        $this->assert_oauth_error(
            'invalid_client',
            fn() => (new oauth_service(null, null, $registry))->authorization()->validate_authorization_request([
                'response_type' => 'code',
                'client_id' => $clientid,
                'redirect_uri' => 'http://localhost/cb',
            ])
        );
    }

    /**
     * Test a login-as session cannot authorize.
     */
    public function test_login_as_cannot_authorize(): void {
        $this->resetAfterTest(true);
        $user = $this->create_mcp_user();

        $this->setAdminUser();
        \core\session\manager::loginas($user->id, context_system::instance());

        $this->expectException(\moodle_exception::class);
        (new oauth_service())->authorization()->require_authorization_context();
    }

    /**
     * Test the anonymous token and revocation endpoints never fetch metadata documents or create clients.
     */
    public function test_token_endpoint_never_fetches_metadata_documents(): void {
        global $DB;

        $this->resetAfterTest(true);
        set_config('oauthenabled', 1, 'webservice_mcp');
        $registry = new client_registry(function (): string {
            $this->fail('The token endpoint must not fetch client metadata.');
        });
        $oauth = new oauth_service(null, null, $registry);
        $clientid = 'https://attacker.example/' . random_string(8);

        $this->assert_oauth_error('invalid_client', fn() => $oauth->exchange_token_request(
            ['grant_type' => 'refresh_token', 'refresh_token' => 'x'],
            $clientid,
            null
        ));
        $this->assert_oauth_error('invalid_client', fn() => $oauth->revoke_token_request(['token' => 'x'], $clientid, null));
        $this->assertFalse($DB->record_exists('webservice_mcp_oauth_client', ['clientid' => $clientid]));
    }

    /**
     * Test a code whose user fails the eligibility re-check at exchange is burned anyway.
     */
    public function test_failed_eligibility_still_burns_code(): void {
        global $DB;

        $this->resetAfterTest(true);
        $user = $this->create_mcp_user();
        $oauth = new oauth_service();
        $registration = $this->register($oauth);
        $clientid = $registration['client_id'];
        $code = $this->authorize($oauth, $clientid);

        $assignments = $DB->get_records('role_assignments', ['userid' => $user->id]);
        $DB->delete_records('role_assignments', ['userid' => $user->id]);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assert_oauth_error('invalid_grant', fn() => $this->exchange($oauth, $clientid, $code));

        foreach ($assignments as $assignment) {
            role_assign($assignment->roleid, $user->id, $assignment->contextid);
        }
        accesslib_clear_all_caches_for_unit_testing();
        $this->assert_oauth_error('invalid_grant', fn() => $this->exchange($oauth, $clientid, $code));
        $used = $DB->get_field('webservice_mcp_oauth_code', 'used', ['code' => credential_manager::hash_token($code)]);
        $this->assertSame(1, (int)$used);
    }

    /**
     * A refresh token from before token families (familyid NULL) still gets grace-period pairs for concurrent
     * refreshes, in the family its first refresh starts; reuse after the window revokes that family.
     */
    public function test_legacy_refresh_token_gets_family_and_grace(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->create_mcp_user();
        $oauth = new oauth_service();
        $registration = $this->register($oauth);
        $clientid = $registration['client_id'];
        $legacy = $this->exchange($oauth, $clientid, $this->authorize($oauth, $clientid));

        // Make both tokens look like pre-0.9.0 rows.
        $DB->set_field('webservice_mcp_credential', 'familyid', null);
        $DB->set_field('webservice_mcp_credential', 'familycreated', null);
        $manager = new credential_manager();
        $legacyrow = $manager->find_credential($legacy['refresh_token']);

        $first = $this->refresh($oauth, $clientid, $legacy['refresh_token']);
        $family = (string)$manager->find_credential($first['access_token'])->familyid;
        $this->assertNotSame('', $family);
        $this->assertSame($family, (string)$manager->find_credential($legacy['refresh_token'])->familyid);
        $this->assertSame((int)$legacyrow->timecreated, (int)$manager->find_credential($first['access_token'])->familycreated);

        // A concurrent refresh with the same legacy token, inside the window: a new pair in the same family.
        $second = $this->refresh($oauth, $clientid, $legacy['refresh_token']);
        $this->assertSame($family, (string)$manager->find_credential($second['access_token'])->familyid);
        $this->assertNotNull($manager->resolve_credential($first['access_token']));
        $this->assertNotNull($manager->resolve_credential($second['access_token']));

        // Outside the window the reuse is theft: the whole family is revoked.
        $DB->set_field('webservice_mcp_credential', 'rotatedat', time() - 31, ['id' => $legacyrow->id]);
        $this->assert_oauth_error('invalid_grant', fn() => $this->refresh($oauth, $clientid, $legacy['refresh_token']));
        $this->assertNull($manager->resolve_credential($first['access_token']));
        $this->assertNull($manager->resolve_credential($second['refresh_token']));
    }
}
