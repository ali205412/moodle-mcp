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
 * MCP web service entry point.
 *
 * This is the main entry point for the MCP (Model Context Protocol) web service.
 * Authentication is performed via tokens, supporting both Bearer tokens in the
 * Authorization header and token parameters in the URL.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Disable Moodle-specific debug messages and error output.
define('NO_DEBUG_DISPLAY', true);

// Mark this as a web service server script.
define('WS_SERVER', true);

require('../../config.php'); // phpcs:ignore moodle.Files.RequireLogin.Missing -- bearer-token endpoint (WS_SERVER).

// Check if the MCP protocol is enabled.
if (!webservice_protocol_is_enabled('mcp')) {
    header("HTTP/1.0 403 Forbidden");
    debugging(
        'The server died because the web services or the MCP protocol are not enabled',
        DEBUG_DEVELOPER
    );
    die;
}

// Instantiate and run the MCP transport server.
// Public MCP Server Card at server.php/server-card (no authentication, no user data).
if (($_SERVER['PATH_INFO'] ?? '') === '/server-card' && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    $card = json_encode(\webservice_mcp\local\mcp\server_card::build(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $etag = '"' . sha1($card) . '"';
    header('Content-Type: ' . \webservice_mcp\local\mcp\server_card::MEDIATYPE);
    header('Cache-Control: public, max-age=3600');
    header('ETag: ' . $etag);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET');
    header('Access-Control-Allow-Headers: Content-Type, If-None-Match');
    header('Access-Control-Expose-Headers: ETag');
    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
    } else if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        echo $card;
    }
    die;
}

$server = new \webservice_mcp\local\transport\server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
$server->run();
die;
