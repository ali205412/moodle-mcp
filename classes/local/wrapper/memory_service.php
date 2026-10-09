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

use stdClass;

/**
 * Per-user persistent memory notes for MCP clients.
 *
 * Memories are plugin-owned rows keyed by the current user, not Moodle context data, so access is governed by
 * ownership rather than a context check (which would also break course- or module-restricted tokens).
 *
 * @package     webservice_mcp
 * @copyright   2025 MohammadReza PourMohammad
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class memory_service {
    /** Maximum content size in bytes. */
    public const MAX_CONTENT_BYTES = 65536;

    /** Maximum number of memories per user. */
    public const MAX_PER_USER = 500;

    /** Default page size for listing. */
    public const DEFAULT_LIMIT = 100;

    /**
     * Create a new memory record.
     *
     * @param string $content Memory content.
     * @return array
     */
    public function write_memory(string $content): array {
        global $DB;

        $userid = $this->require_user();
        $this->validate_content($content);
        if ($DB->count_records('webservice_mcp_memory', ['userid' => $userid]) >= self::MAX_PER_USER) {
            throw new \moodle_exception('wrapper:memorylimit', 'webservice_mcp', '', self::MAX_PER_USER);
        }

        $now = time();
        $record = (object)[
            'userid' => $userid,
            'content' => $content,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('webservice_mcp_memory', $record);

        return $this->export($record);
    }

    /**
     * List the current user's memories, oldest first.
     *
     * @param int $limit Page size (1-500).
     * @param int $offset Offset.
     * @return array
     */
    public function read_memories(int $limit = self::DEFAULT_LIMIT, int $offset = 0): array {
        global $DB;

        $userid = $this->require_user();
        $limit = max(1, min($limit, self::MAX_PER_USER));
        $records = $DB->get_records(
            'webservice_mcp_memory',
            ['userid' => $userid],
            'timecreated ASC, id ASC',
            '*',
            max(0, $offset),
            $limit
        );

        return [
            'memories' => array_values(array_map([$this, 'export'], $records)),
            'total' => $DB->count_records('webservice_mcp_memory', ['userid' => $userid]),
        ];
    }

    /**
     * Read a specific memory owned by the current user.
     *
     * @param int $id Memory id.
     * @return array
     */
    public function read_memory_by_id(int $id): array {
        return $this->export($this->get_owned($id));
    }

    /**
     * Replace the content of a memory owned by the current user.
     *
     * @param int $id Memory id.
     * @param string $content New content.
     * @return array
     */
    public function update_memory(int $id, string $content): array {
        global $DB;

        $record = $this->get_owned($id);
        $this->validate_content($content);
        $record->content = $content;
        $record->timemodified = time();
        $DB->update_record('webservice_mcp_memory', $record);

        return $this->export($record);
    }

    /**
     * Delete a memory owned by the current user.
     *
     * @param int $id Memory id.
     * @return array
     */
    public function delete_memory(int $id): array {
        global $DB;

        $record = $this->get_owned($id);
        $DB->delete_records('webservice_mcp_memory', ['id' => $record->id]);

        return ['deleted' => true, 'id' => (int)$record->id];
    }

    /**
     * Load a memory owned by the current user or throw dml_missing_record_exception.
     *
     * @param int $id Memory id.
     * @return stdClass
     */
    private function get_owned(int $id): stdClass {
        global $DB;

        return $DB->get_record('webservice_mcp_memory', ['id' => $id, 'userid' => $this->require_user()], '*', MUST_EXIST);
    }

    /**
     * Require a real logged-in user and return its id.
     *
     * @return int
     */
    private function require_user(): int {
        global $USER;

        if (empty($USER->id) || \isguestuser()) {
            throw new \moodle_exception('noguest');
        }

        return (int)$USER->id;
    }

    /**
     * Enforce the content size cap.
     *
     * @param string $content Content.
     * @return void
     */
    private function validate_content(string $content): void {
        if (trim($content) === '') {
            throw arguments::invalid('Memory content must not be empty.');
        }
        if (strlen($content) > self::MAX_CONTENT_BYTES) {
            throw new \moodle_exception('wrapper:memorytoolarge', 'webservice_mcp', '', self::MAX_CONTENT_BYTES);
        }
    }

    /**
     * Export a memory record.
     *
     * @param stdClass $record Record.
     * @return array
     */
    private function export(stdClass $record): array {
        return [
            'id' => (int)$record->id,
            'userid' => (int)$record->userid,
            'content' => (string)$record->content,
            'timecreated' => (int)$record->timecreated,
            'timemodified' => (int)$record->timemodified,
        ];
    }
}
