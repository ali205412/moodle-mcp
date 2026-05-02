<?php
namespace webservice_mcp\local\wrapper;

use context_system;
use core_external\external_api;
use stdClass;

class memory_service {
    /**
     * Create a new memory record.
     */
    public function write_memory(string $content): array {
        global $DB, $USER;

        $context = context_system::instance();
        external_api::validate_context($context);

        $record = new stdClass();
        $record->userid = $USER->id;
        $record->content = $content;
        $record->timecreated = time();
        $record->timemodified = time();

        $record->id = $DB->insert_record('webservice_mcp_memory', $record);

        return (array)$record;
    }

    /**
     * Read all memories for the current user.
     */
    public function read_memories(): array {
        global $DB, $USER;

        $context = context_system::instance();
        external_api::validate_context($context);

        $records = $DB->get_records('webservice_mcp_memory', ['userid' => $USER->id], 'timecreated ASC');
        
        return array_values(array_map(fn($r) => (array)$r, $records));
    }

    /**
     * Read a specific memory by ID.
     */
    public function read_memory_by_id(int $id): array {
        global $DB, $USER;

        $context = context_system::instance();
        external_api::validate_context($context);

        $record = $DB->get_record('webservice_mcp_memory', ['id' => $id, 'userid' => $USER->id], '*', MUST_EXIST);

        return (array)$record;
    }

    /**
     * Update an existing memory.
     */
    public function update_memory(int $id, string $content): array {
        global $DB, $USER;

        $context = context_system::instance();
        external_api::validate_context($context);

        $record = $DB->get_record('webservice_mcp_memory', ['id' => $id, 'userid' => $USER->id], '*', MUST_EXIST);
        $record->content = $content;
        $record->timemodified = time();

        $DB->update_record('webservice_mcp_memory', $record);

        return (array)$record;
    }

    /**
     * Delete a memory.
     */
    public function delete_memory(int $id): bool {
        global $DB, $USER;

        $context = context_system::instance();
        external_api::validate_context($context);

        $record = $DB->get_record('webservice_mcp_memory', ['id' => $id, 'userid' => $USER->id], '*', MUST_EXIST);
        
        return $DB->delete_records('webservice_mcp_memory', ['id' => $record->id]);
    }
}
