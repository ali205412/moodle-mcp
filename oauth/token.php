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
 * OAuth token endpoint (authorization_code and refresh_token grants).
 *
 * @package     webservice_mcp
 * @copyright   2025 MohammadReza PourMohammad
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

define('NO_DEBUG_DISPLAY', true);

// Public or client-authenticated endpoint: no browser session.
define('NO_MOODLE_COOKIES', true);
require('../../../config.php');

use webservice_mcp\local\oauth\exception as oauth_exception;
use webservice_mcp\local\oauth\service as oauth_service;

$oauth = new oauth_service();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    if (!$oauth->is_enabled()) {
        throw new oauth_exception('invalid_request', 404, 'OAuth support is disabled for this MCP server.');
    }

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        throw new oauth_exception('invalid_request', 405, 'The token endpoint requires POST.');
    }

    if (empty($_POST)) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (is_array($input)) {
            $_POST = $input;
        }
    }

    [$clientid, $clientsecret] = $oauth->read_client_credentials_from_request();
    $response = $oauth->exchange_token_request($_POST, $clientid, $clientsecret);

    http_response_code(200);
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (oauth_exception $exception) {
    if ($exception->http_status() === 401) {
        header('WWW-Authenticate: Basic realm="Moodle MCP OAuth"');
    }
    http_response_code($exception->http_status());
    echo json_encode([
        'error' => $exception->oauth_error(),
        'error_description' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (\Throwable $exception) {
    // Never let a Moodle error page leak out of a JSON endpoint.
    debugging('MCP OAuth endpoint failure: ' . $exception->getMessage(), DEBUG_DEVELOPER);
    http_response_code(500);
    echo json_encode([
        'error' => 'server_error',
        'error_description' => 'The authorization server encountered an unexpected error.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
