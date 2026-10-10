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

use stdClass;

/**
 * MCP Tasks: asynchronous tools/call executed by Moodle cron as the calling user.
 *
 * Serves both the 2025-11-25 core tasks feature and the 2026-07-28 io.modelcontextprotocol/tasks
 * extension. Tasks are bound to the caller (user + credential family) and are invisible to anyone else.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tasks {
    /** Extension identifier (2026-07-28). */
    public const EXTENSION = 'io.modelcontextprotocol/tasks';

    /** Table. */
    private const TABLE = 'webservice_mcp_task';

    /** Default and maximum retention of a task record, in ms. */
    private const DEFAULT_TTL = 3600000;

    /** Maximum TTL a client may request, in ms. */
    private const MAX_TTL = 86400000;

    /** Suggested poll interval, in ms (cron runs about once a minute). */
    private const POLL_INTERVAL = 5000;

    /** Tools that modern clients get as tasks without asking (known to be slow). */
    private const LONG_RUNNING = [
        'core_course_duplicate_course', 'core_course_import_course', 'core_course_delete_courses',
        'core_course_delete_categories', 'core_course_create_courses', 'core_user_create_users',
        'core_user_delete_users', 'enrol_manual_enrol_users', 'enrol_manual_unenrol_users',
        'core_cohort_add_cohort_members', 'core_group_add_group_members', 'core_role_assign_roles',
        'tool_dataprivacy_create_data_request', 'wrapper_course_duplicate_modules',
        'wrapper_question_import_questions', 'file_upload_from_url',
    ];

    /** @var call_context */
    private call_context $ctx;

    /**
     * Constructor.
     *
     * @param call_context $ctx Request context.
     */
    public function __construct(call_context $ctx) {
        $this->ctx = $ctx;
    }

    /**
     * Decide whether this tools/call should run as a task.
     *
     * Legacy clients opt in per request with params.task; modern clients that declare the
     * extension get tasks for known long-running tools.
     *
     * @param string $name Tool name.
     * @param array $arguments Tool arguments.
     * @param array $params Request params.
     * @return bool
     */
    public function should_run_as_task(string $name, array $arguments, array $params): bool {
        if ($this->ctx->era === call_context::ERA_LEGACY) {
            return isset($params['task']) && is_array($params['task']);
        }
        if (!isset($this->ctx->clientcapabilities['extensions'][self::EXTENSION])) {
            return false;
        }
        $target = $name === 'wrapper_moodle_api_execute' ? (string)($arguments['functionname'] ?? '') : $name;
        return in_array($target, self::LONG_RUNNING, true);
    }

    /**
     * Create a durable task and queue it for cron.
     *
     * @param string $name Tool name.
     * @param array $arguments Arguments.
     * @param array $params Request params (legacy params.task.ttl).
     * @return array CreateTaskResult for the context's era.
     */
    public function create(string $name, array $arguments, array $params): array {
        global $DB;

        $ttl = (int)($params['task']['ttl'] ?? self::DEFAULT_TTL);
        $now = time();
        $record = (object)[
            'taskid' => \core\uuid::generate(),
            'userid' => (int)$this->ctx->user->id,
            'familyid' => (string)($this->ctx->credentialid ?? ''),
            'contextid' => (int)($this->ctx->restrictedcontext->id ?? \context_system::instance()->id),
            'serviceid' => (int)$this->ctx->serviceid,
            'connector' => $this->ctx->connector ? 1 : 0,
            'toolname' => $name,
            'arguments' => json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'working',
            'statusmessage' => 'Queued; runs on the next Moodle cron.',
            'ttl' => max(60000, min(self::MAX_TTL, $ttl > 0 ? $ttl : self::DEFAULT_TTL)),
            'cancelrequested' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record(self::TABLE, $record);

        $task = new \webservice_mcp\task\run_tool();
        $task->set_userid($record->userid);
        $task->set_custom_data(['taskid' => $record->taskid]);
        \core\task\manager::queue_adhoc_task($task);

        $shape = $this->shape($record);
        return $this->ctx->era === call_context::ERA_MODERN
            ? ['resultType' => 'task'] + $shape
            : ['task' => $shape];
    }

    /**
     * tasks/get.
     *
     * @param string $taskid Task id.
     * @return array
     */
    public function get(string $taskid): array {
        $record = $this->load($taskid);
        $result = $this->shape($record);
        if ($this->ctx->era === call_context::ERA_MODERN) {
            if ($record->status === 'completed') {
                $result['result'] = json_decode((string)$record->result, true);
            }
            if ($record->status === 'failed') {
                $result['error'] = json_decode((string)$record->error, true);
            }
        }
        return $result;
    }

    /**
     * tasks/result (legacy): the original result, running the task inline when cron has not claimed it yet.
     *
     * @param string $taskid Task id.
     * @return array
     */
    public function result(string $taskid): array {
        $record = $this->load($taskid);
        if ($record->status === 'working' && empty($record->timestarted)) {
            self::execute($record->taskid);
            $record = $this->load($taskid);
        }
        for ($i = 0; $i < 2 && $record->status === 'working'; $i++) {
            // Cron is running it right now. Wait only briefly: each waiting request holds a PHP worker.
            sleep(1);
            $record = $this->load($taskid);
        }
        $meta = ['io.modelcontextprotocol/related-task' => ['taskId' => $record->taskid]];
        return match ($record->status) {
            'completed' => json_decode((string)$record->result, true) + ['_meta' => $meta],
            'failed' => throw new protocol_exception(
                (int)(json_decode((string)$record->error, true)['code'] ?? protocol_exception::INTERNAL_ERROR),
                (string)(json_decode((string)$record->error, true)['message'] ?? 'Task failed')
            ),
            'cancelled' => throw new protocol_exception(protocol_exception::INVALID_PARAMS, 'Task was cancelled.'),
            default => throw new protocol_exception(
                protocol_exception::INTERNAL_ERROR,
                'Task is still running; poll tasks/get and retry tasks/result later.'
            ),
        };
    }

    /**
     * tasks/list (legacy).
     *
     * @param mixed $cursor Offset cursor.
     * @return array
     */
    public function list(mixed $cursor): array {
        global $DB;
        $offset = is_string($cursor) && ctype_digit($cursor) ? (int)$cursor : 0;
        $records = $DB->get_records(self::TABLE, ['userid' => (int)$this->ctx->user->id,
            'familyid' => (string)($this->ctx->credentialid ?? '')], 'timecreated DESC', '*', $offset, 51);
        $tasks = array_map([$this, 'shape'], array_slice(array_values($records), 0, 50));
        $result = ['tasks' => $tasks];
        if (count($records) > 50) {
            $result['nextCursor'] = (string)($offset + 50);
        }
        return $result;
    }

    /**
     * tasks/update: tasks here never enter input_required, so responses are acknowledged and ignored.
     *
     * @param string $taskid Task id.
     * @return array
     */
    public function update(string $taskid): array {
        $this->load($taskid);
        return [];
    }

    /**
     * tasks/cancel.
     *
     * @param string $taskid Task id.
     * @return array Legacy: the task; modern: empty ack.
     */
    public function cancel(string $taskid): array {
        global $DB;
        $record = $this->load($taskid);
        if (in_array($record->status, ['completed', 'failed', 'cancelled'], true)) {
            throw new protocol_exception(protocol_exception::INVALID_PARAMS, 'Task is already in a terminal state.');
        }
        $record->cancelrequested = 1;
        if (empty($record->timestarted)) {
            $record->status = 'cancelled';
            $record->statusmessage = 'Cancelled before it started.';
        }
        $record->timemodified = time();
        $DB->update_record(self::TABLE, $record);
        return $this->ctx->era === call_context::ERA_MODERN ? [] : $this->shape($record);
    }

    /**
     * Execute a queued task as its owner. Called by the adhoc task (cron) or inline by tasks/result.
     *
     * The caller must already run as the task's user.
     *
     * @param string $taskid Task id.
     * @return void
     */
    public static function execute(string $taskid): void {
        global $DB, $USER;

        $record = $DB->get_record(self::TABLE, ['taskid' => $taskid]);
        if (!$record || $record->status !== 'working' || !empty($record->cancelrequested)) {
            return;
        }
        // Claim with a unique token so cron and an inline tasks/result never both run it.
        $token = random_string(32);
        $DB->execute(
            "UPDATE {" . self::TABLE . "} SET timestarted = :now, claimtoken = :token, statusmessage = :msg
                       WHERE id = :id AND timestarted IS NULL",
            ['now' => time(), 'token' => $token, 'msg' => 'Running.', 'id' => $record->id]
        );
        $record = $DB->get_record(self::TABLE, ['id' => $record->id]);
        if ($record->claimtoken !== $token || (int)$USER->id !== (int)$record->userid) {
            return;
        }

        try {
            self::assert_still_authorised($record);
            $ctx = new call_context(
                call_context::ERA_MODERN,
                dispatcher::MODERN_VERSIONS[0],
                $USER,
                \context::instance_by_id((int)$record->contextid),
                (int)$record->serviceid,
                (bool)$record->connector,
                'task',
                [],
                $record->familyid ?: null
            );
            $arguments = json_decode((string)$record->arguments, true) ?: [];
            $result = dispatcher::run_tool($ctx, $record->toolname, $arguments);
            // Audit-only metadata is server-side; the transport strips it for direct calls, so do the same here.
            unset($result['_meta']['org.moodle/auditdetail']);
            if (isset($result['_meta']) && $result['_meta'] === []) {
                unset($result['_meta']);
            }
            $record->status = 'completed';
            $record->statusmessage = empty($result['isError']) ? 'Completed.' : 'Completed with a tool error.';
            $record->result = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (protocol_exception $e) {
            $record->status = 'failed';
            $record->statusmessage = $e->getMessage();
            $record->error = json_encode(['code' => $e->rpccode, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            abort_all_db_transactions();
            // PHP errors carry server paths; only Moodle exceptions have user-facing messages.
            $message = $e instanceof \moodle_exception || debugging('', DEBUG_DEVELOPER) ? $e->getMessage() : 'Internal error.';
            $record->status = 'failed';
            $record->statusmessage = $message;
            $record->error = json_encode(['code' => protocol_exception::INTERNAL_ERROR, 'message' => $message]);
        }
        $record->timemodified = time();
        $DB->update_record(self::TABLE, $record);
    }

    /**
     * Delete task records past their TTL.
     *
     * @return void
     */
    public static function purge_expired(): void {
        global $DB;
        $DB->delete_records_select(self::TABLE, 'timecreated + ttl / 1000 < :now', ['now' => time()]);
    }

    /**
     * Load a task owned by the caller; foreign or unknown ids are indistinguishable.
     *
     * @param string $taskid Task id.
     * @return stdClass
     */
    private function load(string $taskid): stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['taskid' => $taskid]);
        if (
            !$record || (int)$record->userid !== (int)$this->ctx->user->id
                || (string)$record->familyid !== (string)($this->ctx->credentialid ?? '')
                || $record->timecreated + (int)($record->ttl / 1000) < time()
        ) {
            throw new protocol_exception(protocol_exception::INVALID_PARAMS, 'Unknown task: ' . $taskid);
        }
        return $record;
    }

    /**
     * Re-check, at execution time, that the credential and user may still act.
     *
     * @param stdClass $record Task record.
     * @return void
     */
    private static function assert_still_authorised(stdClass $record): void {
        global $USER;
        // Same service/user/credential checks the transport runs (no IP check: cron has no client address).
        $problem = \webservice_mcp\local\auth\service_access::problem(
            (int)$USER->id,
            (int)$record->serviceid,
            $record->familyid ?: null,
            false
        );
        if ($problem === 'credentialrevoked') {
            throw new protocol_exception(-32001, 'The credential that created this task has been revoked.');
        }
        if ($problem !== null) {
            throw new protocol_exception(-32001, 'You can no longer use the MCP connector: ' . $problem);
        }
        \core_user::require_active_user($USER, true, true);
        if (!has_capability('webservice/mcp:use', \context::instance_by_id((int)$record->contextid))) {
            throw new protocol_exception(-32001, 'You can no longer use the MCP connector.');
        }
    }

    /**
     * Task shape for the context's era.
     *
     * @param stdClass $record Task record.
     * @return array
     */
    private function shape(stdClass $record): array {
        $task = [
            'taskId' => $record->taskid,
            'status' => $record->status,
            'statusMessage' => (string)$record->statusmessage,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z', (int)$record->timecreated),
            'lastUpdatedAt' => gmdate('Y-m-d\TH:i:s\Z', (int)$record->timemodified),
        ];
        if ($this->ctx->era === call_context::ERA_MODERN) {
            $task['ttlMs'] = (int)$record->ttl;
            $task['pollIntervalMs'] = self::POLL_INTERVAL;
        } else {
            $task['ttl'] = (int)$record->ttl;
            $task['pollInterval'] = self::POLL_INTERVAL;
        }
        return $task;
    }
}
