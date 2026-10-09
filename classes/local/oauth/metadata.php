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

/**
 * Discovery documents: protected resource (RFC 9728), authorization server (RFC 8414), and OpenID metadata.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class metadata {
    /** @var service */
    private service $oauth;

    /**
     * Constructor.
     *
     * @param service|null $oauth OAuth service.
     */
    public function __construct(?service $oauth = null) {
        $this->oauth = $oauth ?? new service();
    }

    /**
     * Build the protected-resource metadata document (RFC 9728).
     *
     * @return array
     */
    public function build_protected_resource_metadata(): array {
        return [
            'resource' => $this->oauth->canonical_resource_uri(),
            'authorization_servers' => [$this->oauth->issuer_url()],
            'scopes_supported' => [service::SCOPE_READ, service::SCOPE_WRITE],
            'bearer_methods_supported' => ['header'],
        ];
    }

    /**
     * Build the authorization-server metadata document (RFC 8414).
     *
     * @return array
     */
    public function build_authorization_server_metadata(): array {
        return [
            'issuer' => $this->oauth->issuer_url(),
            'authorization_endpoint' => $this->oauth->authorization_endpoint_url(),
            'token_endpoint' => $this->oauth->token_endpoint_url(),
            'registration_endpoint' => $this->oauth->registration_endpoint_url(),
            'revocation_endpoint' => $this->oauth->revocation_endpoint_url(),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => array_merge(
                ['authorization_code', 'refresh_token'],
                $this->oauth->ema_enabled() ? [service::GRANT_JWT_BEARER] : []
            ),
            'token_endpoint_auth_methods_supported' => client_registry::TOKEN_AUTH_METHODS,
            'revocation_endpoint_auth_methods_supported' => client_registry::TOKEN_AUTH_METHODS,
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => $this->oauth->supported_scopes(),
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /**
     * Build an OpenID Provider metadata document that strict discovery parsers accept.
     *
     * This server issues no ID tokens; the OIDC-required fields are present only so clients that probe
     * {issuer}/.well-known/openid-configuration (the one discovery URL reachable without web server
     * rewrites) can parse it.
     *
     * @return array
     */
    public function build_openid_configuration(): array {
        return $this->build_authorization_server_metadata() + [
            'jwks_uri' => $this->oauth->jwks_url(),
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ];
    }
}
