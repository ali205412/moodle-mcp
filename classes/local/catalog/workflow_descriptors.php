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
 * Built-in workflow descriptors grouping related tools into ordered steps.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class workflow_descriptors {
    /**
     * Return the built-in descriptors.
     *
     * @return array
     */
    public static function all(): array {
        return [
            self::descriptor('workflow_assignment_submission', 'workflow', 'activity', 'mod_assign', [
                'mod_assign_get_assignments', 'mod_assign_get_submission_status', 'mod_assign_start_submission',
                'mod_assign_save_submission', 'mod_assign_submit_for_grading', 'mod_assign_remove_submission',
            ]),
            self::descriptor('workflow_assignment_grading', 'workflow', 'activity', 'mod_assign', [
                'mod_assign_list_participants', 'mod_assign_get_submissions', 'mod_assign_save_grade',
                'mod_assign_save_grades', 'mod_assign_save_user_extensions', 'mod_assign_lock_submissions',
                'mod_assign_unlock_submissions',
            ]),
            self::descriptor('workflow_forum_participation', 'workflow', 'activity', 'mod_forum', [
                'mod_forum_get_forums_by_courses', 'mod_forum_get_forum_discussions', 'mod_forum_get_discussion_posts',
                'mod_forum_can_add_discussion', 'mod_forum_get_forum_access_information',
                'mod_forum_prepare_draft_area_for_post', 'mod_forum_add_discussion', 'mod_forum_add_discussion_post',
                'mod_forum_update_discussion_post', 'mod_forum_delete_post', 'mod_forum_set_subscription_state',
            ]),
            self::descriptor('workflow_quiz_attempt', 'workflow', 'activity', 'mod_quiz', [
                'mod_quiz_get_quizzes_by_courses', 'mod_quiz_get_quiz_access_information', 'mod_quiz_start_attempt',
                'mod_quiz_get_attempt_data', 'mod_quiz_save_attempt', 'mod_quiz_process_attempt',
                'mod_quiz_get_attempt_summary', 'mod_quiz_get_attempt_review',
            ]),
            self::descriptor('workflow_workshop_submission', 'workflow', 'activity', 'mod_workshop', [
                'mod_workshop_get_workshops_by_courses', 'mod_workshop_get_workshop_access_information',
                'mod_workshop_get_user_plan', 'mod_workshop_add_submission', 'mod_workshop_update_submission',
                'mod_workshop_delete_submission', 'mod_workshop_get_submission_assessments',
                'mod_workshop_update_assessment',
            ]),
            self::descriptor('workflow_feedback_response', 'workflow', 'activity', 'mod_feedback', [
                'mod_feedback_get_feedbacks_by_courses', 'mod_feedback_get_feedback_access_information',
                'mod_feedback_launch_feedback', 'mod_feedback_get_page_items', 'mod_feedback_process_page',
                'mod_feedback_get_analysis',
            ]),
            self::descriptor('workflow_chat_participation', 'workflow', 'activity', 'mod_chat', [
                'mod_chat_get_chats_by_courses', 'mod_chat_login_user', 'mod_chat_get_chat_users',
                'mod_chat_send_chat_message', 'mod_chat_get_chat_latest_messages', 'mod_chat_get_sessions',
                'mod_chat_get_session_messages',
            ]),
            self::descriptor('workflow_glossary_entries', 'workflow', 'activity', 'mod_glossary', [
                'mod_glossary_get_glossaries_by_courses', 'mod_glossary_get_entries_by_search',
                'mod_glossary_get_entry_by_id', 'mod_glossary_add_entry', 'mod_glossary_prepare_entry_for_edition',
                'mod_glossary_update_entry', 'mod_glossary_delete_entry',
            ]),
            self::descriptor('workflow_wiki_collaboration', 'workflow', 'activity', 'mod_wiki', [
                'mod_wiki_get_wikis_by_courses', 'mod_wiki_get_subwiki_pages', 'mod_wiki_get_page_contents',
                'mod_wiki_get_page_for_editing', 'mod_wiki_new_page', 'mod_wiki_edit_page',
            ]),
            self::descriptor('workflow_data_entries', 'workflow', 'activity', 'mod_data', [
                'mod_data_get_databases_by_courses', 'mod_data_get_data_access_information', 'mod_data_get_entries',
                'mod_data_search_entries', 'mod_data_add_entry', 'mod_data_update_entry', 'mod_data_delete_entry',
                'mod_data_approve_entry',
            ]),
            self::descriptor('workflow_choice_response', 'workflow', 'activity', 'mod_choice', [
                'mod_choice_get_choices_by_courses', 'mod_choice_get_choice_options',
                'mod_choice_submit_choice_response', 'mod_choice_get_choice_results',
                'mod_choice_delete_choice_responses',
            ]),
            self::descriptor('workflow_survey_response', 'workflow', 'activity', 'mod_survey', [
                'mod_survey_get_surveys_by_courses', 'mod_survey_get_questions', 'mod_survey_submit_answers',
            ]),
            self::descriptor('workflow_scorm_attempt', 'workflow', 'activity', 'mod_scorm', [
                'mod_scorm_get_scorms_by_courses', 'mod_scorm_get_scorm_access_information',
                'mod_scorm_get_scorm_scoes', 'mod_scorm_launch_sco', 'mod_scorm_insert_scorm_tracks',
            ]),
            self::descriptor('workflow_h5pactivity_attempt', 'workflow', 'activity', 'mod_h5pactivity', [
                'mod_h5pactivity_get_h5pactivities_by_courses', 'mod_h5pactivity_get_h5pactivity_access_information',
                'mod_h5pactivity_get_attempts', 'mod_h5pactivity_get_results', 'mod_h5pactivity_get_user_attempts',
            ]),
            self::descriptor('workflow_bigbluebutton_session', 'workflow', 'activity', 'mod_bigbluebuttonbn', [
                'mod_bigbluebuttonbn_get_bigbluebuttonbns_by_courses', 'mod_bigbluebuttonbn_can_join',
                'mod_bigbluebuttonbn_get_join_url', 'mod_bigbluebuttonbn_meeting_info',
                'mod_bigbluebuttonbn_get_recordings',
            ]),
            self::descriptor('workflow_lti_launch', 'workflow', 'activity', 'mod_lti', [
                'mod_lti_get_ltis_by_courses', 'mod_lti_get_tool_launch_data', 'mod_lti_view_lti',
            ]),
            self::descriptor('workflow_badge_management', 'workflow', 'operator', 'core_badges', [
                'core_badges_get_user_badges', 'core_badges_get_user_badge_by_hash', 'core_badges_get_badge',
                'core_badges_enable_badges', 'core_badges_disable_badges', 'wrapper_badge_create_badge',
                'wrapper_badge_update_badge', 'wrapper_badge_update_badge_message', 'wrapper_badge_delete_badges',
                'wrapper_badge_duplicate_badge', 'wrapper_badge_add_related_badges',
                'wrapper_badge_delete_related_badges', 'wrapper_badge_save_alignment',
                'wrapper_badge_delete_alignments', 'wrapper_badge_award_badge', 'wrapper_badge_revoke_badge',
            ]),
            self::descriptor('workflow_question_bank_management', 'workflow', 'operator', 'question', [
                'core_question_update_flag', 'core_question_get_random_question_summaries',
                'qbank_editquestion_set_status', 'qbank_managecategories_move_category',
                'qbank_tagquestion_submit_tags_form', 'qbank_columnsortorder_set_columnbank_order',
                'qbank_columnsortorder_set_hidden_columns', 'qbank_columnsortorder_set_column_size',
                'qbank_viewquestiontext_set_question_text_format', 'wrapper_question_create_category',
                'wrapper_question_update_category', 'wrapper_question_delete_category',
                'wrapper_question_move_questions', 'wrapper_question_delete_questions',
                'wrapper_question_create_question', 'wrapper_question_update_question',
                'wrapper_question_preview_question', 'wrapper_question_import_questions',
            ]),
            self::descriptor('workflow_gradebook_management', 'workflow', 'operator', 'grade', [
                'grade_get_grade_tree', 'grade_create_gradecategories', 'grade_get_gradeitems', 'grade_get_feedback',
                'grade_get_gradable_users', 'gradereport_user_get_grades_table', 'gradereport_user_get_grade_items',
                'gradereport_user_get_access_information', 'gradereport_grader_get_users_in_report',
                'gradereport_overview_get_course_grades', 'gradereport_singleview_get_grade_items_for_search_widget',
                'gradingform_guide_grader_gradingpanel_fetch', 'gradingform_guide_grader_gradingpanel_store',
                'gradingform_rubric_grader_gradingpanel_fetch', 'gradingform_rubric_grader_gradingpanel_store',
                'wrapper_gradebook_create_manual_item', 'wrapper_gradebook_update_manual_item',
                'wrapper_gradebook_move_item', 'wrapper_gradebook_delete_items', 'wrapper_gradebook_update_category',
                'wrapper_gradebook_move_category', 'wrapper_gradebook_delete_categories',
            ]),
            self::descriptor('workflow_user_management', 'workflow', 'operator', 'core_user', [
                'core_user_search_identity', 'core_user_get_users', 'core_user_get_users_by_field',
                'core_user_create_users', 'core_user_update_users', 'core_user_delete_users',
            ]),
            self::descriptor('workflow_enrolment_management', 'workflow', 'operator', 'core_enrol', [
                'core_enrol_get_course_enrolment_methods', 'core_enrol_get_potential_users', 'core_enrol_search_users',
                'core_enrol_submit_user_enrolment_form', 'core_enrol_unenrol_user_enrolment',
                'enrol_manual_enrol_users', 'enrol_manual_unenrol_users', 'enrol_self_get_instance_info',
                'enrol_self_enrol_user',
            ]),
            self::descriptor('workflow_group_management', 'workflow', 'operator', 'core_group', [
                'core_group_get_course_groups', 'core_group_get_groups', 'core_group_create_groups',
                'core_group_update_groups', 'core_group_add_group_members', 'core_group_delete_group_members',
                'core_group_delete_groups',
            ]),
            self::descriptor('workflow_grouping_management', 'workflow', 'operator', 'core_group', [
                'core_group_get_course_groupings', 'core_group_get_groupings', 'core_group_create_groupings',
                'core_group_update_groupings', 'core_group_assign_grouping', 'core_group_unassign_grouping',
                'core_group_delete_groupings',
            ]),
            self::descriptor('workflow_cohort_management', 'workflow', 'operator', 'core_cohort', [
                'core_cohort_search_cohorts', 'core_cohort_get_cohorts', 'core_cohort_create_cohorts',
                'core_cohort_update_cohorts', 'core_cohort_add_cohort_members', 'core_cohort_get_cohort_members',
                'core_cohort_delete_cohort_members', 'core_cohort_delete_cohorts',
            ]),
            self::descriptor('workflow_role_assignment', 'workflow', 'operator', 'core_role', [
                'core_role_assign_roles', 'core_role_unassign_roles',
            ]),
            self::descriptor('workflow_course_catalog_management', 'workflow', 'operator', 'core_course', [
                'core_course_get_categories', 'core_course_create_categories', 'core_course_update_categories',
                'core_course_delete_categories', 'core_course_create_courses', 'core_course_update_courses',
                'core_course_delete_courses', 'core_course_duplicate_course', 'core_course_import_course',
            ]),
            self::descriptor('workflow_course_editor', 'workflow', 'operator', 'core_courseformat', [
                'core_courseformat_get_state', 'core_courseformat_file_handlers', 'core_courseformat_create_module',
                'core_courseformat_new_module', 'core_courseformat_update_course', 'core_course_edit_module',
                'core_course_edit_section', 'core_course_delete_modules', 'wrapper_course_add_section_after',
                'wrapper_course_set_section_visibility', 'wrapper_course_delete_sections',
                'wrapper_course_create_missing_sections', 'wrapper_course_move_module',
                'wrapper_course_move_section_after', 'wrapper_course_set_module_visibility',
                'wrapper_course_duplicate_modules', 'wrapper_course_delete_modules',
            ]),
            self::descriptor('workflow_competency_management', 'workflow', 'operator', 'core_competency', [
                'core_competency_list_competency_frameworks', 'core_competency_create_competency_framework',
                'core_competency_update_competency_framework', 'core_competency_delete_competency_framework',
                'core_competency_create_competency', 'core_competency_update_competency',
                'core_competency_delete_competency', 'core_competency_create_template',
                'core_competency_update_template', 'core_competency_delete_template',
                'core_competency_add_competency_to_course', 'core_competency_remove_competency_from_course',
                'core_competency_create_plan', 'core_competency_update_plan', 'core_competency_complete_plan',
                'core_competency_reopen_plan', 'core_competency_approve_plan', 'core_competency_unapprove_plan',
            ]),
            self::descriptor('workflow_privacy_request_management', 'workflow', 'operator', 'tool_dataprivacy', [
                'tool_dataprivacy_get_access_information', 'tool_dataprivacy_create_data_request',
                'tool_dataprivacy_get_data_requests', 'tool_dataprivacy_get_data_request',
                'tool_dataprivacy_approve_data_request', 'tool_dataprivacy_bulk_approve_data_requests',
                'tool_dataprivacy_deny_data_request', 'tool_dataprivacy_bulk_deny_data_requests',
                'tool_dataprivacy_cancel_data_request', 'tool_dataprivacy_mark_complete',
                'tool_dataprivacy_submit_selected_courses_form',
            ]),
            self::descriptor('workflow_privacy_registry_management', 'workflow', 'operator', 'tool_dataprivacy', [
                'tool_dataprivacy_create_purpose_form', 'tool_dataprivacy_create_category_form',
                'tool_dataprivacy_delete_purpose', 'tool_dataprivacy_delete_category',
                'tool_dataprivacy_set_contextlevel_form', 'tool_dataprivacy_set_context_form',
                'tool_dataprivacy_tree_extra_branches', 'tool_dataprivacy_confirm_contexts_for_deletion',
                'tool_dataprivacy_set_context_defaults', 'tool_dataprivacy_get_category_options',
                'tool_dataprivacy_get_purpose_options', 'tool_dataprivacy_get_activity_options',
            ]),
            self::descriptor('wrapper_course_add_section_after', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_course_set_section_visibility', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_course_delete_sections', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_course_create_missing_sections', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_course_move_module', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_course_move_section_after', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_course_set_module_visibility', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_course_duplicate_modules', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_course_delete_modules', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_question_create_category', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_question_update_category', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_question_delete_category', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_question_move_questions', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_question_delete_questions', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_question_create_question', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_question_update_question', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_question_preview_question', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_question_import_questions', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_gradebook_create_manual_item', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_gradebook_update_manual_item', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_gradebook_move_item', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_gradebook_delete_items', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_gradebook_update_category', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_gradebook_move_category', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_gradebook_delete_categories', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_badge_create_badge', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_badge_update_badge', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_badge_update_badge_message', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_badge_delete_badges', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_badge_duplicate_badge', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_badge_add_related_badges', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_badge_delete_related_badges', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_badge_save_alignment', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_badge_delete_alignments', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_badge_award_badge', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('wrapper_badge_revoke_badge', 'wrapper', 'operator', 'webservice_mcp', [

            ]),
            self::descriptor('workflow_draft_file_upload', 'workflow', 'files', 'core_files', [
                'core_files_get_unused_draft_itemid', 'core_files_upload', 'core_files_delete_draft_files',
            ]),
            self::descriptor('workflow_private_files_edit', 'workflow', 'files', 'core_user', [
                'core_user_get_private_files_info', 'core_user_prepare_private_files_for_edition', 'core_files_upload',
                'core_user_add_user_private_files', 'core_user_update_private_files',
            ]),
        ];
    }

    /**
     * Build one descriptor.
     *
     * @param string $name Descriptor name.
     * @param string $type Descriptor type (workflow or wrapper).
     * @param string $domain Catalog domain.
     * @param string $component Frankenstyle component.
     * @param string[] $tools Ordered tool names.
     * @return array
     */
    private static function descriptor(string $name, string $type, string $domain, string $component, array $tools): array {
        return [
            'name' => $name,
            'type' => $type,
            'domain' => $domain,
            'component' => $component,
            'tools' => $tools,
        ];
    }
}
