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
use webservice_mcp\local\catalog\wrapper_registry;
use webservice_mcp\local\discovery\eligibility_resolver;
use webservice_mcp\local\discovery\risk_analyzer;
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
    private const DEFAULT_LIMIT = 100;

    /** Maximum tools/list page size. */
    private const MAX_LIMIT = 250;

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
        $entries = self::entries_for_services($snapshot['entries'], $serviceids, (string)($options['group'] ?? ''));
        $restrictedcontext = $options['restrictedcontext'] ?? context_system::instance();
        $user = self::current_user($options['user'] ?? null);
        $riskanalyzer = new risk_analyzer();
        $eligibilityresolver = new eligibility_resolver();

        $visibleentries = [];
        foreach ($entries as $entry) {
            $risk = $riskanalyzer->analyze($entry);
            $eligibility = $eligibilityresolver->evaluate(
                $entry,
                $restrictedcontext,
                $user,
                $entries,
                [
                    'risk' => $risk,
                    'connector_mode' => $options['connector_mode'] ?? 'default',
                    'site_policy' => $options['site_policy'] ?? [],
                ]
            );

            if (!$eligibility['visible']) {
                continue;
            }

            $entry['risk'] = $risk;
            $entry['eligibility'] = $eligibility;
            $visibleentries[] = $entry;
        }

        $groups = self::groups_for_entries($visibleentries, $snapshot['coverage']);
        $wrapperdefinitions = [];
        $wrappertools = [];
        if (!empty($options['allow_wrappers'])) {
            $wrapperdefinitions = (new wrapper_manager())->describe_discoverable($restrictedcontext, $user);
            $wrappertools = array_values(array_map([self::class, 'project_wrapper_tool'], $wrapperdefinitions));
            if ($wrappertools !== []) {
                $groups[] = [
                    'id' => 'operator',
                    'label' => 'Operator',
                    'count' => count($wrappertools),
                ];
            }
        }

        $alltools = array_values(array_merge(
            array_values(array_map([self::class, 'project_tool'], $visibleentries)),
            $wrappertools,
        ));

        $offset = self::cursor_offset($options['cursor'] ?? null);
        $limit = self::limit($options['limit'] ?? null);
        $visibletools = array_slice($alltools, $offset, $limit);
        $nextcursor = ($offset + $limit) < count($alltools) ? (string)($offset + $limit) : null;

        return [
            'tools' => $visibletools,
            'nextCursor' => $nextcursor,
            'groups' => $groups,
            'coverage' => self::coverage_for_group(
                $snapshot['coverage'],
                $visibleentries,
                (string)($options['group'] ?? '')
            ),
            'catalogVersion' => $snapshot['signature'] ?? null,
        ];
    }

    /**
     * Filter snapshot entries down to the enabled tools for a service scope.
     *
     * @param array $entries Snapshot entries.
     * @param array $serviceids Enabled service ids for this request.
     * @param string $group Optional domain filter.
     * @return array
     */
    private static function entries_for_services(array $entries, array $serviceids, string $group = ''): array {
        $serviceidindex = array_fill_keys($serviceids, true);
        $filtered = [];

        foreach ($entries as $entry) {
            if ($group !== '' && ($entry['domain'] ?? '') !== $group) {
                continue;
            }

            $enabledserviceids = $entry['enabledserviceids'] ?? [];
            foreach ($enabledserviceids as $serviceid) {
                if (isset($serviceidindex[$serviceid])) {
                    $filtered[] = $entry;
                    break;
                }
            }
        }

        usort(
            $filtered,
            static fn(array $left, array $right): int =>
                [$left['domain'], $left['name']] <=> [$right['domain'], $right['name']]
        );

        return $filtered;
    }

    /**
     * Project one normalized catalog entry into an MCP tool definition.
     *
     * @param array $entry Snapshot entry.
     * @return array
     */
    private static function project_tool(array $entry): array {
        $surface = self::surface_metadata($entry);
        $workflow = (new wrapper_registry())->for_tool($entry['name']);
        $execution = self::execution_metadata($entry);

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
            'annotations' => $entry['annotations'],
            'x-moodle' => [
                'component' => $entry['component'],
                'domain' => $entry['domain'],
                'mutability' => $entry['mutability'],
                'capabilities' => $entry['capabilities'],
                'provenance' => $entry['provenance'],
                'transport' => $entry['transport'],
                'eligibility' => $entry['eligibility'] ?? [],
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
     * Project a discoverable wrapper definition into an MCP tool.
     *
     * @param array $definition Wrapper definition.
     * @return array
     */
    private static function project_wrapper_tool(array $definition): array {
        $workflow = (new wrapper_registry())->for_tool($definition['name']);
        $surface = self::wrapper_surface_metadata($definition);
        $destructive = in_array($definition['name'], [
            'wrapper_course_delete_sections',
            'wrapper_course_delete_modules',
            'wrapper_question_delete_category',
            'wrapper_question_delete_questions',
            'wrapper_gradebook_delete_items',
            'wrapper_gradebook_delete_categories',
            'wrapper_badge_delete_badges',
            'wrapper_badge_delete_related_badges',
            'wrapper_badge_delete_alignments',
            'wrapper_badge_revoke_badge',
        ], true);

        return [
            'name' => $definition['name'],
            'description' => $definition['description'],
            'inputSchema' => $definition['inputSchema'],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'result' => $definition['outputSchema'],
                ],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => $destructive,
                'idempotentHint' => false,
                'openWorldHint' => false,
            ],
            'x-moodle' => [
                'component' => $definition['component'],
                'domain' => $definition['domain'],
                'mutability' => 'write',
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
                    'readonlysession' => false,
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
                    'level' => 'high',
                    'confirmationRequired' => true,
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
    }

    /**
     * Project wrapper-specific surface metadata.
     *
     * @param array $definition Wrapper definition.
     * @return array
     */
    private static function wrapper_surface_metadata(array $definition): array {
        $name = (string)($definition['name'] ?? '');

        return match (true) {
            str_starts_with($name, 'wrapper_question_') => ['surface' => 'operator', 'area' => 'question_bank'],
            str_starts_with($name, 'wrapper_gradebook_') => ['surface' => 'operator', 'area' => 'gradebook'],
            str_starts_with($name, 'wrapper_badge_') => ['surface' => 'operator', 'area' => 'badges'],
            default => ['surface' => 'operator', 'area' => 'authoring'],
        };
    }

    /**
     * Derive curated surface metadata for core learning/personal/file tools.
     *
     * @param array $entry Catalog entry.
     * @return array
     */
    private static function surface_metadata(array $entry): array {
        $name = (string)$entry['name'];
        $component = (string)($entry['component'] ?? '');

        if (in_array(
            $name,
            [
                'core_course_get_categories',
                'core_course_create_categories',
                'core_course_update_categories',
                'core_course_delete_categories',
            ],
            true
        )) {
            return ['surface' => 'operator', 'area' => 'categories'];
        }

        if (in_array(
            $name,
            [
                'core_course_create_courses',
                'core_course_update_courses',
                'core_course_delete_courses',
                'core_course_duplicate_course',
                'core_course_import_course',
            ],
            true
        )) {
            return ['surface' => 'operator', 'area' => 'courses'];
        }

        if (
            str_starts_with($name, 'core_courseformat_') ||
            in_array(
                $name,
                [
                    'core_course_edit_module',
                    'core_course_edit_section',
                    'core_course_delete_modules',
                    'core_course_toggle_activity_recommendation',
                    'core_course_get_activity_chooser_footer',
                    'core_course_get_module',
                ],
                true
            )
        ) {
            return ['surface' => 'operator', 'area' => 'authoring'];
        }

        if (str_starts_with($name, 'core_course_')) {
            return ['surface' => 'learning', 'area' => 'courses'];
        }

        if (str_starts_with($name, 'core_completion_')) {
            return ['surface' => 'learning', 'area' => 'completion'];
        }

        if (str_starts_with($name, 'core_calendar_')) {
            return ['surface' => 'personal', 'area' => 'calendar'];
        }

        if (str_starts_with($name, 'core_badges_')) {
            return ['surface' => 'operator', 'area' => 'badges'];
        }

        if (str_starts_with($name, 'core_message_')) {
            return ['surface' => 'personal', 'area' => 'messaging'];
        }

        if (str_starts_with($name, 'core_notes_')) {
            return ['surface' => 'personal', 'area' => 'notes'];
        }

        if (in_array(
            $name,
            [
                'core_user_get_private_files_info',
                'core_user_prepare_private_files_for_edition',
                'core_user_add_user_private_files',
                'core_user_update_private_files',
            ],
            true
        )) {
            return ['surface' => 'files', 'area' => 'private_files'];
        }

        if (in_array(
            $name,
            [
                'core_user_search_identity',
                'core_user_get_users',
                'core_user_get_users_by_field',
                'core_user_create_users',
                'core_user_update_users',
                'core_user_delete_users',
                'core_user_view_user_list',
            ],
            true
        )) {
            return ['surface' => 'operator', 'area' => 'users'];
        }

        if (str_starts_with($name, 'core_user_')) {
            return ['surface' => 'personal', 'area' => 'profile'];
        }

        if (str_starts_with($name, 'core_files_')) {
            return ['surface' => 'files', 'area' => 'draft_files'];
        }

        if (
            str_starts_with($name, 'core_enrol_') ||
            str_starts_with($name, 'enrol_manual_') ||
            str_starts_with($name, 'enrol_self_')
        ) {
            return ['surface' => 'operator', 'area' => 'enrolments'];
        }

        if (str_starts_with($name, 'core_group_')) {
            return ['surface' => 'operator', 'area' => 'groups'];
        }

        if (str_starts_with($name, 'core_cohort_')) {
            return ['surface' => 'operator', 'area' => 'cohorts'];
        }

        if (str_starts_with($name, 'core_role_')) {
            return ['surface' => 'operator', 'area' => 'roles'];
        }

        if (
            str_starts_with($name, 'core_question_') ||
            str_starts_with($name, 'qbank_')
        ) {
            return ['surface' => 'operator', 'area' => 'question_bank'];
        }

        if (
            str_starts_with($name, 'grade_') ||
            str_starts_with($name, 'gradereport_') ||
            str_starts_with($name, 'gradingform_')
        ) {
            return ['surface' => 'operator', 'area' => 'gradebook'];
        }

        if (str_starts_with($name, 'core_competency_')) {
            return ['surface' => 'operator', 'area' => 'competencies'];
        }

        if (str_starts_with($name, 'tool_dataprivacy_')) {
            return ['surface' => 'operator', 'area' => 'privacy'];
        }

        if (str_starts_with($component, 'mod_')) {
            return ['surface' => 'activity', 'area' => self::activity_area_for_component($component)];
        }

        return ['surface' => 'general', 'area' => $entry['domain']];
    }

    /**
     * Map a module component to a curated activity area label.
     *
     * @param string $component Module component.
     * @return string
     */
    private static function activity_area_for_component(string $component): string {
        return match ($component) {
            'mod_assign' => 'assignments',
            'mod_forum' => 'forums',
            'mod_quiz' => 'quizzes',
            'mod_workshop' => 'workshops',
            'mod_feedback' => 'feedback',
            'mod_chat' => 'chat',
            'mod_glossary' => 'glossary',
            'mod_wiki' => 'wiki',
            'mod_data' => 'database',
            'mod_choice' => 'choice',
            'mod_survey' => 'survey',
            'mod_scorm' => 'scorm',
            'mod_h5pactivity' => 'h5pactivity',
            'mod_bigbluebuttonbn' => 'bigbluebutton',
            'mod_lti' => 'lti',
            default => substr($component, 4),
        };
    }

    /**
     * Derive execution hints for tools that trigger async or long-running work.
     *
     * @param array $entry Catalog entry.
     * @return array
     */
    private static function execution_metadata(array $entry): array {
        $name = (string)$entry['name'];

        if (in_array(
            $name,
            [
                'tool_dataprivacy_create_data_request',
                'tool_dataprivacy_approve_data_request',
                'tool_dataprivacy_bulk_approve_data_requests',
                'tool_dataprivacy_deny_data_request',
                'tool_dataprivacy_bulk_deny_data_requests',
                'tool_dataprivacy_cancel_data_request',
                'tool_dataprivacy_mark_complete',
                'tool_dataprivacy_submit_selected_courses_form',
                'tool_dataprivacy_confirm_contexts_for_deletion',
            ],
            true
        )) {
            return [
                'mode' => 'async_request',
                'followupTools' => [
                    'tool_dataprivacy_get_data_request',
                    'tool_dataprivacy_get_data_requests',
                ],
                'notes' => [
                    'This call updates a privacy-request workflow that may complete after the initial response.',
                ],
            ];
        }

        if (in_array(
            $name,
            [
                'core_course_duplicate_course',
                'core_course_import_course',
                'core_course_delete_courses',
                'core_course_delete_categories',
            ],
            true
        )) {
            return [
                'mode' => 'long_running',
                'followupTools' => [],
                'notes' => [
                    'This call may take noticeably longer than standard tool invocations on large sites.',
                ],
            ];
        }

        return [
            'mode' => 'sync',
            'followupTools' => [],
            'notes' => [],
        ];
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
