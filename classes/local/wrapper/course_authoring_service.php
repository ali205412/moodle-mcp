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
use core_courseformat\base as course_format;
use core_courseformat\stateupdates;
use core_external\external_api;
use stdClass;

/**
 * Wrapper implementations for priority course authoring gaps.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_authoring_service {
    /**
     * Ensure the requested course sections exist.
     *
     * @param int $courseid Course id.
     * @param array $sectionnums Section numbers.
     * @return array
     */
    public function create_missing_sections(int $courseid, array $sectionnums): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $sectionnums = array_values(array_unique(array_map('intval', $sectionnums)));
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $coursecontext = context_course::instance($courseid);

        external_api::validate_context($coursecontext);
        \require_all_capabilities(['moodle/course:update', 'moodle/course:manageactivities'], $coursecontext);

        $created = \course_create_sections_if_missing($course, $sectionnums);
        $modinfo = get_fast_modinfo($course);
        $sectionsinfo = $modinfo->get_section_info_all();

        $sections = [];
        foreach ($sectionnums as $sectionnum) {
            if (!isset($sectionsinfo[$sectionnum])) {
                continue;
            }
            $sectioninfo = $sectionsinfo[$sectionnum];
            $sections[] = [
                'id' => (int)$sectioninfo->id,
                'section' => (int)$sectioninfo->section,
                'sectionnum' => (int)$sectioninfo->section,
                'name' => (string)($sectioninfo->name ?? ''),
            ];
        }

        return [
            'status' => true,
            'created' => (bool)$created,
            'sections' => $sections,
        ];
    }

    /**
     * Add a new course section, optionally after a target section.
     *
     * @param int $courseid Course id.
     * @param int|null $targetsectionid Optional target section id.
     * @return array
     */
    public function add_section_after(int $courseid, ?int $targetsectionid = null): array {
        $course = $this->get_course($courseid);
        $coursecontext = context_course::instance($courseid);

        external_api::validate_context($coursecontext);
        \require_capability('moodle/course:update', $coursecontext);

        return [
            'status' => true,
            'statejson' => $this->run_state_action($course, 'section_add', [], $targetsectionid),
        ];
    }

    /**
     * Set visibility for one or more course sections.
     *
     * @param int $courseid Course id.
     * @param array $sectionids Section ids.
     * @param bool $visible Whether sections should be visible.
     * @return array
     */
    public function set_section_visibility(int $courseid, array $sectionids, bool $visible): array {
        $course = $this->get_course($courseid);
        $coursecontext = context_course::instance($courseid);

        external_api::validate_context($coursecontext);
        \require_all_capabilities(['moodle/course:update', 'moodle/course:sectionvisibility'], $coursecontext);

        return [
            'status' => true,
            'action' => $visible ? 'section_show' : 'section_hide',
            'statejson' => $this->run_state_action(
                $course,
                $visible ? 'section_show' : 'section_hide',
                $sectionids
            ),
        ];
    }

    /**
     * Delete one or more course sections.
     *
     * @param int $courseid Course id.
     * @param array $sectionids Section ids.
     * @return array
     */
    public function delete_sections(int $courseid, array $sectionids): array {
        $course = $this->get_course($courseid);
        $coursecontext = context_course::instance($courseid);

        external_api::validate_context($coursecontext);
        \require_all_capabilities(['moodle/course:update', 'moodle/course:movesections'], $coursecontext);

        return [
            'status' => true,
            'statejson' => $this->run_state_action($course, 'section_delete', $sectionids),
        ];
    }

    /**
     * Move course modules to another section or before another cm.
     *
     * @param int $courseid Course id.
     * @param array $cmids Module ids.
     * @param int|null $targetsectionid Target section id.
     * @param int|null $targetcmid Target cm id.
     * @return array
     */
    public function move_modules(int $courseid, array $cmids, ?int $targetsectionid = null, ?int $targetcmid = null): array {
        $course = $this->get_course($courseid);
        $coursecontext = context_course::instance($courseid);

        external_api::validate_context($coursecontext);
        \require_capability('moodle/course:manageactivities', $coursecontext);

        $updates = $this->stateupdates($course);
        $actions = $this->stateactions($course);
        $actions->cm_move($updates, $course, array_values(array_map('intval', $cmids)), $targetsectionid, $targetcmid);
        course_format::session_cache_reset($course);

        return [
            'status' => true,
            'statejson' => json_encode($updates),
        ];
    }

    /**
     * Set visibility for one or more course modules.
     *
     * @param int $courseid Course id.
     * @param array $cmids Course module ids.
     * @param string $visibility One of show, hide, or stealth.
     * @return array
     */
    public function set_module_visibility(int $courseid, array $cmids, string $visibility): array {
        $course = $this->get_course($courseid);
        $coursecontext = context_course::instance($courseid);

        external_api::validate_context($coursecontext);
        \require_capability('moodle/course:activityvisibility', $coursecontext);

        $action = match ($visibility) {
            'show' => 'cm_show',
            'hide' => 'cm_hide',
            'stealth' => 'cm_stealth',
            default => throw new \moodle_exception('invalidparameter'),
        };

        return [
            'status' => true,
            'action' => $action,
            'statejson' => $this->run_state_action($course, $action, $cmids),
        ];
    }

    /**
     * Duplicate course modules, optionally into a target section or before another cm.
     *
     * @param int $courseid Course id.
     * @param array $cmids Course module ids.
     * @param int|null $targetsectionid Optional target section id.
     * @param int|null $targetcmid Optional target cm id.
     * @return array
     */
    public function duplicate_modules(
        int $courseid,
        array $cmids,
        ?int $targetsectionid = null,
        ?int $targetcmid = null
    ): array {
        $course = $this->get_course($courseid);
        $coursecontext = context_course::instance($courseid);

        external_api::validate_context($coursecontext);
        \require_all_capabilities(
            ['moodle/backup:backuptargetimport', 'moodle/restore:restoretargetimport'],
            $coursecontext
        );

        return [
            'status' => true,
            'statejson' => $this->run_state_action($course, 'cm_duplicate', $cmids, $targetsectionid, $targetcmid),
        ];
    }

    /**
     * Delete one or more course modules.
     *
     * @param int $courseid Course id.
     * @param array $cmids Course module ids.
     * @return array
     */
    public function delete_modules(int $courseid, array $cmids): array {
        $course = $this->get_course($courseid);
        $coursecontext = context_course::instance($courseid);

        external_api::validate_context($coursecontext);
        \require_capability('moodle/course:manageactivities', $coursecontext);

        return [
            'status' => true,
            'statejson' => $this->run_state_action($course, 'cm_delete', $cmids),
        ];
    }

    /**
     * Move course sections after a target section.
     *
     * @param int $courseid Course id.
     * @param array $sectionids Section ids.
     * @param int $targetsectionid Target section id.
     * @return array
     */
    public function move_sections_after(int $courseid, array $sectionids, int $targetsectionid): array {
        $course = $this->get_course($courseid);
        $coursecontext = context_course::instance($courseid);

        external_api::validate_context($coursecontext);
        \require_capability('moodle/course:movesections', $coursecontext);

        $updates = $this->stateupdates($course);
        $actions = $this->stateactions($course);
        $actions->section_move_after($updates, $course, array_values(array_map('intval', $sectionids)), $targetsectionid, null);
        course_format::session_cache_reset($course);

        return [
            'status' => true,
            'statejson' => json_encode($updates),
        ];
    }

    /**
     * Load a course record.
     *
     * @param int $courseid Course id.
     * @return stdClass
     */
    private function get_course(int $courseid): stdClass {
        global $DB;
        return $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    }

    /**
     * Create a stateupdates tracker for the course format.
     *
     * @param stdClass $course Course record.
     * @return stateupdates
     */
    private function stateupdates(stdClass $course): stateupdates {
        $courseformat = \course_get_format($course);
        $updatesclass = 'format_' . $courseformat->get_format() . '\\courseformat\\stateupdates';
        if (!class_exists($updatesclass)) {
            $updatesclass = 'core_courseformat\\stateupdates';
        }

        return new $updatesclass($courseformat);
    }

    /**
     * Create a stateactions helper for the course format.
     *
     * @param stdClass $course Course record.
     * @return \core_courseformat\stateactions
     */
    private function stateactions(stdClass $course): \core_courseformat\stateactions {
        $courseformat = \course_get_format($course);
        $actionsclass = 'format_' . $courseformat->get_format() . '\\courseformat\\stateactions';
        if (!class_exists($actionsclass)) {
            $actionsclass = 'core_courseformat\\stateactions';
        }

        return new $actionsclass();
    }

    /**
     * Execute a standard course-format state action and return encoded updates.
     *
     * @param stdClass $course Course record.
     * @param string $action State action name.
     * @param array $ids Affected ids.
     * @param int|null $targetsectionid Optional target section id.
     * @param int|null $targetcmid Optional target cm id.
     * @return string
     */
    private function run_state_action(
        stdClass $course,
        string $action,
        array $ids = [],
        ?int $targetsectionid = null,
        ?int $targetcmid = null
    ): string {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $updates = $this->stateupdates($course);
        $actions = $this->stateactions($course);
        if (!is_callable([$actions, $action])) {
            throw new \moodle_exception('invalidparameter');
        }

        $actions->$action(
            $updates,
            $course,
            array_values(array_map('intval', $ids)),
            $targetsectionid,
            $targetcmid
        );
        course_format::session_cache_reset($course);

        return json_encode($updates);
    }
}
