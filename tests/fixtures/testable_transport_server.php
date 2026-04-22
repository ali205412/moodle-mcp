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

/**
 * Fixture transport server used by PHPUnit transport tests.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace webservice_mcp;

use stdClass;
use webservice_mcp\local\request;
use webservice_mcp\local\transport\server as transport_server;

/**
 * Test double for transport server helper coverage.
 *
 * @package     webservice_mcp
 */
final class testable_transport_server extends transport_server {
    /** @var bool */
    public bool $writeclosecalled = false;

    /** @var bool */
    public bool $preflightcalled = false;

    /** @var bool */
    public bool $authcalled = false;

    /** @var bool */
    public bool $stubauthentication = false;

    /** @var string */
    public string $capturedbody = '';

    /** @var array */
    public array $capturedheaders = [];

    /** @var int */
    public int $capturedstatus = 200;

    /**
     * Apply a public token without reading request globals.
     *
     * @param string $token Public connector token.
     * @return void
     */
    public function set_public_token_for_test(string $token): void {
        $this->publictoken = $token;
        $this->token = $token;
    }

    /**
     * Apply an already-resolved identity.
     *
     * @param stdClass $identity Identity payload.
     * @return void
     */
    public function apply_identity_for_test(stdClass $identity): void {
        $this->authenticate_transport_identity($identity);
    }

    /**
     * Prime transport request metadata used during session creation.
     *
     * @param array $requestdata Request metadata.
     * @return void
     */
    public function set_transport_request_for_test(array $requestdata): void {
        $this->transportrequest = $requestdata;
    }

    /**
     * Seed the parsed MCP request used by helper-level tests.
     *
     * @param request $request Parsed MCP request.
     * @return void
     */
    public function set_request_for_test(request $request): void {
        $this->mcprequest = $request;
    }

    /**
     * Seed the parsed tool name and arguments for wrapper execution tests.
     *
     * @param string $functionname Wrapper tool name.
     * @param array $parameters Wrapper arguments.
     * @return void
     */
    public function set_tool_call_for_test(string $functionname, array $parameters): void {
        $this->functionname = $functionname;
        $this->parameters = $parameters;
    }

    /**
     * Create a session through the protected helper.
     *
     * @return string
     */
    public function create_transport_session_for_test(): string {
        return $this->create_transport_session();
    }

    /**
     * Fetch raw session metadata from the helper store.
     *
     * @param string $sessionid Session id.
     * @return array|null
     */
    public function get_session_for_test(string $sessionid): ?array {
        return $this->sessionstore->get_session($sessionid);
    }

    /**
     * Trigger the write-close hook without a real HTTP request.
     *
     * @return void
     */
    public function release_transport_session_for_test(): void {
        $this->release_transport_session();
    }

    /**
     * Invoke the protected tools/list response helper.
     *
     * @return void
     */
    public function send_tools_list_for_test(): void {
        $this->send_tools_list_response();
    }

    /**
     * Invoke the protected wrapper execution helper.
     *
     * @return void
     */
    public function execute_wrapper_tool_for_test(): void {
        $this->execute_wrapper_tool();
    }

    /**
     * Invoke protected error generation for assertions.
     *
     * @param \Throwable $exception Exception to encode.
     * @return array
     */
    public function generate_error_for_test(\Throwable $exception): array {
        return $this->generate_error($exception);
    }

    /**
     * Intercept auth during request-lifecycle tests.
     *
     * @return void
     */
    protected function authenticate_user(): void {
        $this->authcalled = true;

        if ($this->stubauthentication) {
            return;
        }

        parent::authenticate_user();
    }

    /**
     * Capture write-close calls in tests.
     *
     * @return void
     */
    protected function close_session_for_transport(): void {
        $this->writeclosecalled = true;
    }

    /**
     * Capture response headers for assertions.
     *
     * @param string $header Header line.
     * @return void
     */
    protected function send_header(string $header): void {
        $this->capturedheaders[] = $header;
    }

    /**
     * Capture response status for assertions.
     *
     * @param int $status Status code.
     * @return void
     */
    protected function set_status(int $status): void {
        $this->capturedstatus = $status;
    }

    /**
     * Capture output for assertions.
     *
     * @param string $body Output chunk.
     * @return void
     */
    protected function emit(string $body): void {
        $this->capturedbody .= $body;
    }

    /**
     * Capture preflight handling without emitting real headers.
     *
     * @return void
     */
    protected function send_preflight_response(): void {
        $this->preflightcalled = true;
    }
}
