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
use webservice_mcp\local\mcp\protocol_exception;
use webservice_mcp\local\mcp\tasks;

/**
 * MCP Tasks tests (2025-11-25 core tasks and the 2026-07-28 tasks extension).
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\mcp\tasks
 * @covers      \webservice_mcp\local\mcp\tool_runner
 * @covers      \webservice_mcp\task\run_tool
 */
final class mcp_tasks_test extends advanced_testcase {
    /**
     * Dispatcher for a user and era.
     *
     * @param \stdClass $user User.
     * @param string $era Era.
     * @param array $capabilities Client capabilities.
     * @param string|null $family Credential family (null: raw external token, no family to re-check).
     * @return dispatcher
     */
    private function dispatcher(\stdClass $user, string $era, array $capabilities = [], ?string $family = null): dispatcher {
        $version = $era === call_context::ERA_MODERN ? '2026-07-28' : '2025-11-25';
        return new dispatcher(new call_context(
            $era,
            $version,
            $user,
            context_system::instance(),
            $this->service(),
            true,
            'connector',
            $capabilities,
            $family
        ));
    }

    /**
     * The enabled service the tasks run under, holding the functions these tests call (created once per test).
     *
     * @return int Service id.
     */
    private function service(): int {
        global $DB;
        if ($id = $DB->get_field('external_services', 'id', ['shortname' => 'mcptasktest'])) {
            return (int)$id;
        }
        $id = $DB->insert_record('external_services', (object)['name' => 'MCP task test', 'shortname' => 'mcptasktest',
            'enabled' => 1, 'requiredcapability' => '', 'restrictedusers' => 0, 'timecreated' => time()]);
        foreach (
            ['core_enrol_get_users_courses', 'core_course_get_courses_by_field', 'core_course_get_contents',
                'core_calendar_get_action_events_by_course', 'core_course_create_courses'] as $function
        ) {
            $DB->insert_record('external_services_functions', (object)['externalserviceid' => $id, 'functionname' => $function]);
        }
        return (int)$id;
    }

    /**
     * Legacy clients opt in per call; tasks/result runs a not-yet-started task inline.
     */
    public function test_legacy_task_lifecycle(): void {
        $this->resetAfterTest();
        $user = get_admin();
        $this->setUser($user);
        $dispatcher = $this->dispatcher($user, call_context::ERA_LEGACY);

        $created = $dispatcher->dispatch(
            'tools/call',
            ['name' => 'moodle_explorer', 'arguments' => [], 'task' => ['ttl' => 60000]]
        );
        $taskid = $created['task']['taskId'];
        $this->assertSame('working', $created['task']['status']);
        $this->assertSame(60000, $created['task']['ttl']);

        $this->assertSame('working', $dispatcher->dispatch('tasks/get', ['taskId' => $taskid])['status']);
        $this->assertCount(1, $dispatcher->dispatch('tasks/list', [])['tasks']);

        $result = $dispatcher->dispatch('tasks/result', ['taskId' => $taskid]);
        $this->assertSame('courses', $result['structuredContent']['view']);
        $this->assertSame($taskid, $result['_meta']['io.modelcontextprotocol/related-task']['taskId']);
        $this->assertSame('completed', $dispatcher->dispatch('tasks/get', ['taskId' => $taskid])['status']);

        // The listed tools advertise optional task support for legacy clients.
        $tools = $dispatcher->dispatch('tools/list', [])['tools'];
        $this->assertSame('optional', $tools[0]['execution']['taskSupport']);
    }

    /**
     * Modern clients declaring the extension get tasks for long-running tools; cron completes them.
     */
    public function test_modern_task_via_cron(): void {
        global $DB;
        $this->resetAfterTest();
        $user = get_admin();
        $this->setUser($user);
        $caps = ['extensions' => [tasks::EXTENSION => (object)[]]];
        $dispatcher = $this->dispatcher($user, call_context::ERA_MODERN, $caps);

        // Not long-running: runs synchronously even with the extension declared.
        $sync = $dispatcher->dispatch('tools/call', ['name' => 'moodle_explorer', 'arguments' => []]);
        $this->assertSame('complete', $sync['resultType']);

        $category = $this->getDataGenerator()->create_category();
        $created = $dispatcher->dispatch('tools/call', ['name' => 'wrapper_moodle_api_execute', 'arguments' => [
            'functionname' => 'core_course_create_courses',
            'params' => ['courses' => [['fullname' => 'Async course', 'shortname' => 'ASYNC1', 'categoryid' => $category->id]]],
        ]]);
        $this->assertSame('task', $created['resultType']);
        $this->assertSame(5000, $created['pollIntervalMs']);

        // Cron runs the gateway call through the wrapper manager, as the queuing user.
        $this->runAdhocTasks('\webservice_mcp\task\run_tool');
        $task = $dispatcher->dispatch('tasks/get', ['taskId' => $created['taskId']]);
        $this->assertSame('completed', $task['status']);
        $this->assertArrayNotHasKey('isError', $task['result']);
        $this->assertTrue($DB->record_exists('course', ['shortname' => 'ASYNC1']));

        // Without the extension the same call is synchronous.
        $plain = $this->dispatcher($user, call_context::ERA_MODERN);
        $sync = $plain->dispatch('tools/call', ['name' => 'moodle_explorer', 'arguments' => []]);
        $this->assertSame('complete', $sync['resultType']);
    }

    /**
     * Cancellation before start, and tasks are invisible to other users and credential families.
     */
    public function test_cancel_and_isolation(): void {
        $this->resetAfterTest();
        $user = get_admin();
        $this->setUser($user);
        $dispatcher = $this->dispatcher($user, call_context::ERA_LEGACY);
        $created = $dispatcher->dispatch('tools/call', ['name' => 'moodle_explorer', 'arguments' => [], 'task' => []]);
        $taskid = $created['task']['taskId'];

        foreach (
            [$this->dispatcher($this->getDataGenerator()->create_user(), call_context::ERA_LEGACY),
                $this->dispatcher($user, call_context::ERA_LEGACY, [], 'c_other')] as $stranger
        ) {
            try {
                $stranger->dispatch('tasks/get', ['taskId' => $taskid]);
                $this->fail('Foreign task was visible');
            } catch (protocol_exception $e) {
                $this->assertSame(protocol_exception::INVALID_PARAMS, $e->rpccode);
            }
        }

        $this->assertSame('cancelled', $dispatcher->dispatch('tasks/cancel', ['taskId' => $taskid])['status']);
        $this->runAdhocTasks('\webservice_mcp\task\run_tool');
        $this->assertSame('cancelled', $dispatcher->dispatch('tasks/get', ['taskId' => $taskid])['status']);

        $this->expectException(protocol_exception::class);
        $dispatcher->dispatch('tasks/cancel', ['taskId' => $taskid]);
    }

    /**
     * A task whose credential family was revoked before it ran fails instead of running.
     */
    public function test_revoked_credential_task_fails(): void {
        $this->resetAfterTest();
        $user = get_admin();
        $this->setUser($user);
        $dispatcher = $this->dispatcher($user, call_context::ERA_LEGACY, [], 'c_999999');
        $created = $dispatcher->dispatch('tools/call', ['name' => 'moodle_explorer', 'arguments' => [], 'task' => []]);
        $taskid = $created['task']['taskId'];

        $this->runAdhocTasks('\webservice_mcp\task\run_tool');
        $task = $dispatcher->dispatch('tasks/get', ['taskId' => $taskid]);
        $this->assertSame('failed', $task['status']);
        $this->assertStringContainsString('revoked', $task['statusMessage']);
    }
}
