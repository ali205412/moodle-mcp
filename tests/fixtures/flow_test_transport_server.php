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
 * Fixture transport server used by connector flow tests.
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
 * Test double for bootstrap-to-transport flow coverage.
 *
 * @package     webservice_mcp
 */
final class flow_test_transport_server extends transport_server {
    /** @var string */
    public string $capturedbody = '';

    /** @var array */
    public array $capturedheaders = [];

    /** @var int */
    public int $capturedstatus = 200;

    /**
     * Apply a public token without reading request globals.
     *
     * @param string $token Connector token.
     * @return void
     */
    public function set_public_token_for_test(string $token): void {
        $this->publictoken = $token;
        $this->token = $token;
    }

    /**
     * Apply a resolved identity.
     *
     * @param stdClass $identity Transport identity.
     * @return void
     */
    public function apply_identity_for_test(stdClass $identity): void {
        $this->authenticate_transport_identity($identity);
    }

    /**
     * Seed transport request metadata.
     *
     * @param array $requestdata Transport request data.
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
     * Reset captured response state between helper invocations.
     *
     * @return void
     */
    public function reset_capture_for_test(): void {
        $this->capturedbody = '';
        $this->capturedheaders = [];
        $this->capturedstatus = 200;
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
     * Avoid mutating the real PHP session during tests.
     *
     * @return void
     */
    protected function close_session_for_transport(): void {
    }
}
