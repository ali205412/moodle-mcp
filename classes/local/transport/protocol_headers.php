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

namespace webservice_mcp\local\transport;

use webservice_mcp\local\request;

/**
 * Parse and validate MCP transport request headers.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class protocol_headers {
    /** Default protocol version for pre-negotiated or legacy clients. */
    public const DEFAULT_PROTOCOL_VERSION = '2025-03-26';

    /** Protocol versions accepted by the transport layer. */
    private const SUPPORTED_PROTOCOL_VERSIONS = [
        '2025-03-26',
        '2025-06-18',
        '2025-11-25',
    ];

    /**
     * Read request headers into a lower-cased associative array.
     *
     * @return array
     */
    public function read_headers(): array {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower((string)$name)] = trim((string)$value);
            }
        }

        foreach ($_SERVER as $name => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (strpos($name, 'HTTP_') !== 0) {
                continue;
            }

            $headername = strtolower(str_replace('_', '-', substr($name, 5)));
            $headers[$headername] = trim($value);
        }

        return $headers;
    }

    /**
     * Validate request transport headers.
     *
     * @param string $httpmethod HTTP request method.
     * @param array $headers Request headers.
     * @param request|null $request Parsed JSON-RPC request when present.
     * @param bool $requiresession Whether this request must carry an MCP session id.
     * @return array
     */
    public function validate(string $httpmethod, array $headers, ?request $request = null, bool $requiresession = true): array {
        $httpmethod = strtoupper($httpmethod);
        if ($httpmethod === 'POST') {
            return $this->validate_post_request($headers, $request, $requiresession);
        }

        if (in_array($httpmethod, ['DELETE', 'GET'], true)) {
            return $this->validate_stateful_request($headers, $requiresession);
        }

        return $this->success();
    }

    /**
     * Determine whether the version is supported.
     *
     * @param string $version Protocol version string.
     * @return bool
     */
    public function is_supported_protocol_version(string $version): bool {
        return in_array($version, self::SUPPORTED_PROTOCOL_VERSIONS, true);
    }

    /**
     * Return the supported protocol versions.
     *
     * @return array
     */
    public function supported_protocol_versions(): array {
        return self::SUPPORTED_PROTOCOL_VERSIONS;
    }

    /**
     * Validate headers for POST JSON-RPC transport requests.
     *
     * @param array $headers Lower-cased request headers.
     * @param request|null $request Parsed request.
     * @param bool $requiresession Whether a transport session is required.
     * @return array
     */
    private function validate_post_request(array $headers, ?request $request, bool $requiresession): array {
        $mcpmethod = $this->header_value($headers, 'mcp-method');
        if ($mcpmethod === null) {
            return $this->error('Missing required Mcp-Method header.');
        }

        if (!$this->is_visible_ascii($mcpmethod)) {
            return $this->error('Mcp-Method must contain only visible ASCII characters.');
        }

        if ($request !== null && $request->method !== $mcpmethod) {
            return $this->error('Mcp-Method header does not match the JSON-RPC method.');
        }

        $initialization = $mcpmethod === 'initialize';
        $sessionid = $this->header_value($headers, 'mcp-session-id');
        if ($initialization && $sessionid !== null) {
            return $this->error('MCP-Session-Id is not allowed during initialize.');
        }

        $protocolversion = $this->header_value($headers, 'mcp-protocol-version');
        if ($initialization) {
            if ($protocolversion !== null && !$this->is_supported_protocol_version($protocolversion)) {
                return $this->error('Unsupported MCP-Protocol-Version header value.');
            }
        } else {
            if ($protocolversion === null) {
                $protocolversion = self::DEFAULT_PROTOCOL_VERSION;
            }

            if (!$this->is_supported_protocol_version($protocolversion)) {
                return $this->error('Unsupported MCP-Protocol-Version header value.');
            }
        }

        $mcpname = $this->header_value($headers, 'mcp-name');
        $requiresname = in_array($mcpmethod, ['tools/call', 'resources/read', 'prompts/get'], true);
        $bodyname = $this->request_target_name($request);

        if ($requiresname && $mcpname === null) {
            return $this->error('Missing required Mcp-Name header.');
        }

        if ($mcpname !== null && !$this->is_visible_ascii($mcpname)) {
            return $this->error('Mcp-Name must contain only visible ASCII characters.');
        }

        if ($mcpname !== null && $bodyname !== null && $mcpname !== $bodyname) {
            return $this->error('Mcp-Name header does not match the request target.');
        }

        if (!$initialization && $requiresession) {
            if ($sessionid === null) {
                return $this->error('Missing required MCP-Session-Id header.');
            }

            if (!$this->is_valid_session_id($sessionid)) {
                return $this->error('MCP-Session-Id is malformed.');
            }
        }

        return $this->success([
            'protocolversion' => $protocolversion ?? self::DEFAULT_PROTOCOL_VERSION,
            'sessionid' => $sessionid,
            'mcpmethod' => $mcpmethod,
            'mcpname' => $mcpname,
            'initialization' => $initialization,
        ]);
    }

    /**
     * Validate non-POST stateful transport requests.
     *
     * @param array $headers Lower-cased request headers.
     * @param bool $requiresession Whether a transport session is required.
     * @return array
     */
    private function validate_stateful_request(array $headers, bool $requiresession): array {
        $protocolversion = $this->header_value($headers, 'mcp-protocol-version') ?? self::DEFAULT_PROTOCOL_VERSION;
        if (!$this->is_supported_protocol_version($protocolversion)) {
            return $this->error('Unsupported MCP-Protocol-Version header value.');
        }

        $sessionid = $this->header_value($headers, 'mcp-session-id');
        if ($requiresession && $sessionid === null) {
            return $this->error('Missing required MCP-Session-Id header.');
        }

        if ($sessionid !== null && !$this->is_valid_session_id($sessionid)) {
            return $this->error('MCP-Session-Id is malformed.');
        }

        return $this->success([
            'protocolversion' => $protocolversion,
            'sessionid' => $sessionid,
            'mcpmethod' => null,
            'mcpname' => null,
            'initialization' => false,
        ]);
    }

    /**
     * Build a success validation result.
     *
     * @param array $overrides Optional values to merge.
     * @return array
     */
    private function success(array $overrides = []): array {
        return array_merge([
            'ok' => true,
            'status' => 200,
            'errorcode' => null,
            'message' => null,
            'protocolversion' => self::DEFAULT_PROTOCOL_VERSION,
            'sessionid' => null,
            'mcpmethod' => null,
            'mcpname' => null,
            'initialization' => false,
        ], $overrides);
    }

    /**
     * Build an error validation result.
     *
     * @param string $message Error message.
     * @return array
     */
    private function error(string $message): array {
        return [
            'ok' => false,
            'status' => 400,
            'errorcode' => -32001,
            'message' => $message,
            'protocolversion' => self::DEFAULT_PROTOCOL_VERSION,
            'sessionid' => null,
            'mcpmethod' => null,
            'mcpname' => null,
            'initialization' => false,
        ];
    }

    /**
     * Resolve a lower-cased header value.
     *
     * @param array $headers Lower-cased request headers.
     * @param string $name Lower-cased header name.
     * @return string|null
     */
    private function header_value(array $headers, string $name): ?string {
        if (!array_key_exists($name, $headers)) {
            return null;
        }

        $value = trim((string)$headers[$name]);
        return $value === '' ? null : $value;
    }

    /**
     * Extract the logical target name from a parsed request.
     *
     * @param request|null $request Parsed request.
     * @return string|null
     */
    private function request_target_name(?request $request): ?string {
        if ($request === null || empty($request->params) || !is_array($request->params)) {
            return null;
        }

        if (!empty($request->params['name']) && is_string($request->params['name'])) {
            return $request->params['name'];
        }

        if (!empty($request->params['uri']) && is_string($request->params['uri'])) {
            return $request->params['uri'];
        }

        return null;
    }

    /**
     * Validate a session id value.
     *
     * @param string $sessionid Candidate session id.
     * @return bool
     */
    private function is_valid_session_id(string $sessionid): bool {
        return $this->is_visible_ascii($sessionid);
    }

    /**
     * Check that a value only contains visible ASCII characters.
     *
     * @param string $value Value to validate.
     * @return bool
     */
    private function is_visible_ascii(string $value): bool {
        return $value !== '' && preg_match('/^[\x21-\x7E]+$/', $value) === 1;
    }
}
