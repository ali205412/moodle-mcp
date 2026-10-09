---
name: moodle-grading
description: Review assignment submissions and record grades and feedback in Moodle with teacher approval.
---

# Grading assignments

1. Identify the assignment: `mod_assign_get_assignments` with the course id, or the cmid from `moodle://course/{id}`.
2. Read the brief, rubric or marking guide: `moodle://module/{cmid}` and the assignment's `intro`.
3. List who submitted: `mod_assign_list_participants` (filter `onlyids` false) and `mod_assign_get_submissions`.
4. Read each submission: online text from the submission plugins; files with `file_read` (use `file_get_download_url` + curl for large files).
5. Draft a grade and feedback per student in a table, quoting evidence from the work. Flag late or missing submissions.
6. Stop and ask the teacher to approve or edit the drafts.
7. Save approved grades with `mod_assign_save_grade` (`assignmentid`, `userid`, `grade`, `attemptnumber` -1, `addattempt` false, `workflowstate` as configured, `applytoall` false, `plugindata.assignfeedbackcomments_editor.text` for comments). Use `mod_assign_save_grades` for many students at once.
8. Feedback files: upload with `file_upload`, pass the draft id as `plugindata.files_filemanager`.

Never release grades or notify students unless the teacher asks.
