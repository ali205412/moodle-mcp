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
 * Read and change activity and section settings through the same forms as course/modedit.php and
 * course/editsection.php.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module_settings_service {
    /** Settings that identify the module and cannot be changed through settings. */
    private const RESERVED_SETTINGS = [
        'id', 'course', 'coursemodule', 'instance', 'module', 'modulename', 'section', 'add', 'update', 'return', 'sr',
    ];

    /**
     * The module's settings as its edit form shows them (course/modedit.php?update=cmid).
     *
     * @param int $cmid Course module id.
     * @return array
     */
    public function get_module_settings(int $cmid): array {
        [$cm, $mform, $current] = $this->edit_form($cmid);

        return [
            'cmid' => (int)$cm->id,
            'modulename' => (string)$cm->modname,
            'name' => (string)$cm->name,
            'settings' => form_submission::current_values($mform),
        ];
    }

    /**
     * Change module settings as saving the module's edit form does: the form validates the merged settings and
     * update_module() saves them.
     *
     * @param int $cmid Course module id.
     * @param array $settings Form field name => new value (see get_module_settings for names and formats).
     * @return array
     */
    public function update_module(int $cmid, array $settings): array {
        if ($settings === []) {
            throw arguments::invalid('settings must contain at least one field to change.');
        }
        foreach (array_keys($settings) as $key) {
            if (in_array(strtolower((string)$key), self::RESERVED_SETTINGS, true)) {
                throw arguments::invalid("The setting \"{$key}\" identifies the module and cannot be changed here.");
            }
        }

        if (array_key_exists('availabilityconditionsjson', $settings)) {
            $settings['availabilityconditionsjson'] = self::availability_json($settings['availabilityconditionsjson']);
        }

        [$cm, $mform, $current] = $this->edit_form($cmid);
        $unknown = array_diff(array_map('strval', array_keys($settings)), form_submission::field_names($mform));
        if ($unknown !== []) {
            throw arguments::invalid('Unknown settings for this ' . $cm->modname . ': ' . implode(', ', $unknown)
                . '. Use wrapper_course_get_module_settings to see the available names.');
        }

        [$data, $errors] = form_submission::submit($mform, $settings);
        if ($data === null) {
            throw form_submission::rejected('module settings', $errors);
        }

        // Same saving path as modedit.php (update_moduleinfo() via core's update_module()).
        \update_module($data);
        $updated = \get_fast_modinfo($cm->course)->get_cm($cm->id);

        return [
            'cmid' => (int)$updated->id,
            'modulename' => (string)$updated->modname,
            'name' => (string)$updated->name,
            'visible' => (bool)$updated->visible,
            'updated' => array_values(array_map('strval', array_keys($settings))),
        ];
    }

    /**
     * Change a course section's name, summary, availability or visibility, as course/editsection.php and the
     * course page's show/hide action do.
     *
     * @param int $sectionid Section id (course_sections.id).
     * @param array $settings name, summary, summaryformat, availability (JSON), visible, or course format options.
     * @return array
     */
    public function update_section(int $sectionid, array $settings): array {
        global $DB;
        moodle_lib::load('course/lib.php');

        $record = $DB->get_record('course_sections', ['id' => $sectionid]);
        if (!$record) {
            throw arguments::invalid("Section {$sectionid} does not exist.");
        }
        $course = \get_course((int)$record->course);
        $context = context_course::instance($course->id);
        external_api::validate_context($context);
        \require_capability('moodle/course:update', $context);

        if ($settings === []) {
            throw arguments::invalid('settings must contain at least one of name, summary, availability, visible.');
        }

        $sectioninfo = \get_fast_modinfo($course)->get_section_info_by_id($sectionid, MUST_EXIST);
        if (array_key_exists('visible', $settings)) {
            // The course page's show/hide action requires both capabilities.
            \require_capability('moodle/course:sectionvisibility', $context);
            \set_section_visible($course->id, (int)$sectioninfo->section, (int)arguments::to_bool($settings['visible']));
            unset($settings['visible']);
        }

        if ($settings !== []) {
            $this->save_section_form($course, $context, $sectionid, $settings);
        }

        $sectioninfo = \get_fast_modinfo($course)->get_section_info_by_id($sectionid, MUST_EXIST);
        return [
            'sectionid' => (int)$sectioninfo->id,
            'section' => (int)$sectioninfo->section,
            'name' => \get_section_name($course, $sectioninfo),
            'visible' => (bool)$sectioninfo->visible,
            'availability' => $sectioninfo->availability === null ? null : (string)$sectioninfo->availability,
        ];
    }

    /**
     * Submit the section edit form with the requested changes and save it like editsection.php.
     *
     * @param stdClass $course Course.
     * @param context_course $context Course context.
     * @param int $sectionid Section id.
     * @param array $settings Requested changes.
     * @return void
     */
    private function save_section_form(stdClass $course, context_course $context, int $sectionid, array $settings): void {
        global $CFG;

        $sectioninfo = \get_fast_modinfo($course)->get_section_info_by_id($sectionid, MUST_EXIST);
        $editoroptions = ['context' => $context, 'maxfiles' => EDITOR_UNLIMITED_FILES, 'maxbytes' => $CFG->maxbytes,
            'trusttext' => false, 'noclean' => true, 'subdirs' => true];
        $courseformat = \course_get_format($course);
        $mform = $courseformat->editsection_form(new \moodle_url('/course/editsection.php', ['id' => $sectionid]), [
            'cs' => $sectioninfo,
            'editoroptions' => $editoroptions,
            'defaultsectionname' => $courseformat->get_default_section_name($sectioninfo),
        ]);
        $initial = \convert_to_array($sectioninfo);
        if (!empty($CFG->enableavailability)) {
            $initial['availabilityconditionsjson'] = $sectioninfo->availability;
        }
        $mform->set_data($initial);
        $quickform = form_submission::quickform($mform);

        $changes = [];
        foreach ($settings as $key => $value) {
            $key = (string)$key;
            if ($key === 'name') {
                // Moodle 4.2 shows a "custom name" checkbox group; the form submission handles both shapes.
                $changes['name'] = trim((string)$value);
            } else if ($key === 'summary') {
                $current = $quickform->_defaultValues['summary_editor'] ?? [];
                $changes['summary_editor'] = [
                    'text' => (string)$value,
                    'format' => (int)($settings['summaryformat'] ?? FORMAT_HTML),
                    'itemid' => $current['itemid'] ?? \file_get_unused_draft_itemid(),
                ];
            } else if ($key === 'summaryformat') {
                continue;
            } else if ($key === 'availability') {
                if (empty($CFG->enableavailability)) {
                    throw arguments::invalid('Restrict access (availability) is disabled on this site.');
                }
                $changes['availabilityconditionsjson'] = self::availability_json($value);
            } else if ($quickform->elementExists($key) && !in_array($key, ['id', 'sesskey'], true)) {
                $changes[$key] = $value;
            } else {
                throw arguments::invalid("Unknown section setting \"{$key}\"; use name, summary, summaryformat, "
                    . 'availability, visible or one of: ' . implode(', ', form_submission::field_names($mform)) . '.');
            }
        }

        [$data, $errors] = form_submission::submit($mform, $changes);
        if ($data === null) {
            throw form_submission::rejected('section settings', $errors);
        }
        if (!empty($CFG->enableavailability)) {
            $data->availability = $data->availabilityconditionsjson === '' ? null : $data->availabilityconditionsjson;
            unset($data->availabilityconditionsjson);
        }
        \course_update_section($course, $sectioninfo, $data);
    }

    /**
     * Load a module's edit form as course/modedit.php?update=cmid does.
     *
     * @param int $cmid Course module id.
     * @return array [stdClass cm, \moodleform form, stdClass current data]
     */
    private function edit_form(int $cmid): array {
        moodle_lib::load(
            'course/lib.php',
            'course/modlib.php',
            'lib/gradelib.php',
            'lib/completionlib.php',
            'lib/plagiarismlib.php'
        );

        $cm = \get_coursemodule_from_id('', $cmid);
        if (!$cm) {
            throw arguments::invalid("Course module {$cmid} does not exist.");
        }
        external_api::validate_context(context_module::instance($cm->id));
        $course = \get_course((int)$cm->course);

        // Requires moodle/course:manageactivities in the module, like the edit page.
        [$cm, , , $current, $cw] = \get_moduleinfo_data($cm, $course);
        $current->update = $cm->id;
        $current->return = 0;
        $current->sr = null;

        $mform = module_form::create($course, (string)$current->modulename, $current, (int)$cw->section, $cm);
        if ($mform === null) {
            throw arguments::invalid("The {$current->modulename} module has no settings form.");
        }

        return [$cm, $mform, $current];
    }

    /**
     * Validate a restriction (availability) JSON value; the page's own check relies on its JavaScript editor.
     *
     * @param mixed $value JSON string or decoded structure; empty for no restriction.
     * @return string
     */
    private static function availability_json(mixed $value): string {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }
        $json = is_string($value) ? $value : json_encode($value);
        $decoded = json_decode($json);
        if (!is_object($decoded)) {
            throw arguments::invalid('availability must be a restriction JSON object such as '
                . '{"op":"&","c":[{"type":"date","d":">=","t":1735689600}],"showc":[true]}.');
        }
        try {
            new \core_availability\tree($decoded);
        } catch (\coding_exception $exception) {
            throw arguments::invalid('Invalid availability restriction: '
                . trim(str_replace('Coding error detected, it must be fixed by a programmer:', '', $exception->getMessage())));
        }

        return $json;
    }
}
