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
use stdClass;
use webservice_mcp\local\catalog\catalog_builder;
use webservice_mcp\local\mcp\tool_runner;
use webservice_mcp\local\discovery\visibility_cache;

/**
 * Search, describe and execute the Moodle external functions a connector user may call.
 *
 * All three operations apply the same visibility rule as tools/list: the function must be enabled in the
 * connector service, not deprecated, and (for login-required functions) called by a logged-in user. Risk and the
 * capabilities a function declares are informational only (likelyPermitted) and never hide or block it. Execution
 * re-checks service membership at call time and always runs through Moodle's own parameter, context and
 * capability checks, which are the real permission boundary.
 *
 * @package     webservice_mcp
 * @copyright   2025 MohammadReza PourMohammad
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discovery_service {
    /** Default number of search hits. */
    public const DEFAULT_SEARCH_LIMIT = 25;

    /** Maximum number of search hits. */
    public const MAX_SEARCH_LIMIT = 100;

    /** Maximum functions per describe call. */
    public const MAX_DESCRIBE = 20;

    /** @var visibility_cache */
    private visibility_cache $visibility;

    /**
     * Constructor.
     *
     * @param visibility_cache|null $visibility Optional visibility cache (for tests).
     */
    public function __construct(?visibility_cache $visibility = null) {
        $this->visibility = $visibility ?? new visibility_cache();
    }

    /**
     * Search callable functions.
     *
     * @param string $query Keywords; empty (without filters) returns a domain/component summary.
     * @param int $limit Maximum hits.
     * @param context $restrictedcontext Restricted context.
     * @param stdClass|null $user Current user.
     * @param int|null $serviceid Connector service id.
     * @param string $component Optional component filter (exact or prefix).
     * @param string $type Optional type filter: read or write.
     * @return array
     */
    public function search_api(
        string $query,
        int $limit,
        context $restrictedcontext,
        ?stdClass $user = null,
        ?int $serviceid = null,
        string $component = '',
        string $type = ''
    ): array {
        global $USER;

        $type = strtolower(trim($type));
        if (!in_array($type, ['', 'read', 'write'], true)) {
            throw arguments::invalid('type must be "read" or "write".');
        }
        $query = trim($query);
        $limit = max(1, min($limit, self::MAX_SEARCH_LIMIT));

        $user ??= $USER;
        $snapshot = (new catalog_builder())->get_snapshot();
        $serviceids = $serviceid === null ? null : [$serviceid];
        $key = $this->visibility->key($snapshot, $serviceids, $restrictedcontext, $user, 'connector');
        $parts = ['search', strtolower($query), $limit, strtolower(trim($component)), $type];

        $cached = $this->visibility->get_derived($key, $parts);
        if (is_array($cached)) {
            return $cached;
        }

        $entries = $this->visibility->visible_entries($snapshot, $serviceids, $restrictedcontext, $user, 'connector');
        $result = api_search::search($entries, $query, $limit, $component, $type);
        $this->visibility->set_derived($key, $parts, $result);

        return $result;
    }

    /**
     * Describe callable functions.
     *
     * @param array $functionnames Function names (at most MAX_DESCRIBE).
     * @param context $restrictedcontext Restricted context.
     * @param stdClass|null $user Current user.
     * @param int|null $serviceid Connector service id.
     * @return array
     */
    public function describe_api(
        array $functionnames,
        context $restrictedcontext,
        ?stdClass $user = null,
        ?int $serviceid = null
    ): array {
        global $USER;

        $functionnames = array_values(array_unique(array_filter(array_map('strval', $functionnames), 'strlen')));
        if ($functionnames === []) {
            throw arguments::invalid('Provide functionname or functionnames.');
        }
        if (count($functionnames) > self::MAX_DESCRIBE) {
            throw arguments::invalid('At most ' . self::MAX_DESCRIBE . ' functions can be described at once.');
        }

        $entries = $this->visible_entries($restrictedcontext, $user ?? $USER, $serviceid);
        $functions = [];
        $unavailable = [];
        foreach ($functionnames as $name) {
            if (!isset($entries[$name])) {
                $unavailable[] = ['name' => $name, 'reason' => 'Not found, or not available to this user and connector.'];
                continue;
            }

            $functions[] = api_search::describe($entries[$name]);
        }

        return ['functions' => $functions, 'unavailable' => $unavailable];
    }

    /**
     * Execute a callable function as the current user.
     *
     * @param string $functionname Function name.
     * @param array $params Function parameters.
     * @param context $restrictedcontext Restricted context.
     * @param stdClass|null $user Current user; must be the session user when given.
     * @param int|null $serviceid Connector service id; when null the function must be in some enabled service.
     * @return array {functionname, type, data}
     */
    public function execute_api(
        string $functionname,
        array $params,
        context $restrictedcontext,
        ?stdClass $user = null,
        ?int $serviceid = null
    ): array {
        global $USER;

        if ($user !== null && (int)$user->id !== (int)$USER->id) {
            throw new \coding_exception('wrapper_moodle_api_execute must run as the authenticated session user.');
        }

        $info = $functionname === '' ? false : external_api::external_function_info($functionname, IGNORE_MISSING);
        if (!$info) {
            throw new \moodle_exception('wrapper:apifunctionunavailable', 'webservice_mcp', '', $functionname);
        }
        if (!empty($info->deprecated)) {
            throw new \moodle_exception('wrapper:apifunctiondeprecated', 'webservice_mcp', '', $functionname);
        }
        if (!$this->in_service($functionname, $serviceid)) {
            throw new \moodle_exception('wrapper:apifunctionunavailable', 'webservice_mcp', '', $functionname);
        }

        // Discovery visibility (login) is checked from the visibility cache; declared capabilities never block, and
        // Moodle's own checks inside the function are authoritative.
        if (!isset($this->visible_entries($restrictedcontext, $user ?? $USER, $serviceid)[$functionname])) {
            throw new \moodle_exception('wrapper:apifunctionhidden', 'webservice_mcp', '', $functionname);
        }

        // Same execute sequence as the web service server (validate, overrides, call, clean) without
        // call_external_function()'s sesskey/session checks, so the gateway behaves identically in HTTP and cron.
        // Context restriction and capability checks happen inside the function via validate_context().
        try {
            $data = tool_runner::call_function($functionname, $params);
        } catch (\Throwable $exception) {
            $ismoodle = $exception instanceof \moodle_exception;
            throw new \moodle_exception('wrapper:apiexecutefailed', 'webservice_mcp', '', (object)[
                'functionname' => $functionname,
                'errorcode' => $ismoodle ? (string)$exception->errorcode : (new \ReflectionClass($exception))->getShortName(),
                // Raw messages of non-Moodle exceptions can leak internals (SQL, paths); show them only to developers.
                'message' => $ismoodle || debugging('', DEBUG_DEVELOPER)
                    ? trim(strip_tags($exception->getMessage()))
                    : 'Internal error',
            ], $ismoodle ? $exception->debuginfo : null);
        }

        return [
            'functionname' => $functionname,
            'type' => ($info->type ?? 'write') === 'read' ? 'read' : 'write',
            'data' => $data,
        ];
    }

    /**
     * Return the declared type (read/write) of an external function, or 'write' when unknown.
     *
     * @param string $functionname Function name.
     * @return string
     */
    public static function function_type(string $functionname): string {
        if ($functionname === '') {
            return 'write';
        }

        $info = external_api::external_function_info($functionname, IGNORE_MISSING);
        return $info && ($info->type ?? '') === 'read' ? 'read' : 'write';
    }

    /**
     * Return the catalog entries visible to the user in this connector, keyed by name.
     *
     * @param context $restrictedcontext Restricted context.
     * @param stdClass|null $user Current user.
     * @param int|null $serviceid Connector service id.
     * @return array
     */
    private function visible_entries(context $restrictedcontext, ?stdClass $user, ?int $serviceid): array {
        return $this->visibility->visible_entries(
            (new catalog_builder())->get_snapshot(),
            $serviceid === null ? null : [$serviceid],
            $restrictedcontext,
            $user,
            'connector'
        );
    }

    /**
     * Check service membership at call time.
     *
     * @param string $functionname Function name.
     * @param int|null $serviceid Connector service id.
     * @return bool
     */
    private function in_service(string $functionname, ?int $serviceid): bool {
        global $DB;

        $sql = "SELECT 1
                  FROM {external_services_functions} sf
                  JOIN {external_services} s ON s.id = sf.externalserviceid
                 WHERE sf.functionname = :functionname AND s.enabled = 1";
        $params = ['functionname' => $functionname];
        if ($serviceid !== null) {
            $sql .= ' AND s.id = :serviceid';
            $params['serviceid'] = $serviceid;
        }

        return $DB->record_exists_sql($sql, $params);
    }
}
