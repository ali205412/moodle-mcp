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

/**
 * Definitions of the search/describe/execute gateway over the full Moodle external function catalog.
 *
 * @package     webservice_mcp
 * @copyright   2025 MohammadReza PourMohammad
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class gateway_definitions {
    /**
     * Return the gateway definitions.
     *
     * @return definition[]
     */
    public static function all(): array {
        $fn = ['type' => 'string', 'description' => 'Exact Moodle function name, e.g. "core_course_get_contents".'];

        return [
            builtin_definitions::def(
                'wrapper_moodle_api_search',
                'Search Moodle functions',
                "Search the Moodle web service functions this connector lets the current user call (800+ on a typical "
                    . "site: courses, enrolments, users, grades, assignments, quizzes, forums, messages, calendar, "
                    . "competencies, badges, files...). Matching is case-insensitive over function name, component, "
                    . "description, required capabilities (e.g. \"mod/assign:grade\") and parameter names (e.g. "
                    . "\"cmid\"); every word counts and results matching more words rank first. Filter with component "
                    . "(exact or prefix, e.g. \"mod_forum\" or \"mod_\") and type (read|write); filters alone list all "
                    . "matching functions.\n\n"
                    . "Workflow: 1) search with a few keywords (\"assign grade\", \"forum discussion\", \"enrol user\"); "
                    . "2) call wrapper_moodle_api_describe on the best hits for the exact parameter schema; 3) call "
                    . "wrapper_moodle_api_execute. With limit <= 10 each hit already includes requiredParams and an "
                    . "exampleArgs skeleton, which is often enough to skip describe for simple functions.\n\n"
                    . "Names follow component_area_verb (core_course_get_courses, mod_forum_add_discussion). type=read "
                    . "functions only read; write functions change data. An empty query without filters returns the "
                    . "available domains and components with counts. likelyPermitted=false means the user lacks a "
                    . "capability the function declares (listed in missingCapabilities); such hits rank last but may "
                    . "still work, since Moodle checks the real permission when the function runs.",
                [],
                builtin_definitions::obj([
                    'query' => ['type' => 'string', 'description' => 'Keywords, capability or parameter name.'],
                    'component' => ['type' => 'string', 'description' => 'Component filter, exact or prefix.'],
                    'type' => ['type' => 'string', 'enum' => ['read', 'write']],
                    'limit' => ['type' => 'integer', 'default' => discovery_service::DEFAULT_SEARCH_LIMIT, 'minimum' => 1,
                        'maximum' => discovery_service::MAX_SEARCH_LIMIT],
                ]),
                builtin_definitions::obj([
                    'query' => ['type' => 'string'],
                    'total' => ['type' => 'integer'],
                    'results' => ['type' => 'array', 'items' => builtin_definitions::obj([
                        'name' => ['type' => 'string'],
                        'component' => ['type' => 'string'],
                        'domain' => ['type' => 'string'],
                        'type' => ['type' => 'string', 'enum' => ['read', 'write']],
                        'readOnly' => ['type' => 'boolean'],
                        'description' => ['type' => 'string'],
                        'likelyPermitted' => ['type' => 'boolean'],
                        'missingCapabilities' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'requiredParams' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'exampleArgs' => ['type' => 'object'],
                    ])],
                    'groups' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'hint' => ['type' => 'string'],
                ]),
                builtin_definitions::READ
            ),
            builtin_definitions::def(
                'wrapper_moodle_api_describe',
                'Describe Moodle functions',
                "Get everything needed to call Moodle functions found with wrapper_moodle_api_search: full description, "
                    . "JSON inputSchema, outputSchema, required capabilities, risk level (informational), read/write type, "
                    . "requiredParams and an exampleArgs skeleton containing the required parameters. Pass "
                    . "functionname, or functionnames (up to 20) to describe several at once.\n\n"
                    . "Reading the schema: Moodle parameters are nested objects and lists; ids are integers; booleans "
                    . "may also be sent as 0/1; values in \"default\" are used when omitted. Parameters named like "
                    . "itemid, draftitemid, *_filemanager, attachmentsid or files take a draft file area id returned by "
                    . "the file upload tools. Then call wrapper_moodle_api_execute with functionname and params shaped "
                    . "exactly like inputSchema.",
                [],
                builtin_definitions::obj([
                    'functionname' => $fn,
                    'functionnames' => ['type' => 'array', 'items' => ['type' => 'string'],
                        'maxItems' => discovery_service::MAX_DESCRIBE],
                ]),
                builtin_definitions::obj([
                    'functions' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'unavailable' => ['type' => 'array', 'items' => builtin_definitions::obj([
                        'name' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ])],
                ]),
                builtin_definitions::READ
            ),
            builtin_definitions::def(
                'wrapper_moodle_api_execute',
                'Execute Moodle function',
                "Call any Moodle web service function available to this connector, as the signed-in user and with "
                    . "their real permissions. Find the function with wrapper_moodle_api_search and get its exact "
                    . "parameters from wrapper_moodle_api_describe first; params must match its inputSchema (an object "
                    . "keyed by parameter name, nested exactly as described).\n\n"
                    . "The result is {functionname, type, data} where data is the function's cleaned return value. "
                    . "Errors (missing permission, invalid parameter, restricted context, function not enabled for "
                    . "this connector) are returned as tool errors with Moodle's message; a permission error means "
                    . "the user may not do that, so do not retry with other functions to get around it.\n\n"
                    . "Write functions change Moodle data immediately; confirm with the user before bulk or "
                    . "destructive changes (delete, unenrol, grade overwrite). Draft file item ids from the file "
                    . "upload tools can be passed to any parameter that accepts a draft itemid.",
                [],
                builtin_definitions::obj([
                    'functionname' => $fn,
                    'params' => ['type' => 'object', 'description' => 'Function parameters matching its inputSchema.'],
                ], ['functionname']),
                builtin_definitions::obj([
                    'functionname' => ['type' => 'string'],
                    'type' => ['type' => 'string', 'enum' => ['read', 'write']],
                    'data' => ['description' => 'The function\'s return value; any JSON.'],
                ]),
                ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false]
            ),
        ];
    }
}
