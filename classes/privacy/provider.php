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

namespace webservice_mcp\privacy;

use context;
use context_system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the MCP web service plugin.
 *
 * All plugin data belongs to the user at system context: connector credentials, pending authorization
 * codes, the audit trail of MCP requests, and assistant memory. Connector-service provisioning markers are
 * access-control state (like core's external_services_users) and are kept so an admin removal is not undone.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** Tables keyed by user id. */
    private const USER_TABLES = [
        'webservice_mcp_credential',
        'webservice_mcp_oauth_code',
        'webservice_mcp_audit',
        'webservice_mcp_memory',
        'webservice_mcp_preapproval',
        'webservice_mcp_task',
    ];

    /** Tables that record the user as the administrator who issued something to someone else. */
    private const ISSUER_TABLES = [
        'webservice_mcp_credential' => null,
        'webservice_mcp_preapproval' => 0,
    ];

    /**
     * Describe the personal data stored by the plugin.
     *
     * @param collection $collection Metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('webservice_mcp_credential', [
            'userid' => 'privacy:metadata:credential:userid',
            'name' => 'privacy:metadata:credential:name',
            'oauthclientid' => 'privacy:metadata:credential:oauthclientid',
            'issuerid' => 'privacy:metadata:credential:issuerid',
            'scope' => 'privacy:metadata:credential:scope',
            'timecreated' => 'privacy:metadata:credential:timecreated',
            'lastaccess' => 'privacy:metadata:credential:lastaccess',
        ], 'privacy:metadata:credential');
        $collection->add_database_table('webservice_mcp_oauth_code', [
            'userid' => 'privacy:metadata:oauth_code:userid',
            'clientid' => 'privacy:metadata:oauth_code:clientid',
            'timecreated' => 'privacy:metadata:oauth_code:timecreated',
        ], 'privacy:metadata:oauth_code');
        $collection->add_database_table('webservice_mcp_audit', [
            'userid' => 'privacy:metadata:audit:userid',
            'action' => 'privacy:metadata:audit:action',
            'toolname' => 'privacy:metadata:audit:toolname',
            'outcome' => 'privacy:metadata:audit:outcome',
            'timecreated' => 'privacy:metadata:audit:timecreated',
        ], 'privacy:metadata:audit');
        $collection->add_database_table('webservice_mcp_preapproval', [
            'userid' => 'privacy:metadata:preapproval:userid',
            'issuerid' => 'privacy:metadata:preapproval:issuerid',
            'scope' => 'privacy:metadata:preapproval:scope',
            'timecreated' => 'privacy:metadata:preapproval:timecreated',
        ], 'privacy:metadata:preapproval');
        $collection->add_database_table('webservice_mcp_memory', [
            'userid' => 'privacy:metadata:memory:userid',
            'content' => 'privacy:metadata:memory:content',
            'timecreated' => 'privacy:metadata:memory:timecreated',
        ], 'privacy:metadata:memory');
        $collection->add_database_table('webservice_mcp_task', [
            'userid' => 'privacy:metadata:task:userid',
            'toolname' => 'privacy:metadata:task:toolname',
            'arguments' => 'privacy:metadata:task:arguments',
            'result' => 'privacy:metadata:task:result',
            'timecreated' => 'privacy:metadata:task:timecreated',
        ], 'privacy:metadata:task');
        return $collection;
    }

    /**
     * Return the system context when the user has any plugin data.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (self::user_has_data($userid)) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Add every user with plugin data in the system context.
     *
     * @param userlist $userlist User list.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        foreach (self::USER_TABLES as $table) {
            $userlist->add_from_sql('userid', "SELECT userid FROM {{$table}} WHERE userid IS NOT NULL", []);
        }
        foreach (array_keys(self::ISSUER_TABLES) as $table) {
            $userlist->add_from_sql('issuerid', "SELECT issuerid FROM {{$table}} WHERE issuerid > 0", []);
        }
    }

    /**
     * Export the user's plugin data.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (!self::includes_system_context($contextlist->get_contexts())) {
            return;
        }

        $userid = (int)$contextlist->get_user()->id;
        $writer = writer::with_context(context_system::instance());
        $base = [get_string('pluginname', 'webservice_mcp')];

        $credentials = $DB->get_records(
            'webservice_mcp_credential',
            ['userid' => $userid],
            'timecreated ASC',
            'id, name, oauthclientid, scope, contextid, timecreated, lastaccess, validuntil, revoked'
        );
        if ($credentials) {
            $writer->export_data(array_merge($base, [get_string('privacy:path:credentials', 'webservice_mcp')]), (object)[
                'credentials' => array_values(array_map(static fn($record): array => [
                    'name' => $record->name,
                    'client' => $record->oauthclientid,
                    'scope' => $record->scope,
                    'timecreated' => transform::datetime($record->timecreated),
                    'lastaccess' => $record->lastaccess ? transform::datetime($record->lastaccess) : null,
                    'validuntil' => $record->validuntil ? transform::datetime($record->validuntil) : null,
                    'revoked' => transform::yesno($record->revoked),
                ], $credentials)),
            ]);
        }

        $audit = $DB->get_records(
            'webservice_mcp_audit',
            ['userid' => $userid],
            'timecreated ASC',
            'id, action, toolname, mutating, outcome, detailcode, timecreated'
        );
        if ($audit) {
            $writer->export_data(array_merge($base, [get_string('privacy:path:audit', 'webservice_mcp')]), (object)[
                'audit' => array_values(array_map(static fn($record): array => [
                    'action' => $record->action,
                    'tool' => $record->toolname,
                    'mutating' => transform::yesno($record->mutating),
                    'outcome' => $record->outcome,
                    'detail' => $record->detailcode,
                    'timecreated' => transform::datetime($record->timecreated),
                ], $audit)),
            ]);
        }

        $preapprovals = $DB->get_records('webservice_mcp_preapproval', ['userid' => $userid], 'timecreated ASC');
        if ($preapprovals) {
            $writer->export_data(array_merge($base, [get_string('privacy:path:preapprovals', 'webservice_mcp')]), (object)[
                'preapprovals' => array_values(array_map(static fn($record): array => [
                    'scope' => $record->scope,
                    'redirecthosts' => $record->redirecthosts,
                    'expiry' => $record->expiry ? transform::datetime($record->expiry) : null,
                    'timecreated' => transform::datetime($record->timecreated),
                ], $preapprovals)),
            ]);
        }

        $tasks = $DB->get_records('webservice_mcp_task', ['userid' => $userid], 'timecreated ASC');
        if ($tasks) {
            $writer->export_data(array_merge($base, [get_string('privacy:path:tasks', 'webservice_mcp')]), (object)[
                'tasks' => array_values(array_map(static fn($record): array => [
                    'toolname' => $record->toolname,
                    'arguments' => $record->arguments,
                    'status' => $record->status,
                    'result' => $record->result,
                    'timecreated' => transform::datetime($record->timecreated),
                ], $tasks)),
            ]);
        }

        $memory = $DB->get_records('webservice_mcp_memory', ['userid' => $userid], 'timecreated ASC');
        if ($memory) {
            $writer->export_data(array_merge($base, [get_string('privacy:path:memory', 'webservice_mcp')]), (object)[
                'memory' => array_values(array_map(static fn($record): array => [
                    'content' => $record->content,
                    'timecreated' => transform::datetime($record->timecreated),
                    'timemodified' => transform::datetime($record->timemodified),
                ], $memory)),
            ]);
        }
    }

    /**
     * Delete all plugin data in the context (only the system context holds any).
     *
     * @param context $context Context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        if (!$context instanceof \context_system) {
            return;
        }
        foreach (self::USER_TABLES as $table) {
            $DB->delete_records($table);
        }
    }

    /**
     * Delete one user's plugin data.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (self::includes_system_context($contextlist->get_contexts())) {
            self::delete_users([(int)$contextlist->get_user()->id]);
        }
    }

    /**
     * Delete several users' plugin data.
     *
     * @param approved_userlist $userlist Approved users.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        if ($userlist->get_context() instanceof \context_system) {
            self::delete_users(array_map('intval', $userlist->get_userids()));
        }
    }

    /**
     * Delete plugin data of the given users.
     *
     * @param int[] $userids User ids.
     * @return void
     */
    private static function delete_users(array $userids): void {
        global $DB;

        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        foreach (self::USER_TABLES as $table) {
            $DB->delete_records_select($table, "userid {$insql}", $params);
        }
        // Keep what an issuer granted to others, but forget who the issuer was.
        foreach (self::ISSUER_TABLES as $table => $anonymous) {
            $DB->set_field_select($table, 'issuerid', $anonymous, "issuerid {$insql}", $params);
        }
    }

    /**
     * Whether the user has any plugin data.
     *
     * @param int $userid User id.
     * @return bool
     */
    private static function user_has_data(int $userid): bool {
        global $DB;

        foreach (self::USER_TABLES as $table) {
            if ($DB->record_exists($table, ['userid' => $userid])) {
                return true;
            }
        }
        foreach (array_keys(self::ISSUER_TABLES) as $table) {
            if ($DB->record_exists($table, ['issuerid' => $userid])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the context list includes the system context.
     *
     * @param context[] $contexts Contexts.
     * @return bool
     */
    private static function includes_system_context(array $contexts): bool {
        foreach ($contexts as $context) {
            if ($context instanceof \context_system) {
                return true;
            }
        }
        return false;
    }
}
