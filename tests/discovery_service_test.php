<?php
namespace webservice_mcp;

use advanced_testcase;
use core_external\external_api;
use webservice_mcp\local\wrapper\discovery_service;

class discovery_service_test extends advanced_testcase {
    
    protected function setUp(): void {
        $this->resetAfterTest(true);
        external_api::set_context_restriction(null);
    }

    public function test_search_api_filters_external_functions(): void {
        $this->setAdminUser();

        $service = new discovery_service();
        $results = $service->search_api('core_user_get_users');
        
        $this->assertNotEmpty($results);
        $this->assertEquals('core_user_get_users', $results[0]['name']);
    }

    public function test_execute_api_validates_and_executes_function(): void {
        $this->setAdminUser();

        $service = new discovery_service();
        
        $user = $this->getDataGenerator()->create_user(['email' => 'findme@example.com']);
        
        $params = [
            'criteria' => [
                [
                    'key' => 'email',
                    'value' => 'findme@example.com'
                ]
            ]
        ];
        
        $result = $service->execute_api('core_user_get_users', $params);
        
        $this->assertEquals('success', $result['status']);
        $this->assertNotEmpty($result['data']['users']);
        $this->assertEquals($user->id, $result['data']['users'][0]['id']);
    }

    public function test_execute_api_catches_exceptions_gracefully(): void {
        $this->setAdminUser();

        $service = new discovery_service();
        
        // Execute a missing function
        $result = $service->execute_api('this_function_does_not_exist', []);
        
        $this->assertEquals('error', $result['status']);
        $this->assertNotEmpty($result['message']);
        
        // Execute with missing params (which triggers exception)
        $result = $service->execute_api('core_user_get_users', []);
        $this->assertEquals('error', $result['status']);
        $this->assertNotEmpty($result['message']);
    }
}
