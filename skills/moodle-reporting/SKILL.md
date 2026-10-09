---
name: moodle-reporting
description: Produce progress, engagement, grade and at-risk reports from Moodle data.
---

# Reporting

- Grades: `gradereport_user_get_grade_items` per student, `gradereport_overview_get_course_grades` for a student's overview.
- Completion: `core_completion_get_activities_completion_status` (courseid, userid), `core_completion_get_course_completion_status`.
- Participation: `core_enrol_get_enrolled_users` (includes `lastcourseaccess`), assignment submission status via `mod_assign_get_submissions`, quiz attempts via `mod_quiz_get_user_attempts`.
- Custom reports: `core_reportbuilder_retrieve_report` for report builder reports the user can view.
- Deadlines: `core_calendar_get_action_events_by_course`.

Method: collect raw data, compute metrics in your own analysis (not by guessing), cite the numbers, and list concrete next steps per student. Treat student data as confidential: only share it with the requesting staff member.
