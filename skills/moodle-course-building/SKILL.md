---
name: moodle-course-building
description: Build or reorganise a Moodle course - sections, activities, resources, quizzes and settings.
---

# Course building

- Inspect first: `moodle://course/{id}` shows sections and activities.
- Sections: `wrapper_course_create_missing_sections`, `wrapper_course_add_section_after`, `wrapper_course_move_section_after`, `wrapper_course_set_section_visibility`; rename or describe with `core_course_edit_section` or `core_courseformat_update_course`.
- Activities: `wrapper_course_add_module` (`modulename` page, url, assign, quiz, forum, folder, resource, label, ...; `section`, `visible`, `intro`, plus module options). Move, duplicate, hide or delete with the `wrapper_course_*_module(s)` tools.
- Files as resources: upload (see the moodle-file-transfer skill), then `wrapper_course_add_module` with `options.files`.
- Quizzes: create questions with `wrapper_question_create_category` and `wrapper_question_create_question`, or import GIFT/Moodle XML with `wrapper_question_import_questions`; then add the quiz module and its slots (search the API for "quiz add question").
- Gradebook: `wrapper_gradebook_*` manages manual items and categories.
- Badges: `wrapper_badge_*`.
- Course image: `file_set_course_image`.

Show the planned structure to the teacher before creating more than a few items.
