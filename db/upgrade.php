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

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade function for webservice_mcp.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_webservice_mcp_upgrade(int $oldversion): bool {
    global $DB;

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

            $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
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
            $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
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

    return true;
}
