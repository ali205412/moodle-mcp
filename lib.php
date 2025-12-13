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
 * MCP web service client for testing and integration.
 *
 * This client provides a simple interface for making JSON-RPC 2.0 requests
 * to the MCP web service. It is primarily used for unit testing but can also
 * be used for integration with other systems.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webservice_mcp_client {
    /**
     * @var moodle_url The MCP server URL.
     */
    private moodle_url $serverurl;

    /**
     * @var string Authentication token.
     */
    private string $token;

    /**
     * Constructor.
     *
     * @param string $serverurl The URL of the MCP web service endpoint.
     * @param string $token The authentication token for the web service.
     */
    public function __construct(string $serverurl, string $token) {
        $this->serverurl = new moodle_url($serverurl);
        $this->token = $token;
    }

    /**
     * Set or update the authentication token.
     *
     * @param string $token The new authentication token.
     * @return void
     */
    public function set_token(string $token): void {
        $this->token = $token;
    }

    /**
     * Execute a web service request using JSON-RPC 2.0 format.
     *
     * This is the core method that sends JSON-RPC requests to the server.
     * It constructs the request, sends it via cURL, and returns the decoded response.
     *
     * @param string $method The method name to call.
     * @param array $params The parameters for the method.
     * @param int|string|null $id Optional request ID (defaults to 1).
     * @return mixed The decoded JSON response.
     */
    public function call(string $method, array $params = [], $id = 1) {
        $request = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => $id,
        ];

        $requestjson = json_encode($request);

        // Add token to URL.
        $url = new moodle_url($this->serverurl);
        $url->param('wstoken', $this->token);

        $curl = new curl();
        $options = [
            'CURLOPT_HTTPHEADER' => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($requestjson),
            ],
        ];

        $result = $curl->post($url->out(false), $requestjson, $options);

        return json_decode($result, true);
    }

    /**
     * Execute an MCP tools/list request.
     *
     * Retrieves the list of available tools from the MCP server.
     *
     * @return mixed The decoded response containing the tools list.
     */
    public function list_tools() {
        return $this->call('tools/list', []);
    }

    /**
     * Execute an MCP tools/call request.
     *
     * Invokes a specific tool with the provided arguments.
     *
     * @param string $toolname The name of the tool to call.
     * @param array $arguments The arguments to pass to the tool.
     * @return mixed The decoded response from the tool invocation.
     */
    public function call_tool(string $toolname, array $arguments = []) {
        return $this->call('tools/call', [
            'name' => $toolname,
            'arguments' => $arguments,
        ]);
    }

    /**
     * Execute an MCP initialize request.
     *
     * Initializes the MCP session with the server.
     *
     * @return mixed The decoded response from the initialization.
     */
    public function initialize() {
        return $this->call('initialize', []);
    }
}
