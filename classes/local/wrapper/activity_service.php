<?php
namespace webservice_mcp\local\wrapper;

use context_course;
use context_module;
use core_external\external_api;
use stdClass;

class activity_service {
    
    /**
     * Adds a new module to a course.
     */
    public function add_module(int $courseid, string $modulename, string $name, array $options = []): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $context = context_course::instance($courseid);
        external_api::validate_context($context);
        \require_capability('moodle/course:manageactivities', $context);

        $moduleinfo = new stdClass();
        $moduleinfo->course = $courseid;
        $moduleinfo->modulename = $modulename;
        $moduleinfo->name = $name;
        
        foreach ($options as $key => $value) {
            $moduleinfo->{$key} = $value;
        }

        $moduleinfo = \create_module($moduleinfo);

        return (array)$moduleinfo;
    }

    /**
     * Reads structured data from internal modules safely.
     */
    public function read_module_data(int $cmid, string $action): array {
        global $CFG, $DB, $USER;

        list($course, $cm) = \get_course_and_cm_from_cmid($cmid);
        $context = context_module::instance($cmid);
        
        external_api::validate_context($context);

        $modulename = $cm->modname;
        $locallib = $CFG->dirroot . '/mod/' . $modulename . '/locallib.php';

        if (!file_exists($locallib)) {
            throw new \coding_exception("locallib.php not found for module {$modulename}");
        }

        require_once($locallib);

        switch ($modulename) {
            case 'assign':
                \require_capability('mod/assign:grade', $context);
                
                // Moodle assign class resides in mod/assign/locallib.php
                $assign = new \assign($context, $cm, $course);
                
                if ($action === 'get_instance' && method_exists($assign, 'get_instance')) {
                    $instance = $assign->get_instance();
                    return [(array)$instance];
                }
                break;
            default:
                throw new \coding_exception("Unsupported action {$action} for module {$modulename}");
        }

        throw new \coding_exception("Action {$action} not recognized or missing capability");
    }
}
