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
 * Builds an activity's own settings form (mod_<name>_mod_form) the way course/modedit.php does.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class module_form {
    /**
     * Create the module's settings form and load its current data, or null when the module has no form.
     *
     * @param stdClass $course Course record.
     * @param string $modulename Module name without mod_.
     * @param stdClass $current Current data (prepare_new_moduleinfo_data() or get_moduleinfo_data()).
     * @param int $sectionnum Section number.
     * @param stdClass|null $cm Course module when editing.
     * @return \moodleform|null
     */
    public static function create(
        stdClass $course,
        string $modulename,
        stdClass $current,
        int $sectionnum,
        ?stdClass $cm
    ): ?\moodleform {
        global $CFG;

        if (
            !\core_component::is_valid_plugin_name('mod', $modulename)
                || !file_exists($CFG->dirroot . '/mod/' . $modulename . '/mod_form.php')
        ) {
            return null;
        }
        moodle_lib::load('course/moodleform_mod.php', 'mod/' . $modulename . '/mod_form.php');

        $classname = 'mod_' . $modulename . '_mod_form';
        $mform = new $classname($current, $sectionnum, $cm, $course);
        $mform->set_data($current);

        return $mform;
    }
}
