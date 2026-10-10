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

use context_course;
use context_module;
use core_external\external_api;
use stdClass;

/**
 * Wrapper implementations for creating and inspecting course modules.
 *
 * @package     webservice_mcp
 * @copyright   2025 MohammadReza PourMohammad
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_service {
    /** Option keys that identify or place the module and must come from dedicated arguments. */
    private const RESERVED_OPTIONS = [
        'id', 'course', 'courseid', 'modulename', 'module', 'coursemodule', 'instance', 'section', 'sectionid',
        'visible', 'visibleold', 'name', 'intro', 'introformat', 'introeditor', 'add', 'update', 'return', 'sr',
    ];

    /** Instance fields never returned by read_module_data (passwords, shared secrets, keys, tokens). */
    private const SECRET_FIELD_PATTERN = '/pass|secret|key|token|salt/i';

    /**
     * Add a new module to a course through Moodle's create_module() flow.
     *
     * @param int $courseid Course id.
     * @param string $modulename Module plugin name without the mod_ prefix.
     * @param string $name Module name.
     * @param array $options Additional module form fields.
     * @param int $section Section number.
     * @param bool $visible Whether the module is visible to students.
     * @param string $intro Description.
     * @param int $introformat Description format.
     * @return array
     */
    public function add_module(
        int $courseid,
        string $modulename,
        string $name,
        array $options = [],
        int $section = 0,
        bool $visible = true,
        string $intro = '',
        int $introformat = FORMAT_HTML
    ): array {
        global $DB;

        moodle_lib::load('course/lib.php', 'course/modlib.php', 'lib/gradelib.php');

        $context = context_course::instance($courseid);
        external_api::validate_context($context);
        \require_capability('moodle/course:manageactivities', $context);

        if (trim($name) === '') {
            throw arguments::invalid('Module name must not be empty.');
        }
        foreach (array_keys($options) as $key) {
            if (in_array(strtolower((string)$key), self::RESERVED_OPTIONS, true)) {
                throw new \moodle_exception('wrapper:reservedmoduleoption', 'webservice_mcp', '', $key);
            }
        }
        $this->validate_module_options($courseid, $context, $options);

        $moduleinfo = (object)$options;
        $moduleinfo->course = $courseid;
        $moduleinfo->modulename = $modulename;
        $moduleinfo->name = $name;
        $moduleinfo->section = $section;
        $moduleinfo->visible = (int)$visible;
        $moduleinfo->introeditor = ['text' => $intro, 'format' => $introformat, 'itemid' => 0];

        if ($modulename === 'lti') {
            $this->validate_lti_type($courseid, $context, $options);
        }
        [$moduleinfo, $errors] = $this->apply_module_form(\get_course($courseid), $moduleinfo);
        if ($errors !== []) {
            throw form_submission::rejected('module settings', $errors);
        }

        // Capability mod/<name>:addinstance and section validity are enforced by create_module().
        $moduleinfo = \create_module($moduleinfo);
        $cm = \get_fast_modinfo($courseid)->get_cm((int)$moduleinfo->coursemodule);

        return [
            'coursemodule' => (int)$cm->id,
            'instance' => (int)$cm->instance,
            'modulename' => (string)$cm->modname,
            'courseid' => (int)$cm->course,
            'section' => (int)$cm->sectionnum,
            'name' => (string)$cm->name,
            'visible' => (bool)$cm->visible,
            'url' => $cm->url ? $cm->url->out(false) : '',
        ];
    }

    /**
     * Inspect a course module the current user can see.
     *
     * Instance settings and file areas are only returned to users who can manage activities in the module,
     * mirroring who can open its settings form.
     *
     * @param int $cmid Course module id.
     * @return array
     */
    public function read_module_data(int $cmid): array {
        global $DB;
        moodle_lib::load('course/lib.php');

        [$course, $cm] = \get_course_and_cm_from_cmid($cmid);
        $context = context_module::instance($cm->id);
        external_api::validate_context($context);
        if (!$cm->uservisible) {
            throw new \moodle_exception('activityiscurrentlyhidden');
        }

        $canmanage = \has_capability('moodle/course:manageactivities', $context);
        $record = $DB->get_record($cm->modname, ['id' => $cm->instance], '*', MUST_EXIST);

        $intro = '';
        if (isset($record->intro)) {
            $intro = \format_module_intro($cm->modname, $record, $cm->id);
        }

        $instance = null;
        $fileareas = [];
        if ($canmanage) {
            $instance = [];
            foreach ((array)$record as $field => $value) {
                if (is_scalar($value) || $value === null) {
                    if (preg_match(self::SECRET_FIELD_PATTERN, (string)$field) !== 1) {
                        $instance[$field] = $value;
                    }
                }
            }
            $fileareas = $this->file_areas($context->id);
        }

        $completion = new \completion_info($course);

        return [
            'cm' => [
                'id' => (int)$cm->id,
                'courseid' => (int)$cm->course,
                'modname' => (string)$cm->modname,
                'instance' => (int)$cm->instance,
                'name' => $cm->get_formatted_name(),
                'idnumber' => (string)$cm->idnumber,
                'sectionnum' => (int)$cm->sectionnum,
                'sectionid' => (int)$cm->section,
                'visible' => (bool)$cm->visible,
                'visibleoncoursepage' => (bool)$cm->visibleoncoursepage,
                'uservisible' => (bool)$cm->uservisible,
                'available' => (bool)$cm->available,
                'availableinfo' => empty($cm->availableinfo)
                    ? ''
                    : \core_availability\info::format_info($cm->availableinfo, $course),
                'groupmode' => (int)$cm->groupmode,
                'groupingid' => (int)$cm->groupingid,
                'completion' => (int)$cm->completion,
                'completionenabled' => $completion->is_enabled($cm) != COMPLETION_TRACKING_NONE,
                'completionexpected' => (int)$cm->completionexpected,
                'added' => (int)$cm->added,
                'url' => $cm->url ? $cm->url->out(false) : '',
            ],
            'intro' => $intro,
            'instance' => $instance,
            'fileareas' => $fileareas,
            'canmanage' => $canmanage,
        ];
    }

    /**
     * Validate option values that create_module() would otherwise accept unchecked.
     *
     * @param int $courseid Course id.
     * @param context_course $context Course context.
     * @param array $options Module options.
     * @return void
     */
    private function validate_module_options(int $courseid, context_course $context, array $options): void {
        global $DB;

        if (!empty($options['lang'])) {
            \require_capability('moodle/course:setforcedlanguage', $context);
        }

        if (
            !empty($options['groupingid']) &&
                !$DB->record_exists('groupings', ['id' => (int)$options['groupingid'], 'courseid' => $courseid])
        ) {
            throw arguments::invalid('groupingid does not belong to this course.');
        }

        if (isset($options['cmidnumber']) && !\grade_verify_idnumber((string)$options['cmidnumber'], $courseid)) {
            throw new \moodle_exception('idnumbertaken');
        }
    }

    /**
     * Complete the settings with the module form's defaults and submit the form, as course/modedit.php does: form
     * defaults overlaid with the requested settings, cleaned, validated and post-processed by the form's get_data().
     *
     * @param stdClass $course Course record.
     * @param stdClass $moduleinfo Requested module settings.
     * @return array [stdClass complete settings, array field => error message]
     */
    private function apply_module_form(stdClass $course, stdClass $moduleinfo): array {
        [, , $cw, $cm, $defaults] = \prepare_new_moduleinfo_data(
            $course,
            (string)$moduleinfo->modulename,
            (int)$moduleinfo->section
        );
        $mform = module_form::create($course, (string)$moduleinfo->modulename, $defaults, (int)$cw->section, $cm);
        if ($mform === null) {
            // Unknown modules are rejected by create_module() itself.
            return [$moduleinfo, []];
        }

        [$data, $errors] = form_submission::submit($mform, (array)$moduleinfo);
        if ($data === null) {
            return [$moduleinfo, $errors];
        }

        // Settings the form does not know keep the requested or default value.
        return [(object)((array)$data + (array)$moduleinfo + (array)$defaults), []];
    }

    /**
     * Require an LTI tool type that is available to the user in this course, as the LTI activity chooser does.
     *
     * @param int $courseid Course id.
     * @param context_course $context Course context.
     * @param array $options Module options.
     * @return void
     */
    private function validate_lti_type(int $courseid, context_course $context, array $options): void {
        global $USER;
        moodle_lib::load('mod/lti/locallib.php');

        $typeid = (int)($options['typeid'] ?? 0);
        if (class_exists('\\mod_lti\\local\\types_helper')) {
            // Moodle 4.3+: manual (typeid 0) instances can no longer be created.
            $types = \mod_lti\local\types_helper::get_lti_types_by_course($courseid, (int)$USER->id);
            $manualallowed = false;
        } else {
            $types = \lti_get_lti_types_by_course($courseid);
            $manualallowed = \has_capability('mod/lti:addmanualinstance', $context);
        }

        if ($typeid === 0 ? !$manualallowed : !isset($types[$typeid])) {
            throw arguments::invalid('typeid must be an LTI tool type available in this course.');
        }
    }

    /**
     * Summarize the file areas used in a module context.
     *
     * @param int $contextid Module context id.
     * @return array
     */
    private function file_areas(int $contextid): array {
        global $DB;

        $rs = $DB->get_recordset_sql(
            "SELECT component, filearea, COUNT(1) AS filecount
               FROM {files}
              WHERE contextid = :contextid AND filename <> '.'
           GROUP BY component, filearea
           ORDER BY component, filearea",
            ['contextid' => $contextid]
        );
        $areas = [];
        foreach ($rs as $row) {
            $areas[] = [
                'component' => (string)$row->component,
                'filearea' => (string)$row->filearea,
                'filecount' => (int)$row->filecount,
            ];
        }
        $rs->close();

        return $areas;
    }
}
