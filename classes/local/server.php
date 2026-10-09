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

namespace webservice_mcp\local;

use moodle_exception;
use webservice_base_server;

// The legacy server extends a class declared in webservice/lib.php, so the
// upstream file must be loaded before the class declaration is parsed.
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
global $CFG;
require_once($CFG->dirroot . '/webservice/lib.php');
// phpcs:enable


/**
 * MCP (Model Context Protocol) web service server implementation.
 *
 * This server handles JSON-RPC 2.0 requests following the MCP specification.
 * It supports MCP-specific methods like initialize, tools/list, and tools/call,
 * as well as direct function invocation.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class server extends webservice_base_server {
    /** @var string Protocol version supported by this server. */
    protected const PROTOCOL_VERSION = '2025-03-26';

    /** @var string Server name. */
    protected const SERVER_NAME = 'Moodle MCP Server';

    /** @var string Server version. */
    protected const SERVER_VERSION = '1.0.0';

    /** @var string HTTP request method. */
    protected string $httpmethod;

    /** @var request|null Parsed MCP request object. */
    protected ?request $mcprequest = null;

    /**
     * Constructor.
     *
     * @param int $authmethod Authentication method (e.g., WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN).
     */
    public function __construct(int $authmethod) {
        parent::__construct($authmethod);
        $this->wsname = 'mcp';
    }


    /**
     * Parse and validate incoming request, set token, method, parameters.
     *
     * @return void
     * @throws moodle_exception If the incoming request is invalid.
     */
    protected function parse_request(): void {
        parent::set_web_service_call_settings();

        $this->token = $this->extract_token();
        $this->httpmethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($this->httpmethod === 'POST' && !request::is_raw_input_empty()) {
            $this->mcprequest = request::from_raw_input();

            // Handle MCP tool invocation.
            if ($this->is_tool_call()) {
                $this->extract_tool_call();
            }
        }
    }

    /**
     * Determine whether the incoming MCP request represents a tools/call invocation.
     *
     * @return bool True if the request is a tools/call request, false otherwise.
     */
    protected function is_tool_call(): bool {
        return !empty($this->mcprequest->method) && $this->mcprequest->method === 'tools/call';
    }

    /**
     * Extract the function name and parameters from an MCP tools/call request.
     *
     * This method populates:
     *   - $this->functionname
     *   - $this->parameters
     *
     * @return void
     * @throws moodle_exception If the tool name is missing.
     */
    public function extract_tool_call(): void {
        if (empty($this->mcprequest->params) || empty($this->mcprequest->params['name'])) {
            throw new moodle_exception('err_missing_tool_name', 'webservice_mcp');
        }

        // Extract function and arguments.
        $this->functionname = $this->mcprequest->params['name'];
        $this->parameters = $this->mcprequest->params['arguments'] ?? [];
    }

    /**
     * Extract the Bearer token from the Authorization header.
     *
     * @return string|null
     */
    protected function extract_token(): ?string {
        // Try robust header extraction (case-insensitive).
        $auth = null;

        if (function_exists('getallheaders')) {
            $headers = array_change_key_case(getallheaders(), CASE_LOWER);
            $auth = $headers['authorization'] ?? null;
        }

        // Fallback to $_SERVER keys (common in CGI/FPM).
        if ($auth === null) {
            $keys = [
                'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'Authorization',
            ];
            foreach ($keys as $key) {
                if (!empty($_SERVER[$key])) {
                    $auth = $_SERVER[$key];
                    break;
                }
            }
        }

        if (!empty($auth) && preg_match('/Bearer\s+(\S+)/i', $auth, $matches)) {
            return $matches[1];
        }

        // Tokens are accepted only in the Authorization header: URLs leak into logs and referrers.
        return null;
    }





    /**
     * Send a successful response for standard function calls.
     *
     * Not used: the transport server emits every response through the dispatcher.
     *
     * @return void
     */
    protected function send_response(): void {
        // Responses are produced by the transport dispatcher; this base flow is never used.
        throw new \coding_exception('webservice_mcp responses are emitted by the transport server.');
    }

    /**
     * Sends an error response, optionally logging exception details for debugging.
     *
     * @param Exception|null $ex The exception to log and include in the error response, or null if no exception is provided.
     * @return void
     */
    protected function send_error($ex = null): void {
        if ($ex !== null && debugging('', DEBUG_MINIMAL)) {
            $this->log_exception_for_debug($ex);
        }

        echo $this->safe_json_encode($this->generate_error($ex));
    }

    /**
     * Generates a standardized error response for handling exceptions in the JSON-RPC protocol.
     *
     * @param Exception|moodle_exception|null $ex The exception to process. If null, a default internal error is returned.
     * @return array The formatted error response containing error code, message, and additional data.
     */
    protected function generate_error($ex): array {
        if ($ex === null) {
            return [
                'jsonrpc' => $this->mcprequest->jsonrpc,
                'error' => ['code' => -32603, 'message' => 'Internal error'],
                'id' => $this->mcprequest->id,
            ];
        }

        $errordata = [
            'exception' => get_class($ex),
            'message' => $ex->getMessage(),
        ];

        if (isset($ex->errorcode)) {
            $errordata['errorcode'] = $ex->errorcode;
        }

        if (debugging() && isset($ex->debuginfo)) {
            $errordata['debuginfo'] = $ex->debuginfo;
        }

        $code = -32603;
        if (isset($ex->code) && is_numeric($ex->code)) {
            $code = (int) $ex->code;
        }

        return [
            'jsonrpc' => $this->mcprequest->jsonrpc ?? '2.0',
            'error' => [
                'code' => $code,
                'message' => $ex->getMessage(),
                'data' => $errordata,
            ],
            'id' => $this->mcprequest->id ?? null,
        ];
    }


    /**
     * Safely encode data to JSON and handle errors.
     *
     * @param mixed $data
     * @return string JSON encoded string
     */
    protected function safe_json_encode(mixed $data): string {
        // Use JSON_THROW_ON_ERROR if available.
        // Invalid UTF-8 from Moodle data must not fail the whole response.
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            // Avoid leaking internal structures; return minimal error JSON-RPC.
            $fallback = [
                'jsonrpc' => '2.0',
                'error' => ['code' => -32603, 'message' => 'Internal JSON encoding error'],
                'id' => $this->mcprequest->id ?? null,
            ];
            return json_encode($fallback);
        }

        return $encoded;
    }

    /**
     * Log rich exception information when debugging is enabled.
     *
     * @param \Throwable $ex
     * @return void
     */
    protected function log_exception_for_debug(\Throwable $ex): void {
        $info = get_exception_info($ex);
        $message = 'MCP exception handler: ' . $info->message .
            ' Debug: ' . ($info->debuginfo ?? '') . "\n" .
            format_backtrace($info->backtrace ?? [], true);
        debugging($message);
    }
}
