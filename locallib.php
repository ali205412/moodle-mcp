<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->dirroot/webservice/lib.php");
require_once("$CFG->dirroot/webservice/mcp/lib.php");

/**
 * MCP test client for automated testing.
 *
 * Implements the Moodle webservice_test_client_interface to provide
 * standardized testing capabilities for the MCP web service protocol.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webservice_mcp_test_client implements webservice_test_client_interface {
    /**
     * Execute a test web service request.
     *
     * This method implements the standard Moodle web service testing interface,
     * making JSON-RPC 2.0 requests to the MCP endpoint for automated testing.
     *
     * @param string $serverurl The server URL (including token parameter).
     * @param string $function The function name to call.
     * @param array $params The parameters of the called function.
     * @return mixed The decoded response from the server.
     */
    public function simpletest($serverurl, $function, $params) {
        // The admin test page passes the token as ?wstoken=; MCP accepts it only as a Bearer header.
        $query = [];
        parse_str((string)parse_url($serverurl, PHP_URL_QUERY), $query);
        $url = strtok($serverurl, '?');

        $client = new webservice_mcp_client($url, (string)($query['wstoken'] ?? ''));
        return $client->call_tool($function, $params);
    }
}
