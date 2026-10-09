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

namespace webservice_mcp\task;

/**
 * Adhoc task running one queued MCP tool call as its owner.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_tool extends \core\task\adhoc_task {
    /**
     * Run the task. Cron has already switched to the task's user (set_userid at queue time).
     *
     * @return void
     */
    public function execute() {
        $data = $this->get_custom_data();
        if (empty($data->taskid)) {
            return;
        }
        try {
            \webservice_mcp\local\mcp\tasks::execute((string)$data->taskid);
        } finally {
            // The restriction is a process-wide static; don't let it leak into the next adhoc task in this cron run.
            \core_external\external_api::set_context_restriction(null);
        }
    }
}
