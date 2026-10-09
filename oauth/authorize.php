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
 * OAuth authorization endpoint with the user consent screen.
 *
 * @package     webservice_mcp
 * @copyright   2025 MohammadReza PourMohammad
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

require('../../../config.php');

use webservice_mcp\local\oauth\exception as oauth_exception;
use webservice_mcp\local\oauth\service as oauth_service;

$oauth = new oauth_service();
$authorization = $oauth->authorization();

if (!$oauth->is_enabled()) {
    throw new moodle_exception('invalidaccess');
}

$rawparams = [
    'response_type' => optional_param('response_type', '', PARAM_RAW_TRIMMED),
    'client_id' => optional_param('client_id', '', PARAM_RAW_TRIMMED),
    'redirect_uri' => optional_param('redirect_uri', '', PARAM_RAW_TRIMMED),
    'scope' => optional_param('scope', '', PARAM_RAW_TRIMMED),
    'state' => optional_param('state', '', PARAM_RAW),
    'code_challenge' => optional_param('code_challenge', '', PARAM_RAW_TRIMMED),
    'code_challenge_method' => optional_param('code_challenge_method', '', PARAM_RAW_TRIMMED),
    'resource' => optional_param('resource', '', PARAM_RAW_TRIMMED),
    'contextid' => optional_param('contextid', 0, PARAM_INT),
];

// Authenticate before validating so anonymous requests cannot trigger client metadata fetches.
$pageurl = new moodle_url(
    '/webservice/mcp/oauth/authorize.php',
    array_filter($rawparams, static fn($value): bool => $value !== '')
);

if (!isloggedin() || isguestuser()) {
    $SESSION->wantsurl = $pageurl->out(false);
    redirect(get_login_url());
}

require_login(0, false);

try {
    $validated = $authorization->validate_authorization_request($rawparams);
} catch (oauth_exception $exception) {
    $errorredirect = $authorization->authorization_error_redirect($rawparams, $exception);
    if ($errorredirect !== null) {
        redirect($errorredirect);
    }
    http_response_code($exception->http_status());
    throw new moodle_exception('error', 'moodle', '', null, $exception->getMessage());
}

$context = $authorization->require_authorization_context((int)$validated['contextid']);

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('login');
$PAGE->set_cacheable(false);
$PAGE->set_title(get_string('oauth:authorize_heading', 'webservice_mcp'));
$PAGE->set_heading(get_string('oauth:authorize_heading', 'webservice_mcp'));

$deniedparams = array_filter([
    'error' => 'access_denied',
    'error_description' => get_string('oauth:authorize_denied', 'webservice_mcp'),
    'state' => $validated['state'] !== '' ? $validated['state'] : null,
], static fn($value): bool => $value !== null);

if (optional_param('cancel', 0, PARAM_BOOL)) {
    require_sesskey();
    redirect($oauth->build_redirect_uri($validated['redirecturi'], $deniedparams));
}

// An administrator pre-approval replaces the consent screen, but only for a verified client and a navigation
// the user started on this site or typed (Sec-Fetch-Site none/same-origin); anything else shows the consent page.
$preapprovals = new \webservice_mcp\local\auth\preapproval_service();
$preapproved = !optional_param('cancel', 0, PARAM_BOOL)
    && $preapprovals->may_auto_approve($validated['client'], (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''))
    && $preapprovals->find(
        (int)$USER->id,
        (string)parse_url($validated['redirecturi'], PHP_URL_HOST),
        $validated['scope'],
        (int)$context->id
    ) !== null;
if ($preapproved) {
    $preapprovals->record_auto_approval((int)$USER->id, (string)$validated['client']->clientid, (int)$context->id);
}

if ($preapproved || optional_param('approve', 0, PARAM_BOOL)) {
    if (!$preapproved) {
        require_sesskey();
    }
    $code = $authorization->create_authorization_code(
        (int)$USER->id,
        $validated['client'],
        $context,
        $validated['redirecturi'],
        $validated['scope'],
        $validated['resourceuri'],
        $validated['codechallenge'],
        $validated['codechallengemethod']
    );

    redirect($oauth->build_redirect_uri($validated['redirecturi'], array_filter([
        'code' => $code,
        'state' => $validated['state'] !== '' ? $validated['state'] : null,
    ], static fn($value): bool => $value !== null)));
}

$client = $authorization->describe_client($validated['client'], $validated['redirecturi']);
$scopelabels = $oauth->scope_labels();
$scopes = preg_split('/\s+/', trim((string)$validated['scope'])) ?: [];

// Render the header first: it sends Moodle's default X-Frame-Options, which must then be overridden.
$header = $OUTPUT->header();
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
echo $header;

echo $OUTPUT->heading(get_string('oauth:consent_heading', 'webservice_mcp', s($client['name'])));

if ($client['registration'] === 'dynamic') {
    echo $OUTPUT->notification(get_string('oauth:consent_unverified', 'webservice_mcp'), \core\output\notification::NOTIFY_WARNING);
} else if ($client['registration'] === 'metadata') {
    echo html_writer::tag('p', get_string('oauth:consent_clienthost', 'webservice_mcp', s($client['clienthost'])));
}

echo html_writer::div(
    get_string('oauth:consent_redirect', 'webservice_mcp', html_writer::tag('strong', s($client['redirecthost']))),
    'alert alert-info'
);

echo html_writer::tag('p', get_string('oauth:consent_scopes', 'webservice_mcp'));
echo html_writer::alist(array_map(
    static fn(string $scope): string => s($scopelabels[$scope] ?? $scope),
    $scopes
));
echo html_writer::tag('p', get_string('oauth:consent_context', 'webservice_mcp', s($context->get_context_name())));
echo html_writer::tag('p', get_string('oauth:consent_account', 'webservice_mcp', s(fullname($USER))));

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out_omit_querystring()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
foreach ($rawparams as $name => $value) {
    echo html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => $name,
        'value' => (string)$value,
    ]);
}
echo html_writer::tag('button', get_string('oauth:consent_allow', 'webservice_mcp'), [
    'type' => 'submit',
    'name' => 'approve',
    'value' => '1',
    'class' => 'btn btn-primary me-2',
]);
echo html_writer::tag('button', get_string('oauth:authorize_cancel', 'webservice_mcp'), [
    'type' => 'submit',
    'name' => 'cancel',
    'value' => '1',
    'class' => 'btn btn-secondary',
]);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
