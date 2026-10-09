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
 * Connected apps: list and revoke MCP connector credentials.
 *
 * Users manage their own connections; holders of webservice/mcp:manageconnectors may view any user (?userid=)
 * or every user (?all=1).
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$userid = optional_param('userid', 0, PARAM_INT);
$all = optional_param('all', 0, PARAM_BOOL);
$revoke = optional_param('revoke', '', PARAM_ALPHANUMEXT);
$page = optional_param('page', 0, PARAM_INT);

require_login(0, false);

$systemcontext = context_system::instance();
$isadminview = $all || ($userid && $userid !== (int)$USER->id);
if ($isadminview) {
    require_capability('webservice/mcp:manageconnectors', $systemcontext);
}
$targetuserid = $all ? null : ($userid ?: (int)$USER->id);

$pageurl = new moodle_url('/webservice/mcp/connections.php', array_filter(['userid' => $userid, 'all' => (int)$all]));
$PAGE->set_context($systemcontext);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout($isadminview ? 'admin' : 'standard');
$PAGE->set_title(get_string('connections:heading', 'webservice_mcp'));
$PAGE->set_heading(get_string('connections:heading', 'webservice_mcp'));

$manager = new \webservice_mcp\local\auth\connection_service();

if ($revoke !== '') {
    require_sesskey();
    $manager->revoke_connection($revoke, $targetuserid, (int)$USER->id);
    redirect($pageurl, get_string('connections:revoked', 'webservice_mcp'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$perpage = \webservice_mcp\local\auth\connection_service::per_page();
$total = $manager->count_connections($targetuserid);
$connections = $manager->list_connections($targetuserid, $page * $perpage, $perpage);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('connections:heading', 'webservice_mcp'));
echo html_writer::tag('p', get_string('connections:intro', 'webservice_mcp'));

if (!$connections) {
    echo $OUTPUT->notification(get_string('connections:none', 'webservice_mcp'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('connections:app', 'webservice_mcp'),
    get_string('connections:issuedby', 'webservice_mcp'),
    get_string('connections:created', 'webservice_mcp'),
    get_string('connections:lastused', 'webservice_mcp'),
    get_string('connections:expires', 'webservice_mcp'),
    '',
];
if ($targetuserid === null) {
    array_unshift($table->head, get_string('user'));
}

foreach ($connections as $connection) {
    $button = new single_button(
        new moodle_url($pageurl, ['revoke' => $connection->key]),
        get_string('connections:revoke', 'webservice_mcp'),
        'post'
    );
    $issuer = $connection->issuerid ? core_user::get_user($connection->issuerid) : null;
    $row = [
        s($connection->label),
        $issuer ? fullname($issuer) : '',
        userdate($connection->timecreated),
        $connection->lastaccess ? userdate($connection->lastaccess) : get_string('never'),
        $connection->validuntil ? userdate($connection->validuntil) : get_string('never'),
        $OUTPUT->render($button),
    ];
    if ($targetuserid === null) {
        $user = core_user::get_user($connection->userid);
        array_unshift($row, $user ? html_writer::link(
            new moodle_url('/webservice/mcp/connections.php', ['userid' => $user->id]),
            fullname($user)
        ) : (string)$connection->userid);
    }
    $table->data[] = $row;
}

echo html_writer::table($table);
echo $OUTPUT->paging_bar($total, $page, $perpage, $pageurl);
echo $OUTPUT->footer();
