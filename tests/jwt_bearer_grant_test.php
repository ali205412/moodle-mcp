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
use Firebase\JWT\JWT;
use stdClass;
use webservice_mcp\local\auth\transport_identity;
use webservice_mcp\local\oauth\exception as oauth_exception;
use webservice_mcp\local\oauth\jwt_bearer_grant;
use webservice_mcp\local\oauth\metadata;
use webservice_mcp\local\oauth\service as oauth_service;

/**
 * Tests for Enterprise Managed Authorization (RFC 7523 identity assertion grant).
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\oauth\jwt_bearer_grant
 * @covers      \webservice_mcp\local\oauth\token_issuer
 */
final class jwt_bearer_grant_test extends advanced_testcase {
    /** Trusted issuer. */
    private const ISSUER = 'https://idp.example/tenant';

    /** Pre-registered client id. */
    private const CLIENT = 'mcp_enterprise_client';

    /** @var string PEM private key. */
    private string $privatekey = '';

    /** @var array JWKS served by the stub. */
    private array $jwks = [];

    /**
     * Generate an RSA key pair and the matching JWKS.
     *
     * @return void
     */
    private function generate_keys(): void {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privatekey);
        $details = openssl_pkey_get_details($key);
        $this->jwks = ['keys' => [[
            'kty' => 'RSA',
            'kid' => 'k1',
            'use' => 'sig',
            'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
            'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
        ]]];
    }

    /**
     * Build the OAuth service with a stubbed issuer.
     *
     * @return oauth_service
     */
    private function oauth(): oauth_service {
        $jwks = $this->jwks;
        $fetcher = static function (string $url) use ($jwks): string {
            if ($url === self::ISSUER . '/.well-known/openid-configuration') {
                return json_encode(['issuer' => self::ISSUER, 'jwks_uri' => 'https://idp.example/keys']);
            }
            if ($url === 'https://idp.example/keys') {
                return json_encode($jwks);
            }
            throw new \coding_exception('Unexpected fetch ' . $url);
        };
        return new oauth_service(null, null, null, new jwt_bearer_grant($fetcher));
    }

    /**
     * Prepare settings, a client, and a user with webservice/mcp:use.
     *
     * @param int $isdynamic Whether the client was dynamically registered.
     * @return stdClass User.
     */
    private function setup_site(int $isdynamic = 0): stdClass {
        global $DB;

        set_config('oauthenabled', 1, 'webservice_mcp');
        set_config('emaenabled', 1, 'webservice_mcp');
        set_config('ematrustedissuers', self::ISSUER . "\nhttps://other.example", 'webservice_mcp');
        $this->generate_keys();

        $DB->insert_record('webservice_mcp_oauth_client', (object)[
            'timecreated' => time(),
            'timemodified' => time(),
            'clientid' => self::CLIENT,
            'clientname' => 'Enterprise client',
            'redirecturis' => json_encode(['https://claude.ai/api/mcp/auth_callback']),
            'scope' => oauth_service::REGISTRATION_DEFAULT_SCOPE,
            'granttypes' => json_encode(['authorization_code', 'refresh_token']),
            'responsetypes' => json_encode(['code']),
            'tokenauthmethod' => 'none',
            'isdynamic' => $isdynamic,
            'revoked' => 0,
        ]);

        $user = $this->getDataGenerator()->create_user(['email' => 'pat@example.com']);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('webservice/mcp:use', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();

        return $user;
    }

    /**
     * Sign an assertion.
     *
     * @param array $overrides Claim overrides.
     * @param array $header Extra header fields.
     * @param string|null $key PEM key (defaults to the trusted key).
     * @return string
     */
    private function assertion(array $overrides = [], array $header = ['typ' => 'oauth-id-jag+jwt'], ?string $key = null): string {
        $claims = $overrides + [
            'iss' => self::ISSUER,
            'sub' => 'subject-1',
            'aud' => (new oauth_service())->issuer_url(),
            'client_id' => self::CLIENT,
            'exp' => time() + 300,
            'iat' => time(),
            'email' => 'pat@example.com',
            'email_verified' => true,
            'jti' => bin2hex(random_bytes(8)),
        ];
        // Signed by hand: the bundled php-jwt 6.4 encode() always overwrites typ with "JWT".
        $segments = [
            JWT::urlsafeB64Encode(json_encode($header + ['alg' => 'RS256', 'kid' => 'k1'])),
            JWT::urlsafeB64Encode(json_encode($claims)),
        ];
        openssl_sign(implode('.', $segments), $signature, $key ?? $this->privatekey, OPENSSL_ALGO_SHA256);
        $segments[] = JWT::urlsafeB64Encode($signature);
        return implode('.', $segments);
    }

    /**
     * Exchange an assertion at the token endpoint.
     *
     * @param oauth_service $oauth OAuth service.
     * @param string $assertion Assertion.
     * @return array
     */
    private function exchange(oauth_service $oauth, string $assertion): array {
        return $oauth->exchange_token_request([
            'grant_type' => oauth_service::GRANT_JWT_BEARER,
            'assertion' => $assertion,
            'resource' => $oauth->canonical_resource_uri(),
        ], self::CLIENT, null);
    }

    /**
     * Assert an exchange fails with the given error.
     *
     * @param string $error Expected OAuth error.
     * @param string $assertion Assertion.
     * @return void
     */
    private function assert_rejected(string $error, string $assertion): void {
        try {
            $this->exchange($this->oauth(), $assertion);
            $this->fail('Assertion accepted: expected ' . $error);
        } catch (oauth_exception $exception) {
            $this->assertSame($error, $exception->oauth_error(), $exception->getMessage());
        }
    }

    /**
     * Test a valid assertion yields tokens for the matching user and the grant is advertised.
     */
    public function test_valid_assertion_issues_tokens(): void {
        $this->resetAfterTest(true);
        $user = $this->setup_site();

        $tokens = $this->exchange($this->oauth(), $this->assertion());

        $this->assertNotEmpty($tokens['refresh_token']);
        $identity = (new transport_identity())->resolve($tokens['access_token']);
        $this->assertSame((int)$user->id, (int)$identity->user->id);
        $granttypes = (new metadata())->build_authorization_server_metadata()['grant_types_supported'];
        $this->assertContains(oauth_service::GRANT_JWT_BEARER, $granttypes);

        set_config('emausermatchfield', 'username', 'webservice_mcp');
        $tokens = $this->exchange($this->oauth(), $this->assertion(['preferred_username' => $user->username, 'email' => null]));
        $this->assertNotEmpty($tokens['access_token']);
    }

    /**
     * Test invalid assertions are rejected.
     */
    public function test_invalid_assertions_rejected(): void {
        $this->resetAfterTest(true);
        $this->setup_site();

        $otherkey = '';
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $otherkey);

        $this->assert_rejected('invalid_grant', $this->assertion(['iss' => 'https://evil.example']));
        $this->assert_rejected('invalid_grant', $this->assertion(['aud' => 'https://elsewhere.example']));
        $this->assert_rejected('invalid_grant', $this->assertion(['client_id' => 'someone_else']));
        $this->assert_rejected('invalid_grant', $this->assertion(['exp' => time() - 120, 'iat' => time() - 600]));
        $this->assert_rejected('invalid_grant', $this->assertion(['email' => 'nobody@example.com']));
        $this->assert_rejected('invalid_grant', $this->assertion([], ['typ' => 'at+jwt']));
        $this->assert_rejected('invalid_grant', $this->assertion([], ['typ' => 'oauth-id-jag+jwt'], $otherkey));
        $this->assert_rejected('invalid_grant', 'not.a.jwt');

        set_config('emaenabled', 0, 'webservice_mcp');
        $this->assert_rejected('unsupported_grant_type', $this->assertion());
        $this->assertNotContains(
            oauth_service::GRANT_JWT_BEARER,
            (new metadata())->build_authorization_server_metadata()['grant_types_supported']
        );
    }

    /**
     * Test assertions are single-use and need a jti.
     */
    public function test_assertion_replay_and_jti(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setup_site();

        $assertion = $this->assertion();
        $this->exchange($this->oauth(), $assertion);
        // Replay protection is durable: it survives a cache purge.
        \cache_helper::purge_all();
        $this->assert_rejected('invalid_grant', $assertion);
        $this->assertSame(1, $DB->count_records('webservice_mcp_jti'));

        $this->assert_rejected('invalid_grant', $this->assertion(['jti' => null]));
        $this->assert_rejected('invalid_grant', $this->assertion(['exp' => time() + 2 * DAYSECS]));

        set_config('emarequirejti', 0, 'webservice_mcp');
        $this->assertNotEmpty($this->exchange($this->oauth(), $this->assertion(['jti' => null], ['typ' => 'JWT']))['access_token']);
        $this->assert_rejected('invalid_grant', $this->assertion(['jti' => null]));
    }

    /**
     * Test users are mapped by immutable oid/sub through auth_oidc first, and by email only when verified.
     */
    public function test_user_mapping_prefers_oid_and_requires_verified_email(): void {
        global $DB;

        $this->resetAfterTest(true);
        $user = $this->setup_site();
        $other = $this->getDataGenerator()->create_user(['email' => 'other@example.com']);

        $this->assert_rejected('invalid_grant', $this->assertion(['email_verified' => false]));
        $this->assert_rejected('invalid_grant', $this->assertion(['email_verified' => null]));

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('auth_oidc_token');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('oidcuniqid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('username', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman->create_table($table);
        try {
            $DB->insert_record('auth_oidc_token', (object)['oidcuniqid' => 'oid-of-pat', 'username' => $user->username]);
            // The oid wins over a (possibly reassigned) email that points at someone else.
            $tokens = $this->exchange($this->oauth(), $this->assertion(['oid' => 'oid-of-pat', 'email' => $other->email]));
            $identity = (new transport_identity())->resolve($tokens['access_token']);
            $this->assertSame((int)$user->id, (int)$identity->user->id);
        } finally {
            $dbman->drop_table($table);
        }
    }

    /**
     * Test dynamically registered clients cannot use identity assertions.
     */
    public function test_dynamic_clients_refused(): void {
        $this->resetAfterTest(true);
        $this->setup_site(1);

        $this->assert_rejected('unauthorized_client', $this->assertion());
    }
}
