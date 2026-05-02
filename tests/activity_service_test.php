<?php
namespace webservice_mcp;

use advanced_testcase;
use core_external\external_api;
use webservice_mcp\local\wrapper\activity_service;

class activity_service_test extends advanced_testcase {
    protected function setUp(): void {
        $this->resetAfterTest(true);
        external_api::set_context_restriction(null);
    }

    public function test_wrapper_course_add_module_provisions_module(): void {
        global $DB;
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        
        $service = new activity_service();
        
        $options = [
            'intro' => 'Test intro',
            'introeditor' => ['text' => 'Test intro', 'format' => FORMAT_HTML, 'itemid' => 0],
            'introformat' => FORMAT_HTML,
            'externalurl' => 'https://moodle.org',
            'display' => 0,
            'section' => 1,
            'visible' => 1
        ];

        $module = $service->add_module($course->id, 'url', 'Test URL', $options);
        
        $this->assertArrayHasKey('coursemodule', $module);
        
        $cm = get_coursemodule_from_id('url', $module['coursemodule'], 0, false, MUST_EXIST);
        $this->assertEquals('Test URL', $cm->name);
    }

    public function test_wrapper_module_read_data_returns_structured_data(): void {
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $course->id]);
        
        // Ensure student has submitted
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        
        $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id);
        
        $service = new activity_service();
        $data = $service->read_module_data($cm->id, 'get_instance');
        
        $this->assertIsArray($data);
    }
}
