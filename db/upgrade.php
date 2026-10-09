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
 * Upgrade steps for the MCP web service plugin.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade function for webservice_mcp.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_webservice_mcp_upgrade(int $oldversion): bool {
    global $DB;

    require_once(__DIR__ . '/upgradelib.php');

    $dbman = $DB->get_manager();

    if ($oldversion < 2026042103) {
        $table = new xmldb_table('webservice_mcp_credential');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('token', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('serviceidentifier', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('tokentype', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sid', XMLDB_TYPE_CHAR, '128', null, null, null, null);
            $table->add_field('validuntil', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('iprestriction', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('lastaccess', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('revoked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('usermodified_fk', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
            $table->add_key('contextid_fk', XMLDB_KEY_FOREIGN, ['contextid'], 'context', ['id']);
            $table->add_key('uniq_token', XMLDB_KEY_UNIQUE, ['token']);

            $table->add_index('sid_idx', XMLDB_INDEX_NOTUNIQUE, ['sid']);
            $table->add_index('service_ctx_idx', XMLDB_INDEX_NOTUNIQUE, ['serviceidentifier', 'contextid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026042103, 'webservice', 'mcp');
    }

    if ($oldversion < 2026042104) {
        $table = new xmldb_table('webservice_mcp_audit');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('credentialid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('serviceid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('sessionid', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('requestid', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('action', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'request');
            $table->add_field('toolname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('mutating', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('outcome', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'success');
            $table->add_field('detailcode', XMLDB_TYPE_CHAR, '64', null, null, null, null);
            $table->add_field('auditid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('credentialid_fk', XMLDB_KEY_FOREIGN, ['credentialid'], 'webservice_mcp_credential', ['id']);
            $table->add_key('contextid_fk', XMLDB_KEY_FOREIGN, ['contextid'], 'context', ['id']);
            $table->add_key('serviceid_fk', XMLDB_KEY_FOREIGN, ['serviceid'], 'external_services', ['id']);
            $table->add_key('uniq_auditid', XMLDB_KEY_UNIQUE, ['auditid']);

            $table->add_index('timecreated_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
            $table->add_index('action_idx', XMLDB_INDEX_NOTUNIQUE, ['action']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026042104, 'webservice', 'mcp');
    }

    if ($oldversion < 2026042201) {
        $table = new xmldb_table('webservice_mcp_credential');

        $field = new xmldb_field('scope', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'iprestriction');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('resourceuri', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'scope');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('oauthclientid', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'resourceuri');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('oauthclientid_idx', XMLDB_INDEX_NOTUNIQUE, ['oauthclientid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $table = new xmldb_table('webservice_mcp_oauth_client');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('clientid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('clientsecret', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('clientname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('redirecturis', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('scope', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('granttypes', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('responsetypes', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('tokenauthmethod', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'none');
            $table->add_field('isdynamic', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('revoked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('uniq_clientid', XMLDB_KEY_UNIQUE, ['clientid']);

            $table->add_index('revoked_idx', XMLDB_INDEX_NOTUNIQUE, ['revoked']);

            $dbman->create_table($table);
        }

        $table = new xmldb_table('webservice_mcp_oauth_code');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('expiresat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('clientid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('code', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);
            $table->add_field('redirecturi', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('scope', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('resourceuri', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('serviceidentifier', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('codechallenge', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);
            $table->add_field('codechallengemethod', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'S256');
            $table->add_field('used', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('contextid_fk', XMLDB_KEY_FOREIGN, ['contextid'], 'context', ['id']);
            $table->add_key('uniq_code', XMLDB_KEY_UNIQUE, ['code']);

            $table->add_index('clientid_idx', XMLDB_INDEX_NOTUNIQUE, ['clientid']);
            $table->add_index('expiresat_idx', XMLDB_INDEX_NOTUNIQUE, ['expiresat']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026042201, 'webservice', 'mcp');
    }

    if ($oldversion < 2026050101) {
        $table = new xmldb_table('webservice_mcp_memory');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('content', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026050101, 'webservice', 'mcp');
    }

    if ($oldversion < 2026100900) {
        // Tokens and authorization codes are now stored as SHA-256 hashes. This step does nothing else, is
        // transactional, and is guarded by the tokenshashed flag, so a re-run can never hash twice.
        webservice_mcp_hash_stored_tokens();
        upgrade_plugin_savepoint(true, 2026100900, 'webservice', 'mcp');
    }

    if ($oldversion < 2026101000) {
        $table = new xmldb_table('webservice_mcp_credential');

        $field = new xmldb_field('familyid', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'revoked');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('familycreated', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'familyid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('familyid_idx', XMLDB_INDEX_NOTUNIQUE, ['familyid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $field = new xmldb_field('issuerid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'familycreated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $key = new xmldb_key('issuerid_fk', XMLDB_KEY_FOREIGN, ['issuerid'], 'user', ['id']);
        $dbman->add_key($table, $key);

        $table = new xmldb_table('webservice_mcp_preapproval');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('scope', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('redirecthosts', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('issuerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('expiry', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('issuerid_fk', XMLDB_KEY_FOREIGN, ['issuerid'], 'user', ['id']);
            $table->add_key('contextid_fk', XMLDB_KEY_FOREIGN, ['contextid'], 'context', ['id']);

            $dbman->create_table($table);
        }

        $table = new xmldb_table('webservice_mcp_oauth_code');
        $field = new xmldb_field('familyid', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'used');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('webservice_mcp_provision');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('serviceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('itemtype', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
            $table->add_field('itemname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('serviceid_fk', XMLDB_KEY_FOREIGN, ['serviceid'], 'external_services', ['id']);

            $table->add_index('service_item_uix', XMLDB_INDEX_UNIQUE, ['serviceid', 'itemtype', 'itemname']);

            $dbman->create_table($table);
        }

        // The connector service used to carry component=webservice_mcp, which makes core delete it (with every admin
        // user restriction) on each plugin upgrade because the plugin ships no db/services.php. Track ownership by id.
        $shortname = (string)get_config('webservice_mcp', 'connectorserviceidentifier') ?: 'webservice_mcp_connector';
        $service = $DB->get_record('external_services', ['shortname' => $shortname, 'component' => 'webservice_mcp']);
        if ($service) {
            // One-time: file upload/download endpoints honour these flags; admins may turn them off afterwards.
            $DB->update_record('external_services', (object)[
                'id' => $service->id,
                'component' => null,
                'downloadfiles' => 1,
                'uploadfiles' => 1,
            ]);
            set_config('connectorserviceid', $service->id, 'webservice_mcp');
            try {
                (new \webservice_mcp\local\auth\connector_service_manager())->sync_service();
            } catch (\Throwable $exception) {
                debugging('MCP connector service sync failed during upgrade: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            }
        }

        upgrade_plugin_savepoint(true, 2026101000, 'webservice', 'mcp');
    }

    if ($oldversion < 2026101001) {
        // MCP Tasks: asynchronous tool calls executed by cron.
        $table = new xmldb_table('webservice_mcp_task');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('taskid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('familyid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('serviceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('connector', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('toolname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('arguments', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'working');
        $table->add_field('statusmessage', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('result', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('error', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('ttl', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '3600000');
        $table->add_field('cancelrequested', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('claimtoken', XMLDB_TYPE_CHAR, '32', null, null, null, null);
        $table->add_field('timestarted', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('taskid_uix', XMLDB_INDEX_UNIQUE, ['taskid']);
        $table->add_index('timecreated_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026101001, 'webservice', 'mcp');
    }

    if ($oldversion < 2026101003) {
        $table = new xmldb_table('webservice_mcp_credential');
        $field = new xmldb_field('rotatedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'issuerid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('webservice_mcp_jti');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('keyhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('expiresat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('keyhash_uix', XMLDB_INDEX_UNIQUE, ['keyhash']);
        $table->add_index('expiresat_idx', XMLDB_INDEX_NOTUNIQUE, ['expiresat']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026101003, 'webservice', 'mcp');
    }

    if ($oldversion < 2026101004) {
        $table = new xmldb_table('webservice_mcp_oauth_client');
        $field = new xmldb_field('previoussecret', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'clientsecret');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('previoussecretexpires', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'previoussecret');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026101004, 'webservice', 'mcp');
    }

    if ($oldversion < 2026101005) {
        // Create the ticket signing secret up front instead of lazily on concurrent first requests.
        webservice_mcp_ensure_signing_secret();
        upgrade_plugin_savepoint(true, 2026101005, 'webservice', 'mcp');
    }

    if ($oldversion < 2026101006) {
        // Keep every existing client's redirect host authorizable under the new allowlist.
        webservice_mcp_seed_redirect_hosts();
        upgrade_plugin_savepoint(true, 2026101006, 'webservice', 'mcp');
    }

    if ($oldversion < 2026101007) {
        // Registration rate limits move from MUC to a table, so purging caches no longer resets them.
        $table = new xmldb_table('webservice_mcp_ratelimit');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('bucket', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('bucket_time_idx', XMLDB_INDEX_NOTUNIQUE, ['bucket', 'timecreated']);
        $table->add_index('timecreated_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Admin key labels get their own column; existing labels are copied out of name, which stays as it is.
        $table = new xmldb_table('webservice_mcp_credential');
        $field = new xmldb_field('label', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'rotatedat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('label_idx', XMLDB_INDEX_NOTUNIQUE, ['label']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        webservice_mcp_copy_admin_key_labels();

        // The showhighrisktools setting was deprecated and ignored; it is now removed.
        unset_config('showhighrisktools', 'webservice_mcp');

        upgrade_plugin_savepoint(true, 2026101007, 'webservice', 'mcp');
    }

    if ($oldversion < 2026101008) {
        // Short file links (pluginfile.php?t=<linkid>) resolving to a signed ticket.
        $table = new xmldb_table('webservice_mcp_link');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('linkid', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('expiresat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('linkid_uix', XMLDB_INDEX_UNIQUE, ['linkid']);
        $table->add_index('expiresat_idx', XMLDB_INDEX_NOTUNIQUE, ['expiresat']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026101008, 'webservice', 'mcp');
    }

    if ($oldversion < 2026101010) {
        // Audit rows keep the error message, not only its code, so failures can be diagnosed.
        $table = new xmldb_table('webservice_mcp_audit');
        $field = new xmldb_field('detail', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'detailcode');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026101010, 'webservice', 'mcp');
    }

    return true;
}
