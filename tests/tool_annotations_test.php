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

namespace webservice_mcp;

use advanced_testcase;
use context_system;
use webservice_mcp\local\mcp\call_context;
use webservice_mcp\local\mcp\dispatcher;

/**
 * Tool annotations as clients see them: read-only tools must be marked so clients can auto-allow them.
 *
 * @package     webservice_mcp
 * @copyright   2025 MohammadReza PourMohammad
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\mcp\dispatcher
 * @covers      \webservice_mcp\local\tool_provider
 * @covers      \webservice_mcp\local\wrapper\builtin_definitions
 * @covers      \webservice_mcp\local\wrapper\gateway_definitions
 * @covers      \webservice_mcp\local\wrapper\arguments
 */
final class tool_annotations_test extends advanced_testcase {
    /** Tools that cannot change anything. */
    private const READ_ONLY = [
        'wrapper_moodle_api_search',
        'wrapper_moodle_api_describe',
        'wrapper_memory_read',
        'wrapper_module_read_data',
        'wrapper_question_preview_question',
        'moodle_explorer',
        'file_list',
        'file_read',
        'file_get_download_url',
        'backup_status',
        'core_webservice_get_site_info',
        'core_course_get_contents',
    ];

    /**
     * Walk the full connector tools/list (wrappers, gateway, file tools, app and native tools).
     *
     * @return array Tools keyed by name.
     */
    private function list_all_tools(): array {
        global $DB;

        $serviceid = (int)$DB->insert_record('external_services', (object)[
            'name' => 'Annotation audit',
            'shortname' => 'annotation_audit',
            'enabled' => 1,
            'restrictedusers' => 0,
            'downloadfiles' => 1,
            'uploadfiles' => 1,
            'timecreated' => time(),
        ]);
        foreach (['core_webservice_get_site_info', 'core_course_get_contents', 'core_course_delete_courses'] as $function) {
            $DB->insert_record('external_services_functions', (object)[
                'externalserviceid' => $serviceid,
                'functionname' => $function,
            ]);
        }
        set_config('exposenativetools', 1, 'webservice_mcp');

        $dispatcher = new dispatcher(new call_context(
            call_context::ERA_MODERN,
            '2026-07-28',
            get_admin(),
            context_system::instance(),
            $serviceid,
            true,
            'connector'
        ));
        $tools = [];
        $cursor = null;
        do {
            $page = $dispatcher->dispatch('tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
            foreach ($page['tools'] as $tool) {
                $tools[$tool['name']] = $tool;
            }
            $cursor = $page['nextCursor'] ?? null;
        } while ($cursor !== null);

        return $tools;
    }

    /**
     * Every tool has a title and all four hints; read-only tools are fully marked as safe.
     */
    public function test_every_listed_tool_is_annotated(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $tools = $this->list_all_tools();

        foreach (self::READ_ONLY as $name) {
            $this->assertArrayHasKey($name, $tools, "{$name} is listed");
        }
        $this->assertArrayHasKey('wrapper_moodle_api_execute', $tools);

        $problems = [];
        foreach ($tools as $name => $tool) {
            $hints = $tool['annotations'] ?? null;
            if (!is_string($tool['title'] ?? null) || trim($tool['title']) === '') {
                $problems[] = "{$name}: no title";
            }
            if (!is_array($hints)) {
                $problems[] = "{$name}: no annotations";
                continue;
            }
            foreach (['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'] as $hint) {
                if (!is_bool($hints[$hint] ?? null)) {
                    $problems[] = "{$name}: {$hint} missing";
                }
            }
            if (
                !empty($hints['readOnlyHint'])
                    && ($hints['destructiveHint'] !== false || $hints['idempotentHint'] !== true
                        || $hints['openWorldHint'] !== false)
            ) {
                $problems[] = "{$name}: read-only but destructive, non-idempotent or open-world";
            }
            if (in_array($name, self::READ_ONLY, true) && empty($hints['readOnlyHint'])) {
                $problems[] = "{$name}: should be read-only";
            }
        }
        $this->assertSame([], $problems);

        $this->assertFalse($tools['wrapper_moodle_api_execute']['annotations']['readOnlyHint']);
        $this->assertTrue($tools['core_course_delete_courses']['annotations']['destructiveHint']);
        $this->assertSame('Course: get contents', $tools['core_course_get_contents']['title']);
    }

    /**
     * Invalid wrapper input reaches the client as a tool error that explains what is wrong and what is accepted.
     */
    public function test_invalid_input_error_explains_itself(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ctx = new call_context(
            call_context::ERA_MODERN,
            '2026-07-28',
            get_admin(),
            context_system::instance(),
            null,
            true,
            'connector'
        );
        $dispatcher = new dispatcher($ctx, static fn(string $name, array $arguments): array =>
            \webservice_mcp\local\mcp\tool_runner::run($name, $arguments, $ctx));

        $result = $dispatcher->dispatch('tools/call', [
            'name' => 'wrapper_course_set_module_visibility',
            'arguments' => ['courseid' => $course->id, 'cmids' => [$page->cmid], 'visibility' => 'invisible'],
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame('wrapper:invalidinput', $result['_meta']['org.moodle/errorcode']);
        $this->assertStringContainsString(
            'Unknown visibility "invisible"; use show, hide or stealth.',
            $result['content'][0]['text']
        );
    }
}
