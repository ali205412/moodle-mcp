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
 * Bulk MCP access administration: issue per-user keys, pre-approve OAuth, list and revoke keys.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use webservice_mcp\local\auth\admin_key_service;
use webservice_mcp\local\auth\preapproval_service;

$systemcontext = context_system::instance();
$pageurl = new moodle_url('/webservice/mcp/admin/keys.php');

if (has_capability('moodle/site:config', $systemcontext)) {
    admin_externalpage_setup('webservice_mcp_keys');
} else {
    require_login(0, false);
    require_capability(admin_key_service::CAPABILITY, $systemcontext);
    $PAGE->set_context($systemcontext);
    $PAGE->set_url($pageurl);
    $PAGE->set_pagelayout('admin');
    $PAGE->set_title(get_string('adminkeys:heading', 'webservice_mcp'));
    $PAGE->set_heading(get_string('adminkeys:heading', 'webservice_mcp'));
}
$PAGE->set_cacheable(false);
header('Cache-Control: no-store');

$keys = new admin_key_service();
$perpage = \webservice_mcp\local\auth\connection_service::per_page();
$keypage = optional_param('kpage', 0, PARAM_INT);
$preapprovalpage = optional_param('ppage', 0, PARAM_INT);
$preapprovals = new preapproval_service($keys);

// Revoke selected keys, or every key matching the filter.
$filters = [
    'label' => optional_param('filterlabel', '', PARAM_TEXT),
    'issuerid' => optional_param('filterissuerid', 0, PARAM_INT),
    'userid' => optional_param('filteruserid', 0, PARAM_INT),
];
if (optional_param('revoke', '', PARAM_ALPHA) !== '') {
    require_sesskey();
    $ids = optional_param_array('ids', [], PARAM_INT);
    $revokefilters = optional_param('revoke', '', PARAM_ALPHA) === 'all'
        ? ['label' => $filters['label'], 'issuerid' => $filters['issuerid'], 'userids' => array_filter([$filters['userid']])]
        : ['ids' => $ids];
    $count = $keys->revoke_keys($revokefilters, $USER);
    redirect(
        new moodle_url($pageurl, array_filter(['filterlabel' => $filters['label'],
        'filterissuerid' => $filters['issuerid'], 'filteruserid' => $filters['userid']])),
        get_string('adminkeys:revokedcount', 'webservice_mcp', $count),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Users selected in Site administration > Users > Bulk user actions arrive via the session.
$bulkusers = array_map('intval', array_values((array)($SESSION->bulk_users ?? [])));
$form = new \webservice_mcp\form\admin_keys_form($pageurl, ['bulkcount' => count($bulkusers)]);
$results = null;
$csv = '';
$data = $form->get_data();
if ($data) {
    $file = admin_key_service::parse_users_file((string)$form->get_file_content('usersfile'));
    $userids = $keys->resolve_targets([
        'userids' => array_merge($file['userids'], !empty($data->usebulkselection) ? $bulkusers : []),
        'identifiers' => array_merge(admin_key_service::parse_identifiers((string)$data->identifiers), $file['identifiers']),
        'idnumbers' => array_merge(admin_key_service::parse_identifiers((string)$data->idnumbers), $file['idnumbers']),
        'cohortid' => (int)$data->cohortid,
        'courseid' => (int)$data->courseid,
        'roleid' => (int)$data->roleid,
        'allmcpusers' => !empty($data->allmcpusers),
    ]);
    $options = [
        'label' => $data->label,
        'scope' => $data->scope,
        'expiresdays' => (int)$data->expiresdays,
        'contextid' => (int)$data->contextid,
        'failonskip' => !empty($data->failonskip),
        'redirecthosts' => (string)$data->redirecthosts,
    ];
    if ($data->action === 'issue') {
        $results = $keys->issue($userids, $options, $USER);
        $csv = admin_key_service::build_csv($results);
    } else if ($data->action === 'preapprove') {
        $results = $preapprovals->preapprove($userids, $options, $USER);
    } else {
        $count = $preapprovals->unpreapprove($userids, $USER);
        redirect($pageurl, get_string('adminkeys:unpreapprovedcount', 'webservice_mcp', $count));
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('adminkeys:heading', 'webservice_mcp'));

if ($results !== null) {
    if ($csv !== '' && substr_count($csv, "\n") > 1) {
        // Plaintext tokens exist only in this response: the CSV is embedded, never stored.
        echo $OUTPUT->notification(get_string('adminkeys:csvonce', 'webservice_mcp'), \core\output\notification::NOTIFY_WARNING);
        echo html_writer::link(
            'data:text/csv;base64,' . base64_encode($csv),
            get_string('adminkeys:downloadcsv', 'webservice_mcp'),
            ['download' => 'mcp-keys-' . date('Ymd-His') . '.csv', 'class' => 'btn btn-primary mb-3']
        );
    }
    $table = new html_table();
    $table->head = [
        get_string('user'),
        get_string('email'),
        get_string('status'),
        get_string('adminkeys:reason', 'webservice_mcp'),
    ];
    foreach ($results as $row) {
        $table->data[] = [
            s($row->fullname ?: $row->userid),
            s($row->email),
            s($row->status),
            $row->reason !== '' ? get_string('adminkeys:reason_' . $row->reason, 'webservice_mcp') : '',
        ];
    }
    echo html_writer::table($table);
}

$form->display();

// Existing admin-issued keys.
echo $OUTPUT->heading(get_string('adminkeys:existing', 'webservice_mcp'), 3);
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $pageurl->out_omit_querystring(), 'class' => 'mb-2']);
$filterfields = [
    'filterlabel' => 'adminkeys:label',
    'filterissuerid' => 'adminkeys:issuerid',
    'filteruserid' => 'adminkeys:userid',
];
foreach ($filterfields as $name => $string) {
    echo html_writer::label(get_string($string, 'webservice_mcp'), 'id_' . $name, true, ['class' => 'me-1']);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => $name, 'id' => 'id_' . $name,
        'value' => $filters[str_replace('filter', '', $name)] ?: '', 'class' => 'me-2']);
}
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter'), 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

