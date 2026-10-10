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

use webservice_mcp\local\wrapper\builtin_definitions as defs;

/**
 * Targeted tools for Moodle UI pages without a web service: activity and section settings, site administration,
 * role overrides, enrolment methods, reports and course reset. Each mirrors its page's own capability checks.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ui_parity_tools {
    /**
     * Definitions of the tools.
     *
     * @return definition[]
     */
    public static function definitions(): array {
        $settings = ['type' => 'object', 'description' => 'Form field name => new value. Booleans may be true/false.'];
        $paging = [
            'limit' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 500],
            'offset' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
        ];
        $selfaccess = ['type' => 'boolean', 'default' => false,
            'description' => 'Confirm acting on the method your own access to the course depends on.'];
        $instance = defs::obj([
            'instanceid' => ['type' => 'integer'],
            'courseid' => ['type' => 'integer'],
            'plugin' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'enabled' => ['type' => 'boolean'],
            'roleid' => ['type' => 'integer'],
        ]);

        return array_merge(self::course_definitions($settings), [
            defs::def(
                'wrapper_admin_search_settings',
                'Search site settings',
                'Search site administration settings by name, label or description, like the admin search page '
                    . '(needs moodle/site:config). Returns each setting\'s name ("plugin/name" or "name"), page '
                    . '(section), current value (passwords masked), default, choices and whether config.php forces it.',
                ['moodle/site:config'],
                defs::obj(['query' => ['type' => 'string', 'minLength' => 2], 'limit' => $paging['limit']], ['query']),
                defs::obj(['query' => ['type' => 'string'], 'total' => ['type' => 'integer'],
                    'settings' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'pages' => ['type' => 'array', 'items' => ['type' => 'object']]]),
                defs::READ
            ),
            defs::def(
                'wrapper_admin_get_settings',
                'Read site settings',
                'Read site administration settings by name ("maxbytes", "enrol_self/defaultenrol") or all settings of '
                    . 'one admin page (section, as in admin/settings.php?section=...). Only pages you can open are read.',
                ['moodle/site:configview'],
                defs::obj(['names' => ['type' => 'array', 'items' => ['type' => 'string']], 'section' => ['type' => 'string']]),
                defs::obj(['settings' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'unknown' => ['type' => 'array', 'items' => ['type' => 'string']]]),
                defs::READ
            ),
            defs::def(
                'wrapper_admin_set_settings',
                'Change site settings',
                'Change site administration settings as saving the admin page does: each value is validated by the '
                    . 'setting itself and logged in the config changes report. Settings on pages you cannot open, and '
                    . 'settings forced in config.php, are refused. Read the setting first to see its type and choices.',
                ['moodle/site:config'],
                defs::obj(['settings' => ['type' => 'object', 'description' => 'Setting name => value.']], ['settings']),
                defs::obj(['saved' => ['type' => 'array', 'items' => ['type' => 'string']], 'errors' => ['type' => 'object']]),
                defs::DESTRUCTIVE
            ),
            defs::def(
                'wrapper_role_get_overrides',
                'Read role permissions in a context',
                'A role\'s permissions in a course, category, activity, block or user context, as the Permissions page '
                    . 'shows: the override set here, the value inherited from above, the capability\'s risks and whether '
                    . 'you may change it. Filter by capability substring or overridden-only.',
                ['moodle/role:review'],
                defs::obj(
                    ['contextid' => defs::id('Context id (not the system context).'), 'roleid' => defs::id('Role id.'),
                    'capability' => ['type' => 'string'], 'overriddenonly' => ['type' => 'boolean', 'default' => false]],
                    ['contextid', 'roleid']
                ),
                defs::obj(['contextid' => ['type' => 'integer'], 'contextname' => ['type' => 'string'],
                    'roleid' => ['type' => 'integer'], 'rolename' => ['type' => 'string'],
                    'capabilities' => ['type' => 'array', 'items' => ['type' => 'object']]]),
                defs::READ
            ),
            defs::def(
                'wrapper_role_set_override',
                'Override a role permission',
                'Set a role\'s permission for one capability in a context (inherit, allow, prevent or prohibit), with '
                    . 'the Permissions page\'s rules: the role must be overridable here, and risky capabilities need '
                    . 'moodle/role:override (moodle/role:safeoverride covers only risk-free ones).',
                ['moodle/role:review'],
                defs::obj(
                    ['contextid' => defs::id('Context id.'), 'roleid' => defs::id('Role id.'),
                    'capability' => ['type' => 'string'],
                    'permission' => ['type' => 'string', 'enum' => ['inherit', 'allow', 'prevent', 'prohibit']]],
                    ['contextid', 'roleid', 'capability', 'permission']
                ),
                defs::obj(['contextid' => ['type' => 'integer'], 'roleid' => ['type' => 'integer'],
                    'capability' => ['type' => 'string'], 'override' => ['type' => 'string']]),
                ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true]
            ),
            defs::def(
                'wrapper_enrol_add_instance',
                'Add an enrolment method',
                'Add an enrolment method (self, guest, cohort, meta...) to a course with the method\'s own settings '
                    . 'form and validation. List existing methods with core_enrol_get_course_enrolment_methods.',
                ['moodle/course:enrolconfig'],
                defs::obj(
                    ['courseid' => defs::id('Course id.'), 'plugin' => ['type' => 'string'], 'settings' => $settings],
                    ['courseid', 'plugin']
                ),
                $instance,
                defs::WRITE
            ),
            defs::def(
                'wrapper_enrol_update_instance',
                'Change an enrolment method',
                'Change an enrolment method\'s settings (name, password, enrolment period, role...) through its settings '
                    . 'form; needs enrol/<plugin>:config.',
                ['moodle/course:enrolreview'],
                defs::obj(
                    ['instanceid' => defs::id('Enrolment method id.'), 'settings' => $settings],
                    ['instanceid', 'settings']
                ),
                $instance,
                defs::IDEMPOTENT_WRITE
            ),
            defs::def(
                'wrapper_enrol_delete_instance',
                'Delete an enrolment method',
                'Delete an enrolment method and unenrol everyone enrolled through it.',
                ['moodle/course:enrolconfig'],
                defs::obj(
                    ['instanceid' => defs::id('Enrolment method id.'), 'confirmselfaccess' => $selfaccess],
                    ['instanceid']
                ),
                defs::obj(['instanceid' => ['type' => 'integer'], 'deleted' => ['type' => 'boolean'],
                    'unenrolledusers' => ['type' => 'integer']]),
                defs::DESTRUCTIVE
            ),
            defs::def(
                'wrapper_enrol_set_instance_status',
                'Enable or disable an enrolment method',
                'Enable or disable an enrolment method; users enrolled through a disabled method lose access.',
                ['moodle/course:enrolconfig'],
                defs::obj(['instanceid' => defs::id('Enrolment method id.'), 'enabled' => ['type' => 'boolean'],
                    'confirmselfaccess' => $selfaccess], ['instanceid', 'enabled']),
                $instance,
                ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true]
            ),
            defs::def(
                'wrapper_report_logs',
                'Read logs',
                'Log entries as the Logs report shows them, newest first: time, user, related user, context, '
                    . 'component, event name, description, URL, origin and IP. courseid 0 means site logs (needs '
                    . 'report/log:view at site level). Filter by userid, from/to timestamps, component, eventname '
                    . '(e.g. \\core\\event\\course_viewed), crud (c, r, u, d), edulevel (0 other, 1 teaching, '
                    . '2 participating) or origin (web, ws, cli, restore).',
                ['report/log:view'],
                defs::obj(['courseid' => defs::id('Course id, or 0 for the site.'), 'userid' => ['type' => 'integer'],
                    'from' => ['type' => 'integer'], 'to' => ['type' => 'integer'], 'component' => ['type' => 'string'],
                    'eventname' => ['type' => 'string'], 'crud' => ['type' => 'string', 'enum' => ['c', 'r', 'u', 'd']],
                    'edulevel' => ['type' => 'integer'], 'origin' => ['type' => 'string']] + $paging, ['courseid']),
                defs::obj(['total' => ['type' => 'integer'], 'offset' => ['type' => 'integer'],
                    'events' => ['type' => 'array', 'items' => ['type' => 'object']]]),
                defs::READ
            ),
            defs::def(
                'wrapper_report_participation',
                'Activity participation',
                'How often each member of a role viewed or posted in one activity, as the Course participation '
                    . 'report shows. action: view, post or empty for all; timefrom limits to recent activity.',
                ['report/participation:view'],
                defs::obj(['courseid' => defs::id('Course id.'), 'cmid' => defs::id('Activity (course module) id.'),
                    'roleid' => defs::id('Role whose members to list, e.g. the student role.'),
                    'action' => ['type' => 'string', 'enum' => ['', 'view', 'post'], 'default' => ''],
                    'timefrom' => ['type' => 'integer', 'default' => 0], 'groupid' => ['type' => 'integer', 'default' => 0],
                ] + $paging, ['courseid', 'cmid', 'roleid']),
                defs::obj(['cmid' => ['type' => 'integer'], 'activity' => ['type' => 'string'], 'roleid' => ['type' => 'integer'],
                    'action' => ['type' => 'string'], 'users' => ['type' => 'array', 'items' => ['type' => 'object']]]),
                defs::READ
            ),
        ]);
    }

    /**
     * Course, activity and section definitions.
     *
     * @param array $settings Settings schema.
     * @return definition[]
     */
    private static function course_definitions(array $settings): array {
        return [
            defs::def(
                'wrapper_course_get_module_settings',
                'Read activity settings',
                'An activity\'s settings exactly as its edit page shows them (same field names and formats), for use '
                    . 'with wrapper_course_update_module. Needs moodle/course:manageactivities.',
                ['moodle/course:manageactivities'],
                defs::obj(['cmid' => defs::id('Course module id.')], ['cmid']),
                defs::obj(['cmid' => ['type' => 'integer'], 'modulename' => ['type' => 'string'], 'name' => ['type' => 'string'],
                    'settings' => ['type' => 'object']]),
                defs::READ
            ),
            defs::def(
                'wrapper_course_update_module',
                'Change activity settings',
                'Change an activity\'s settings as saving its edit page does: the activity\'s own form validates the '
                    . 'merged settings (e.g. dates, grades, completion, availability JSON). Use the field names from '
                    . 'wrapper_course_get_module_settings; editors take {text, format, itemid}.',
                ['moodle/course:manageactivities'],
                defs::obj(['cmid' => defs::id('Course module id.'), 'settings' => $settings], ['cmid', 'settings']),
                defs::obj(['cmid' => ['type' => 'integer'], 'modulename' => ['type' => 'string'], 'name' => ['type' => 'string'],
                    'visible' => ['type' => 'boolean'], 'updated' => ['type' => 'array', 'items' => ['type' => 'string']]]),
                defs::IDEMPOTENT_WRITE
            ),
            defs::def(
                'wrapper_course_update_section',
                'Change section settings',
                'Change a course section as its edit page and the show/hide action do. settings may contain name ("" '
                    . 'for the default name), summary (HTML), summaryformat, availability (restriction JSON), visible, '
                    . 'or course format options. visible needs moodle/course:sectionvisibility.',
                ['moodle/course:update'],
                defs::obj(
                    ['sectionid' => defs::id('Section id (course_sections.id).'), 'settings' => $settings],
                    ['sectionid', 'settings']
                ),
                defs::obj(['sectionid' => ['type' => 'integer'], 'section' => ['type' => 'integer'],
                    'name' => ['type' => 'string'], 'visible' => ['type' => 'boolean'],
                    'availability' => ['type' => ['string', 'null']]]),
                defs::IDEMPOTENT_WRITE
            ),
            defs::def(
                'wrapper_course_reset',
                'Reset course',
                'Reset a course as its Reset page does, deleting the user data chosen in options (e.g. unenrol_users '
                    . '[role ids], reset_gradebook_grades, reset_forum_all, reset_events, reset_start_date timestamp). '
                    . 'usedefaults=true starts from the page\'s default selection. This permanently deletes data; '
                    . 'confirm with the user first.',
                ['moodle/course:reset'],
                defs::obj(['courseid' => defs::id('Course id.'), 'options' => $settings,
                    'usedefaults' => ['type' => 'boolean', 'default' => false]], ['courseid']),
                defs::obj(['courseid' => ['type' => 'integer'], 'results' => ['type' => 'array', 'items' => defs::obj([
                    'component' => ['type' => 'string'], 'item' => ['type' => 'string'], 'error' => ['type' => ['string', 'null']],
                ])]]),
                defs::DESTRUCTIVE
            ),
        ];
    }

    /**
     * Run one of these tools, or return null when the name is not one of them.
     *
     * @param string $name Tool name.
     * @param array $a Arguments.
     * @return array|null
     */
    public static function execute(string $name, array $a): ?array {
        return match ($name) {
            'wrapper_course_get_module_settings' => (new module_settings_service())->get_module_settings(
                arguments::integer($a, 'cmid')
            ),
            'wrapper_course_update_module' => (new module_settings_service())->update_module(
                arguments::integer($a, 'cmid'),
                arguments::values($a, 'settings')
            ),
            'wrapper_course_update_section' => (new module_settings_service())->update_section(
                arguments::integer($a, 'sectionid'),
                arguments::values($a, 'settings')
            ),
            'wrapper_course_reset' => (new course_reset_service())->reset_course(
                arguments::integer($a, 'courseid'),
                arguments::values($a, 'options'), arguments::flag($a, 'usedefaults')
            ),
            'wrapper_admin_search_settings' => (new admin_settings_service())->search_settings(
                arguments::text($a, 'query'),
                arguments::integer($a, 'limit', 50)
            ),
            'wrapper_admin_get_settings' => (new admin_settings_service())->get_settings(
                arguments::values($a, 'names'),
                arguments::text($a, 'section')
            ),
            'wrapper_admin_set_settings' => (new admin_settings_service())->set_settings(arguments::values($a, 'settings')),
            'wrapper_role_get_overrides' => (new role_override_service())->get_overrides(
                arguments::integer($a, 'contextid'),
                arguments::integer($a, 'roleid'), arguments::text($a, 'capability'), arguments::flag($a, 'overriddenonly')
            ),
            'wrapper_role_set_override' => (new role_override_service())->set_override(
                arguments::integer($a, 'contextid'),
                arguments::integer($a, 'roleid'), arguments::text($a, 'capability'), arguments::text($a, 'permission')
            ),
            'wrapper_enrol_add_instance' => (new enrol_instance_service())->add_instance(
                arguments::integer($a, 'courseid'),
                arguments::text($a, 'plugin'), arguments::values($a, 'settings')
            ),
            'wrapper_enrol_update_instance' => (new enrol_instance_service())->update_instance(
                arguments::integer($a, 'instanceid'),
                arguments::values($a, 'settings')
            ),
            'wrapper_enrol_delete_instance' => (new enrol_instance_service())->delete_instance(
                arguments::integer($a, 'instanceid'),
                arguments::flag($a, 'confirmselfaccess')
            ),
            'wrapper_enrol_set_instance_status' => (new enrol_instance_service())->set_status(
                arguments::integer($a, 'instanceid'),
                arguments::flag($a, 'enabled'), arguments::flag($a, 'confirmselfaccess')
            ),
            'wrapper_report_logs' => (new report_service())->logs(arguments::integer($a, 'courseid'), $a,
                arguments::integer($a, 'limit', 50), arguments::integer($a, 'offset')),
            'wrapper_report_participation' => (new report_service())->participation(
                arguments::integer($a, 'courseid'),
                arguments::integer($a, 'cmid'), arguments::integer($a, 'roleid'), arguments::text($a, 'action'),
                arguments::integer($a, 'timefrom'), arguments::integer($a, 'groupid'), arguments::integer($a, 'limit', 100),
                arguments::integer($a, 'offset')
            ),
            default => null,
        };
    }
}
