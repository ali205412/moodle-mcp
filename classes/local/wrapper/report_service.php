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

namespace webservice_mcp\local\wrapper;

use context;
use context_course;
use context_system;
use core_external\external_api;
use stdClass;

/**
 * The logs report (report/log) and the course participation report (report/participation), with their access rules.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_service {
    /** Maximum rows per call. */
    private const MAX_LIMIT = 500;

    /**
     * Log entries as the logs report lists them (report/log:view in the course, or the site for site logs).
     *
     * @param int $courseid Course id; 0 or the site id for site-wide logs.
     * @param array $filters userid, from, to (timestamps), component, eventname, crud (c/r/u/d), edulevel, origin.
     * @param int $limit Rows to return.
     * @param int $offset Rows to skip.
     * @return array
     */
    public function logs(int $courseid, array $filters, int $limit = 50, int $offset = 0): array {
        global $SITE;

        $sitewide = $courseid === 0 || $courseid === (int)$SITE->id;
        $context = $sitewide ? context_system::instance() : context_course::instance($courseid);
        external_api::validate_context($context);
        \require_capability('report/log:view', $context);

        $where = [];
        $params = [];
        if (!$sitewide) {
            $where[] = 'courseid = :courseid';
            $params['courseid'] = $courseid;
            $this->restrict_to_visible_groups($courseid, $context, $where, $params);
        }
        $columns = ['userid' => 'int', 'component' => 'string', 'eventname' => 'string', 'crud' => 'string',
            'edulevel' => 'int', 'origin' => 'string'];
        foreach ($columns as $column => $type) {
            if (isset($filters[$column]) && $filters[$column] !== '') {
                $where[] = "{$column} = :{$column}";
                $params[$column] = $type === 'int' ? (int)$filters[$column] : (string)$filters[$column];
            }
        }
        if (!empty($filters['eventname']) && !str_starts_with((string)$filters['eventname'], '\\')) {
            $params['eventname'] = '\\' . $filters['eventname'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'timecreated >= :timefrom';
            $params['timefrom'] = (int)$filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'timecreated <= :timeto';
            $params['timeto'] = (int)$filters['to'];
        }
        if (!\has_capability('moodle/site:viewanonymousevents', $context)) {
            $where[] = 'anonymous = 0';
        }

        $reader = $this->reader();
        $select = $where === [] ? '1 = 1' : implode(' AND ', $where);
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $events = $reader->get_events_select($select, $params, 'timecreated DESC, id DESC', max(0, $offset), $limit);
        $viewfullnames = \has_capability('moodle/site:viewfullnames', $context);

        $rows = [];
        foreach ($events as $event) {
            $extra = $event->get_logextra();
            $url = $event->get_url();
            $eventcontext = context::instance_by_id((int)$event->contextid, IGNORE_MISSING);
            $rows[] = [
                'time' => (int)$event->timecreated,
                'userid' => (int)$event->userid,
                'user' => $this->user_name((int)$event->userid, $viewfullnames),
                'relateduserid' => $event->relateduserid === null ? null : (int)$event->relateduserid,
                'relateduser' => $event->relateduserid ? $this->user_name((int)$event->relateduserid, $viewfullnames) : null,
                'context' => $eventcontext ? $eventcontext->get_context_name(true) : '',
                'component' => (string)$event->component,
                'eventname' => $event::get_name(),
                'description' => trim(strip_tags((string)$event->get_description())),
                'url' => $url ? $url->out(false) : null,
                'origin' => (string)($extra['origin'] ?? ''),
                'ip' => (string)($extra['ip'] ?? ''),
            ];
        }

        return [
            'total' => (int)$reader->get_events_select_count($select, $params),
            'offset' => max(0, $offset),
            'events' => $rows,
        ];
    }

    /**
     * Participation counts for one activity, as report/participation shows them (report/participation:view).
     *
     * @param int $courseid Course id.
     * @param int $cmid Activity (course module) id.
     * @param int $roleid Role whose members are listed.
     * @param string $action "" for all actions, "view" or "post".
     * @param int $timefrom Count actions after this timestamp.
     * @param int $groupid Optional group.
     * @param int $limit Rows to return.
     * @param int $offset Rows to skip.
     * @return array
     */
    public function participation(
        int $courseid,
        int $cmid,
        int $roleid,
        string $action = '',
        int $timefrom = 0,
        int $groupid = 0,
        int $limit = 100,
        int $offset = 0
    ): array {
        global $DB;
        moodle_lib::load('report/participation/locallib.php');

        $course = \get_course($courseid);
        $context = context_course::instance($course->id);
        external_api::validate_context($context);
        \require_capability('report/participation:view', $context);

        if (!in_array($action, ['', 'view', 'post'], true)) {
            throw arguments::invalid("Unknown action \"{$action}\"; use view, post or leave empty for all.");
        }
        if (!$DB->record_exists('role', ['id' => $roleid])) {
            throw arguments::invalid("Role {$roleid} does not exist.");
        }
        $cm = \get_fast_modinfo($course)->get_cms()[$cmid] ?? null;
        if (!$cm) {
            throw arguments::invalid("Activity {$cmid} is not in course {$courseid}.");
        }
        $logtable = \report_participation_get_log_table_name();
        if ($logtable === '') {
            throw arguments::invalid('No log store that supports the participation report is enabled.');
        }
        $groupid = $this->allowed_group($course, $context, $groupid);

        [$ctxsql, $params] = $DB->get_in_or_equal($context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'ctx');
        [$crudsql, $crudparams] = \report_participation_get_crud_sql($action);
        $params += $crudparams + [
            'roleid' => $roleid,
            'instanceid' => $cmid,
            'timefrom' => $timefrom,
            'edulevel' => \core\event\base::LEVEL_PARTICIPATING,
            'contextlevel' => CONTEXT_MODULE,
        ];
        $groupsql = '';
        if ($groupid > 0) {
            $groupsql = 'JOIN {groups_members} gm ON gm.userid = u.id AND gm.groupid = :groupid';
            $params['groupid'] = $groupid;
        }
        $anonymoussql = \has_capability('moodle/site:viewanonymousevents', $context) ? '' : 'AND l.anonymous = 0';
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;

        $users = $DB->get_records_sql(
            "SELECT u.id, {$namefields}, COUNT(DISTINCT l.timecreated) AS actions
               FROM {user} u
               JOIN (SELECT DISTINCT userid FROM {role_assignments} WHERE contextid {$ctxsql} AND roleid = :roleid) ra
                    ON ra.userid = u.id
               {$groupsql}
          LEFT JOIN {{$logtable}} l ON l.contextinstanceid = :instanceid AND l.timecreated > :timefrom {$crudsql}
                    AND l.edulevel = :edulevel {$anonymoussql} AND l.contextlevel = :contextlevel
                    AND (l.origin = 'web' OR l.origin = 'ws') AND l.userid = u.id
           GROUP BY u.id, {$namefields}
           ORDER BY actions DESC, u.id ASC",
            $params,
            max(0, $offset),
            max(1, min($limit, self::MAX_LIMIT))
        );
        $viewfullnames = \has_capability('moodle/site:viewfullnames', $context);

        return [
            'cmid' => $cmid,
            'activity' => $cm->get_formatted_name(),
            'roleid' => $roleid,
            'action' => $action === '' ? 'all' : $action,
            'users' => array_values(array_map(static fn(stdClass $user): array => [
                'userid' => (int)$user->id,
                'fullname' => \fullname($user, $viewfullnames),
                'actions' => (int)$user->actions,
            ], $users)),
        ];
    }

    /**
     * Limit course logs to the user's groups in separate-groups courses without moodle/site:accessallgroups.
     *
     * @param int $courseid Course id.
     * @param context $context Course context.
     * @param array $where Conditions (by reference).
     * @param array $params Parameters (by reference).
     * @return void
     */
    private function restrict_to_visible_groups(int $courseid, context $context, array &$where, array &$params): void {
        global $DB, $USER;

        $course = \get_course($courseid);
        if ((int)$course->groupmode !== SEPARATEGROUPS || \has_capability('moodle/site:accessallgroups', $context)) {
            return;
        }
        $userids = [(int)$USER->id];
        foreach (\groups_get_all_groups($courseid, $USER->id) as $group) {
            $userids = array_merge($userids, array_keys(\groups_get_members($group->id, 'u.id')));
        }
        [$insql, $inparams] = $DB->get_in_or_equal(array_unique($userids), SQL_PARAMS_NAMED, 'visibleuser');
        $where[] = "userid {$insql}";
        $params += $inparams;
    }

    /**
     * Validate the group filter against separate-groups rules.
     *
     * @param stdClass $course Course.
     * @param context $context Course context.
     * @param int $groupid Requested group, 0 for all.
     * @return int Group to filter by.
     */
    private function allowed_group(stdClass $course, context $context, int $groupid): int {
        global $USER;

        $restricted = (int)$course->groupmode === SEPARATEGROUPS && !\has_capability('moodle/site:accessallgroups', $context);
        $mine = $restricted ? \groups_get_all_groups($course->id, $USER->id) : \groups_get_all_groups($course->id);
        if ($groupid > 0 && !isset($mine[$groupid])) {
            throw arguments::invalid("Group {$groupid} is not in this course or not visible to you.");
        }
        if ($groupid === 0 && $restricted) {
            if ($mine === []) {
                throw arguments::invalid('This course uses separate groups and you are not in any group.');
            }
            return (int)array_key_first($mine);
        }

        return $groupid;
    }

    /**
     * The first enabled SQL log reader.
     *
     * @return \core\log\sql_reader
     */
    private function reader(): \core\log\sql_reader {
        $readers = \get_log_manager()->get_readers('\core\log\sql_reader');
        if ($readers === []) {
            throw arguments::invalid('No log store that can be read is enabled on this site.');
        }

        return reset($readers);
    }

    /**
     * Display name of a user for the report.
     *
     * @param int $userid User id.
     * @param bool $viewfullnames Whether full names may be shown.
     * @return string
     */
    private function user_name(int $userid, bool $viewfullnames): string {
        static $cache = [];
        if ($userid <= 0) {
            return '-';
        }
        if (!isset($cache[$userid])) {
            $user = \core_user::get_user($userid);
            $cache[$userid] = $user ? \fullname($user, $viewfullnames) : '-';
        }

        return $cache[$userid];
    }
}
