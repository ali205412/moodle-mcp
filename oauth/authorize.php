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

require('../../../config.php');

use webservice_mcp\local\oauth\exception as oauth_exception;
use webservice_mcp\local\oauth\service as oauth_service;

$oauth = new oauth_service();

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

/**
 * Redirect back to the OAuth client when the request is valid enough to do so.
 *
 * @param oauth_service $oauth OAuth helper.
 * @param array $rawparams Raw request params.
 * @param oauth_exception $exception OAuth protocol exception.
 * @return never
 */
function webservice_mcp_redirect_authorize_error(
    oauth_service $oauth,
    array $rawparams,
    oauth_exception $exception
): never {
    $clientid = trim((string)($rawparams['client_id'] ?? ''));
    $redirecturi = trim((string)($rawparams['redirect_uri'] ?? ''));
    $state = $rawparams['state'] ?? null;
    $client = $clientid !== '' ? $oauth->get_client($clientid) : null;

    if ($client !== null && $redirecturi !== '') {
        try {
            $normalizedredirect = oauth_service::normalize_redirect_uri($redirecturi);
            if (in_array($normalizedredirect, $client->redirecturis, true)) {
                redirect($oauth->build_redirect_uri($normalizedredirect, array_filter([
                    'error' => $exception->oauth_error(),
                    'error_description' => $exception->getMessage(),
                    'state' => $state !== '' ? $state : null,
                ], static fn($value): bool => $value !== null)));
            }
        } catch (\Throwable) {
            // Fall through to the Moodle error page.
        }
    }

    http_response_code($exception->http_status());
    throw new moodle_exception('error', 'moodle', '', null, $exception->getMessage());
}

try {
    $validated = $oauth->validate_authorization_request($rawparams);
} catch (oauth_exception $exception) {
    webservice_mcp_redirect_authorize_error($oauth, $rawparams, $exception);
}

$pageurl = new moodle_url('/webservice/mcp/oauth/authorize.php', array_filter($rawparams, static fn($value): bool => $value !== ''));

require_login(0, false);
core_user::require_active_user($USER);

$context = $oauth->require_authorization_context((int)$validated['contextid']);

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('login');
$PAGE->set_cacheable(false);
$PAGE->set_title(get_string('oauth:authorize_heading', 'webservice_mcp'));
$PAGE->set_heading(get_string('oauth:authorize_heading', 'webservice_mcp'));

if (optional_param('cancel', 0, PARAM_BOOL)) {
    require_sesskey();
    redirect($oauth->build_redirect_uri($validated['redirecturi'], array_filter([
        'error' => 'access_denied',
        'error_description' => get_string('oauth:authorize_denied', 'webservice_mcp'),
        'state' => $validated['state'] !== '' ? $validated['state'] : null,
    ], static fn($value): bool => $value !== null)));
}

if (optional_param('approve', 0, PARAM_BOOL)) {
    require_sesskey();
    $code = $oauth->create_authorization_code(
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

$scopelabels = $oauth->scope_labels();
$scopes = preg_split('/\s+/', trim((string)$validated['scope'])) ?: [];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('oauth:authorize_heading', 'webservice_mcp'));
echo html_writer::tag('p', get_string('oauth:authorize_intro', 'webservice_mcp', s((string)$validated['client']->clientname)));
echo html_writer::alist(array_map(
    static fn(string $scope): string => s($scope) . ' - ' . s($scopelabels[$scope] ?? $scope),
    $scopes
));
echo html_writer::tag('p', get_string('oauth:authorize_resource', 'webservice_mcp', s((string)$validated['resourceuri'])));

echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
foreach ($rawparams as $name => $value) {
    echo html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => $name,
        'value' => (string)$value,
    ]);
}
echo html_writer::tag('button', get_string('oauth:authorize_approve', 'webservice_mcp'), [
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
