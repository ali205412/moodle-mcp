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
 * Registry of plugin-owned wrapper tools for future coverage phases.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wrapper_registry {
    /** @var array Default built-in workflow descriptors. */
    private const DEFAULT_DESCRIPTORS = [
        [
            'name' => 'workflow_assignment_submission',
            'type' => 'workflow',
            'domain' => 'activity',
            'component' => 'mod_assign',
            'tools' => [
                'mod_assign_get_assignments',
                'mod_assign_get_submission_status',
                'mod_assign_start_submission',
                'mod_assign_save_submission',
                'mod_assign_submit_for_grading',
                'mod_assign_remove_submission',
            ],
        ],
        [
            'name' => 'workflow_assignment_grading',
            'type' => 'workflow',
            'domain' => 'activity',
            'component' => 'mod_assign',
            'tools' => [
                'mod_assign_list_participants',
                'mod_assign_get_submissions',
                'mod_assign_save_grade',
                'mod_assign_save_grades',
                'mod_assign_save_user_extensions',
                'mod_assign_lock_submissions',
                'mod_assign_unlock_submissions',
            ],
        ],
        [
            'name' => 'workflow_forum_participation',
            'type' => 'workflow',
            'domain' => 'activity',
            'component' => 'mod_forum',
            'tools' => [
                'mod_forum_get_forums_by_courses',
                'mod_forum_get_forum_discussions',
                'mod_forum_get_discussion_posts',
                'mod_forum_can_add_discussion',
                'mod_forum_get_forum_access_information',
                'mod_forum_prepare_draft_area_for_post',
                'mod_forum_add_discussion',
                'mod_forum_add_discussion_post',
                'mod_forum_update_discussion_post',
                'mod_forum_delete_post',
                'mod_forum_set_subscription_state',
            ],
        ],
        [
            'name' => 'workflow_quiz_attempt',
            'type' => 'workflow',
            'domain' => 'activity',
            'component' => 'mod_quiz',
            'tools' => [
                'mod_quiz_get_quizzes_by_courses',
                'mod_quiz_get_quiz_access_information',
                'mod_quiz_start_attempt',
                'mod_quiz_get_attempt_data',
                'mod_quiz_save_attempt',
                'mod_quiz_process_attempt',
                'mod_quiz_get_attempt_summary',
                'mod_quiz_get_attempt_review',
            ],
        ],
        [
            'name' => 'workflow_workshop_submission',
            'type' => 'workflow',
            'domain' => 'activity',
            'component' => 'mod_workshop',
            'tools' => [
                'mod_workshop_get_workshops_by_courses',
                'mod_workshop_get_workshop_access_information',
                'mod_workshop_get_user_plan',
                'mod_workshop_add_submission',
                'mod_workshop_update_submission',
                'mod_workshop_delete_submission',
                'mod_workshop_get_submission_assessments',
                'mod_workshop_update_assessment',
            ],
        ],
        [
            'name' => 'workflow_feedback_response',
            'type' => 'workflow',
            'domain' => 'activity',
            'component' => 'mod_feedback',
            'tools' => [
                'mod_feedback_get_feedbacks_by_courses',
                'mod_feedback_get_feedback_access_information',
                'mod_feedback_launch_feedback',
                'mod_feedback_get_page_items',
                'mod_feedback_process_page',
                'mod_feedback_get_analysis',
            ],
        ],
        [
            'name' => 'workflow_chat_participation',
            'type' => 'workflow',
            'domain' => 'activity',
            'component' => 'mod_chat',
            'tools' => [
                'mod_chat_get_chats_by_courses',
                'mod_chat_login_user',
                'mod_chat_get_chat_users',
                'mod_chat_send_chat_message',
                'mod_chat_get_chat_latest_messages',
                'mod_chat_get_sessions',
                'mod_chat_get_session_messages',
            ],
        ],
        [
            'name' => 'workflow_glossary_entries',
            'type' => 'workflow',
            'domain' => 'activity',
            'component' => 'mod_glossary',
            'tools' => [
                'mod_glossary_get_glossaries_by_courses',
                'mod_glossary_get_entries_by_search',
                'mod_glossary_get_entry_by_id',
                'mod_glossary_add_entry',
                'mod_glossary_prepare_entry_for_edition',
                'mod_glossary_update_entry',
                'mod_glossary_delete_entry',
            ],
        ],
        [
            'name' => 'workflow_wiki_collaboration',
            'type' => 'workflow',
            'domain' => 'activity',
            'component' => 'mod_wiki',
            'tools' => [
                'mod_wiki_get_wikis_by_courses',
                'mod_wiki_get_subwiki_pages',
                'mod_wiki_get_page_contents',
                'mod_wiki_get_page_for_editing',
                'mod_wiki_new_page',
                'mod_wiki_edit_page',
            ],
        ],
        [
            'name' => 'workflow_data_entries',
            'type' => 'workflow',
            'domain' => 'activity',
            'component' => 'mod_data',
            'tools' => [
                'mod_data_get_databases_by_courses',
                'mod_data_get_data_access_information',
                'mod_data_get_entries',
                'mod_data_search_entries',
                'mod_data_add_entry',
                'mod_data_update_entry',
                'mod_data_delete_entry',
                'mod_data_approve_entry',
            ],
        ],
        [
            'name' => 'workflow_choice_response',
            'type' => 'workflow',
            'domain' => 'activity',
            'component' => 'mod_choice',
            'tools' => [
                'mod_choice_get_choices_by_courses',
                'mod_choice_get_choice_options',
                'mod_choice_submit_choice_response',
                'mod_choice_get_choice_results',
                'mod_choice_delete_choice_responses',
            ],
        ],
        [
            'name' => 'workflow_survey_response',
            'type' => 'workflow',
            'domain' => 'activity',
            'component' => 'mod_survey',
            'tools' => [
                'mod_survey_get_surveys_by_courses',
                'mod_survey_get_questions',
                'mod_survey_submit_answers',
            ],
        ],
        [
            'name' => 'workflow_scorm_attempt',
            'type' => 'workflow',
            'domain' => 'activity',
            'component' => 'mod_scorm',
            'tools' => [
                'mod_scorm_get_scorms_by_courses',
                'mod_scorm_get_scorm_access_information',
                'mod_scorm_get_scorm_scoes',
                'mod_scorm_launch_sco',
                'mod_scorm_insert_scorm_tracks',
            ],
        ],
        [
            'name' => 'workflow_h5pactivity_attempt',
            'type' => 'workflow',
            'domain' => 'activity',
            'component' => 'mod_h5pactivity',
            'tools' => [
                'mod_h5pactivity_get_h5pactivities_by_courses',
                'mod_h5pactivity_get_h5pactivity_access_information',
                'mod_h5pactivity_get_attempts',
                'mod_h5pactivity_get_results',
                'mod_h5pactivity_get_user_attempts',
            ],
        ],
        [
            'name' => 'workflow_bigbluebutton_session',
            'type' => 'workflow',
            'domain' => 'activity',
            'component' => 'mod_bigbluebuttonbn',
            'tools' => [
                'mod_bigbluebuttonbn_get_bigbluebuttonbns_by_courses',
                'mod_bigbluebuttonbn_can_join',
                'mod_bigbluebuttonbn_get_join_url',
                'mod_bigbluebuttonbn_meeting_info',
                'mod_bigbluebuttonbn_get_recordings',
            ],
        ],
        [
            'name' => 'workflow_lti_launch',
            'type' => 'workflow',
            'domain' => 'activity',
            'component' => 'mod_lti',
            'tools' => [
                'mod_lti_get_ltis_by_courses',
                'mod_lti_get_tool_launch_data',
                'mod_lti_view_lti',
            ],
        ],
        [
            'name' => 'workflow_badge_management',
            'type' => 'workflow',
            'domain' => 'operator',
            'component' => 'core_badges',
            'tools' => [
                'core_badges_get_user_badges',
                'core_badges_get_user_badge_by_hash',
                'core_badges_get_badge',
                'core_badges_enable_badges',
                'core_badges_disable_badges',
            ],
        ],
        [
            'name' => 'workflow_question_bank_management',
            'type' => 'workflow',
            'domain' => 'operator',
            'component' => 'question',
            'tools' => [
                'core_question_update_flag',
                'core_question_get_random_question_summaries',
                'qbank_editquestion_set_status',
                'qbank_managecategories_move_category',
                'qbank_tagquestion_submit_tags_form',
                'qbank_columnsortorder_set_columnbank_order',
                'qbank_columnsortorder_set_hidden_columns',
                'qbank_columnsortorder_set_column_size',
                'qbank_viewquestiontext_set_question_text_format',
            ],
        ],
        [
            'name' => 'workflow_gradebook_management',
            'type' => 'workflow',
            'domain' => 'operator',
            'component' => 'grade',
            'tools' => [
                'grade_get_grade_tree',
                'grade_create_gradecategories',
                'grade_get_gradeitems',
                'grade_get_feedback',
                'grade_get_gradable_users',
                'gradereport_user_get_grades_table',
                'gradereport_user_get_grade_items',
                'gradereport_user_get_access_information',
                'gradereport_grader_get_users_in_report',
                'gradereport_overview_get_course_grades',
                'gradereport_singleview_get_grade_items_for_search_widget',
                'gradingform_guide_grader_gradingpanel_fetch',
                'gradingform_guide_grader_gradingpanel_store',
                'gradingform_rubric_grader_gradingpanel_fetch',
                'gradingform_rubric_grader_gradingpanel_store',
            ],
        ],
        [
            'name' => 'workflow_user_management',
            'type' => 'workflow',
            'domain' => 'operator',
            'component' => 'core_user',
            'tools' => [
                'core_user_search_identity',
                'core_user_get_users',
                'core_user_get_users_by_field',
                'core_user_create_users',
                'core_user_update_users',
                'core_user_delete_users',
            ],
        ],
        [
            'name' => 'workflow_enrolment_management',
            'type' => 'workflow',
            'domain' => 'operator',
            'component' => 'core_enrol',
            'tools' => [
                'core_enrol_get_course_enrolment_methods',
                'core_enrol_get_potential_users',
                'core_enrol_search_users',
                'core_enrol_submit_user_enrolment_form',
                'core_enrol_unenrol_user_enrolment',
                'enrol_manual_enrol_users',
                'enrol_manual_unenrol_users',
                'enrol_self_get_instance_info',
                'enrol_self_enrol_user',
            ],
        ],
        [
            'name' => 'workflow_group_management',
            'type' => 'workflow',
            'domain' => 'operator',
            'component' => 'core_group',
            'tools' => [
                'core_group_get_course_groups',
                'core_group_get_groups',
                'core_group_create_groups',
                'core_group_update_groups',
                'core_group_add_group_members',
                'core_group_delete_group_members',
                'core_group_delete_groups',
            ],
        ],
        [
            'name' => 'workflow_grouping_management',
            'type' => 'workflow',
            'domain' => 'operator',
            'component' => 'core_group',
            'tools' => [
                'core_group_get_course_groupings',
                'core_group_get_groupings',
                'core_group_create_groupings',
                'core_group_update_groupings',
                'core_group_assign_grouping',
                'core_group_unassign_grouping',
                'core_group_delete_groupings',
            ],
        ],
        [
            'name' => 'workflow_cohort_management',
            'type' => 'workflow',
            'domain' => 'operator',
            'component' => 'core_cohort',
            'tools' => [
                'core_cohort_search_cohorts',
                'core_cohort_get_cohorts',
                'core_cohort_create_cohorts',
                'core_cohort_update_cohorts',
                'core_cohort_add_cohort_members',
                'core_cohort_get_cohort_members',
                'core_cohort_delete_cohort_members',
                'core_cohort_delete_cohorts',
            ],
        ],
        [
            'name' => 'workflow_role_assignment',
            'type' => 'workflow',
            'domain' => 'operator',
            'component' => 'core_role',
            'tools' => [
                'core_role_assign_roles',
                'core_role_unassign_roles',
            ],
        ],
        [
            'name' => 'workflow_course_catalog_management',
            'type' => 'workflow',
            'domain' => 'operator',
            'component' => 'core_course',
            'tools' => [
                'core_course_get_categories',
                'core_course_create_categories',
                'core_course_update_categories',
                'core_course_delete_categories',
                'core_course_create_courses',
                'core_course_update_courses',
                'core_course_delete_courses',
                'core_course_duplicate_course',
                'core_course_import_course',
            ],
        ],
        [
            'name' => 'workflow_course_editor',
            'type' => 'workflow',
            'domain' => 'operator',
            'component' => 'core_courseformat',
            'tools' => [
                'core_courseformat_get_state',
                'core_courseformat_file_handlers',
                'core_courseformat_create_module',
                'core_courseformat_new_module',
                'core_courseformat_update_course',
                'core_course_edit_module',
                'core_course_edit_section',
                'core_course_delete_modules',
                'wrapper_course_add_section_after',
                'wrapper_course_set_section_visibility',
                'wrapper_course_delete_sections',
                'wrapper_course_create_missing_sections',
                'wrapper_course_move_module',
                'wrapper_course_move_section_after',
                'wrapper_course_set_module_visibility',
                'wrapper_course_duplicate_modules',
                'wrapper_course_delete_modules',
            ],
        ],
        [
            'name' => 'workflow_competency_management',
            'type' => 'workflow',
            'domain' => 'operator',
            'component' => 'core_competency',
            'tools' => [
                'core_competency_list_competency_frameworks',
                'core_competency_create_competency_framework',
                'core_competency_update_competency_framework',
                'core_competency_delete_competency_framework',
                'core_competency_create_competency',
                'core_competency_update_competency',
                'core_competency_delete_competency',
                'core_competency_create_template',
                'core_competency_update_template',
                'core_competency_delete_template',
                'core_competency_add_competency_to_course',
                'core_competency_remove_competency_from_course',
                'core_competency_create_plan',
                'core_competency_update_plan',
                'core_competency_complete_plan',
                'core_competency_reopen_plan',
                'core_competency_approve_plan',
                'core_competency_unapprove_plan',
            ],
        ],
        [
            'name' => 'workflow_privacy_request_management',
            'type' => 'workflow',
            'domain' => 'operator',
            'component' => 'tool_dataprivacy',
            'tools' => [
                'tool_dataprivacy_get_access_information',
                'tool_dataprivacy_create_data_request',
                'tool_dataprivacy_get_data_requests',
                'tool_dataprivacy_get_data_request',
                'tool_dataprivacy_approve_data_request',
                'tool_dataprivacy_bulk_approve_data_requests',
                'tool_dataprivacy_deny_data_request',
                'tool_dataprivacy_bulk_deny_data_requests',
                'tool_dataprivacy_cancel_data_request',
                'tool_dataprivacy_mark_complete',
                'tool_dataprivacy_submit_selected_courses_form',
            ],
        ],
        [
            'name' => 'workflow_privacy_registry_management',
            'type' => 'workflow',
            'domain' => 'operator',
            'component' => 'tool_dataprivacy',
            'tools' => [
                'tool_dataprivacy_create_purpose_form',
                'tool_dataprivacy_create_category_form',
                'tool_dataprivacy_delete_purpose',
                'tool_dataprivacy_delete_category',
                'tool_dataprivacy_set_contextlevel_form',
                'tool_dataprivacy_set_context_form',
                'tool_dataprivacy_tree_extra_branches',
                'tool_dataprivacy_confirm_contexts_for_deletion',
                'tool_dataprivacy_set_context_defaults',
                'tool_dataprivacy_get_category_options',
                'tool_dataprivacy_get_purpose_options',
                'tool_dataprivacy_get_activity_options',
            ],
        ],
        [
            'name' => 'wrapper_course_add_section_after',
            'type' => 'wrapper',
            'domain' => 'operator',
            'component' => 'webservice_mcp',
            'tools' => [],
        ],
        [
            'name' => 'wrapper_course_set_section_visibility',
            'type' => 'wrapper',
            'domain' => 'operator',
            'component' => 'webservice_mcp',
            'tools' => [],
        ],
        [
            'name' => 'wrapper_course_delete_sections',
            'type' => 'wrapper',
            'domain' => 'operator',
            'component' => 'webservice_mcp',
            'tools' => [],
        ],
        [
            'name' => 'wrapper_course_create_missing_sections',
            'type' => 'wrapper',
            'domain' => 'operator',
            'component' => 'webservice_mcp',
            'tools' => [],
        ],
        [
            'name' => 'wrapper_course_move_module',
            'type' => 'wrapper',
            'domain' => 'operator',
            'component' => 'webservice_mcp',
            'tools' => [],
        ],
        [
            'name' => 'wrapper_course_move_section_after',
            'type' => 'wrapper',
            'domain' => 'operator',
            'component' => 'webservice_mcp',
            'tools' => [],
        ],
        [
            'name' => 'wrapper_course_set_module_visibility',
            'type' => 'wrapper',
            'domain' => 'operator',
            'component' => 'webservice_mcp',
            'tools' => [],
        ],
        [
            'name' => 'wrapper_course_duplicate_modules',
            'type' => 'wrapper',
            'domain' => 'operator',
            'component' => 'webservice_mcp',
            'tools' => [],
        ],
        [
            'name' => 'wrapper_course_delete_modules',
            'type' => 'wrapper',
            'domain' => 'operator',
            'component' => 'webservice_mcp',
            'tools' => [],
        ],
        [
            'name' => 'workflow_draft_file_upload',
            'type' => 'workflow',
            'domain' => 'files',
            'component' => 'core_files',
            'tools' => [
                'core_files_get_unused_draft_itemid',
                'core_files_upload',
                'core_files_delete_draft_files',
            ],
        ],
        [
            'name' => 'workflow_private_files_edit',
            'type' => 'workflow',
            'domain' => 'files',
            'component' => 'core_user',
            'tools' => [
                'core_user_get_private_files_info',
                'core_user_prepare_private_files_for_edition',
                'core_files_upload',
                'core_user_add_user_private_files',
                'core_user_update_private_files',
            ],
        ],
    ];

    /** @var array */
    private array $descriptors;

    /**
     * Constructor.
     *
     * @param array $descriptors Optional override descriptors for tests or later phases.
     */
    public function __construct(array $descriptors = []) {
        $this->descriptors = $descriptors;
    }

    /**
     * Return all registered wrapper descriptors.
     *
     * @return array
     */
    public function all(): array {
        return array_merge(self::DEFAULT_DESCRIPTORS, $this->descriptors);
    }

    /**
     * Return workflow descriptors that include the given tool.
     *
     * @param string $toolname Tool name.
     * @return array
     */
    public function for_tool(string $toolname): array {
        $matches = [];
        foreach ($this->all() as $descriptor) {
            if (!in_array($toolname, $descriptor['tools'] ?? [], true)) {
                continue;
            }

            $matches[] = [
                'name' => $descriptor['name'],
                'type' => $descriptor['type'] ?? 'workflow',
                'domain' => $descriptor['domain'] ?? 'core',
                'component' => $descriptor['component'] ?? '',
                'step' => array_search($toolname, $descriptor['tools'], true) + 1,
                'steps' => $descriptor['tools'],
            ];
        }

        return $matches;
    }

    /**
     * Return all workflow descriptors for a given component.
     *
     * @param string $component Frankenstyle component.
     * @return array
     */
    public function for_component(string $component): array {
        $matches = [];
        foreach ($this->all() as $descriptor) {
            if (($descriptor['component'] ?? '') !== $component) {
                continue;
            }
            $matches[] = $descriptor;
        }

        return $matches;
    }
}
