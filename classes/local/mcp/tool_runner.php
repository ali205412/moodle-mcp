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

namespace webservice_mcp\local\mcp;

use core_external\external_api;
use webservice_mcp\local\wrapper\manager as wrapper_manager;

/**
 * Executes a tool outside an HTTP request (MCP Tasks run from cron as the calling user).
 *
 * Mirrors the transport's execution path: wrappers through the wrapper manager, harvested
 * functions through the core web service execute sequence, restricted to the connector service.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_runner {
    /**
     * Run a tool as the current $USER and return its structured payload.
     *
     * @param string $name Tool name.
     * @param array $arguments Arguments.
     * @param call_context $ctx Context rebuilt from the task record.
     * @return array Structured payload ['result' => mixed].
     */
    public static function run(string $name, array $arguments, call_context $ctx): array {
        global $USER;

        external_api::set_context_restriction($ctx->restrictedcontext);

        $wrappers = new wrapper_manager();
        if ($ctx->connector && $wrappers->find($name) !== null) {
            return ['result' => $wrappers->execute($name, $arguments, $ctx->restrictedcontext, $USER, (int)$ctx->serviceid)];
        }

        $function = external_api::external_function_info($name);
        if (!self::function_in_service($function->name, (int)$ctx->serviceid)) {
            throw new \webservice_access_exception("Access to the function {$name}() is not allowed by the connector service.");
        }

        return ['result' => self::call_function($name, $arguments)];
    }

    /**
     * Call an external function as the current $USER, outside call_external_function's
     * session/sesskey checks (which refuse to run in cron and in tokenless contexts).
     *
     * Same sequence as core's webservice_base_server::execute(): validate, plugin overrides,
     * call, clean. Permissions are enforced inside the function itself.
     *
     * @param string $name Function name.
     * @param array $arguments Arguments.
     * @return mixed Cleaned return value.
     */
    public static function call_function(string $name, array $arguments): mixed {
        global $PAGE, $COURSE, $SITE, $CFG;
        require_once($CFG->libdir . '/pagelib.php');

        $function = external_api::external_function_info($name);
        $savedpage = $PAGE;
        $savedcourse = $COURSE;
        // A fresh page per call, as call_external_function does, so each function can set its own context.
        $PAGE = new \moodle_page();
        $COURSE = clone($SITE);
        try {
            $params = array_values(call_user_func(
                [$function->classname, 'validate_parameters'],
                $function->parameters_desc,
                $arguments
            ));

            $result = false;
            foreach (get_plugins_with_function('override_webservice_execution') as $plugins) {
                foreach ($plugins as $callback) {
                    $result = $callback($function, $params);
                    if ($result !== false) {
                        break 2;
                    }
                }
            }
            if ($result === false) {
                $result = call_user_func_array([$function->classname, $function->methodname], $params);
            }

            return $function->returns_desc !== null
                ? external_api::clean_returnvalue($function->returns_desc, $result)
                : $result;
        } finally {
            $PAGE = $savedpage;
            $COURSE = $savedcourse;
        }
    }

    /**
     * Whether a function belongs to the enabled service.
     *
     * @param string $functionname Function name.
     * @param int $serviceid Service id.
     * @return bool
     */
    private static function function_in_service(string $functionname, int $serviceid): bool {
        global $DB;
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {external_services} s
               JOIN {external_services_functions} sf ON sf.externalserviceid = s.id
              WHERE s.id = :sid AND s.enabled = 1 AND sf.functionname = :name",
            ['sid' => $serviceid, 'name' => $functionname]
        );
    }
}
