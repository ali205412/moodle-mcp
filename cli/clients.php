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
 * CLI for OAuth client administration: pre-register, list, and revoke clients.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use webservice_mcp\local\oauth\client_admin_service;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'register' => false,
    'list' => false,
    'revoke' => false,
    'rotate-secret' => false,
    'name' => '',
    'redirect-uris' => '',
    'confidential' => false,
    'scope' => 'write',
    'type' => '',
    'include-revoked' => false,
    'clientid' => '',
    'as-user' => '',
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

$actions = array_filter(['register', 'list', 'revoke', 'rotate-secret'], static fn($name) => !empty($options[$name]));
if ($options['help'] || count($actions) !== 1) {
    echo "OAuth client administration for the MCP connector.

Exactly one action:
  --register   Pre-register a client: --name=TEXT --redirect-uris=URI,URI [--confidential] [--scope=read|write]
               (a confidential client's secret is printed once)
  --list       List clients [--type=dynamic|metadata|registered] [--include-revoked]
  --revoke     Revoke a client and all its tokens: --clientid=ID
  --rotate-secret  Replace a confidential client's secret: --clientid=ID (printed once; the old secret keeps working
               for the secretrotationoverlap setting, default 0 = stops immediately)
Options: --as-user=USERNAME (default: main admin)

Example:
  php webservice/mcp/cli/clients.php --register --name='Acme agent' --redirect-uris=https://claude.ai/api/mcp/auth_callback
";
    exit(count($actions) === 1 ? 0 : 1);
}

$actor = $options['as-user'] !== ''
    ? core_user::get_user_by_username($options['as-user'], '*', null, MUST_EXIST)
    : get_admin();
\core\session\manager::set_user($actor);
$clients = new client_admin_service();

switch (reset($actions)) {
    case 'register':
        $response = $clients->register([
            'name' => (string)$options['name'],
            'redirecturis' => explode(',', (string)$options['redirect-uris']),
            'confidential' => !empty($options['confidential']),
            'scope' => (string)$options['scope'],
        ], $actor);
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        break;

    case 'list':
        foreach ($clients->list_clients((string)$options['type'], !empty($options['include-revoked'])) as $client) {
            mtrace(sprintf(
                "%-50s %-10s %-8s %-4d %s",
                $client->clientid,
                client_admin_service::registration_type($client),
                $client->revoked ? 'revoked' : 'active',
                $client->activecredentials,
                $client->clientname
            ));
        }
        break;

    case 'rotate-secret':
        echo json_encode([
            'client_id' => (string)$options['clientid'],
            'client_secret' => $clients->rotate_secret((string)$options['clientid'], $actor),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        break;

    case 'revoke':
        if (!$clients->revoke((string)$options['clientid'], $actor)) {
            cli_error('Unknown client ' . $options['clientid']);
        }
        mtrace('Revoked ' . $options['clientid']);
        break;
}
