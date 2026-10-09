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
use context_course;
use context_system;
use webservice_mcp\local\mcp\call_context;
use webservice_mcp\local\mcp\dispatcher;
use webservice_mcp\local\mcp\protocol_exception;

/**
 * Regressions for the pre-production review: transactions, connector gating, service scoping,
 * context restriction in cron, completion leaks and error masking.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\mcp\dispatcher
 * @covers      \webservice_mcp\local\mcp\resources
 * @covers      \webservice_mcp\local\mcp\completions
 */
final class mcp_hardening_test extends advanced_testcase {
    /**
     * A service holding the given functions.
     *
     * @param string[] $functions Function names.
     * @return int Service id.
     */
    private function service(array $functions): int {
        global $DB;
        $id = $DB->insert_record('external_services', (object)['name' => 'svc' . random_string(6), 'enabled' => 1,
            'requiredcapability' => '', 'restrictedusers' => 0, 'component' => null, 'timecreated' => time(),
            'shortname' => 'svc' . random_string(6), 'downloadfiles' => 1, 'uploadfiles' => 1]);
        foreach ($functions as $function) {
            $DB->insert_record('external_services_functions', (object)['externalserviceid' => $id, 'functionname' => $function]);
        }
        return $id;
    }

    /**
     * A tool that throws inside a delegated transaction must not leave it open.
     */
    public function test_tool_error_aborts_open_transaction(): void {
        global $DB;
        $this->resetAfterTest();
        $user = get_admin();
        $this->setUser($user);
        $executor = static function () use ($DB): array {
            $DB->start_delegated_transaction();
            throw new \moodle_exception('nopermissions', 'error', '', 'x');
        };
        $dispatcher = new dispatcher(new call_context(
            call_context::ERA_MODERN,
            '2026-07-28',
            $user,
            context_system::instance(),
            0,
            true
        ), $executor);

        $result = $dispatcher->dispatch('tools/call', ['name' => 'core_webservice_get_site_info', 'arguments' => []]);
        $this->assertTrue($result['isError']);
        $this->assertFalse($DB->is_transaction_started());
    }

    /**
     * File tools are connector-only, including through the task branch and in cron.
     */
    public function test_raw_token_cannot_queue_file_tools(): void {
        $this->resetAfterTest();
        $user = get_admin();
        $this->setUser($user);
        $ctx = new call_context(call_context::ERA_LEGACY, '2025-11-25', $user, context_system::instance(), 0, false);

        try {
            (new dispatcher($ctx))->dispatch('tools/call', ['name' => 'file_list', 'arguments' => [], 'task' => []]);
            $this->fail('A raw token queued a file tool.');
        } catch (protocol_exception $e) {
            $this->assertSame(protocol_exception::INVALID_PARAMS, $e->rpccode);
        }

        $this->expectException(protocol_exception::class);
        dispatcher::run_tool($ctx, 'file_list', []);
    }

    /**
     * Resources only call functions that the token's service offers.
     */
    public function test_resources_respect_service_function_list(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $narrow = new call_context(
            call_context::ERA_MODERN,
            '2026-07-28',
            $user,
            context_system::instance(),
            $this->service(['core_enrol_get_users_courses']),
            false
        );
        try {
            (new dispatcher($narrow))->dispatch('resources/read', ['uri' => 'moodle://site']);
            $this->fail('resources/read reached a function outside the service.');
        } catch (protocol_exception $e) {
            $this->assertSame(protocol_exception::INVALID_PARAMS, $e->rpccode);
        }

        $wide = new call_context(
            call_context::ERA_MODERN,
            '2026-07-28',
            $user,
            context_system::instance(),
            $this->service(['core_webservice_get_site_info']),
            false
        );
        $read = (new dispatcher($wide))->dispatch('resources/read', ['uri' => 'moodle://site']);
        $this->assertStringContainsString('"siteurl"', $read['contents'][0]['text']);
    }

    /**
     * Tasks run with the credential's context restriction, even from a fresh cron process.
     */
    public function test_run_tool_applies_context_restriction(): void {
        $this->resetAfterTest();
        $user = get_admin();
        $this->setUser($user);
        $a = $this->getDataGenerator()->create_course();
        $b = $this->getDataGenerator()->create_course();
        $ctx = new call_context(
            call_context::ERA_MODERN,
            '2026-07-28',
            $user,
            context_course::instance($a->id),
            $this->service(['core_course_get_courses_by_field', 'core_course_get_contents',
            'core_calendar_get_action_events_by_course']),
            true
        );

        \core_external\external_api::set_context_restriction(null);
        $own = dispatcher::run_tool($ctx, 'moodle_explorer', ['view' => 'course', 'courseid' => $a->id]);
        $this->assertArrayNotHasKey('isError', $own, json_encode($own));
        $this->assertSame((int)$a->id, (int)$own['structuredContent']['course']['id']);
        // Last: the failure aborts open transactions, which on PostgreSQL includes the test's own wrapper.
        $other = dispatcher::run_tool($ctx, 'moodle_explorer', ['view' => 'course', 'courseid' => $b->id]);
        $this->assertTrue($other['isError']);
        $this->assertStringNotContainsString('connector service', $other['content'][0]['text']);
    }

    /**
     * Completion never suggests activities or participants from courses the user cannot access.
     */
    public function test_completion_hides_inaccessible_courses(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $mine = $generator->create_course();
        $other = $generator->create_course();
        $generator->enrol_user($user->id, $mine->id, 'student');
        $generator->enrol_user($generator->create_user()->id, $other->id, 'student');
        $visible = $generator->create_module('page', ['course' => $mine->id, 'name' => 'Shared name']);
        $generator->create_module('page', ['course' => $other->id, 'name' => 'Shared name']);
        $this->setUser($user);

        $ctx = new call_context(
            call_context::ERA_MODERN,
            '2026-07-28',
            $user,
            context_system::instance(),
            $this->service(['core_enrol_get_enrolled_users']),
            true
        );
        $dispatcher = new dispatcher($ctx);
        $complete = fn(string $arg, int $courseid) => $dispatcher->dispatch('completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'x'],
            'argument' => ['name' => $arg, 'value' => ''],
            'context' => ['arguments' => ['courseid' => (string)$courseid]],
        ])['completion']['values'];

        $this->assertSame([], $complete('cmid', (int)$other->id));
        $this->assertSame([], $complete('userid', (int)$other->id));
        $this->assertContains((string)$visible->cmid, $complete('cmid', (int)$mine->id));
    }

    /**
     * PHP errors (paths, line numbers) are masked unless developer debugging is on.
     */
    public function test_error_result_masks_php_errors(): void {
        $this->resetAfterTest();
        set_debugging(DEBUG_NORMAL);
        $text = dispatcher::error_result(new \TypeError('Argument #1 in /var/www/html/moodle/secret.php:12'))['content'][0]['text'];
        $this->assertStringNotContainsString('/var/www', $text);
        $this->assertStringContainsString('Internal error', $text);

        $moodle = dispatcher::error_result(new \moodle_exception('nopermissions', 'error', '', 'do that'))['content'][0]['text'];
        $this->assertStringContainsString('do that', $moodle);
    }
}
