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

namespace webservice_mcp\local\files;

use context_course;
use context_module;
use core_external\external_api;
use moodle_exception;
use webservice_mcp\local\mcp\call_context;

/**
 * Zip exports: checks permissions now and returns a download ticket; the download endpoint streams the zip.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export_service {
    /**
     * export_course_content: link to a zip of the course content (as "Download course content").
     *
     * @param array $args courseid.
     * @param call_context $ctx Request context.
     * @return array
     */
    public function course_content(array $args, call_context $ctx): array {
        global $USER;

        file_service::apply_restriction($ctx);
        $context = context_course::instance((int)($args['courseid'] ?? 0));
        locator::check_restriction($context, $ctx->restrictedcontext);
        external_api::validate_context($context);
        if (!\core\content::can_export_context($context, $USER)) {
            throw new moodle_exception(
                'nopermissions',
                'error',
                '',
                'download course content (it may be disabled for this site or course)'
            );
        }
        $link = tickets::download_url($ctx, ['k' => tickets::KIND_COURSE_CONTENT, 'courseid' => (int)$context->instanceid]);
        return self::link_result($link, 'course-' . $context->instanceid . '.zip');
    }

    /**
     * export_assignment_submissions: link to a zip of all (or one group's) submissions.
     *
     * @param array $args cmid, groupid.
     * @param call_context $ctx Request context.
     * @return array
     */
    public function assign_submissions(array $args, call_context $ctx): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        file_service::apply_restriction($ctx);
        [$course, $cm] = get_course_and_cm_from_cmid((int)($args['cmid'] ?? 0), 'assign');
        $context = context_module::instance($cm->id);
        locator::check_restriction($context, $ctx->restrictedcontext);
        external_api::validate_context($context);
        (new \assign($context, $cm, $course))->require_view_grades();
        $groupid = (int)($args['groupid'] ?? 0);
        if ($groupid) {
            self::group_userids($cm, $groupid);
        }
        $link = tickets::download_url($ctx, array_filter(['k' => tickets::KIND_ASSIGN_ALL, 'cmid' => (int)$cm->id,
            'groupid' => $groupid ?: null]));
        return self::link_result($link, 'submissions-' . $cm->id . '.zip');
    }

    /**
     * Members of a group the user may see in this activity.
     *
     * @param \cm_info|\stdClass $cm Course module.
     * @param int $groupid Group id.
     * @return int[] User ids ([0] when the group is empty, so nothing matches).
     */
    public static function group_userids($cm, int $groupid): array {
        $group = groups_get_group($groupid, 'id, courseid', MUST_EXIST);
        if ((int)$group->courseid !== (int)$cm->course) {
            throw new moodle_exception('invalidparameter', 'debug', '', null, 'The group is not in this course.');
        }
        $context = context_module::instance($cm->id);
        if (groups_get_activity_groupmode($cm) == SEPARATEGROUPS && !groups_is_member($groupid)) {
            require_capability('moodle/site:accessallgroups', $context);
        }
        $ids = array_map('intval', array_keys(groups_get_members($groupid, 'u.id')));
        return $ids ?: [0];
    }

    /**
     * Tool result for a streamed download link.
     *
     * @param array $link url and expires.
     * @param string $filename Suggested local file name.
     * @return array
     */
    private static function link_result(array $link, string $filename): array {
        return [
            'url' => $link['url'],
            'expires' => $link['expires'],
            'curl' => 'curl -fL -o ' . escapeshellarg($filename) . ' ' . escapeshellarg($link['url']),
            'note' => 'The zip is generated while it downloads, so its size is not known in advance.',
        ];
    }
}
