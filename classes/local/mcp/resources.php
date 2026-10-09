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

use webservice_mcp\local\files\file_service;
use webservice_mcp\local\wrapper\memory_service;

/**
 * MCP resources: read-only views of Moodle data addressed by moodle:// URIs.
 *
 * Reads go through Moodle external functions or file permission checks, so a resource
 * never shows more than the user could see through the Moodle UI.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resources {
    /** Max courses enumerated in resources/list. */
    private const MAX_LISTED_COURSES = 100;

    /** JSON mime type. */
    private const JSON = 'application/json';

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
     * resources/list.
     *
     * @param mixed $cursor Unused; the list is bounded.
     * @return array
     */
    public function list(mixed $cursor = null): array {
        $resources = [
            $this->resource('moodle://site', 'site', 'Site information', 'Site name, release, your user and enabled features.'),
            $this->resource('moodle://user/me', 'me', 'My profile', 'Your Moodle profile.'),
            $this->resource('moodle://courses', 'my-courses', 'My courses', 'Courses you are enrolled in.'),
            $this->resource('moodle://calendar/upcoming', 'upcoming', 'Upcoming events', 'Deadlines and events.'),
        ];

        $resources = array_merge($resources, apps::resources());

        $courses = enrol_get_my_courses(['id', 'fullname', 'shortname'], 'fullname ASC', self::MAX_LISTED_COURSES);
        foreach ($courses as $course) {
            $resources[] = $this->resource(
                'moodle://course/' . $course->id,
                'course-' . $course->id,
                format_string($course->fullname),
                'Course ' . format_string($course->shortname) . ': sections, activities and files.'
            );
        }

        if ($this->ctx->connector) {
            foreach ((new memory_service())->read_memories() as $memory) {
                $resources[] = $this->resource(
                    'moodle://memory/' . $memory['id'],
                    'memory-' . $memory['id'],
                    'Memory ' . $memory['id'],
                    \core_text::substr((string)$memory['content'], 0, 120),
                    'text/plain'
                );
            }
        }

        return ['resources' => $resources, 'ttlMs' => 60000, 'cacheScope' => 'private'];
    }

    /**
     * resources/templates/list.
     *
     * @return array
     */
    public function templates(): array {
        $templates = [
            ['uriTemplate' => 'moodle://course/{courseid}', 'name' => 'course', 'title' => 'Course contents',
                'description' => 'Course details plus every section and activity you can see.'],
            ['uriTemplate' => 'moodle://course/{courseid}/grades', 'name' => 'course-grades', 'title' => 'My course grades',
                'description' => 'Your grade items in a course.'],
            ['uriTemplate' => 'moodle://course/{courseid}/participants', 'name' => 'course-participants',
                'title' => 'Course participants', 'description' => 'Enrolled users you are allowed to see.'],
            ['uriTemplate' => 'moodle://module/{cmid}', 'name' => 'module', 'title' => 'Activity',
                'description' => 'One activity or resource: settings, description and file list.'],
            ['uriTemplate' => 'moodle://user/{userid}', 'name' => 'user', 'title' => 'User profile',
                'description' => 'A user profile, as far as you may view it.'],
        ];
        if ($this->ctx->connector) {
            $templates[] = ['uriTemplate' => 'moodle://file/{contextid}/{component}/{filearea}/{itemid}/{+path}',
                'name' => 'file', 'title' => 'File', 'description' => 'Any file you can access. '
                . 'Small files are returned inline; large files return a download link.'];
            $templates[] = ['uriTemplate' => 'moodle://memory/{id}', 'name' => 'memory', 'title' => 'Memory'];
        }
        foreach ($templates as &$template) {
            $template['mimeType'] ??= self::JSON;
        }
        unset($template);
        return ['resourceTemplates' => $templates, 'ttlMs' => 3600000, 'cacheScope' => 'public'];
    }

    /**
     * resources/read.
     *
     * @param string $uri Resource URI.
     * @return array
     */
    public function read(string $uri): array {
        if ($app = apps::read($uri) ?? skills::read($uri)) {
            return $app;
        }
        $path = parse_url($uri);
        if (($path['scheme'] ?? '') !== 'moodle') {
            throw $this->not_found($uri);
        }
        $segments = array_values(array_filter(explode('/', ($path['host'] ?? '') . ($path['path'] ?? '')), 'strlen'));
        $kind = $segments[0] ?? '';
        $id = isset($segments[1]) && ctype_digit($segments[1]) ? (int)$segments[1] : null;

        try {
            $data = match (true) {
                $kind === 'file' && $this->ctx->connector => null,
                $kind === 'site' => self::call('core_webservice_get_site_info', [], $this->ctx),
                $kind === 'courses' =>
                    self::call('core_enrol_get_users_courses', ['userid' => (int)$this->ctx->user->id], $this->ctx),
                $kind === 'calendar' => self::call('core_calendar_get_calendar_upcoming_view', ['courseid' => SITEID], $this->ctx),
                $kind === 'user' && ($segments[1] ?? '') === 'me' => $this->user((int)$this->ctx->user->id),
                $kind === 'user' && $id !== null => $this->user($id),
                $kind === 'course' && $id !== null && ($segments[2] ?? '') === 'grades' =>
                    self::call(
                        'gradereport_user_get_grade_items',
                        ['courseid' => $id, 'userid' => (int)$this->ctx->user->id], $this->ctx
                    ),
                $kind === 'course' && $id !== null && ($segments[2] ?? '') === 'participants' =>
                    self::call('core_enrol_get_enrolled_users', ['courseid' => $id], $this->ctx),
                $kind === 'course' && $id !== null && !isset($segments[2]) => $this->course($id),
                $kind === 'module' && $id !== null => $this->module($id),
                $kind === 'memory' && $id !== null && $this->ctx->connector =>
                    (new memory_service())->read_memory_by_id($id),
                default => throw $this->not_found($uri),
            };
        } catch (protocol_exception $e) {
            throw $e;
        } catch (\dml_missing_record_exception | \moodle_exception $e) {
            if ($e instanceof \required_capability_exception || $e instanceof \require_login_exception) {
                throw new protocol_exception(protocol_exception::INVALID_PARAMS, 'Access denied: ' . $e->getMessage());
            }
            throw $this->not_found($uri, $e->getMessage());
        }

        if ($kind === 'file') {
            return (new file_service())->read_resource($uri, $this->ctx) + ['ttlMs' => 0, 'cacheScope' => 'private'];
        }

        return [
            'contents' => [[
                'uri' => $uri,
                'mimeType' => $kind === 'memory' ? 'text/plain' : self::JSON,
                'text' => $kind === 'memory'
                    ? (string)$data['content']
                    : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            ]],
            'ttlMs' => 0,
            'cacheScope' => 'private',
        ];
    }

    /**
     * Course details plus contents.
     *
     * @param int $courseid Course id.
     * @return array
     */
    private function course(int $courseid): array {
        $course = self::call('core_course_get_courses_by_field', ['field' => 'id', 'value' => (string)$courseid], $this->ctx);
        if (empty($course['courses'])) {
            throw new \moodle_exception('invalidcourseid');
        }
        return [
            'course' => $course['courses'][0],
            'sections' => self::call('core_course_get_contents', ['courseid' => $courseid], $this->ctx),
        ];
    }

    /**
     * Activity details.
     *
     * @param int $cmid Course module id.
     * @return array
     */
    private function module(int $cmid): array {
        $cm = self::call('core_course_get_course_module', ['cmid' => $cmid], $this->ctx);
        $contents = self::call('core_course_get_contents', [
            'courseid' => (int)$cm['cm']['course'],
            'options' => [['name' => 'cmid', 'value' => (string)$cmid]],
        ], $this->ctx);
        $module = null;
        foreach ($contents as $section) {
            foreach ($section['modules'] ?? [] as $candidate) {
                if ((int)$candidate['id'] === $cmid) {
                    $module = $candidate;
                }
            }
        }
        return ['cm' => $cm['cm'], 'module' => $module];
    }

    /**
     * User profile through core_user_get_users_by_field (applies Moodle's profile visibility rules).
     *
     * @param int $userid User id.
     * @return array
     */
    private function user(int $userid): array {
        $users = self::call('core_user_get_users_by_field', ['field' => 'id', 'values' => [(string)$userid]], $this->ctx);
        if (empty($users)) {
            throw new \moodle_exception('invaliduser');
        }
        return $users[0];
    }

    /**
     * Call a Moodle external function as the current user; failures propagate as exceptions.
     *
     * Goes through tool_runner::run, so the token's service function list and restricted
     * context apply exactly as they do to tools/call.
     *
     * @param string $function Function name.
     * @param array $args Arguments.
     * @param call_context $ctx Request context.
     * @return mixed
     */
    public static function call(string $function, array $args, call_context $ctx): mixed {
        return tool_runner::run($function, $args, $ctx)['result'];
    }


    /**
     * Build one Resource entry.
     *
     * @param string $uri URI.
     * @param string $name Name.
     * @param string $title Title.
     * @param string $description Description.
     * @param string $mimetype Mime type.
     * @return array
     */
    private function resource(string $uri, string $name, string $title, string $description, string $mimetype = self::JSON): array {
        return ['uri' => $uri, 'name' => $name, 'title' => $title, 'description' => $description, 'mimeType' => $mimetype];
    }

    /**
     * Era-appropriate resource-not-found error.
     *
     * @param string $uri URI.
     * @param string $detail Optional detail.
     * @return protocol_exception
     */
    private function not_found(string $uri, string $detail = ''): protocol_exception {
        $code = $this->ctx->era === call_context::ERA_MODERN
            ? protocol_exception::INVALID_PARAMS
            : protocol_exception::RESOURCE_NOT_FOUND_LEGACY;
        return new protocol_exception(
            $code,
            'Resource not found: ' . $uri . ($detail !== '' ? " ({$detail})" : ''),
            200,
            ['uri' => $uri]
        );
    }
}
