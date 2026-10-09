# Common Moodle functions

## Courses and content
- `core_course_get_courses_by_field` (field id|shortname|category) - course details
- `core_course_get_contents` (courseid) - sections, activities, files
- `core_course_search_courses` - search the catalogue
- `core_course_create_courses`, `core_course_update_courses`, `core_course_duplicate_course`
- `core_course_get_recent_courses` - recently accessed

## People
- `core_enrol_get_enrolled_users` (courseid) - participants
- `core_enrol_get_users_courses` (userid) - a user's courses
- `core_user_get_users_by_field` (field id|username|email)
- `enrol_manual_enrol_users`, `enrol_manual_unenrol_users`
- `core_group_create_groups`, `core_group_add_group_members`
- `core_cohort_create_cohorts`, `core_cohort_add_cohort_members`
- `core_role_assign_roles`

## Assignments
- `mod_assign_get_assignments` (courseids)
- `mod_assign_list_participants`, `mod_assign_get_submissions`, `mod_assign_get_submission_status`
- `mod_assign_save_submission`, `mod_assign_submit_for_grading`
- `mod_assign_save_grade`, `mod_assign_save_grades`

## Quizzes and forums
- `mod_quiz_get_quizzes_by_courses`, `mod_quiz_get_user_attempts`, `mod_quiz_get_attempt_review`
- `mod_forum_get_forum_discussions`, `mod_forum_get_discussion_posts`
- `mod_forum_add_discussion`, `mod_forum_add_discussion_post`

## Grades and progress
- `gradereport_user_get_grade_items` (courseid, userid)
- `gradereport_overview_get_course_grades`
- `core_grades_update_grades`
- `core_completion_get_activities_completion_status`, `core_completion_get_course_completion_status`

## Calendar and messages
- `core_calendar_get_action_events_by_timesort`, `core_calendar_get_action_events_by_course`
- `core_calendar_create_calendar_events`
- `core_message_send_instant_messages`, `core_message_get_conversations`
