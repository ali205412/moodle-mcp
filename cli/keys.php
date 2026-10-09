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
 * CLI for bulk MCP access administration: issue/list/revoke admin keys and manage OAuth pre-approvals.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use webservice_mcp\local\auth\admin_key_service;
use webservice_mcp\local\auth\preapproval_service;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'issue' => false,
    'revoke' => false,
    'list' => false,
    'preapprove' => false,
    'unpreapprove' => false,
    'userids' => '',
    'usernames' => '',
    'emails' => '',
    'idnumbers' => '',
    'file' => '',
    'cohort' => 0,
    'course' => 0,
    'role' => 0,
    'all-mcp-users' => false,
    'label' => '',
    'expires-days' => 365,
    'scope' => 'read',
    'contextid' => 0,
    'redirect-hosts' => preapproval_service::DEFAULT_HOSTS,
    'fail-on-skip' => false,
    'issuer' => 0,
    'output' => '',
    'as-user' => '',
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

$actions = array_filter(['issue', 'revoke', 'list', 'preapprove', 'unpreapprove'], static fn($name) => !empty($options[$name]));
if ($options['help'] || count($actions) !== 1) {
    echo "Bulk MCP access administration.

Exactly one action:
  --issue          Issue per-user admin keys (writes a CSV with the tokens; requires --output)
  --list           List active admin-issued keys (filters: --label, --issuer, --userids)
  --revoke         Revoke admin-issued keys (filters: --label, --issuer, --userids)
  --preapprove     Pre-approve OAuth consent for users (--redirect-hosts, --scope, --expires-days)
  --unpreapprove   Remove pre-approvals (selectors, or --issuer for all by that issuer)

Selectors: --userids=1,2 --usernames=a,b --emails=x@y,z@w --idnumbers=E1,E2 --file=users.csv
           --cohort=ID --course=ID [--role=ID] --all-mcp-users
           (--file: CSV with a userid/id, username, email or idnumber header column, else the first column)
Options:   --label=TEXT --expires-days=N --scope=read|write --contextid=ID --fail-on-skip
           --output=path.csv (created with mode 0600) --as-user=USERNAME (default: main admin)

Example:
  php webservice/mcp/cli/keys.php --issue --cohort=12 --label='Spring pilot' --scope=write --output=/root/keys.csv
";
    exit(count($actions) === 1 ? 0 : 1);
}

$actor = $options['as-user'] !== ''
    ? core_user::get_user_by_username($options['as-user'], '*', null, MUST_EXIST)
    : get_admin();
\core\session\manager::set_user($actor);

$split = static fn(string $value): array => array_values(array_filter(array_map('trim', explode(',', $value))));
$keys = new admin_key_service();
$preapprovals = new preapproval_service($keys);
$file = ['userids' => [], 'identifiers' => [], 'idnumbers' => []];
if ($options['file'] !== '') {
    if (!is_readable($options['file'])) {
        cli_error('Cannot read ' . $options['file']);
    }
    $file = admin_key_service::parse_users_file((string)file_get_contents($options['file']));
}
$selector = [
    'userids' => array_merge(array_map('intval', $split((string)$options['userids'])), $file['userids']),
    'identifiers' => array_merge($split((string)$options['usernames']), $split((string)$options['emails']), $file['identifiers']),
    'idnumbers' => array_merge($split((string)$options['idnumbers']), $file['idnumbers']),
    'cohortid' => (int)$options['cohort'],
    'courseid' => (int)$options['course'],
    'roleid' => (int)$options['role'],
    'allmcpusers' => !empty($options['all-mcp-users']),
];
$grant = [
    'label' => (string)$options['label'],
    'scope' => (string)$options['scope'],
    'expiresdays' => (int)$options['expires-days'],
    'contextid' => (int)$options['contextid'],
    'redirecthosts' => (string)$options['redirect-hosts'],
    'failonskip' => !empty($options['fail-on-skip']),
];

/**
 * Print per-user results.
 *
 * @param array $results Result rows.
 * @return void
 */
function webservice_mcp_cli_print_results(array $results): void {
    foreach ($results as $row) {
        mtrace(sprintf("%-8d %-30s %-12s %s", $row->userid, $row->username, $row->status, $row->reason));
    }
}

switch (reset($actions)) {
    case 'issue':
        if ($options['output'] === '') {
            cli_error('--output=path.csv is required: tokens are shown only once.');
        }
        $results = $keys->issue($keys->resolve_targets($selector), $grant, $actor);
        $umask = umask(0077);
        file_put_contents($options['output'], admin_key_service::build_csv($results));
        umask($umask);
        chmod($options['output'], 0600);
        webservice_mcp_cli_print_results($results);
        mtrace('Tokens written to ' . $options['output']);
        break;

    case 'list':
        admin_key_service::require_issuer($actor);
        $userids = $split((string)$options['userids']);
        foreach (
            $keys->list_keys(['label' => $options['label'], 'issuerid' => (int)$options['issuer'],
                'userid' => (int)($userids[0] ?? 0)]) as $key
        ) {
            mtrace(sprintf(
                "%-8d %-30s %-30s %-20s %s",
                $key->id,
                $key->username,
                $key->name,
                $key->scope,
                $key->validuntil ? date('c', (int)$key->validuntil) : '-'
            ));
        }
        break;

    case 'revoke':
        $count = $keys->revoke_keys([
            'label' => (string)$options['label'],
            'issuerid' => (int)$options['issuer'],
            'userids' => array_map('intval', $split((string)$options['userids'])),
        ], $actor);
        mtrace("Revoked {$count} key(s).");
        break;

    case 'preapprove':
        webservice_mcp_cli_print_results($preapprovals->preapprove($keys->resolve_targets($selector), $grant, $actor));
        break;

    case 'unpreapprove':
        $count = $preapprovals->unpreapprove($keys->resolve_targets($selector), $actor, (int)$options['issuer']);
        mtrace("Removed {$count} pre-approval(s).");
        break;
}
