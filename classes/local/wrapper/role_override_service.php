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
use core_external\external_api;

/**
 * Role permission overrides in a context, as admin/roles/permissions.php shows and changes them.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class role_override_service {
    /** Permission names accepted by set_override. */
    private const PERMISSIONS = [
        'inherit' => CAP_INHERIT,
        'allow' => CAP_ALLOW,
        'prevent' => CAP_PREVENT,
        'prohibit' => CAP_PROHIBIT,
    ];

    /**
     * A role's permissions in a context: the override set here and the value inherited from parent contexts.
     *
     * @param int $contextid Context id (not the system context; base role definitions are edited elsewhere).
     * @param int $roleid Role id.
     * @param string $capability Optional capability name filter (substring).
     * @param bool $overriddenonly Only capabilities overridden in this context.
     * @return array
     */
    public function get_overrides(int $contextid, int $roleid, string $capability = '', bool $overriddenonly = false): array {
        global $DB;

        [$context, $role] = $this->load($contextid, $roleid);
        \require_capability('moodle/role:review', $context);

        $here = $DB->get_records_menu(
            'role_capabilities',
            ['contextid' => $context->id, 'roleid' => $role->id],
            '',
            'capability, permission'
        );
        $inherited = $this->inherited_permissions($context, (int)$role->id);
        $canoverride = \has_capability('moodle/role:override', $context);
        $cansafeoverride = \has_capability('moodle/role:safeoverride', $context);
        $overridable = isset(\get_overridable_roles($context)[$role->id]);

        $capabilities = [];
        foreach ($context->get_capabilities() as $cap) {
            if ($capability !== '' && !str_contains($cap->name, $capability)) {
                continue;
            }
            $override = isset($here[$cap->name]) ? (int)$here[$cap->name] : null;
            if ($overriddenonly && ($override === null || $override === CAP_INHERIT)) {
                continue;
            }
            $capabilities[] = [
                'capability' => $cap->name,
                'override' => $override === null ? 'inherit' : $this->permission_name($override),
                'inherited' => $this->permission_name($inherited[$cap->name] ?? CAP_INHERIT),
                'risks' => $this->risks((int)$cap->riskbitmask),
                'canchange' => $overridable && ($canoverride || ($cansafeoverride && \is_safe_capability($cap))),
            ];
        }

        return [
            'contextid' => (int)$context->id,
            'contextname' => $context->get_context_name(),
            'roleid' => (int)$role->id,
            'rolename' => \role_get_name($role, $context),
            'capabilities' => $capabilities,
        ];
    }

    /**
     * Set one capability override for a role in a context, with the permissions page's rules: the role must be
     * overridable here, and without moodle/role:override only risk-free capabilities may be changed
     * (moodle/role:safeoverride).
     *
     * @param int $contextid Context id.
     * @param int $roleid Role id.
     * @param string $capability Capability name.
     * @param string $permission inherit, allow, prevent or prohibit.
     * @return array
     */
    public function set_override(int $contextid, int $roleid, string $capability, string $permission): array {
        global $DB;

        [$context, $role] = $this->load($contextid, $roleid);
        \require_capability('moodle/role:review', $context);

        $permission = strtolower(trim($permission));
        if (!isset(self::PERMISSIONS[$permission])) {
            throw arguments::invalid("Unknown permission \"{$permission}\"; use inherit, allow, prevent or prohibit.");
        }
        $cap = $DB->get_record('capabilities', ['name' => $capability]);
        if (!$cap) {
            throw arguments::invalid("Unknown capability \"{$capability}\".");
        }
        if (!in_array($cap->name, array_column($context->get_capabilities(), 'name'), true)) {
            throw arguments::invalid("The capability {$cap->name} does not apply to this context.");
        }
        if (!isset(\get_overridable_roles($context)[$role->id])) {
            throw arguments::invalid('You cannot override the permissions of role ' . $role->shortname . ' in this context.');
        }
        if (!\has_capability('moodle/role:override', $context)) {
            if (!\has_capability('moodle/role:safeoverride', $context)) {
                throw new \required_capability_exception($context, 'moodle/role:override', 'nopermissions', '');
            }
            if (!\is_safe_capability($cap)) {
                throw arguments::invalid("{$cap->name} carries risks ("
                    . implode(', ', $this->risks((int)$cap->riskbitmask))
                    . '); changing it needs moodle/role:override, you only have moodle/role:safeoverride.');
            }
        }

        \role_change_permission($role->id, $context, $cap->name, self::PERMISSIONS[$permission]);

        return [
            'contextid' => (int)$context->id,
            'roleid' => (int)$role->id,
            'capability' => $cap->name,
            'override' => $permission,
        ];
    }

    /**
     * Load and validate the context and role.
     *
     * @param int $contextid Context id.
     * @param int $roleid Role id.
     * @return array [context, stdClass role]
     */
    private function load(int $contextid, int $roleid): array {
        global $DB;

        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context) {
            throw arguments::invalid("Context {$contextid} does not exist.");
        }
        if ((int)$context->contextlevel === CONTEXT_SYSTEM) {
            throw new \moodle_exception('cannotoverridebaserole', 'error');
        }
        external_api::validate_context($context);
        $role = $DB->get_record('role', ['id' => $roleid]);
        if (!$role) {
            throw arguments::invalid("Role {$roleid} does not exist.");
        }

        return [$context, $role];
    }

    /**
     * The role's permission for each capability inherited from parent contexts: a prohibit anywhere wins,
     * otherwise the definition closest to this context.
     *
     * @param context $context Context.
     * @param int $roleid Role id.
     * @return array capability => permission
     */
    private function inherited_permissions(context $context, int $roleid): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($context->get_parent_context_ids(), SQL_PARAMS_NAMED);
        $params['roleid'] = $roleid;
        $rows = $DB->get_recordset_sql(
            "SELECT rc.id, rc.capability, rc.permission, ctx.depth
               FROM {role_capabilities} rc
               JOIN {context} ctx ON ctx.id = rc.contextid
              WHERE rc.roleid = :roleid AND rc.contextid {$insql}
           ORDER BY ctx.depth ASC",
            $params
        );
        $permissions = [];
        foreach ($rows as $row) {
            if (($permissions[$row->capability] ?? null) === CAP_PROHIBIT || (int)$row->permission === CAP_INHERIT) {
                continue;
            }
            $permissions[$row->capability] = (int)$row->permission;
        }
        $rows->close();

        return $permissions;
    }

    /**
     * Name of a permission constant.
     *
     * @param int $permission Permission.
     * @return string
     */
    private function permission_name(int $permission): string {
        return array_search($permission, self::PERMISSIONS, true) ?: 'inherit';
    }

    /**
     * Names of the risks in a risk bitmask.
     *
     * @param int $bitmask Risk bitmask.
     * @return string[]
     */
    private function risks(int $bitmask): array {
        $names = [RISK_MANAGETRUST => 'managetrust', RISK_CONFIG => 'config', RISK_XSS => 'xss', RISK_PERSONAL => 'personal',
            RISK_SPAM => 'spam', RISK_DATALOSS => 'dataloss'];
        return array_values(array_filter($names, static fn(int $risk): bool => ($bitmask & $risk) !== 0, ARRAY_FILTER_USE_KEY));
    }
}
