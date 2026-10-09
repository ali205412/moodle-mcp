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
 * Upgrade helpers for the MCP web service plugin.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Hash stored connector tokens and authorization codes in place, exactly once.
 *
 * Plaintext tokens and SHA-256 hex digests look alike, so a second pass would silently lock every user out.
 * The pass therefore runs inside one transaction together with the tokenshashed flag: it either completes and
 * records the flag, or rolls back entirely, and once the flag is set it never runs again.
 *
 * @return bool True when this call hashed the rows, false when they were already hashed.
 */
function webservice_mcp_hash_stored_tokens(): bool {
    global $DB;

    if (get_config('webservice_mcp', 'tokenshashed')) {
        return false;
    }

    $transaction = $DB->start_delegated_transaction();
    foreach (['webservice_mcp_credential' => 'token', 'webservice_mcp_oauth_code' => 'code'] as $tablename => $column) {
        $rs = $DB->get_recordset($tablename, null, 'id ASC', 'id, ' . $column);
        foreach ($rs as $record) {
            $DB->set_field($tablename, $column, hash('sha256', (string)$record->{$column}), ['id' => $record->id]);
        }
        $rs->close();
    }
    set_config('tokenshashed', 1, 'webservice_mcp');
    $transaction->allow_commit();

    return true;
}

/**
 * Create the HMAC secret for signed file tickets and request states if it does not exist yet.
 *
 * @return void
 */
function webservice_mcp_ensure_signing_secret(): void {
    if (strlen((string)get_config('webservice_mcp', 'signingsecret')) < 64) {
        set_config('signingsecret', bin2hex(random_bytes(32)), 'webservice_mcp');
    }
}

/**
 * Add the redirect hosts of every active OAuth client to the redirect-host allowlist.
 *
 * The allowlist is new in 0.9; without this, clients registered earlier with other hosts (for example a
 * self-hosted MCP client) could no longer re-authorize. The defaults are kept and hosts are not duplicated.
 *
 * @return string[] The resulting allowlist.
 */
function webservice_mcp_seed_redirect_hosts(): array {
    global $DB;

    $configured = get_config('webservice_mcp', 'allowedredirecthosts');
    $current = ($configured === false || trim((string)$configured) === '')
        ? \webservice_mcp\local\oauth\client_registry::DEFAULT_REDIRECT_HOSTS
        : (string)$configured;
    $hosts = preg_split('/[\s,]+/', trim($current)) ?: [];
    $known = array_map(static fn(string $host): string => trim(strtolower($host), '[]'), $hosts);

    $rs = $DB->get_recordset('webservice_mcp_oauth_client', ['revoked' => 0], 'id ASC', 'id, redirecturis');
    foreach ($rs as $client) {
        foreach (json_decode((string)$client->redirecturis, true) ?: [] as $uri) {
            $host = strtolower((string)parse_url((string)$uri, PHP_URL_HOST));
            if ($host !== '' && !in_array(trim($host, '[]'), $known, true)) {
                $hosts[] = $host;
                $known[] = trim($host, '[]');
            }
        }
    }
    $rs->close();

    set_config('allowedredirecthosts', implode("\n", $hosts), 'webservice_mcp');
    return $hosts;
}

/**
 * Copy admin-issued key labels from name into the dedicated label column (keeping name), for keys without one.
 *
 * @return void
 */
function webservice_mcp_copy_admin_key_labels(): void {
    global $DB;

    $DB->execute(
        'UPDATE {webservice_mcp_credential} SET label = name WHERE tokentype = :tokentype AND label IS NULL',
        ['tokentype' => 3]
    );
}
