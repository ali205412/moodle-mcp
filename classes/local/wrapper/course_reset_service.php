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
use core_external\external_api;

/**
 * Course reset through course/reset.php's form and reset_course_userdata().
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_reset_service {
    /**
     * Reset a course: delete user data such as enrolments, grades, submissions and posts, as the reset page does.
     *
     * @param int $courseid Course id (not the site).
     * @param array $options Reset form field name => value, e.g. reset_events, unenrol_users (role ids),
     *     reset_gradebook_grades, reset_forum_all, reset_start_date (timestamp).
     * @param bool $usedefaults Start from the page's "Select default" choices before applying options.
     * @return array
     */
    public function reset_course(int $courseid, array $options, bool $usedefaults = false): array {
        global $SITE;
        moodle_lib::load(
            'course/lib.php',
            'course/reset_form.php',
            'backup/util/interfaces/checksumable.class.php',
            'backup/backup.class.php',
            'backup/util/helper/backup_helper.class.php'
        );

        if ($courseid === (int)$SITE->id) {
            throw arguments::invalid('The site front page cannot be reset.');
        }
        $course = \get_course($courseid);
        $context = context_course::instance($course->id);
        // Sets $COURSE, which the reset form reads, like require_login($course) on the page.
        external_api::validate_context($context);
        \require_capability('moodle/course:reset', $context);
        if (!$usedefaults && $options === []) {
            throw arguments::invalid('Choose what to reset in options, or set usedefaults=true for the page\'s defaults.');
        }

        $mform = new \course_reset_form();
        if ($usedefaults) {
            $mform->load_defaults();
        }
        $fields = form_submission::field_names($mform);
        $unknown = array_diff(array_map('strval', array_keys($options)), $fields);
        if ($unknown !== []) {
            throw arguments::invalid('Unknown reset options: ' . implode(', ', $unknown) . '. Available: '
                . implode(', ', array_diff($fields, ['id'])) . '.');
        }
        if (array_key_exists('id', $options)) {
            throw arguments::invalid('The course is given by courseid, not options.id.');
        }

        $changes = $options;

        [$data, $errors] = form_submission::submit($mform, $changes);
        if ($data === null) {
            throw form_submission::rejected('reset options', $errors);
        }
        $data->reset_start_date_old = $course->startdate;
        $data->reset_end_date_old = $course->enddate;

        $status = \reset_course_userdata($data);

        return [
            'courseid' => (int)$course->id,
            'results' => array_values(array_map(static fn(array $item): array => [
                'component' => trim(strip_tags((string)$item['component'])),
                'item' => trim(strip_tags((string)$item['item'])),
                'error' => $item['error'] === false ? null : trim(strip_tags((string)$item['error'])),
            ], $status)),
        ];
    }
}
