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
use core_external\external_api;
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

    /** @var memory_service */
    private memory_service $memoryservice;

    /** @var activity_service */
    private activity_service $activityservice;

    /** @var discovery_service */
    private discovery_service $discoveryservice;

    /**
     * Constructor.
     *
     * @param definition[] $definitions Optional definitions.
     * @param bool $includedefaults Whether to include built-in wrapper definitions.
     * @param course_authoring_service|null $courseauthoringservice Optional authoring service.
     * @param question_bank_service|null $questionbankservice Optional question-bank service.
     * @param gradebook_service|null $gradebookservice Optional gradebook service.
     * @param badge_service|null $badgeservice Optional badge service.
     * @param memory_service|null $memoryservice Optional memory service.
     * @param activity_service|null $activityservice Optional activity service.
     * @param discovery_service|null $discoveryservice Optional discovery service.
     */
    public function __construct(
        array $definitions = [],
        bool $includedefaults = true,
        ?course_authoring_service $courseauthoringservice = null,
        ?question_bank_service $questionbankservice = null,
        ?gradebook_service $gradebookservice = null,
        ?badge_service $badgeservice = null,
        ?memory_service $memoryservice = null,
        ?activity_service $activityservice = null,
        ?discovery_service $discoveryservice = null
    ) {
        $this->definitions = $includedefaults ? array_merge(builtin_definitions::all(), $definitions) : $definitions;
        $this->courseauthoringservice = $courseauthoringservice ?? new course_authoring_service();
        $this->questionbankservice = $questionbankservice ?? new question_bank_service();
        $this->gradebookservice = $gradebookservice ?? new gradebook_service();
        $this->badgeservice = $badgeservice ?? new badge_service();
        $this->memoryservice = $memoryservice ?? new memory_service();
        $this->activityservice = $activityservice ?? new activity_service();
        $this->discoveryservice = $discoveryservice ?? new discovery_service();
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
     * Determine whether a wrapper call mutates Moodle state.
     *
     * Read-only wrappers are those whose definition declares readOnlyHint; wrapper_moodle_api_execute is
     * mutating unless the target external function is declared type "read".
     *
     * @param string $name Tool name.
     * @param array $arguments Tool arguments.
     * @return bool
     */
    public function is_mutating(string $name, array $arguments = []): bool {
        if ($name === 'wrapper_moodle_api_execute') {
            return discovery_service::function_type(arguments::text($arguments, 'functionname')) !== 'read';
        }

        $definition = $this->find($name);
        return $definition === null || !$definition->is_read_only();
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
     * Failures are thrown as exceptions (usually moodle_exception) for the transport to report as tool errors.
     *
     * @param string $name Tool name.
     * @param array $arguments Tool arguments.
     * @param context $restrictedcontext Current restricted context.
     * @param stdClass|null $user Current user.
     * @param int|null $serviceid Connector external service id scoping wrapper_moodle_api_* tools.
     * @return array
     */
    public function execute(
        string $name,
        array $arguments,
        context $restrictedcontext,
        ?stdClass $user = null,
        ?int $serviceid = null
    ): array {
        $definition = $this->find($name);
        if ($definition === null || !$definition->can_discover($restrictedcontext, $user)) {
            throw arguments::invalid(
                'Unknown wrapper tool "' . $name
                . '", or it is not available to you in this context (it needs capabilities you lack here).'
            );
        }

        // Every wrapper validates its target context against this restriction, as native web service calls do.
        external_api::set_context_restriction($restrictedcontext);

        $depth = self::transaction_depth();
        try {
            return $this->dispatch($name, $arguments, $restrictedcontext, $user, $serviceid);
        } catch (\Throwable $exception) {
            // A wrapper that failed mid-transaction must not leave the connection in a half-open transaction. Only
            // roll back when the wrapper opened one itself; an outer transaction (none in a web service request)
            // belongs to the caller.
            if (self::transaction_depth() > $depth) {
                \abort_all_db_transactions();
            }
            throw $exception;
        }
    }

    /**
     * Number of open database transactions (moodle_database keeps the stack protected).
     *
     * @return int
     */
    private static function transaction_depth(): int {
        global $DB;

        // phpcs:ignore Squiz.Scope.StaticThisUsage.Found -- $this is $DB: the closure is bound to it by call().
        return (fn(): int => count($this->transactions))->call($DB);
    }

    /**
     * Route a wrapper call to its service.
     *
     * @param string $name Tool name.
     * @param array $a Tool arguments.
     * @param context $restrictedcontext Current restricted context.
     * @param stdClass|null $user Current user.
     * @param int|null $serviceid Connector external service id.
     * @return array
     */
    private function dispatch(string $name, array $a, context $restrictedcontext, ?stdClass $user, ?int $serviceid): array {
        return match ($name) {
            'wrapper_course_add_section_after' => $this->courseauthoringservice->add_section_after(
                arguments::integer($a, 'courseid'),
                arguments::optional_integer($a, 'targetsectionid')
            ),
            'wrapper_course_set_section_visibility' => $this->courseauthoringservice->set_section_visibility(
                arguments::integer($a, 'courseid'),
                arguments::values($a, 'sectionids'),
                arguments::flag($a, 'visible')
            ),
            'wrapper_course_delete_sections' => $this->courseauthoringservice->delete_sections(
                arguments::integer($a, 'courseid'),
                arguments::values($a, 'sectionids')
            ),
            'wrapper_course_create_missing_sections' => $this->courseauthoringservice->create_missing_sections(
                arguments::integer($a, 'courseid'),
                arguments::values($a, 'sectionnums')
            ),
            'wrapper_course_move_module' => $this->courseauthoringservice->move_modules(
                arguments::integer($a, 'courseid'),
                arguments::values($a, 'cmids'),
                arguments::optional_integer($a, 'targetsectionid'),
                arguments::optional_integer($a, 'targetcmid')
            ),
            'wrapper_course_move_section_after' => $this->courseauthoringservice->move_sections_after(
                arguments::integer($a, 'courseid'),
                arguments::values($a, 'sectionids'),
                arguments::integer($a, 'targetsectionid')
            ),
            'wrapper_course_set_module_visibility' => $this->courseauthoringservice->set_module_visibility(
                arguments::integer($a, 'courseid'),
                arguments::values($a, 'cmids'),
                arguments::text($a, 'visibility')
            ),
            'wrapper_course_duplicate_modules' => $this->courseauthoringservice->duplicate_modules(
                arguments::integer($a, 'courseid'),
                arguments::values($a, 'cmids'),
                arguments::optional_integer($a, 'targetsectionid'),
                arguments::optional_integer($a, 'targetcmid')
            ),
            'wrapper_course_delete_modules' => $this->courseauthoringservice->delete_modules(
                arguments::integer($a, 'courseid'),
                arguments::values($a, 'cmids')
            ),
            'wrapper_question_create_category' => $this->questionbankservice->create_category(
                arguments::integer($a, 'contextid'),
                arguments::text($a, 'name'),
                arguments::optional_integer($a, 'parentcategoryid'),
                arguments::text($a, 'info'),
                arguments::integer($a, 'infoformat', (int)FORMAT_HTML),
                arguments::optional_text($a, 'idnumber')
            ),
            'wrapper_question_update_category' => $this->questionbankservice->update_category(
                arguments::integer($a, 'categoryid'),
                arguments::text($a, 'name'),
                arguments::text($a, 'info'),
                arguments::integer($a, 'infoformat', (int)FORMAT_HTML),
                arguments::optional_integer($a, 'parentcategoryid'),
                arguments::optional_text($a, 'idnumber')
            ),
            'wrapper_question_delete_category' => $this->questionbankservice->delete_category(
                arguments::integer($a, 'categoryid'),
                arguments::optional_integer($a, 'movequestionstocategoryid')
            ),
            'wrapper_question_move_questions' => $this->questionbankservice->move_questions(
                arguments::values($a, 'questionids'),
                arguments::integer($a, 'targetcategoryid')
            ),
            'wrapper_question_delete_questions' => $this->questionbankservice->delete_questions(
                arguments::values($a, 'questionids')
            ),
            'wrapper_question_create_question' => $this->questionbankservice->create_question(
                arguments::integer($a, 'categoryid'),
                arguments::values($a, 'payload')
            ),
            'wrapper_question_update_question' => $this->questionbankservice->update_question(
                arguments::integer($a, 'questionid'),
                arguments::values($a, 'payload')
            ),
            'wrapper_question_preview_question' => $this->questionbankservice->preview_question(
                arguments::integer($a, 'questionid')
            ),
            'wrapper_question_import_questions' => $this->questionbankservice->import_questions(
                arguments::integer($a, 'categoryid'),
                arguments::text($a, 'format'),
                arguments::text($a, 'content'),
                arguments::flag($a, 'catfromfile'),
                arguments::flag($a, 'contextfromfile')
            ),
            'wrapper_gradebook_create_manual_item' => $this->gradebookservice->create_manual_item(
                arguments::integer($a, 'courseid'),
                arguments::values($a, 'payload')
            ),
            'wrapper_gradebook_update_manual_item' => $this->gradebookservice->update_manual_item(
                arguments::integer($a, 'courseid'),
                arguments::integer($a, 'itemid'),
                arguments::values($a, 'payload')
            ),
            'wrapper_gradebook_move_item' => $this->gradebookservice->move_item(
                arguments::integer($a, 'courseid'),
                arguments::integer($a, 'itemid'),
                arguments::optional_integer($a, 'parentcategoryid'),
                arguments::optional_integer($a, 'afteritemid')
            ),
            'wrapper_gradebook_delete_items' => $this->gradebookservice->delete_items(
                arguments::integer($a, 'courseid'),
                arguments::values($a, 'itemids')
            ),
            'wrapper_gradebook_update_category' => $this->gradebookservice->update_category(
                arguments::integer($a, 'courseid'),
                arguments::integer($a, 'categoryid'),
                arguments::values($a, 'payload')
            ),
            'wrapper_gradebook_move_category' => $this->gradebookservice->move_category(
                arguments::integer($a, 'courseid'),
                arguments::integer($a, 'categoryid'),
                arguments::optional_integer($a, 'parentcategoryid'),
                arguments::optional_integer($a, 'aftercategoryid'),
                arguments::optional_integer($a, 'afteritemid')
            ),
            'wrapper_gradebook_delete_categories' => $this->gradebookservice->delete_categories(
                arguments::integer($a, 'courseid'),
                arguments::values($a, 'categoryids')
            ),
            'wrapper_badge_create_badge' => $this->badgeservice->create_badge(
                arguments::values($a, 'payload'),
                arguments::optional_integer($a, 'courseid')
            ),
            'wrapper_badge_update_badge' => $this->badgeservice->update_badge(
                arguments::integer($a, 'badgeid'),
                arguments::values($a, 'payload')
            ),
            'wrapper_badge_update_badge_message' => $this->badgeservice->update_badge_message(
                arguments::integer($a, 'badgeid'),
                arguments::values($a, 'payload')
            ),
            'wrapper_badge_delete_badges' => $this->badgeservice->delete_badges(
                arguments::values($a, 'badgeids'),
                arguments::flag($a, 'archive', true)
            ),
            'wrapper_badge_duplicate_badge' => $this->badgeservice->duplicate_badge(
                arguments::integer($a, 'badgeid')
            ),
            'wrapper_badge_add_related_badges' => $this->badgeservice->add_related_badges(
                arguments::integer($a, 'badgeid'),
                arguments::values($a, 'relatedbadgeids')
            ),
            'wrapper_badge_delete_related_badges' => $this->badgeservice->delete_related_badges(
                arguments::integer($a, 'badgeid'),
                arguments::values($a, 'relatedbadgeids')
            ),
            'wrapper_badge_save_alignment' => $this->badgeservice->save_alignment(
                arguments::integer($a, 'badgeid'),
                arguments::values($a, 'payload'),
                arguments::optional_integer($a, 'alignmentid')
            ),
            'wrapper_badge_delete_alignments' => $this->badgeservice->delete_alignments(
                arguments::integer($a, 'badgeid'),
                arguments::values($a, 'alignmentids')
            ),
            'wrapper_badge_award_badge' => $this->badgeservice->award_badge(
                arguments::integer($a, 'badgeid'),
                arguments::integer($a, 'recipientid'),
                arguments::optional_integer($a, 'issuerroleid')
            ),
            'wrapper_badge_revoke_badge' => $this->badgeservice->revoke_badge(
                arguments::integer($a, 'badgeid'),
                arguments::integer($a, 'recipientid'),
                arguments::optional_integer($a, 'issuerroleid')
            ),
            'wrapper_memory_write' => $this->memoryservice->write_memory(
                arguments::text($a, 'content')
            ),
            'wrapper_memory_read' => isset($a['id'])
                ? ['memories' => [$this->memoryservice->read_memory_by_id(arguments::integer($a, 'id'))], 'total' => 1]
                : $this->memoryservice->read_memories(
                    arguments::integer($a, 'limit', memory_service::DEFAULT_LIMIT),
                    arguments::integer($a, 'offset')
                ),
            'wrapper_memory_update' => $this->memoryservice->update_memory(
                arguments::integer($a, 'id'),
                arguments::text($a, 'content')
            ),
            'wrapper_memory_delete' => $this->memoryservice->delete_memory(
                arguments::integer($a, 'id')
            ),
            'wrapper_course_add_module' => $this->activityservice->add_module(
                arguments::integer($a, 'courseid'),
                arguments::text($a, 'modulename'),
                arguments::text($a, 'name'),
                arguments::values($a, 'options'),
                arguments::integer($a, 'section'),
                arguments::flag($a, 'visible', true),
                arguments::text($a, 'intro'),
                arguments::integer($a, 'introformat', (int)FORMAT_HTML)
            ),
            'wrapper_module_read_data' => $this->activityservice->read_module_data(
                arguments::integer($a, 'cmid')
            ),
            'wrapper_moodle_api_search' => $this->discoveryservice->search_api(
                arguments::text($a, 'query'),
                arguments::integer($a, 'limit', discovery_service::DEFAULT_SEARCH_LIMIT),
                $restrictedcontext,
                $user,
                $serviceid,
                arguments::text($a, 'component'),
                arguments::text($a, 'type')
            ),
            'wrapper_moodle_api_describe' => $this->discoveryservice->describe_api(
                array_merge(
                    arguments::values($a, 'functionnames'),
                    isset($a['functionname']) ? [arguments::text($a, 'functionname')] : []
                ),
                $restrictedcontext,
                $user,
                $serviceid
            ),
            'wrapper_moodle_api_execute' => $this->discoveryservice->execute_api(
                arguments::text($a, 'functionname'),
                arguments::values($a, 'params'),
                $restrictedcontext,
                $user,
                $serviceid
            ),
            default => throw arguments::invalid('Unknown wrapper tool "' . $name . '".'),
        };
    }
}
