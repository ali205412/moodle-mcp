<?php
namespace webservice_mcp;

use advanced_testcase;
use context_system;
use core_external\external_api;
use webservice_mcp\local\wrapper\memory_service;

class memory_service_test extends advanced_testcase {
    
    protected function setUp(): void {
        $this->resetAfterTest(true);
        external_api::set_context_restriction(null);
    }

    public function test_write_memory_persists_content_scoped_to_user(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $service = new memory_service();
        $result = $service->write_memory('This is my memory');

        $this->assertArrayHasKey('id', $result);
        $this->assertEquals($user->id, $result['userid']);
        $this->assertEquals('This is my memory', $result['content']);

        $record = $DB->get_record('webservice_mcp_memory', ['id' => $result['id']]);
        $this->assertNotFalse($record);
        $this->assertEquals($user->id, $record->userid);
    }

    public function test_read_memories_retrieves_only_user_memories(): void {
        global $DB;
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        // User 1 creates a memory
        $this->setUser($user1);
        $service = new memory_service();
        $service->write_memory('User 1 memory');

        // User 2 creates a memory
        $this->setUser($user2);
        $service->write_memory('User 2 memory');

        // Verify User 1 only sees their memory
        $this->setUser($user1);
        $memories = $service->read_memories();
        $this->assertCount(1, $memories);
        $this->assertEquals('User 1 memory', $memories[0]['content']);
    }

    public function test_crud_ownership_validation(): void {
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $this->setUser($user1);
        $service = new memory_service();
        $memory = $service->write_memory('Shared memory? No');

        $this->setUser($user2);
        
        // Trying to read user 1's memory as user 2 should throw dml_missing_record_exception
        $this->expectException(\dml_missing_record_exception::class);
        $service->read_memory_by_id($memory['id']);
    }

    public function test_update_ownership_validation(): void {
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $this->setUser($user1);
        $service = new memory_service();
        $memory = $service->write_memory('Update me');

        $this->setUser($user2);
        
        $this->expectException(\dml_missing_record_exception::class);
        $service->update_memory($memory['id'], 'Updated by hacker');
    }

    public function test_delete_ownership_validation(): void {
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $this->setUser($user1);
        $service = new memory_service();
        $memory = $service->write_memory('Delete me');

        $this->setUser($user2);
        
        $this->expectException(\dml_missing_record_exception::class);
        $service->delete_memory($memory['id']);
    }

    public function test_delete_memory(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();

        $this->setUser($user);
        $service = new memory_service();
        $memory = $service->write_memory('Delete me soon');

        $this->assertTrue($service->delete_memory($memory['id']));
        
        $record = $DB->get_record('webservice_mcp_memory', ['id' => $memory['id']]);
        $this->assertFalse($record);
    }
}
