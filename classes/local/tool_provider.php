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

namespace webservice_mcp\local;

use context;
use context_system;
use core_external\external_description;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use webservice_mcp\local\catalog\catalog_builder;
use webservice_mcp\local\catalog\schema_builder;
use webservice_mcp\local\catalog\tool_metadata;
use webservice_mcp\local\catalog\wrapper_registry;
use webservice_mcp\local\discovery\visibility_cache;
use webservice_mcp\local\wrapper\manager as wrapper_manager;

/**
 * Tool provider for MCP protocol.
 *
 * This class provides methods to discover and describe available Moodle
 * external functions as MCP tools. It generates JSON Schema representations
 * of function parameters and return values, making them discoverable to
 * MCP clients.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_provider {
    /** Default tools/list page size. */
    private const DEFAULT_LIMIT = 2000;

    /** Maximum tools/list page size. */
    private const MAX_LIMIT = 2000;

    /** Valid MCP tool names: up to 128 characters from [A-Za-z0-9_-] (SEP-986, Claude API). */
    private const TOOL_NAME_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    /**
     * Retrieve a list of available tools for a given token.
     *
     * This method queries the database for external functions available
     * to the service associated with the provided token, and converts
     * each function's metadata into MCP tool format with JSON Schema
     * descriptions.
     *
     * @param string $token The external service token.
     * @return array Array of tool definitions.
     */
    public static function get_tools(string $token): array {
        return self::list_tools($token)['tools'];
    }

    /**
     * Retrieve a structured tools/list payload for a token.
     *
     * @param string $token External service token.
     * @param array $options Projection options.
     * @return array
     */
    public static function list_tools(string $token, array $options = []): array {
        global $DB;

        $tokenrecord = $DB->get_record('external_tokens', ['token' => $token], '*', MUST_EXIST);
        $options['restrictedcontext'] ??= context::instance_by_id((int)$tokenrecord->contextid);
        $options['connector_mode'] ??= 'external_token';

        return self::list_tools_for_service_ids([(int)$tokenrecord->externalserviceid], $options);
    }

    /**
     * Retrieve a list of tools exposed by the supplied service ids.
     *
     * @param array $serviceids External service ids.
     * @return array
     */
    public static function get_tools_for_service_ids(array $serviceids): array {
        return self::list_tools_for_service_ids($serviceids)['tools'];
    }

    /**
     * Retrieve a structured tools/list payload for the supplied service ids.
     *
     * @param array $serviceids External service ids.
     * @param array $options Projection options.
     * @return array
     */
    public static function list_tools_for_service_ids(array $serviceids, array $options = []): array {
        $serviceids = array_values(array_unique(array_map('intval', $serviceids)));
        if ($serviceids === []) {
            return [
                'tools' => [],
                'nextCursor' => null,
                'groups' => [],
                'coverage' => [],
                'catalogVersion' => null,
            ];
        }

        $snapshot = (new catalog_builder())->get_snapshot();
        $group = (string)($options['group'] ?? '');
        $restrictedcontext = $options['restrictedcontext'] ?? context_system::instance();
        $user = self::current_user($options['user'] ?? null);
        $wrappermode = !empty($options['allow_wrappers']);

        // Connector mode lists wrapper tools only, unless the admin opts in to native tools as well: 800+ tools
        // exhaust clients without deferred tool loading. Native functions stay reachable through the
        // wrapper_moodle_api_search/describe/execute gateway either way.
        $visibleentries = [];
        if (!$wrappermode || !empty(get_config('webservice_mcp', 'exposenativetools'))) {
            $visible = (new visibility_cache())->visible_entries(
                $snapshot,
                $serviceids,
                $restrictedcontext,
                $user,
                (string)($options['connector_mode'] ?? 'default')
            );
            $visibleentries = array_values(array_filter($visible, static fn(array $entry): bool =>
                ($group === '' || $entry['domain'] === $group)
                && preg_match(self::TOOL_NAME_PATTERN, (string)$entry['name']) === 1));
            usort($visibleentries, static fn(array $left, array $right): int =>
                [$left['domain'], $left['name']] <=> [$right['domain'], $right['name']]);
        }

        $groups = self::groups_for_entries($visibleentries, $snapshot['coverage']);
        $alltools = array_map([self::class, 'project_tool'], $visibleentries);

        if ($wrappermode && ($group === '' || $group === 'operator')) {
            $wrappertools = array_map(
                [self::class, 'project_wrapper_tool'],
                (new wrapper_manager())->describe_discoverable($restrictedcontext, $user)
            );
            if ($wrappertools !== []) {
                $groups[] = [
                    'id' => 'operator',
                    'label' => 'Operator',
                    'count' => count($wrappertools),
                ];
            }
            // Wrappers first so the search/describe/execute gateway is never paginated away.
            $alltools = array_merge($wrappertools, $alltools);
        }

        $offset = self::cursor_offset($options['cursor'] ?? null);
        $limit = self::limit($options['limit'] ?? null);
        $visibletools = array_slice($alltools, $offset, $limit);
        $nextcursor = ($offset + $limit) < count($alltools) ? (string)($offset + $limit) : null;

        return [
            'tools' => $visibletools,
            'nextCursor' => $nextcursor,
            'groups' => $groups,
            'coverage' => self::coverage_for_group($snapshot['coverage'], $visibleentries, $group),
            'catalogVersion' => $snapshot['signature'] ?? null,
        ];
    }

    /**
     * Project one normalized catalog entry into an MCP tool definition.
     *
     * @param array $entry Snapshot entry.
     * @return array
     */
    private static function project_tool(array $entry): array {
        $surface = tool_metadata::surface($entry);
        $workflow = (new wrapper_registry())->for_tool($entry['name']);
        $execution = tool_metadata::execution($entry);

        return [
            'name' => $entry['name'],
            'description' => $entry['description'],
            'inputSchema' => $entry['inputSchema'],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'result' => $entry['outputSchema'],
                ],
            ],
            'annotations' => self::native_annotations($entry),
            'x-moodle' => [
                'component' => $entry['component'],
                'domain' => $entry['domain'],
                'mutability' => $entry['mutability'],
                'capabilities' => $entry['capabilities'],
                'provenance' => $entry['provenance'],
                'transport' => $entry['transport'],
                'eligibility' => $entry['eligibility'] ?? [],
                'likelyPermitted' => (bool)($entry['eligibility']['likelyPermitted'] ?? true),
                'risk' => $entry['risk'] ?? [],
                'surface' => $surface,
                'workflow' => $workflow,
                'execution' => $execution,
                'services' => array_map(
                    static fn(array $service): array => [
                        'id' => $service['id'],
                        'shortname' => $service['shortname'],
                        'enabled' => $service['enabled'],
                    ],
                    $entry['services']
                ),
            ],
        ];
    }

    /**
     * MCP annotations for a native function: read-only and idempotent when declared type "read"; destructive
     * when a write function's name or capabilities (RISK_DATALOSS) indicate it removes data.
     *
     * @param array $entry Visible catalog entry.
     * @return array
     */
    private static function native_annotations(array $entry): array {
        $readonly = ($entry['mutability'] ?? 'write') === 'read';

        return [
            'readOnlyHint' => $readonly,
            'destructiveHint' => !$readonly && (!empty($entry['annotations']['destructiveHint'])
                || in_array('data_loss', $entry['risk']['signals'] ?? [], true)),
            'idempotentHint' => $readonly,
            'openWorldHint' => false,
        ];
    }

    /**
     * Project a discoverable wrapper definition into an MCP tool.
     *
     * @param array $definition Wrapper definition.
     * @return array
     */
    private static function project_wrapper_tool(array $definition): array {
        $workflow = (new wrapper_registry())->for_tool($definition['name']);
        $surface = tool_metadata::wrapper_surface($definition);
        $annotations = $definition['annotations'];
        $readonly = !empty($annotations['readOnlyHint']);
        $destructive = !empty($annotations['destructiveHint']);

        $tool = [
            'name' => $definition['name'],
            'description' => $definition['description'],
            'inputSchema' => $definition['inputSchema'],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'result' => $definition['outputSchema'],
                ],
            ],
            'annotations' => $annotations,
            'x-moodle' => [
                'component' => $definition['component'],
                'domain' => $definition['domain'],
                'mutability' => $readonly ? 'read' : 'write',
                'capabilities' => $definition['requiredCapabilities'],
                'provenance' => [
                    'source' => 'wrapper',
                    'classname' => '',
                    'methodname' => '',
                    'classpath' => '',
                ],
                'transport' => [
                    'allowedfromajax' => false,
                    'loginrequired' => true,
                    'readonlysession' => $readonly,
                ],
                'eligibility' => [
                    'status' => 'visible',
                    'connectorMode' => 'connector',
                    'callTimeChecks' => ['context', 'capability'],
                    'resolvedCapabilities' => $definition['requiredCapabilities'],
                    'deferredCapabilities' => [],
                    'accessInformationTools' => [],
                ],
                'risk' => [
                    'level' => $readonly ? 'low' : 'high',
                    'confirmationRequired' => !$readonly,
                    'signals' => array_values(array_filter([
                        'wrapper',
                        $surface['area'],
                        $destructive ? 'destructive_operation' : null,
                    ])),
                    'destructive' => $destructive,
                    'capabilities' => array_map(
                        static fn(string $capability): array => ['name' => $capability],
                        $definition['requiredCapabilities']
                    ),
                ],
                'surface' => $surface,
                'workflow' => $workflow,
                'execution' => [
                    'mode' => 'sync',
                    'followupTools' => [],
                    'notes' => [],
                ],
                'wrapper' => [
                    'name' => $definition['name'],
                    'domain' => $definition['domain'],
                ],
                'services' => [],
            ],
        ];
        if (($definition['title'] ?? '') !== '') {
            $tool['title'] = $definition['title'];
        }

        return $tool;
    }

    /**
     * Build structured group metadata for the current entry slice.
     *
     * @param array $entries Filtered snapshot entries.
     * @param array $coverage Site-wide coverage summary.
     * @return array
     */
    private static function groups_for_entries(array $entries, array $coverage): array {
        $groups = [];
        foreach ($entries as $entry) {
            $domain = $entry['domain'];
            if (!isset($groups[$domain])) {
                $groups[$domain] = [
                    'id' => $domain,
                    'label' => $coverage[$domain]['label'] ?? ucfirst($domain),
                    'count' => 0,
                ];
            }

            $groups[$domain]['count']++;
        }

        ksort($groups);
        return array_values($groups);
    }

    /**
     * Return coverage metadata, optionally filtered to one domain.
     *
     * @param array $coverage Coverage summary keyed by domain.
     * @param array $visibleentries Visible entries after filtering.
     * @param string $group Optional domain filter.
     * @return array
     */
    private static function coverage_for_group(array $coverage, array $visibleentries, string $group = ''): array {
        $visiblecounts = [];
        foreach ($visibleentries as $entry) {
            $domain = $entry['domain'];
            $visiblecounts[$domain] = ($visiblecounts[$domain] ?? 0) + 1;
        }

        $payload = [];
        foreach ($coverage as $domain => $bucket) {
            if ($group !== '' && $domain !== $group) {
                continue;
            }

            $bucket['visibleTools'] = $visiblecounts[$domain] ?? 0;
            $payload[] = $bucket;
        }

        return $payload;
    }

    /**
     * Resolve the current user used for eligibility checks.
     *
     * @param mixed $user Optional explicit user object.
     * @return object|null
     */
    private static function current_user(mixed $user): ?object {
        if (is_object($user) && !empty($user->id)) {
            return $user;
        }

        if (!empty($GLOBALS['USER']) && is_object($GLOBALS['USER']) && !empty($GLOBALS['USER']->id)) {
            return $GLOBALS['USER'];
        }

        return null;
    }

    /**
     * Normalize a cursor value into an offset.
     *
     * @param mixed $cursor Cursor input.
     * @return int
     */
    private static function cursor_offset(mixed $cursor): int {
        if (is_string($cursor) && ctype_digit($cursor)) {
            return (int)$cursor;
        }

        if (is_int($cursor) && $cursor >= 0) {
            return $cursor;
        }

        return 0;
    }

    /**
     * Normalize requested page size.
     *
     * @param mixed $limit Requested limit.
     * @return int
     */
    private static function limit(mixed $limit): int {
        if (!is_int($limit) && !(is_string($limit) && ctype_digit($limit))) {
            return self::DEFAULT_LIMIT;
        }

        $limit = (int)$limit;
        if ($limit <= 0) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }

    /**
     * Build a JSON Schema from an external description object.
     *
     * @param external_description|null $desc The external description.
     * @return array JSON Schema representation.
     */
    protected static function build_schema(?external_description $desc): array {
        return schema_builder::build($desc);
    }

    /**
     * Generate JSON Schema representation of a parameter description.
     *
     * Converts Moodle external API parameter descriptions into JSON Schema
     * format compatible with MCP tool definitions.
     *
     * @param external_description $param The parameter description.
     * @return array JSON Schema representation.
     */
    protected static function generate_schema(external_description $param): array {
        return schema_builder::build($param);
    }

    /**
     * Convert Moodle parameter type to JSON Schema type.
     *
     * @param external_description $param The parameter description.
     * @return string JSON Schema type (string, number, boolean, object, array).
     */
    protected static function get_schema_type(external_description $param): string {
        if ($param instanceof external_value) {
            switch ($param->type) {
                case PARAM_INT:
                    return 'integer';
                case PARAM_FLOAT:
                    return 'number';
                case PARAM_BOOL:
                    return 'boolean';
                default:
                    return 'string';
            }
        }

        if ($param instanceof external_single_structure) {
            return 'object';
        }

        if ($param instanceof external_multiple_structure) {
            return 'array';
        }

        return 'object';
    }
}
