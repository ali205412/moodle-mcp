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
 * Browser bootstrap entrypoint for Moodle MCP connector credentials.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$issuerid = optional_param('issuerid', 0, PARAM_INT);
$format = optional_param('format', 'json', PARAM_ALPHA);

$pageurl = new moodle_url('/webservice/mcp/launch.php', [
    'format' => $format,
]);

if (!empty($issuerid)) {
    $pageurl->param('issuerid', $issuerid);
}

if (!isloggedin() && !empty($issuerid) && is_enabled_auth('oauth2')) {
    $bridge = new \webservice_mcp\local\auth\oauth_bridge();
    $bridge->redirect_to_login($issuerid, $pageurl);
}

require_login(0, false);
core_user::require_active_user($USER);

$PAGE->set_context(context_system::instance());
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('base');

$service = new \webservice_mcp\local\auth\bootstrap_service();

try {
    $credential = $service->issue_bootstrap_for_current_user();
} catch (\required_capability_exception $exception) {
    throw new moodle_exception('launch:denied', 'webservice_mcp', '', null, $exception->getMessage());
}

$payload = $service->build_bootstrap_payload($credential);

if ($service->wants_json_response($format)) {
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('launch:heading', 'webservice_mcp'));
echo $OUTPUT->notification(get_string('launch:success', 'webservice_mcp'), \core\output\notification::NOTIFY_SUCCESS);
echo html_writer::tag('pre', s(json_encode($payload, JSON_PRETTY_PRINT)));
echo $OUTPUT->footer();
