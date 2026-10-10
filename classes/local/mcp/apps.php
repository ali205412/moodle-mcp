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
 * MCP Apps (io.modelcontextprotocol/ui): interactive views rendered by hosts such as claude.ai.
 *
 * Hosts without MCP Apps support ignore the ui metadata and use the text content.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apps {
    /** Extension identifier. */
    public const EXTENSION = 'io.modelcontextprotocol/ui';

    /** MCP App mime type. */
    public const MIMETYPE = 'text/html;profile=mcp-app';

    /** Explorer resource URI. */
    public const EXPLORER_URI = 'ui://moodle/explorer';

    /** Explorer tool name. */
    public const EXPLORER_TOOL = 'moodle_explorer';

    /**
     * Tool definitions linked to UI resources.
     *
     * @return array
     */
    public static function tools(): array {
        return [[
            'name' => self::EXPLORER_TOOL,
            'title' => 'Moodle Explorer',
            'description' => 'Interactive browser of your Moodle courses: course list with progress, then sections, activities, '
                . 'files (with download) and upcoming deadlines. Pass courseid to open one course; omit it for the course list. '
                . 'Hosts that support MCP Apps show it as an interactive panel; others get a text summary.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'view' => [
                        'type' => 'string',
                        'enum' => ['courses', 'course'],
                        'description' => 'Defaults to course when courseid is given.',
                    ],
                    'courseid' => ['type' => 'integer', 'description' => 'Course to open; required when view=course.'],
                ],
            ],
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true,
                'openWorldHint' => false],
            '_meta' => ['ui' => ['resourceUri' => self::EXPLORER_URI, 'visibility' => ['model', 'app']]],
        ]];
    }

    /**
     * Whether a tool name belongs to an app.
     *
     * @param string $name Tool name.
     * @return bool
     */
    public static function handles(string $name): bool {
        return $name === self::EXPLORER_TOOL;
    }

    /**
     * Run the explorer tool.
     *
     * @param array $args Arguments.
     * @param call_context $ctx Request context.
     * @return array CallToolResult.
     */
    public static function execute(array $args, call_context $ctx): array {
        $ctx->require_scope(false);
        if (($args['view'] ?? (empty($args['courseid']) ? 'courses' : 'course')) === 'course') {
            $courseid = (int)($args['courseid'] ?? 0);
            if ($courseid <= 0) {
                throw new \invalid_parameter_exception('courseid is required for view=course');
            }
            $data = self::course($courseid, $ctx);
            $lines = ["Course {$data['course']['fullname']} (id {$courseid})"];
            foreach ($data['sections'] as $section) {
                $names = array_map(
                    static fn(array $m): string => "{$m['name']} [{$m['modname']} {$m['id']}]",
                    $section['modules'] ?? []
                );
                $lines[] = '- ' . ($section['name'] ?: 'Section') . ($names ? ': ' . implode('; ', $names) : '');
            }
            foreach ($data['deadlines'] as $deadline) {
                $lines[] = 'Due ' . userdate((int)$deadline['timesort']) . ': ' . $deadline['name'];
            }
        } else {
            $data = ['view' => 'courses', 'courses' => self::courses($ctx)];
            $lines = array_map(
                static fn(array $c): string => "- {$c['fullname']} ({$c['shortname']}, id {$c['id']})",
                $data['courses']
            );
            array_unshift($lines, 'Your courses:');
        }

        return [
            'content' => [['type' => 'text', 'text' => implode("\n", $lines)]],
            'structuredContent' => $data,
        ];
    }

    /**
     * resources/read for ui:// URIs.
     *
     * @param string $uri URI.
     * @return array|null Result, or null when the URI is not an app.
     */
    public static function read(string $uri): ?array {
        if ($uri !== self::EXPLORER_URI) {
            return null;
        }
        global $CFG;
        return [
            'contents' => [[
                'uri' => $uri,
                'mimeType' => self::MIMETYPE,
                'text' => file_get_contents($CFG->dirroot . '/webservice/mcp/apps/explorer.html'),
                '_meta' => ['ui' => ['prefersBorder' => true]],
            ]],
            'ttlMs' => 3600000,
            'cacheScope' => 'public',
        ];
    }

    /**
     * Resource list entries for apps.
     *
     * @return array
     */
    public static function resources(): array {
        return [[
            'uri' => self::EXPLORER_URI,
            'name' => 'moodle-explorer',
            'title' => 'Moodle Explorer',
            'description' => 'Interactive course and file browser.',
            'mimeType' => self::MIMETYPE,
        ]];
    }

    /**
     * Enrolled courses with progress.
     *
     * @param call_context $ctx Request context.
     * @return array
     */
    private static function courses(call_context $ctx): array {
        $courses = [];
        foreach (resources::call('core_enrol_get_users_courses', ['userid' => (int)$ctx->user->id], $ctx) as $course) {
            $courses[] = [
                'id' => (int)$course['id'],
                'fullname' => (string)$course['fullname'],
                'shortname' => (string)$course['shortname'],
                'progress' => $course['progress'] ?? null,
                'completed' => !empty($course['completed']),
                'visible' => (int)($course['visible'] ?? 1) === 1,
                'favourite' => !empty($course['isfavourite']),
                'lastaccess' => $course['lastaccess'] ?? null,
                'url' => (new \moodle_url('/course/view.php', ['id' => (int)$course['id']]))->out(false),
            ];
        }
        // Favourites first, then most recently opened.
        usort($courses, static fn(array $a, array $b): int => [$b['favourite'], (int)$b['lastaccess'], $a['fullname']]
            <=> [$a['favourite'], (int)$a['lastaccess'], $b['fullname']]);
        return $courses;
    }

    /**
     * Course sections, visible modules with files, and deadlines.
     *
     * @param int $courseid Course id.
     * @param call_context $ctx Request context.
     * @return array
     */
    private static function course(int $courseid, call_context $ctx): array {
        $course = resources::call('core_course_get_courses_by_field', ['field' => 'id', 'value' => (string)$courseid], $ctx);
        if (empty($course['courses'])) {
            throw new \moodle_exception('invalidcourseid');
        }
        $sections = [];
        foreach (resources::call('core_course_get_contents', ['courseid' => $courseid], $ctx) as $section) {
            $modules = [];
            foreach ($section['modules'] ?? [] as $module) {
                $modules[] = [
                    'id' => (int)$module['id'],
                    'name' => (string)$module['name'],
                    'modname' => (string)$module['modname'],
                    'modlabel' => self::module_label((string)$module['modname']),
                    'uservisible' => $module['uservisible'] ?? true,
                    'visible' => (int)($module['visible'] ?? 1) === 1,
                    'url' => empty($module['noviewlink']) ? ($module['url'] ?? null) : null,
                    'completion' => !empty($module['completiondata']['istrackeduser'])
                        ? (int)$module['completiondata']['state'] : null,
                    'dates' => array_map(static fn(array $d): array => [
                        'label' => (string)$d['label'],
                        'timestamp' => (int)$d['timestamp'],
                    ], $module['dates'] ?? []),
                    'contents' => array_values(array_map(static fn(array $f): array => [
                        'type' => $f['type'] ?? 'file',
                        'filename' => (string)($f['filename'] ?? ''),
                        'filepath' => (string)($f['filepath'] ?? '/'),
                        'fileurl' => (string)($f['fileurl'] ?? ''),
                        'filesize' => (int)($f['filesize'] ?? 0),
                        'mimetype' => $f['mimetype'] ?? null,
                    ], $module['contents'] ?? [])),
                ];
            }
            $summary = trim(html_to_text((string)($section['summary'] ?? ''), 0, false));
            $sections[] = [
                'id' => (int)$section['id'],
                'name' => (string)$section['name'],
                'summary' => \core_text::strlen($summary) > 400 ? \core_text::substr($summary, 0, 400) . '…' : $summary,
                'visible' => (int)($section['visible'] ?? 1) === 1,
                'modules' => $modules,
            ];
        }
        try {
            $events = resources::call(
                'core_calendar_get_action_events_by_course',
                ['courseid' => $courseid, 'timesortfrom' => time(), 'limitnum' => 10],
                $ctx
            );
            $deadlines = array_map(static fn(array $e): array => [
                'name' => (string)$e['name'],
                'timesort' => (int)$e['timesort'],
                'overdue' => !empty($e['overdue']),
                'url' => (string)($e['action']['url'] ?? $e['url'] ?? ''),
                'actionname' => (string)($e['action']['name'] ?? ''),
            ], $events['events'] ?? []);
        } catch (\Throwable $e) {
            $deadlines = [];
        }
        return [
            'view' => 'course',
            'course' => [
                'id' => $courseid,
                'fullname' => (string)$course['courses'][0]['fullname'],
                'url' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
            ],
            'sections' => $sections,
            'deadlines' => $deadlines,
        ];
    }

    /**
     * Human name of an activity type, e.g. "Assignment" for assign.
     *
     * @param string $modname Module name.
     * @return string
     */
    private static function module_label(string $modname): string {
        $manager = get_string_manager();
        return $manager->string_exists('modulename', 'mod_' . $modname)
            ? get_string('modulename', 'mod_' . $modname) : $modname;
    }
}
