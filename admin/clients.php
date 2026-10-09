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
 * OAuth client administration: pre-register clients and list or revoke any client.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use webservice_mcp\local\oauth\client_admin_service;

$systemcontext = context_system::instance();
$type = optional_param('type', '', PARAM_ALPHA);
$includerevoked = optional_param('includerevoked', 0, PARAM_BOOL);
$page = optional_param('page', 0, PARAM_INT);
$pageurl = new moodle_url('/webservice/mcp/admin/clients.php', array_filter([
    'type' => $type,
    'includerevoked' => $includerevoked,
]));

if (has_capability('moodle/site:config', $systemcontext)) {
    admin_externalpage_setup('webservice_mcp_clients', '', null, $pageurl);
} else {
    require_login(0, false);
    require_capability(client_admin_service::CAPABILITY, $systemcontext);
    $PAGE->set_context($systemcontext);
    $PAGE->set_url($pageurl);
    $PAGE->set_pagelayout('admin');
    $PAGE->set_title(get_string('clients:heading', 'webservice_mcp'));
    $PAGE->set_heading(get_string('clients:heading', 'webservice_mcp'));
}
$PAGE->set_cacheable(false);
header('Cache-Control: no-store');

$clients = new client_admin_service();

$revoke = optional_param('revoke', '', PARAM_RAW_TRIMMED);
if ($revoke !== '') {
    require_sesskey();
    $clients->revoke($revoke, $USER);
    redirect($pageurl, get_string('clients:revoked', 'webservice_mcp'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$rotated = null;
$rotate = optional_param('rotate', '', PARAM_RAW_TRIMMED);
if ($rotate !== '') {
    require_sesskey();
    try {
        $rotated = ['client_id' => $rotate, 'client_secret' => $clients->rotate_secret($rotate, $USER)];
    } catch (\webservice_mcp\local\oauth\exception $exception) {
        \core\notification::error(s($exception->getMessage()));
    }
}

$form = new \webservice_mcp\form\client_form($pageurl);
$registered = null;
if ($data = $form->get_data()) {
    try {
        $registered = $clients->register([
            'name' => $data->name,
            'redirecturis' => preg_split('/\s+/', (string)$data->redirecturis) ?: [],
            'confidential' => !empty($data->confidential),
            'scope' => $data->scope,
        ], $USER);
    } catch (\webservice_mcp\local\oauth\exception $exception) {
        \core\notification::error(s($exception->getMessage()));
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('clients:heading', 'webservice_mcp'));

if ($registered !== null) {
    $details = ['client_id' => $registered['client_id']];
    if (!empty($registered['client_secret'])) {
        $details['client_secret'] = $registered['client_secret'];
        echo $OUTPUT->notification(get_string('clients:secretonce', 'webservice_mcp'), \core\output\notification::NOTIFY_WARNING);
    }
    $details['redirect_uris'] = $registered['redirect_uris'];
    echo html_writer::tag('pre', s(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
}

if ($rotated !== null) {
    echo $OUTPUT->notification(get_string('clients:secretonce', 'webservice_mcp'), \core\output\notification::NOTIFY_WARNING);
    echo html_writer::tag('pre', s(json_encode($rotated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
}

$form->display();

echo $OUTPUT->heading(get_string('clients:existing', 'webservice_mcp'), 3);
$selecturl = new moodle_url('/webservice/mcp/admin/clients.php', array_filter(['includerevoked' => $includerevoked]));
echo $OUTPUT->single_select($selecturl, 'type', [
    '' => get_string('all'),
    'dynamic' => get_string('clients:type_dynamic', 'webservice_mcp'),
    'metadata' => get_string('clients:type_metadata', 'webservice_mcp'),
    'registered' => get_string('clients:type_registered', 'webservice_mcp'),
], $type, null);

$perpage = \webservice_mcp\local\auth\connection_service::per_page();
$table = new html_table();
$table->head = [
    get_string('clients:name', 'webservice_mcp'),
    get_string('clients:clientid', 'webservice_mcp'),
    get_string('clients:type', 'webservice_mcp'),
    get_string('clients:redirecturis', 'webservice_mcp'),
    get_string('clients:activecredentials', 'webservice_mcp'),
    get_string('connections:created', 'webservice_mcp'),
    '',
];
foreach ($clients->list_clients($type, (bool)$includerevoked, $page * $perpage, $perpage) as $client) {
    $hosts = array_unique(array_map(
        static fn($uri): string => (string)parse_url((string)$uri, PHP_URL_HOST),
        json_decode((string)$client->redirecturis, true) ?: []
    ));
    $action = '';
    if (!empty($client->revoked)) {
        $action = get_string('clients:isrevoked', 'webservice_mcp');
    } else {
        $action = $OUTPUT->render(new single_button(
            new moodle_url($pageurl, ['revoke' => $client->clientid, 'page' => $page]),
            get_string('connections:revoke', 'webservice_mcp'),
            'post'
        ));
        if ($client->tokenauthmethod !== 'none') {
            $action .= $OUTPUT->render(new single_button(
                new moodle_url($pageurl, ['rotate' => $client->clientid, 'page' => $page]),
                get_string('clients:rotatesecret', 'webservice_mcp'),
                'post'
            ));
        }
    }
    $table->data[] = [
        s($client->clientname),
        s($client->clientid),
        get_string('clients:type_' . client_admin_service::registration_type($client), 'webservice_mcp'),
        s(implode(', ', $hosts)),
        (int)$client->activecredentials,
        userdate($client->timecreated),
        $action,
    ];
}
echo html_writer::table($table);
echo $OUTPUT->paging_bar($clients->count_clients($type, (bool)$includerevoked), $page, $perpage, $pageurl);
echo html_writer::link(
    new moodle_url($pageurl, ['includerevoked' => $includerevoked ? 0 : 1]),
    get_string($includerevoked ? 'clients:hiderevoked' : 'clients:showrevoked', 'webservice_mcp')
);
echo $OUTPUT->footer();
