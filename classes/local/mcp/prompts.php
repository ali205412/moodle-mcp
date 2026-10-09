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

namespace webservice_mcp\local\mcp;

/**
 * MCP prompts: reusable Moodle workflows surfaced as slash commands by clients.
 *
 * Prompts only produce instructions; every action they lead to still runs through
 * permission-checked tools.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prompts {
    /** @var call_context */
    private call_context $ctx;

    /**
     * Constructor.
     *
     * @param call_context $ctx Request context.
     */
    public function __construct(call_context $ctx) {
        $this->ctx = $ctx;
    }

    /**
     * Prompt catalogue: name => [title, description, arguments [name => [description, required]], template].
     *
     * Templates use {arg} placeholders; optional arguments that are absent become "(not given)".
     *
     * @return array
     */
    public static function catalogue(): array {
        $course = ['courseid' => ['Course id (see moodle://courses)', true]];
        return [
            'course_overview' => ['Course overview', 'Summarise a course: structure, activities, deadlines and recent activity.',
                $course,
                "Give me an overview of Moodle course {courseid}. Read moodle://course/{courseid} for structure, then use "
                . "core_calendar_get_action_events_by_course and the course's recent activity to list upcoming deadlines. "
                . "Present: purpose, sections with key activities, what is due in the next two weeks, and anything that "
                . "looks misconfigured (hidden activities students need, missing due dates, empty sections)."],
            'my_todo' => ['What do I need to do?', 'Everything due, overdue or unread across your courses.', [],
                "Check my Moodle to-do list: upcoming and overdue deadlines across all my courses "
                . "(core_calendar_get_action_events_by_timesort), "
                . "unsubmitted assignments, unread messages and notifications, and new forum posts. Group by urgency "
                . "and include direct links."],
            'grade_submissions' => ['Grade assignment submissions', 'Review submissions and draft grades and feedback.',
                ['cmid' => ['Assignment course module id', true], 'focus' => ['What to focus on when grading', false]],
                "Help me grade the assignment with cmid {cmid}. Focus: {focus}. Read the assignment (moodle://module/{cmid}, "
                . "mod_assign_get_assignments) including any rubric/marking guide, list submissions (mod_assign_get_submissions, "
                . "mod_assign_list_participants), and read submitted files with file_read. For each student draft a grade and "
                . "feedback in a table. Do NOT save anything until I approve; then save with mod_assign_save_grade."],
            'student_progress_report' => ['Student progress report',
                'Grades, completion and engagement for one student or a class.',
                $course + ['userid' => ['Student user id (omit for whole class)', false]],
                "Write a progress report for course {courseid}, student {userid}. Use gradereport_user_get_grade_items, "
                . "core_completion_get_activities_completion_status and recent submissions. Summarise strengths, gaps, "
                . "missing work and concrete next steps."],
            'at_risk_students' => ['Find at-risk students', 'Spot students who are falling behind in a course.', $course,
                "Identify students at risk in course {courseid}: low grades, missing submissions, no recent access, "
                . "incomplete required activities. Use participants, grades and completion data. Rank them with evidence "
                . "and suggest an intervention for each. Do not message anyone without my approval."],
            'build_quiz' => ['Build a quiz', 'Create questions in the question bank and a quiz activity.',
                $course + ['topic' => ['Topic or learning objectives', true], 'count' => ['Number of questions', false],
                    'section' => ['Course section number for the quiz', false]],
                "Build a quiz on \"{topic}\" in course {courseid} with {count} questions in section {section}. Draft the "
                . "questions for my review first (mix of multichoice, truefalse, shortanswer, with feedback). After I "
                . "approve: create a question category (wrapper_question_create_category), add the questions "
                . "(wrapper_question_create_question or GIFT via wrapper_question_import_questions), then create the quiz "
                . "(wrapper_course_add_module modulename quiz) and add the questions (search the API for quiz slots)."],
            'publish_material' => ['Publish course material', 'Upload files and turn them into course resources.',
                $course + ['what' => ['Files or content to publish', true], 'section' => ['Section number', false]],
                "Publish {what} to course {courseid}, section {section}. For local files get an upload URL with "
                . "file_create_upload_url and upload with curl -T; for small content use file_upload. Then create a File "
                . "resource or Folder (wrapper_course_add_module, options.files = draftitemid) or a Page for text. "
                . "Confirm names and visibility with me before creating."],
            'weekly_digest' => ['Weekly digest', 'A teacher-friendly summary of the past week across your courses.', [],
                "Write my weekly Moodle digest: for each course I teach, new submissions waiting for grading, forum "
                . "activity, upcoming deadlines and students who have not logged in this week. Keep it short."],
            'summarize_forum' => ['Summarise a forum', 'Key threads, questions and unanswered posts.',
                ['cmid' => ['Forum course module id', true]],
                "Summarise the forum with cmid {cmid}: main threads, recurring questions, unanswered posts and overall "
                . "sentiment. Use mod_forum_get_forum_discussions and mod_forum_get_discussion_posts. Suggest replies but "
                . "do not post without my approval."],
            'create_course' => ['Create a course', 'Set up a new course, optionally copying another.',
                ['fullname' => ['Course full name', true], 'shortname' => ['Course short name', true],
                    'categoryid' => ['Category id', false], 'template_courseid' => ['Course to copy from', false]],
                "Create a course \"{fullname}\" ({shortname}) in category {categoryid}, based on course "
                . "{template_courseid} if given (backup_create + restore_from_draft, or core_course_duplicate_course). "
                . "Show me the plan before creating."],
            'enrol_users' => ['Enrol users', 'Enrol a list of people into a course with a role.',
                $course + ['who' => ['Names, emails or a cohort', true], 'role' => ['Role, e.g. student', false]],
                "Enrol {who} into course {courseid} as {role}. Look users up first (core_user_get_users_by_field), "
                . "show matches and ambiguities, and only enrol (enrol_manual_enrol_users) after I confirm."],
            'backup_course' => ['Back up a course', 'Create a course backup and give me a download link.', $course,
                "Back up course {courseid} with backup_create, poll backup_status until it finishes, then give me the "
                . "download link from file_get_download_url."],
            'explore_api' => ['Find the right Moodle function', 'Discover how to do something via the Moodle API.',
                ['task' => ['What you want to do', true]],
                "I want to: {task}. Find the right Moodle function with wrapper_moodle_api_search (try several keyword "
                . "variants), inspect candidates with wrapper_moodle_api_describe, explain the best option and its "
                . "parameters, and run it only if it is read-only or I confirm."],
        ];
    }

    /**
     * prompts/list.
     *
     * @return array
     */
    public function list(): array {
        $prompts = [];
        foreach (self::catalogue() as $name => [$title, $description, $arguments]) {
            $prompt = ['name' => $name, 'title' => $title, 'description' => $description];
            if ($arguments !== []) {
                $prompt['arguments'] = [];
                foreach ($arguments as $argname => [$argdescription, $required]) {
                    $prompt['arguments'][] = ['name' => $argname, 'description' => $argdescription, 'required' => $required];
                }
            }
            $prompts[] = $prompt;
        }
        return ['prompts' => $prompts, 'ttlMs' => 3600000, 'cacheScope' => 'public'];
    }

    /**
     * prompts/get.
     *
     * @param string $name Prompt name.
     * @param array $arguments String arguments.
     * @return array
     */
    public function get(string $name, array $arguments): array {
        $catalogue = self::catalogue();
        if (!isset($catalogue[$name])) {
            throw new protocol_exception(protocol_exception::INVALID_PARAMS, 'Unknown prompt: ' . $name);
        }
        [$title, $description, $argdefs, $template] = $catalogue[$name];

        $replacements = [];
        foreach ($argdefs as $argname => [, $required]) {
            $value = trim((string)($arguments[$argname] ?? ''));
            if ($value === '' && $required) {
                throw new protocol_exception(protocol_exception::INVALID_PARAMS, "Missing required argument: {$argname}");
            }
            $replacements['{' . $argname . '}'] = $value === '' ? '(not given)' : $value;
        }

        $messages = [[
            'role' => 'user',
            'content' => ['type' => 'text', 'text' => strtr($template, $replacements)],
        ]];

        $courseid = $arguments['courseid'] ?? null;
        if ($courseid !== null && ctype_digit((string)$courseid)) {
            $messages[] = [
                'role' => 'user',
                'content' => [
                    'type' => 'resource',
                    'resource' => $this->course_brief((int)$courseid),
                ],
            ];
        }

        return ['description' => $description, 'messages' => $messages];
    }

    /**
     * A short embedded course header so the model starts with the right course.
     *
     * Course contents are not embedded (they can be large); the prompt text points at the resource.
     *
     * @param int $courseid Course id.
     * @return array
     */
    private function course_brief(int $courseid): array {
        try {
            $data = resources::call(
                'core_course_get_courses_by_field',
                ['field' => 'id', 'value' => (string)$courseid],
                $this->ctx
            );
            $text = json_encode(
                $data['courses'][0] ?? ['id' => $courseid, 'error' => 'not visible'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
        } catch (\Throwable $e) {
            $text = json_encode(['id' => $courseid, 'error' => $e->getMessage()]);
        }
        return ['uri' => 'moodle://course/' . $courseid, 'mimeType' => 'application/json', 'text' => $text];
    }
}
