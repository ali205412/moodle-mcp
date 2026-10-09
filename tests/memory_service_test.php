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
use core_external\external_api;
use webservice_mcp\local\wrapper\manager;
use webservice_mcp\local\wrapper\memory_service;

/**
 * Tests for the per-user memory wrappers.
 *
 * @package     webservice_mcp
 * @copyright   2025 MohammadReza PourMohammad
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\wrapper\memory_service
 */
final class memory_service_test extends advanced_testcase {
    /**
     * Reset state and the static context restriction.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        external_api::set_context_restriction(null);
    }

    /**
     * Memories are stored for the current user.
     */
    public function test_write_memory_persists_content_scoped_to_user(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = (new memory_service())->write_memory('This is my memory');

        $this->assertEquals($user->id, $result['userid']);
        $this->assertSame('This is my memory', $result['content']);
        $this->assertEquals($user->id, $DB->get_field('webservice_mcp_memory', 'userid', ['id' => $result['id']]));
    }

    /**
     * Listing only returns the current user's memories.
     */
    public function test_read_memories_retrieves_only_user_memories(): void {
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $service = new memory_service();

        $this->setUser($user1);
        $service->write_memory('User 1 memory');
        $this->setUser($user2);
        $service->write_memory('User 2 memory');

        $this->setUser($user1);
        $memories = $service->read_memories();
        $this->assertSame(1, $memories['total']);
        $this->assertSame('User 1 memory', $memories['memories'][0]['content']);
    }

    /**
     * Other users' memories cannot be read, updated or deleted.
     */
    public function test_ownership_is_enforced(): void {
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $service = new memory_service();

        $this->setUser($user1);
        $memory = $service->write_memory('Private');

        $this->setUser($user2);
        foreach (
            ['read_memory_by_id' => [$memory['id']], 'update_memory' => [$memory['id'], 'x'],
                'delete_memory' => [$memory['id']]] as $method => $args
        ) {
            try {
                $service->$method(...$args);
                $this->fail("{$method} should reject another user's memory.");
            } catch (\dml_missing_record_exception $exception) {
                $this->assertInstanceOf(\dml_missing_record_exception::class, $exception);
            }
        }
    }

    /**
     * Memories can be updated and deleted through the manager, also under a course-restricted token.
     */
    public function test_memory_wrappers_work_with_restricted_context(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($user);
        $context = context_course::instance($course->id);
        $manager = new manager();

        $memory = $manager->execute('wrapper_memory_write', ['content' => 'first'], $context, $user);
        $updated = $manager->execute('wrapper_memory_update', ['id' => $memory['id'], 'content' => 'second'], $context, $user);
        $this->assertSame('second', $updated['content']);

        $read = $manager->execute('wrapper_memory_read', ['id' => $memory['id']], $context, $user);
        $this->assertSame('second', $read['memories'][0]['content']);

        $deleted = $manager->execute('wrapper_memory_delete', ['id' => $memory['id']], $context, $user);
        $this->assertTrue($deleted['deleted']);
        $this->assertFalse($DB->record_exists('webservice_mcp_memory', ['id' => $memory['id']]));
    }

    /**
     * Content size is capped.
     */
    public function test_content_size_cap(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectExceptionObject(new \moodle_exception(
            'wrapper:memorytoolarge',
            'webservice_mcp',
            '',
            memory_service::MAX_CONTENT_BYTES
        ));
        (new memory_service())->write_memory(str_repeat('x', memory_service::MAX_CONTENT_BYTES + 1));
    }

    /**
     * The number of memories per user is capped.
     */
    public function test_count_cap(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $records = [];
        for ($i = 0; $i < memory_service::MAX_PER_USER; $i++) {
            $records[] = (object)['userid' => $user->id, 'content' => "m{$i}", 'timecreated' => 1, 'timemodified' => 1];
        }
        $DB->insert_records('webservice_mcp_memory', $records);

        $this->expectExceptionObject(new \moodle_exception(
            'wrapper:memorylimit',
            'webservice_mcp',
            '',
            memory_service::MAX_PER_USER
        ));
        (new memory_service())->write_memory('one too many');
    }

    /**
     * Guests cannot store memories.
     */
    public function test_guest_is_rejected(): void {
        $this->setGuestUser();

        $this->expectException(\moodle_exception::class);
        (new memory_service())->write_memory('guest');
    }
}
