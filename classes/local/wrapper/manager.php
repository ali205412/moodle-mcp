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

namespace webservice_mcp\local\wrapper;

use context;
use stdClass;

/**
 * Registry and discovery helper for connector-owned wrapper tools.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** @var definition[] */
    private array $definitions;

    /** @var course_authoring_service */
    private course_authoring_service $courseauthoringservice;

    /**
     * Constructor.
     *
     * @param definition[] $definitions Optional definitions.
     * @param bool $includedefaults Whether to include built-in wrapper definitions.
     * @param course_authoring_service|null $courseauthoringservice Optional authoring service.
     */
    public function __construct(
        array $definitions = [],
        bool $includedefaults = true,
        ?course_authoring_service $courseauthoringservice = null
    ) {
        $this->definitions = $includedefaults ? array_merge(self::default_definitions(), $definitions) : $definitions;
        $this->courseauthoringservice = $courseauthoringservice ?? new course_authoring_service();
    }

    /**
     * Return all registered definitions.
     *
     * @return definition[]
     */
    public function all(): array {
        return $this->definitions;
    }

    /**
     * Find a definition by tool name.
     *
     * @param string $name Tool name.
     * @return definition|null
     */
    public function find(string $name): ?definition {
        foreach ($this->definitions as $definition) {
            if ($definition->get_name() === $name) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Return only wrapper definitions currently discoverable in the restricted context.
     *
     * @param context $restrictedcontext Current restricted context.
     * @param stdClass|null $user Current user.
     * @return array
     */
    public function describe_discoverable(context $restrictedcontext, ?stdClass $user = null): array {
        $descriptions = [];
        foreach ($this->definitions as $definition) {
            if (!$definition->can_discover($restrictedcontext, $user)) {
                continue;
            }

            $descriptions[] = $definition->describe();
        }

        return $descriptions;
    }

    /**
     * Execute a discoverable wrapper.
     *
     * @param string $name Tool name.
     * @param array $arguments Tool arguments.
     * @param context $restrictedcontext Current restricted context.
     * @param stdClass|null $user Current user.
     * @return array
     */
    public function execute(string $name, array $arguments, context $restrictedcontext, ?stdClass $user = null): array {
        $definition = $this->find($name);
        if ($definition === null || !$definition->can_discover($restrictedcontext, $user)) {
            throw new \moodle_exception('invalidparameter');
        }

        return match ($name) {
            'wrapper_course_add_section_after' => $this->courseauthoringservice->add_section_after(
                (int)($arguments['courseid'] ?? 0),
                isset($arguments['targetsectionid']) ? (int)$arguments['targetsectionid'] : null
            ),
            'wrapper_course_set_section_visibility' => $this->courseauthoringservice->set_section_visibility(
                (int)($arguments['courseid'] ?? 0),
                is_array($arguments['sectionids'] ?? null) ? $arguments['sectionids'] : [],
                (bool)($arguments['visible'] ?? false)
            ),
            'wrapper_course_delete_sections' => $this->courseauthoringservice->delete_sections(
                (int)($arguments['courseid'] ?? 0),
                is_array($arguments['sectionids'] ?? null) ? $arguments['sectionids'] : []
            ),
            'wrapper_course_create_missing_sections' => $this->courseauthoringservice->create_missing_sections(
                (int)($arguments['courseid'] ?? 0),
                is_array($arguments['sectionnums'] ?? null) ? $arguments['sectionnums'] : []
            ),
            'wrapper_course_move_module' => $this->courseauthoringservice->move_modules(
                (int)($arguments['courseid'] ?? 0),
                is_array($arguments['cmids'] ?? null) ? $arguments['cmids'] : [],
                isset($arguments['targetsectionid']) ? (int)$arguments['targetsectionid'] : null,
                isset($arguments['targetcmid']) ? (int)$arguments['targetcmid'] : null
            ),
            'wrapper_course_move_section_after' => $this->courseauthoringservice->move_sections_after(
                (int)($arguments['courseid'] ?? 0),
                is_array($arguments['sectionids'] ?? null) ? $arguments['sectionids'] : [],
                (int)($arguments['targetsectionid'] ?? 0)
            ),
            'wrapper_course_set_module_visibility' => $this->courseauthoringservice->set_module_visibility(
                (int)($arguments['courseid'] ?? 0),
                is_array($arguments['cmids'] ?? null) ? $arguments['cmids'] : [],
                (string)($arguments['visibility'] ?? '')
            ),
            'wrapper_course_duplicate_modules' => $this->courseauthoringservice->duplicate_modules(
                (int)($arguments['courseid'] ?? 0),
                is_array($arguments['cmids'] ?? null) ? $arguments['cmids'] : [],
                isset($arguments['targetsectionid']) ? (int)$arguments['targetsectionid'] : null,
                isset($arguments['targetcmid']) ? (int)$arguments['targetcmid'] : null
            ),
            'wrapper_course_delete_modules' => $this->courseauthoringservice->delete_modules(
                (int)($arguments['courseid'] ?? 0),
                is_array($arguments['cmids'] ?? null) ? $arguments['cmids'] : []
            ),
            default => throw new \moodle_exception('invalidparameter'),
        };
    }

    /**
     * Return built-in wrapper definitions.
     *
     * @return array
     */
    private static function default_definitions(): array {
        return [
            new definition(
                'wrapper_course_add_section_after',
                'webservice_mcp',
                'operator',
                'Add a new course section, optionally after another section.',
                ['moodle/course:update'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'targetsectionid' => ['type' => 'number'],
                    ],
                    'required' => ['courseid'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'boolean'],
                        'statejson' => ['type' => 'string'],
                    ],
                ],
            ),
            new definition(
                'wrapper_course_set_section_visibility',
                'webservice_mcp',
                'operator',
                'Show or hide one or more course sections with structured course-state updates.',
                ['moodle/course:update', 'moodle/course:sectionvisibility'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'sectionids' => ['type' => 'array', 'items' => ['type' => 'number']],
                        'visible' => ['type' => 'boolean'],
                    ],
                    'required' => ['courseid', 'sectionids', 'visible'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'boolean'],
                        'action' => ['type' => 'string'],
                        'statejson' => ['type' => 'string'],
                    ],
                ],
            ),
            new definition(
                'wrapper_course_delete_sections',
                'webservice_mcp',
                'operator',
                'Delete one or more course sections and return structured state updates.',
                ['moodle/course:update', 'moodle/course:movesections'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'sectionids' => ['type' => 'array', 'items' => ['type' => 'number']],
                    ],
                    'required' => ['courseid', 'sectionids'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'boolean'],
                        'statejson' => ['type' => 'string'],
                    ],
                ],
            ),
            new definition(
                'wrapper_course_create_missing_sections',
                'webservice_mcp',
                'operator',
                'Ensure one or more course sections exist, creating missing sections when needed.',
                ['moodle/course:update', 'moodle/course:manageactivities'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'sectionnums' => ['type' => 'array', 'items' => ['type' => 'number']],
                    ],
                    'required' => ['courseid', 'sectionnums'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'boolean'],
                        'created' => ['type' => 'boolean'],
                    ],
                ],
            ),
            new definition(
                'wrapper_course_move_module',
                'webservice_mcp',
                'operator',
                'Move one or more existing course modules to a target section or before another module.',
                ['moodle/course:manageactivities'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'cmids' => ['type' => 'array', 'items' => ['type' => 'number']],
                        'targetsectionid' => ['type' => 'number'],
                        'targetcmid' => ['type' => 'number'],
                    ],
                    'required' => ['courseid', 'cmids'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'boolean'],
                        'statejson' => ['type' => 'string'],
                    ],
                ],
            ),
            new definition(
                'wrapper_course_move_section_after',
                'webservice_mcp',
                'operator',
                'Move one or more course sections after another target section.',
                ['moodle/course:movesections'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'sectionids' => ['type' => 'array', 'items' => ['type' => 'number']],
                        'targetsectionid' => ['type' => 'number'],
                    ],
                    'required' => ['courseid', 'sectionids', 'targetsectionid'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'boolean'],
                        'statejson' => ['type' => 'string'],
                    ],
                ],
            ),
            new definition(
                'wrapper_course_set_module_visibility',
                'webservice_mcp',
                'operator',
                'Show, hide, or stealth one or more course modules with structured state updates.',
                ['moodle/course:activityvisibility'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'cmids' => ['type' => 'array', 'items' => ['type' => 'number']],
                        'visibility' => [
                            'type' => 'string',
                            'enum' => ['show', 'hide', 'stealth'],
                        ],
                    ],
                    'required' => ['courseid', 'cmids', 'visibility'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'boolean'],
                        'action' => ['type' => 'string'],
                        'statejson' => ['type' => 'string'],
                    ],
                ],
            ),
            new definition(
                'wrapper_course_duplicate_modules',
                'webservice_mcp',
                'operator',
                'Duplicate one or more course modules with optional placement controls.',
                ['moodle/backup:backuptargetimport', 'moodle/restore:restoretargetimport'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'cmids' => ['type' => 'array', 'items' => ['type' => 'number']],
                        'targetsectionid' => ['type' => 'number'],
                        'targetcmid' => ['type' => 'number'],
                    ],
                    'required' => ['courseid', 'cmids'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'boolean'],
                        'statejson' => ['type' => 'string'],
                    ],
                ],
            ),
            new definition(
                'wrapper_course_delete_modules',
                'webservice_mcp',
                'operator',
                'Delete one or more course modules and return structured state updates.',
                ['moodle/course:manageactivities'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'cmids' => ['type' => 'array', 'items' => ['type' => 'number']],
                    ],
                    'required' => ['courseid', 'cmids'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'boolean'],
                        'statejson' => ['type' => 'string'],
                    ],
                ],
            ),
        ];
    }
}
