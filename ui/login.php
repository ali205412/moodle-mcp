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
 * UI bridge login: turn a one-time, loopback-bound key minted by session_bridge into a Moodle session.
 *
 * Mirrors admin/tool/mobile/autologin.php, but answers 204 instead of redirecting, does not apply the concurrent
 * login limit (that would log the user out of their own browser), and only accepts keys minted by the bridge.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../../config.php');

use webservice_mcp\local\ui\session_bridge;

$userid = required_param('userid', PARAM_INT);
$key = required_param('key', PARAM_ALPHANUMEXT);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/webservice/mcp/ui/login.php'));
header('Cache-Control: no-store');

// Never replace an existing session: the bridge always calls this without cookies.
if (isloggedin() && !isguestuser()) {
    http_response_code(409);
    exit;
}

try {
    $login = session_bridge::redeem_login_key($userid, $key);
    $user = get_complete_user_data('id', $login->user->id);
    if (!$user) {
        throw new moodle_exception('cannotfinduser', '', '', $login->user->id);
    }
} catch (\Throwable $exception) {
    debugging('MCP UI bridge login refused: ' . $exception->getMessage(), DEBUG_DEVELOPER);
    http_response_code(403);
    exit;
}

complete_user_login($user);
$SESSION->webservice_mcp_ui = $login->family;
http_response_code(204);
