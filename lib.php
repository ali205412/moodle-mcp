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
 * Library functions and the bundled MCP client for webservice_mcp.
 *
 * @package     webservice_mcp
 * @copyright   2025 MohammadReza PourMohammad
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
     * Send one stateless MCP request (protocol 2026-07-28) and return the decoded response.
     *
     * @param string $method MCP method.
     * @param array $params Method params.
     * @param int|string $id JSON-RPC id.
     * @return array|null
     */
    public function call(string $method, array $params = [], $id = 1) {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientInfo' => ['name' => 'webservice_mcp_client', 'version' => '1.0'],
            'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
        ];
        $requestjson = json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => $id]);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
            'Authorization: Bearer ' . $this->token,
            'MCP-Protocol-Version: 2026-07-28',
            'Mcp-Method: ' . $method,
        ];
        $name = $params['name'] ?? $params['uri'] ?? null;
        if (is_string($name)) {
            $headers[] = 'Mcp-Name: ' . (preg_match('/^[\x21-\x7E]+$/', $name) ? $name : '=?base64?' . base64_encode($name) . '?=');
        }

        $curl = new curl();
        $result = $curl->post($this->serverurl->out(false), $requestjson, ['CURLOPT_HTTPHEADER' => $headers]);

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
        return $this->call('server/discover', []);
    }
}

/**
 * Add "Generate MCP keys" to Site administration > Users > Bulk user actions (Moodle 4.2/4.3).
 *
 * Moodle 4.4+ uses the extend_bulk_user_actions hook registered in db/hooks.php instead.
 *
 * @return array Action links keyed by frankenstyle identifier.
 */
function webservice_mcp_bulk_user_actions(): array {
    return \webservice_mcp\hook_callbacks::bulk_user_actions();
}
