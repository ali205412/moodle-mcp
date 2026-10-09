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

namespace webservice_mcp;

use action_link;
use context_system;
use moodle_url;

/**
 * Hook and legacy callback implementations.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Bulk user actions offered to users who may issue MCP access for others.
     *
     * @return array Action links keyed by identifier.
     */
    public static function bulk_user_actions(): array {
        if (!has_capability('webservice/mcp:issueforothers', context_system::instance())) {
            return [];
        }

        return [
            'webservice_mcp_keys' => new action_link(
                new moodle_url('/webservice/mcp/admin/keys.php'),
                get_string('adminkeys:bulkaction', 'webservice_mcp')
            ),
        ];
    }

    /**
     * Moodle 4.4+ hook: add the bulk user action.
     *
     * @param \core_user\hook\extend_bulk_user_actions $hook Hook.
     * @return void
     */
    public static function extend_bulk_user_actions(\core_user\hook\extend_bulk_user_actions $hook): void {
        foreach (self::bulk_user_actions() as $identifier => $action) {
            $hook->add_action($identifier, $action, get_string('pluginname', 'webservice_mcp'));
        }
    }
}
