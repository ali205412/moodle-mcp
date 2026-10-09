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

namespace webservice_mcp\local\catalog;

/**
 * Curated surface and execution hints projected onto MCP tools.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_metadata {
    /**
     * Project wrapper-specific surface metadata.
     *
     * @param array $definition Wrapper definition.
     * @return array
     */
    public static function wrapper_surface(array $definition): array {
        $name = (string)($definition['name'] ?? '');

        return match (true) {
            str_starts_with($name, 'wrapper_question_') => ['surface' => 'operator', 'area' => 'question_bank'],
            str_starts_with($name, 'wrapper_gradebook_') => ['surface' => 'operator', 'area' => 'gradebook'],
            str_starts_with($name, 'wrapper_badge_') => ['surface' => 'operator', 'area' => 'badges'],
            default => ['surface' => 'operator', 'area' => 'authoring'],
        };
    }

    /**
     * Derive curated surface metadata for core learning/personal/file tools.
     *
     * @param array $entry Catalog entry.
     * @return array
     */
    public static function surface(array $entry): array {
        $name = (string)$entry['name'];
        $component = (string)($entry['component'] ?? '');

        if (
            in_array(
                $name,
                [
                'core_course_get_categories',
                'core_course_create_categories',
                'core_course_update_categories',
                'core_course_delete_categories',
                ],
                true
            )
        ) {
            return ['surface' => 'operator', 'area' => 'categories'];
        }

        if (
            in_array(
                $name,
                [
                'core_course_create_courses',
                'core_course_update_courses',
                'core_course_delete_courses',
                'core_course_duplicate_course',
                'core_course_import_course',
                ],
                true
            )
        ) {
            return ['surface' => 'operator', 'area' => 'courses'];
        }

        if (
            str_starts_with($name, 'core_courseformat_') ||
            in_array(
                $name,
                [
                    'core_course_edit_module',
                    'core_course_edit_section',
                    'core_course_delete_modules',
                    'core_course_toggle_activity_recommendation',
                    'core_course_get_activity_chooser_footer',
                    'core_course_get_module',
                ],
                true
            )
        ) {
            return ['surface' => 'operator', 'area' => 'authoring'];
        }

        if (str_starts_with($name, 'core_course_')) {
            return ['surface' => 'learning', 'area' => 'courses'];
        }

        if (str_starts_with($name, 'core_completion_')) {
            return ['surface' => 'learning', 'area' => 'completion'];
        }

        if (str_starts_with($name, 'core_calendar_')) {
            return ['surface' => 'personal', 'area' => 'calendar'];
        }

        if (str_starts_with($name, 'core_badges_')) {
            return ['surface' => 'operator', 'area' => 'badges'];
        }

        if (str_starts_with($name, 'core_message_')) {
            return ['surface' => 'personal', 'area' => 'messaging'];
        }

        if (str_starts_with($name, 'core_notes_')) {
            return ['surface' => 'personal', 'area' => 'notes'];
        }

        if (
            in_array(
                $name,
                [
                'core_user_get_private_files_info',
                'core_user_prepare_private_files_for_edition',
                'core_user_add_user_private_files',
                'core_user_update_private_files',
                ],
                true
            )
        ) {
            return ['surface' => 'files', 'area' => 'private_files'];
        }

        if (
            in_array(
                $name,
                [
                'core_user_search_identity',
                'core_user_get_users',
                'core_user_get_users_by_field',
                'core_user_create_users',
                'core_user_update_users',
                'core_user_delete_users',
                'core_user_view_user_list',
                ],
                true
            )
        ) {
            return ['surface' => 'operator', 'area' => 'users'];
        }

        if (str_starts_with($name, 'core_user_')) {
            return ['surface' => 'personal', 'area' => 'profile'];
        }

        if (str_starts_with($name, 'core_files_')) {
            return ['surface' => 'files', 'area' => 'draft_files'];
        }

        if (
            str_starts_with($name, 'core_enrol_') ||
            str_starts_with($name, 'enrol_manual_') ||
            str_starts_with($name, 'enrol_self_')
        ) {
            return ['surface' => 'operator', 'area' => 'enrolments'];
        }

        if (str_starts_with($name, 'core_group_')) {
            return ['surface' => 'operator', 'area' => 'groups'];
        }

        if (str_starts_with($name, 'core_cohort_')) {
            return ['surface' => 'operator', 'area' => 'cohorts'];
        }

        if (str_starts_with($name, 'core_role_')) {
            return ['surface' => 'operator', 'area' => 'roles'];
        }

        if (
            str_starts_with($name, 'core_question_') ||
            str_starts_with($name, 'qbank_')
        ) {
            return ['surface' => 'operator', 'area' => 'question_bank'];
        }

        if (
            str_starts_with($name, 'grade_') ||
            str_starts_with($name, 'gradereport_') ||
            str_starts_with($name, 'gradingform_')
        ) {
            return ['surface' => 'operator', 'area' => 'gradebook'];
        }

        if (str_starts_with($name, 'core_competency_')) {
            return ['surface' => 'operator', 'area' => 'competencies'];
        }

        if (str_starts_with($name, 'tool_dataprivacy_')) {
            return ['surface' => 'operator', 'area' => 'privacy'];
        }

        if (str_starts_with($component, 'mod_')) {
            return ['surface' => 'activity', 'area' => self::activity_area_for_component($component)];
        }

        return ['surface' => 'general', 'area' => $entry['domain']];
    }

    /**
     * Map a module component to a curated activity area label.
     *
     * @param string $component Module component.
     * @return string
     */
    private static function activity_area_for_component(string $component): string {
        return match ($component) {
            'mod_assign' => 'assignments',
            'mod_forum' => 'forums',
            'mod_quiz' => 'quizzes',
            'mod_workshop' => 'workshops',
            'mod_feedback' => 'feedback',
            'mod_chat' => 'chat',
            'mod_glossary' => 'glossary',
            'mod_wiki' => 'wiki',
            'mod_data' => 'database',
            'mod_choice' => 'choice',
            'mod_survey' => 'survey',
            'mod_scorm' => 'scorm',
            'mod_h5pactivity' => 'h5pactivity',
            'mod_bigbluebuttonbn' => 'bigbluebutton',
            'mod_lti' => 'lti',
            default => substr($component, 4),
        };
    }

    /**
     * Derive execution hints for tools that trigger async or long-running work.
     *
     * @param array $entry Catalog entry.
     * @return array
     */
    public static function execution(array $entry): array {
        $name = (string)$entry['name'];

        if (
            in_array(
                $name,
                [
                'tool_dataprivacy_create_data_request',
                'tool_dataprivacy_approve_data_request',
                'tool_dataprivacy_bulk_approve_data_requests',
                'tool_dataprivacy_deny_data_request',
                'tool_dataprivacy_bulk_deny_data_requests',
                'tool_dataprivacy_cancel_data_request',
                'tool_dataprivacy_mark_complete',
                'tool_dataprivacy_submit_selected_courses_form',
                'tool_dataprivacy_confirm_contexts_for_deletion',
                ],
                true
            )
        ) {
            return [
                'mode' => 'async_request',
                'followupTools' => [
                    'tool_dataprivacy_get_data_request',
                    'tool_dataprivacy_get_data_requests',
                ],
                'notes' => [
                    'This call updates a privacy-request workflow that may complete after the initial response.',
                ],
            ];
        }

        if (
            in_array(
                $name,
                [
                'core_course_duplicate_course',
                'core_course_import_course',
                'core_course_delete_courses',
                'core_course_delete_categories',
                ],
                true
            )
        ) {
            return [
                'mode' => 'long_running',
                'followupTools' => [],
                'notes' => [
                    'This call may take noticeably longer than standard tool invocations on large sites.',
                ],
            ];
        }

        return [
            'mode' => 'sync',
            'followupTools' => [],
            'notes' => [],
        ];
    }
}
