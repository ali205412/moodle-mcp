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

/**
 * Seed a throwaway Moodle for live end-to-end MCP client tests (never run on a real site).
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/enrol/locallib.php');

if (empty($CFG->mcpe2e)) {
    cli_error('Refusing to seed: $CFG->mcpe2e is not set (this script is for throwaway test sites only).');
}

\core\session\manager::set_user(get_admin());
set_config('enablewebservices', 1);
set_config('webserviceprotocols', 'mcp');

// Every signed-in user may use the connector on this test site.
$userrole = $DB->get_field('role', 'id', ['shortname' => 'user']);
assign_capability('webservice/mcp:use', CAP_ALLOW, $userrole, context_system::instance()->id, true);

$category = core_course_category::get_default();
$course = create_course((object)['fullname' => 'E2E Biology', 'shortname' => 'E2EBIO', 'category' => $category->id,
    'summary' => 'Live test course', 'numsections' => 3]);

$users = [];
foreach (['teacher' => 'editingteacher', 'student' => 'student'] as $username => $role) {
    $users[$username] = user_create_user((object)['username' => $username, 'password' => 'Pass-word1!',
        'firstname' => ucfirst($username), 'lastname' => 'E2E', 'email' => "{$username}@example.com",
        'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id]);
    enrol_try_internal_enrol($course->id, $users[$username], $DB->get_field('role', 'id', ['shortname' => $role]));
}

// A page and a file resource, created the way the course editor does.
$draftid = file_get_unused_draft_itemid();
get_file_storage()->create_file_from_string([
    'contextid' => context_user::instance(get_admin()->id)->id, 'component' => 'user', 'filearea' => 'draft',
    'itemid' => $draftid, 'filepath' => '/', 'filename' => 'syllabus.txt',
], "Week 1: cells\nWeek 2: genetics\n");
foreach (
    [
    ['modulename' => 'page', 'name' => 'Welcome page', 'content' => '<p>Welcome to biology</p>', 'contentformat' => FORMAT_HTML],
    ['modulename' => 'resource', 'name' => 'Syllabus', 'files' => $draftid],
    ] as $module
) {
    create_module((object)($module + ['course' => $course->id, 'section' => 1, 'visible' => 1,
        'introeditor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0], 'display' => 0]));
}

echo json_encode(['courseid' => (int)$course->id, 'teacherid' => (int)$users['teacher']->id,
    'studentid' => (int)$users['student']->id]), "\n";
