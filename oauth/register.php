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

define('NO_DEBUG_DISPLAY', true);

require('../../../config.php');

use webservice_mcp\local\oauth\exception as oauth_exception;
use webservice_mcp\local\oauth\service as oauth_service;

$oauth = new oauth_service();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Pragma: no-cache');

try {
    if (!$oauth->is_enabled()) {
        throw new oauth_exception('invalid_request', 404, 'OAuth support is disabled for this MCP server.');
    }

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode([
            'error' => 'invalid_request',
            'error_description' => 'The registration endpoint requires POST.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $rawbody = file_get_contents('php://input');
    $payload = json_decode($rawbody ?: '', true);
    if (!is_array($payload)) {
        throw new oauth_exception('invalid_client_metadata', 400, 'The registration body must be valid JSON.');
    }

    $response = $oauth->register_dynamic_client($payload);
    http_response_code(201);
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (oauth_exception $exception) {
    http_response_code($exception->http_status());
    echo json_encode([
        'error' => $exception->oauth_error(),
        'error_description' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
