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

    /** @var question_bank_service */
    private question_bank_service $questionbankservice;

    /** @var gradebook_service */
    private gradebook_service $gradebookservice;

    /** @var badge_service */
    private badge_service $badgeservice;

    /**
     * Constructor.
     *
     * @param definition[] $definitions Optional definitions.
     * @param bool $includedefaults Whether to include built-in wrapper definitions.
     * @param course_authoring_service|null $courseauthoringservice Optional authoring service.
     * @param question_bank_service|null $questionbankservice Optional question-bank service.
     * @param gradebook_service|null $gradebookservice Optional gradebook service.
     * @param badge_service|null $badgeservice Optional badge service.
     */
    public function __construct(
        array $definitions = [],
        bool $includedefaults = true,
        ?course_authoring_service $courseauthoringservice = null,
        ?question_bank_service $questionbankservice = null,
        ?gradebook_service $gradebookservice = null,
        ?badge_service $badgeservice = null
    ) {
        $this->definitions = $includedefaults ? array_merge(self::default_definitions(), $definitions) : $definitions;
        $this->courseauthoringservice = $courseauthoringservice ?? new course_authoring_service();
        $this->questionbankservice = $questionbankservice ?? new question_bank_service();
        $this->gradebookservice = $gradebookservice ?? new gradebook_service();
        $this->badgeservice = $badgeservice ?? new badge_service();
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
     * Determine whether the named wrapper mutates Moodle state.
     *
     * @param string $name Tool name.
     * @return bool
     */
    public function is_mutating(string $name): bool {
        return !in_array($name, [
            'wrapper_question_preview_question',
        ], true);
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
            'wrapper_question_create_category' => $this->questionbankservice->create_category(
                (int)($arguments['contextid'] ?? 0),
                (string)($arguments['name'] ?? ''),
                isset($arguments['parentcategoryid']) ? (int)$arguments['parentcategoryid'] : null,
                (string)($arguments['info'] ?? ''),
                (int)($arguments['infoformat'] ?? FORMAT_HTML),
                isset($arguments['idnumber']) ? (string)$arguments['idnumber'] : null
            ),
            'wrapper_question_update_category' => $this->questionbankservice->update_category(
                (int)($arguments['categoryid'] ?? 0),
                (string)($arguments['name'] ?? ''),
                (string)($arguments['info'] ?? ''),
                (int)($arguments['infoformat'] ?? FORMAT_HTML),
                isset($arguments['parentcategoryid']) ? (int)$arguments['parentcategoryid'] : null,
                isset($arguments['idnumber']) ? (string)$arguments['idnumber'] : null
            ),
            'wrapper_question_delete_category' => $this->questionbankservice->delete_category(
                (int)($arguments['categoryid'] ?? 0),
                isset($arguments['movequestionstocategoryid']) ? (int)$arguments['movequestionstocategoryid'] : null
            ),
            'wrapper_question_move_questions' => $this->questionbankservice->move_questions(
                is_array($arguments['questionids'] ?? null) ? $arguments['questionids'] : [],
                (int)($arguments['targetcategoryid'] ?? 0)
            ),
            'wrapper_question_delete_questions' => $this->questionbankservice->delete_questions(
                is_array($arguments['questionids'] ?? null) ? $arguments['questionids'] : []
            ),
            'wrapper_question_create_question' => $this->questionbankservice->create_question(
                (int)($arguments['categoryid'] ?? 0),
                is_array($arguments['payload'] ?? null) ? $arguments['payload'] : []
            ),
            'wrapper_question_update_question' => $this->questionbankservice->update_question(
                (int)($arguments['questionid'] ?? 0),
                is_array($arguments['payload'] ?? null) ? $arguments['payload'] : []
            ),
            'wrapper_question_preview_question' => $this->questionbankservice->preview_question(
                (int)($arguments['questionid'] ?? 0)
            ),
            'wrapper_question_import_questions' => $this->questionbankservice->import_questions(
                (int)($arguments['categoryid'] ?? 0),
                (string)($arguments['format'] ?? ''),
                (string)($arguments['content'] ?? ''),
                (bool)($arguments['catfromfile'] ?? false),
                (bool)($arguments['contextfromfile'] ?? false)
            ),
            'wrapper_gradebook_create_manual_item' => $this->gradebookservice->create_manual_item(
                (int)($arguments['courseid'] ?? 0),
                is_array($arguments['payload'] ?? null) ? $arguments['payload'] : []
            ),
            'wrapper_gradebook_update_manual_item' => $this->gradebookservice->update_manual_item(
                (int)($arguments['courseid'] ?? 0),
                (int)($arguments['itemid'] ?? 0),
                is_array($arguments['payload'] ?? null) ? $arguments['payload'] : []
            ),
            'wrapper_gradebook_move_item' => $this->gradebookservice->move_item(
                (int)($arguments['courseid'] ?? 0),
                (int)($arguments['itemid'] ?? 0),
                isset($arguments['parentcategoryid']) ? (int)$arguments['parentcategoryid'] : null,
                isset($arguments['afteritemid']) ? (int)$arguments['afteritemid'] : null
            ),
            'wrapper_gradebook_delete_items' => $this->gradebookservice->delete_items(
                (int)($arguments['courseid'] ?? 0),
                is_array($arguments['itemids'] ?? null) ? $arguments['itemids'] : []
            ),
            'wrapper_gradebook_update_category' => $this->gradebookservice->update_category(
                (int)($arguments['courseid'] ?? 0),
                (int)($arguments['categoryid'] ?? 0),
                is_array($arguments['payload'] ?? null) ? $arguments['payload'] : []
            ),
            'wrapper_gradebook_move_category' => $this->gradebookservice->move_category(
                (int)($arguments['courseid'] ?? 0),
                (int)($arguments['categoryid'] ?? 0),
                isset($arguments['parentcategoryid']) ? (int)$arguments['parentcategoryid'] : null,
                isset($arguments['aftercategoryid']) ? (int)$arguments['aftercategoryid'] : null,
                isset($arguments['afteritemid']) ? (int)$arguments['afteritemid'] : null
            ),
            'wrapper_gradebook_delete_categories' => $this->gradebookservice->delete_categories(
                (int)($arguments['courseid'] ?? 0),
                is_array($arguments['categoryids'] ?? null) ? $arguments['categoryids'] : []
            ),
            'wrapper_badge_create_badge' => $this->badgeservice->create_badge(
                is_array($arguments['payload'] ?? null) ? $arguments['payload'] : [],
                isset($arguments['courseid']) ? (int)$arguments['courseid'] : null
            ),
            'wrapper_badge_update_badge' => $this->badgeservice->update_badge(
                (int)($arguments['badgeid'] ?? 0),
                is_array($arguments['payload'] ?? null) ? $arguments['payload'] : []
            ),
            'wrapper_badge_update_badge_message' => $this->badgeservice->update_badge_message(
                (int)($arguments['badgeid'] ?? 0),
                is_array($arguments['payload'] ?? null) ? $arguments['payload'] : []
            ),
            'wrapper_badge_delete_badges' => $this->badgeservice->delete_badges(
                is_array($arguments['badgeids'] ?? null) ? $arguments['badgeids'] : [],
                (bool)($arguments['archive'] ?? true)
            ),
            'wrapper_badge_duplicate_badge' => $this->badgeservice->duplicate_badge(
                (int)($arguments['badgeid'] ?? 0)
            ),
            'wrapper_badge_add_related_badges' => $this->badgeservice->add_related_badges(
                (int)($arguments['badgeid'] ?? 0),
                is_array($arguments['relatedbadgeids'] ?? null) ? $arguments['relatedbadgeids'] : []
            ),
            'wrapper_badge_delete_related_badges' => $this->badgeservice->delete_related_badges(
                (int)($arguments['badgeid'] ?? 0),
                is_array($arguments['relatedbadgeids'] ?? null) ? $arguments['relatedbadgeids'] : []
            ),
            'wrapper_badge_save_alignment' => $this->badgeservice->save_alignment(
                (int)($arguments['badgeid'] ?? 0),
                is_array($arguments['payload'] ?? null) ? $arguments['payload'] : [],
                isset($arguments['alignmentid']) ? (int)$arguments['alignmentid'] : null
            ),
            'wrapper_badge_delete_alignments' => $this->badgeservice->delete_alignments(
                (int)($arguments['badgeid'] ?? 0),
                is_array($arguments['alignmentids'] ?? null) ? $arguments['alignmentids'] : []
            ),
            'wrapper_badge_award_badge' => $this->badgeservice->award_badge(
                (int)($arguments['badgeid'] ?? 0),
                (int)($arguments['recipientid'] ?? 0),
                isset($arguments['issuerroleid']) ? (int)$arguments['issuerroleid'] : null
            ),
            'wrapper_badge_revoke_badge' => $this->badgeservice->revoke_badge(
                (int)($arguments['badgeid'] ?? 0),
                (int)($arguments['recipientid'] ?? 0),
                isset($arguments['issuerroleid']) ? (int)$arguments['issuerroleid'] : null
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
            new definition(
                'wrapper_question_create_category',
                'webservice_mcp',
                'operator',
                'Create a question-bank category in a Moodle context.',
                ['moodle/question:managecategory'],
                [
                    'type' => 'object',
                    'properties' => [
                        'contextid' => ['type' => 'number'],
                        'name' => ['type' => 'string'],
                        'parentcategoryid' => ['type' => 'number'],
                        'info' => ['type' => 'string'],
                        'infoformat' => ['type' => 'number'],
                        'idnumber' => ['type' => 'string'],
                    ],
                    'required' => ['contextid', 'name'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'categoryid' => ['type' => 'number'],
                        'contextid' => ['type' => 'number'],
                        'parentcategoryid' => ['type' => 'number'],
                        'name' => ['type' => 'string'],
                    ],
                ],
            ),
            new definition(
                'wrapper_question_update_category',
                'webservice_mcp',
                'operator',
                'Update a question-bank category, including optional context moves.',
                ['moodle/question:managecategory'],
                [
                    'type' => 'object',
                    'properties' => [
                        'categoryid' => ['type' => 'number'],
                        'name' => ['type' => 'string'],
                        'info' => ['type' => 'string'],
                        'infoformat' => ['type' => 'number'],
                        'parentcategoryid' => ['type' => 'number'],
                        'idnumber' => ['type' => 'string'],
                    ],
                    'required' => ['categoryid', 'name'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'categoryid' => ['type' => 'number'],
                        'contextid' => ['type' => 'number'],
                        'parentcategoryid' => ['type' => 'number'],
                        'name' => ['type' => 'string'],
                    ],
                ],
            ),
            new definition(
                'wrapper_question_delete_category',
                'webservice_mcp',
                'operator',
                'Delete a question-bank category, optionally moving remaining questions first.',
                ['moodle/question:managecategory'],
                [
                    'type' => 'object',
                    'properties' => [
                        'categoryid' => ['type' => 'number'],
                        'movequestionstocategoryid' => ['type' => 'number'],
                    ],
                    'required' => ['categoryid'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'deleted' => ['type' => 'boolean'],
                        'categoryid' => ['type' => 'number'],
                    ],
                ],
            ),
            new definition(
                'wrapper_question_move_questions',
                'webservice_mcp',
                'operator',
                'Move authored questions to another question-bank category.',
                [],
                [
                    'type' => 'object',
                    'properties' => [
                        'questionids' => ['type' => 'array', 'items' => ['type' => 'number']],
                        'targetcategoryid' => ['type' => 'number'],
                    ],
                    'required' => ['questionids', 'targetcategoryid'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'moved' => ['type' => 'boolean'],
                        'targetcategoryid' => ['type' => 'number'],
                    ],
                ],
            ),
            new definition(
                'wrapper_question_delete_questions',
                'webservice_mcp',
                'operator',
                'Delete authored questions by question id.',
                [],
                [
                    'type' => 'object',
                    'properties' => [
                        'questionids' => ['type' => 'array', 'items' => ['type' => 'number']],
                    ],
                    'required' => ['questionids'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'deleted' => ['type' => 'boolean'],
                        'questionids' => ['type' => 'array', 'items' => ['type' => 'number']],
                    ],
                ],
            ),
            new definition(
                'wrapper_question_create_question',
                'webservice_mcp',
                'operator',
                'Create a supported authored question version in a question-bank category.',
                ['moodle/question:add'],
                [
                    'type' => 'object',
                    'properties' => [
                        'categoryid' => ['type' => 'number'],
                        'payload' => [
                            'type' => 'object',
                            'properties' => [
                                'qtype' => ['type' => 'string', 'enum' => ['shortanswer', 'truefalse', 'essay', 'description']],
                                'name' => ['type' => 'string'],
                                'questiontext' => ['type' => 'string'],
                                'generalfeedback' => ['type' => 'string'],
                                'defaultmark' => ['type' => 'number'],
                            ],
                        ],
                    ],
                    'required' => ['categoryid', 'payload'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'questionid' => ['type' => 'number'],
                        'questionbankentryid' => ['type' => 'number'],
                        'version' => ['type' => 'number'],
                        'qtype' => ['type' => 'string'],
                    ],
                ],
            ),
            new definition(
                'wrapper_question_update_question',
                'webservice_mcp',
                'operator',
                'Create a new version of a supported authored question.',
                [],
                [
                    'type' => 'object',
                    'properties' => [
                        'questionid' => ['type' => 'number'],
                        'payload' => ['type' => 'object'],
                    ],
                    'required' => ['questionid', 'payload'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'questionid' => ['type' => 'number'],
                        'previousquestionid' => ['type' => 'number'],
                        'version' => ['type' => 'number'],
                        'qtype' => ['type' => 'string'],
                    ],
                ],
            ),
            new definition(
                'wrapper_question_preview_question',
                'webservice_mcp',
                'operator',
                'Return Moodle’s native preview URL for an authored question.',
                [],
                [
                    'type' => 'object',
                    'properties' => [
                        'questionid' => ['type' => 'number'],
                    ],
                    'required' => ['questionid'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'questionid' => ['type' => 'number'],
                        'previewurl' => ['type' => 'string'],
                    ],
                ],
            ),
            new definition(
                'wrapper_question_import_questions',
                'webservice_mcp',
                'operator',
                'Import questions into a category from supported Moodle formats.',
                ['moodle/question:add'],
                [
                    'type' => 'object',
                    'properties' => [
                        'categoryid' => ['type' => 'number'],
                        'format' => ['type' => 'string', 'enum' => ['gift', 'xml']],
                        'content' => ['type' => 'string'],
                        'catfromfile' => ['type' => 'boolean'],
                        'contextfromfile' => ['type' => 'boolean'],
                    ],
                    'required' => ['categoryid', 'format', 'content'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'boolean'],
                        'format' => ['type' => 'string'],
                        'categoryid' => ['type' => 'number'],
                    ],
                ],
            ),
            new definition(
                'wrapper_gradebook_create_manual_item',
                'webservice_mcp',
                'operator',
                'Create a manual gradebook item using Moodle’s gradebook setup rules.',
                ['moodle/grade:manage'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'payload' => ['type' => 'object'],
                    ],
                    'required' => ['courseid', 'payload'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'itemid' => ['type' => 'number'],
                        'courseid' => ['type' => 'number'],
                        'itemname' => ['type' => 'string'],
                    ],
                ],
            ),
            new definition(
                'wrapper_gradebook_update_manual_item',
                'webservice_mcp',
                'operator',
                'Update a manual gradebook item using Moodle’s gradebook setup rules.',
                ['moodle/grade:manage'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'itemid' => ['type' => 'number'],
                        'payload' => ['type' => 'object'],
                    ],
                    'required' => ['courseid', 'itemid', 'payload'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'itemid' => ['type' => 'number'],
                        'courseid' => ['type' => 'number'],
                        'itemname' => ['type' => 'string'],
                    ],
                ],
            ),
            new definition(
                'wrapper_gradebook_move_item',
                'webservice_mcp',
                'operator',
                'Move a manual gradebook item to another category or position.',
                ['moodle/grade:manage'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'itemid' => ['type' => 'number'],
                        'parentcategoryid' => ['type' => 'number'],
                        'afteritemid' => ['type' => 'number'],
                    ],
                    'required' => ['courseid', 'itemid'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'itemid' => ['type' => 'number'],
                        'parentcategoryid' => ['type' => 'number'],
                        'sortorder' => ['type' => 'number'],
                    ],
                ],
            ),
            new definition(
                'wrapper_gradebook_delete_items',
                'webservice_mcp',
                'operator',
                'Delete manual gradebook items.',
                ['moodle/grade:manage'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'itemids' => ['type' => 'array', 'items' => ['type' => 'number']],
                    ],
                    'required' => ['courseid', 'itemids'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'deleted' => ['type' => 'boolean'],
                        'itemids' => ['type' => 'array', 'items' => ['type' => 'number']],
                    ],
                ],
            ),
            new definition(
                'wrapper_gradebook_update_category',
                'webservice_mcp',
                'operator',
                'Update a gradebook category using Moodle’s grade edit tree.',
                ['moodle/grade:manage'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'categoryid' => ['type' => 'number'],
                        'payload' => ['type' => 'object'],
                    ],
                    'required' => ['courseid', 'categoryid', 'payload'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'categoryid' => ['type' => 'number'],
                        'courseid' => ['type' => 'number'],
                        'name' => ['type' => 'string'],
                    ],
                ],
            ),
            new definition(
                'wrapper_gradebook_move_category',
                'webservice_mcp',
                'operator',
                'Move a gradebook category to another parent or sort position.',
                ['moodle/grade:manage'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'categoryid' => ['type' => 'number'],
                        'parentcategoryid' => ['type' => 'number'],
                        'aftercategoryid' => ['type' => 'number'],
                        'afteritemid' => ['type' => 'number'],
                    ],
                    'required' => ['courseid', 'categoryid'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'categoryid' => ['type' => 'number'],
                        'parentcategoryid' => ['type' => 'number'],
                        'sortorder' => ['type' => 'number'],
                    ],
                ],
            ),
            new definition(
                'wrapper_gradebook_delete_categories',
                'webservice_mcp',
                'operator',
                'Delete gradebook categories.',
                ['moodle/grade:manage'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'categoryids' => ['type' => 'array', 'items' => ['type' => 'number']],
                    ],
                    'required' => ['courseid', 'categoryids'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'deleted' => ['type' => 'boolean'],
                        'categoryids' => ['type' => 'array', 'items' => ['type' => 'number']],
                    ],
                ],
            ),
            new definition(
                'wrapper_badge_create_badge',
                'webservice_mcp',
                'operator',
                'Create a site or course badge through Moodle’s native badge model.',
                ['moodle/badges:createbadge'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'payload' => ['type' => 'object'],
                    ],
                    'required' => ['payload'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'name' => ['type' => 'string'],
                        'status' => ['type' => 'number'],
                    ],
                ],
            ),
            new definition(
                'wrapper_badge_update_badge',
                'webservice_mcp',
                'operator',
                'Update badge details through Moodle’s native badge model.',
                ['moodle/badges:configuredetails'],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'payload' => ['type' => 'object'],
                    ],
                    'required' => ['badgeid', 'payload'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'name' => ['type' => 'string'],
                        'status' => ['type' => 'number'],
                    ],
                ],
            ),
            new definition(
                'wrapper_badge_update_badge_message',
                'webservice_mcp',
                'operator',
                'Update badge award-message settings.',
                ['moodle/badges:configuremessages'],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'payload' => ['type' => 'object'],
                    ],
                    'required' => ['badgeid', 'payload'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'status' => ['type' => 'number'],
                    ],
                ],
            ),
            new definition(
                'wrapper_badge_delete_badges',
                'webservice_mcp',
                'operator',
                'Delete badges through Moodle’s native badge model.',
                ['moodle/badges:deletebadge'],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeids' => ['type' => 'array', 'items' => ['type' => 'number']],
                        'archive' => ['type' => 'boolean'],
                    ],
                    'required' => ['badgeids'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'deleted' => ['type' => 'boolean'],
                        'badgeids' => ['type' => 'array', 'items' => ['type' => 'number']],
                    ],
                ],
            ),
            new definition(
                'wrapper_badge_duplicate_badge',
                'webservice_mcp',
                'operator',
                'Duplicate a badge into a new inactive copy.',
                ['moodle/badges:createbadge', 'moodle/badges:configuredetails'],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                    ],
                    'required' => ['badgeid'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'name' => ['type' => 'string'],
                    ],
                ],
            ),
            new definition(
                'wrapper_badge_add_related_badges',
                'webservice_mcp',
                'operator',
                'Attach related badges to a badge.',
                ['moodle/badges:configuredetails'],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'relatedbadgeids' => ['type' => 'array', 'items' => ['type' => 'number']],
                    ],
                    'required' => ['badgeid', 'relatedbadgeids'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'status' => ['type' => 'boolean'],
                    ],
                ],
            ),
            new definition(
                'wrapper_badge_delete_related_badges',
                'webservice_mcp',
                'operator',
                'Remove related-badge links from a badge.',
                ['moodle/badges:configuredetails'],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'relatedbadgeids' => ['type' => 'array', 'items' => ['type' => 'number']],
                    ],
                    'required' => ['badgeid', 'relatedbadgeids'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'status' => ['type' => 'boolean'],
                    ],
                ],
            ),
            new definition(
                'wrapper_badge_save_alignment',
                'webservice_mcp',
                'operator',
                'Create or update a badge alignment record.',
                ['moodle/badges:configuredetails'],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'alignmentid' => ['type' => 'number'],
                        'payload' => ['type' => 'object'],
                    ],
                    'required' => ['badgeid', 'payload'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'alignmentid' => ['type' => 'number'],
                        'status' => ['type' => 'boolean'],
                    ],
                ],
            ),
            new definition(
                'wrapper_badge_delete_alignments',
                'webservice_mcp',
                'operator',
                'Delete badge alignment records.',
                ['moodle/badges:configuredetails'],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'alignmentids' => ['type' => 'array', 'items' => ['type' => 'number']],
                    ],
                    'required' => ['badgeid', 'alignmentids'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'status' => ['type' => 'boolean'],
                    ],
                ],
            ),
            new definition(
                'wrapper_badge_award_badge',
                'webservice_mcp',
                'operator',
                'Manually award a badge to a user.',
                ['moodle/badges:awardbadge'],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'recipientid' => ['type' => 'number'],
                        'issuerroleid' => ['type' => 'number'],
                    ],
                    'required' => ['badgeid', 'recipientid'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'recipientid' => ['type' => 'number'],
                        'awarded' => ['type' => 'boolean'],
                        'issued' => ['type' => 'boolean'],
                    ],
                ],
            ),
            new definition(
                'wrapper_badge_revoke_badge',
                'webservice_mcp',
                'operator',
                'Manually revoke a badge from a user.',
                ['moodle/badges:revokebadge'],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'recipientid' => ['type' => 'number'],
                        'issuerroleid' => ['type' => 'number'],
                    ],
                    'required' => ['badgeid', 'recipientid'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'recipientid' => ['type' => 'number'],
                        'revoked' => ['type' => 'boolean'],
                    ],
                ],
            ),
        ];
    }
}