$existing = $keys->list_keys($filters, $keypage * $perpage, $perpage);
$listurl = new moodle_url($pageurl, array_filter([
    'filterlabel' => $filters['label'],
    'filterissuerid' => $filters['issuerid'],
    'filteruserid' => $filters['userid'],
    'ppage' => $preapprovalpage,
]));
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
foreach ($filters as $name => $value) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter' . $name, 'value' => $value]);
}
$table = new html_table();
$table->head = ['', get_string('user'), get_string('adminkeys:label', 'webservice_mcp'),
    get_string('connections:issuedby', 'webservice_mcp'),
    get_string('adminkeys:scope', 'webservice_mcp'), get_string('connections:expires', 'webservice_mcp'),
    get_string('connections:lastused', 'webservice_mcp')];
foreach ($existing as $key) {
    $issuer = $key->issuerid ? core_user::get_user($key->issuerid) : null;
    $table->data[] = [
        html_writer::checkbox('ids[]', $key->id, false),
        s($key->username),
        s($key->name),
        $issuer ? fullname($issuer) : '',
        s($key->scope),
        $key->validuntil ? userdate($key->validuntil) : '',
        $key->lastaccess ? userdate($key->lastaccess) : get_string('never'),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->paging_bar($keys->count_keys($filters), $keypage, $perpage, $listurl, 'kpage');
echo html_writer::tag(
    'button',
    get_string('adminkeys:revokeselected', 'webservice_mcp'),
    ['type' => 'submit', 'name' => 'revoke', 'value' => 'selected', 'class' => 'btn btn-danger me-2']
);
if (array_filter($filters)) {
    echo html_writer::tag(
        'button',
        get_string('adminkeys:revokematching', 'webservice_mcp'),
        ['type' => 'submit', 'name' => 'revoke', 'value' => 'all', 'class' => 'btn btn-outline-danger']
    );
}
echo html_writer::end_tag('form');

// Pre-approvals.
echo $OUTPUT->heading(get_string('adminkeys:preapprovals', 'webservice_mcp'), 3);
$table = new html_table();
$table->head = [get_string('user'), get_string('adminkeys:scope', 'webservice_mcp'),
    get_string('adminkeys:redirecthosts', 'webservice_mcp'), get_string('connections:expires', 'webservice_mcp')];
foreach ($preapprovals->list_preapprovals($preapprovalpage * $perpage, $perpage) as $preapproval) {
    $table->data[] = [
        s($preapproval->username),
        s($preapproval->scope),
        s(str_replace("\n", ', ', $preapproval->redirecthosts)),
        $preapproval->expiry ? userdate($preapproval->expiry) : get_string('never'),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->paging_bar(
    $preapprovals->count_preapprovals(),
    $preapprovalpage,
    $perpage,
    new moodle_url($listurl, ['kpage' => $keypage]),
    'ppage'
);
echo $OUTPUT->footer();
