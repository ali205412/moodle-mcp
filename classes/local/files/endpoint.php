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

namespace webservice_mcp\local\files;

use context;
use core_external\external_api;
use stdClass;
use webservice_mcp\local\transport\origin_validator;

/**
 * HTTP plumbing shared by the ticket download and upload endpoints: CORS, login and JSON errors.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class endpoint {
    /**
     * Apply the transport's Origin allowlist. Answers preflights and rejected origins itself.
     *
     * @param string $methods Allowed methods for preflight.
     * @return bool True when the request should be processed.
     */
    public static function cors(string $methods): bool {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $validator = new origin_validator();
        try {
            $allowed = $validator->is_origin_allowed($origin) ? $validator->get_response_origin($origin) : false;
        } catch (\Throwable $e) {
            $allowed = false;
        }
        if ($allowed === false) {
            self::send_json(403, ['error' => 'Origin not allowed.', 'errorcode' => 'originnotallowed']);
            return false;
        }
        if ($allowed !== null) {
            header('Access-Control-Allow-Origin: ' . $allowed);
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: ' . $methods);
            header('Access-Control-Allow-Headers: Range, If-None-Match, Content-Type, Content-Range, Content-Disposition');
            header('Access-Control-Expose-Headers: Content-Range, Content-Length, Content-Disposition, ETag, Accept-Ranges');
            header('Access-Control-Max-Age: 600');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            return false;
        }
        return true;
    }

    /**
     * Become the ticket's user for this request, mirroring the transport's login.
     *
     * @param stdClass $user User.
     * @param context $restriction Token context restriction.
     * @return void
     */
    public static function login(stdClass $user, context $restriction): void {
        enrol_check_plugins($user, false);
        \core\session\manager::set_user($user);
        set_login_session_preferences();
        external_api::set_context_restriction($restriction);
    }

    /**
     * Send a JSON error for a failure.
     *
     * @param \Throwable $e Failure.
     * @return void
     */
    public static function send_error(\Throwable $e): void {
        $errorcode = $e instanceof \moodle_exception ? (string)$e->errorcode : 'internalerror';
        $body = ['error' => $e->getMessage(), 'errorcode' => $errorcode];
        if ($e instanceof \moodle_exception && !empty($e->debuginfo) && debugging('', DEBUG_DEVELOPER)) {
            $body['debuginfo'] = $e->debuginfo;
        }
        if (!($e instanceof \moodle_exception)) {
            debugging('MCP file endpoint failure: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $body['error'] = 'Internal error.';
        }
        self::send_json(self::status_for($e), $body);
    }

    /**
     * HTTP status for a failure.
     *
     * @param \Throwable $e Failure.
     * @return int
     */
    public static function status_for(\Throwable $e): int {
        if ($e instanceof transfer_exception) {
            return $e->status;
        }
        if (
            $e instanceof \required_capability_exception || $e instanceof \require_login_exception
                || $e instanceof \core_external\restricted_context_exception || $e instanceof \webservice_access_exception
        ) {
            return 403;
        }
        if ($e instanceof \dml_missing_record_exception) {
            return 404;
        }
        if (!($e instanceof \moodle_exception)) {
            return 500;
        }
        $codes = [
            'filenotfound' => 404, 'invalidrecord' => 404, 'nopermissions' => 403, 'noguest' => 403,
            'maxbytesfile' => 413, 'maxbytes' => 413, 'userquotalimit' => 413, 'maxareabytes' => 413,
            'maxdraftitemids' => 429, 'virusfound' => 422, 'virusfounduser' => 422,
        ];
        return $codes[$e->errorcode] ?? 400;
    }

    /**
     * Send a JSON response.
     *
     * @param int $status HTTP status.
     * @param array $body Body.
     * @return void
     */
    public static function send_json(int $status, array $body): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
