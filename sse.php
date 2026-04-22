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
 * Legacy SSE compatibility entry point for MCP transport.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_DEBUG_DISPLAY', true);
define('WS_SERVER', true);

require('../../config.php');

if (!webservice_protocol_is_enabled('mcp')) {
    header("HTTP/1.0 403 Forbidden");
    debugging(
        'The server died because the web services or the MCP protocol are not enabled',
        DEBUG_DEVELOPER
    );
    die;
}

$controller = new \webservice_mcp\local\transport\sse_controller(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
$controller->run();
die;
