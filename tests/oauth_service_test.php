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
use webservice_mcp\local\auth\credential_manager;
use webservice_mcp\local\oauth\service as oauth_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the plugin OAuth server used by Claude-compatible MCP clients.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\oauth\service
 * @covers      \webservice_mcp\local\oauth\exception
 */
final class oauth_service_test extends advanced_testcase {
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
     * Test DCR, authorize, code exchange, and refresh rotation.
     */
    public function test_oauth_service_supports_dynamic_registration_code_exchange_and_refresh(): void {
        global $DB;

        $this->resetAfterTest(true);
        set_config('oauthenabled', 1, 'webservice_mcp');

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('webservice/mcp:use', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);

        $oauth = new oauth_service();
        $registration = $oauth->register_dynamic_client([
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'scope' => 'mcp:read mcp:write offline_access',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ]);

        $this->assertNotEmpty($registration['client_id']);
        $this->assertArrayNotHasKey('client_secret', $registration);

        $verifier = 'verifier-for-claude-connector';
        $validated = $oauth->validate_authorization_request([
            'response_type' => 'code',
            'client_id' => $registration['client_id'],
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'scope' => 'mcp:read mcp:write offline_access',
            'state' => 'opaque-state',
            'resource' => $oauth->canonical_resource_uri(),
            'code_challenge' => $this->pkce_challenge($verifier),
            'code_challenge_method' => 'S256',
        ]);

        $context = $oauth->require_authorization_context();
        $code = $oauth->create_authorization_code(
            $user->id,
            $validated['client'],
            $context,
            $validated['redirecturi'],
            $validated['scope'],
            $validated['resourceuri'],
            $validated['codechallenge'],
            $validated['codechallengemethod']
        );

        $initialtokens = $oauth->exchange_token_request([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'code_verifier' => $verifier,
            'resource' => $oauth->canonical_resource_uri(),
        ], $registration['client_id'], null);

        $this->assertSame('Bearer', $initialtokens['token_type']);
        $this->assertSame($oauth->canonical_resource_uri(), $initialtokens['resource']);
        $this->assertNotEmpty($initialtokens['access_token']);
        $this->assertNotEmpty($initialtokens['refresh_token']);
        $this->assertStringContainsString('offline_access', $initialtokens['scope']);

        $accesstoken = $DB->get_record('webservice_mcp_credential', ['token' => $initialtokens['access_token']], '*', MUST_EXIST);
        $refreshtoken = $DB->get_record('webservice_mcp_credential', ['token' => $initialtokens['refresh_token']], '*', MUST_EXIST);

        $this->assertSame((int)credential_manager::TOKEN_TYPE_DURABLE, (int)$accesstoken->tokentype);
        $this->assertSame((int)credential_manager::TOKEN_TYPE_REFRESH, (int)$refreshtoken->tokentype);
        $this->assertSame($registration['client_id'], (string)$accesstoken->oauthclientid);
        $this->assertSame($oauth->canonical_resource_uri(), (string)$accesstoken->resourceuri);

        $refreshedtokens = $oauth->exchange_token_request([
            'grant_type' => 'refresh_token',
            'refresh_token' => $initialtokens['refresh_token'],
            'resource' => $oauth->canonical_resource_uri(),
        ], $registration['client_id'], null);

        $this->assertNotSame($initialtokens['access_token'], $refreshedtokens['access_token']);
        $this->assertNotSame($initialtokens['refresh_token'], $refreshedtokens['refresh_token']);
        $this->assertNull((new credential_manager())->resolve_credential($initialtokens['refresh_token']));
    }

    /**
     * Test metadata documents advertise the expected Claude-compatible endpoints.
     */
    public function test_oauth_service_metadata_exposes_registration_and_token_endpoints(): void {
        $this->resetAfterTest(true);

        $oauth = new oauth_service();
        $resource = $oauth->build_protected_resource_metadata();
        $metadata = $oauth->build_authorization_server_metadata();

        $this->assertSame($oauth->canonical_resource_uri(), $resource['resource']);
        $this->assertSame([$oauth->issuer_url()], $resource['authorization_servers']);
        $this->assertSame($oauth->issuer_url(), $metadata['issuer']);
        $this->assertSame($oauth->authorization_endpoint_url(), $metadata['authorization_endpoint']);
        $this->assertSame($oauth->token_endpoint_url(), $metadata['token_endpoint']);
        $this->assertSame($oauth->registration_endpoint_url(), $metadata['registration_endpoint']);
        $this->assertContains('authorization_code', $metadata['grant_types_supported']);
        $this->assertContains('refresh_token', $metadata['grant_types_supported']);
        $this->assertContains('none', $metadata['token_endpoint_auth_methods_supported']);
        $this->assertContains('client_secret_basic', $metadata['token_endpoint_auth_methods_supported']);
        $this->assertContains('S256', $metadata['code_challenge_methods_supported']);
    }
}
