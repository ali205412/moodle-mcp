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
 * Question-bank category management (create, update, delete).
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_category_service {
    /**
     * Create a question category in the requested context.
     *
     * @param int $contextid Target context id.
     * @param string $name Category name.
     * @param int|null $parentcategoryid Optional parent category id.
     * @param string $info Optional description.
     * @param int $infoformat Description format.
     * @param string|null $idnumber Optional idnumber.
     * @return array
     */
    public function create_category(
        int $contextid,
        string $name,
        ?int $parentcategoryid = null,
        string $info = '',
        int $infoformat = FORMAT_HTML,
        ?string $idnumber = null
    ): array {
        global $DB;
        require_once($this->dirroot() . '/lib/questionlib.php');
        require_once($this->dirroot() . '/lib/filelib.php');

        $context = context::instance_by_id($contextid, MUST_EXIST);
        external_api::validate_context($context);
        \require_capability('moodle/question:managecategory', $context);

        if ($name === '') {
            throw new \moodle_exception('categorynamecantbeblank', 'question');
        }

        $parentcategoryid ??= (int)\question_get_top_category($contextid, true)->id;
        $parentcontextid = (int)$DB->get_field('question_categories', 'contextid', ['id' => $parentcategoryid], MUST_EXIST);
        if ($parentcontextid !== $contextid) {
            throw new \moodle_exception(
                'cannotinsertquestioncatecontext',
                'question',
                '',
                ['cat' => $name, 'ctx' => $contextid],
            );
        }

        $idnumber = arguments::trimmed_or_null($idnumber);
        if ($idnumber !== null && $DB->record_exists('question_categories', ['idnumber' => $idnumber, 'contextid' => $contextid])) {
            throw new \moodle_exception('idnumbertaken', 'error');
        }

        $category = (object)[
            'parent' => $parentcategoryid,
            'contextid' => $contextid,
            'name' => $name,
            'info' => $info,
            'infoformat' => $infoformat,
            'sortorder' => $this->next_category_sortorder($parentcategoryid),
            'stamp' => \make_unique_id_code(),
            'idnumber' => $idnumber,
        ];
        $categoryid = (int)$DB->insert_record('question_categories', $category);

        $event = \core\event\question_category_created::create_from_question_category_instance((object)[
            'id' => $categoryid,
            'contextid' => $contextid,
        ]);
        $event->trigger();

        return $this->category_result($categoryid);
    }

    /**
     * Update a question category.
     *
     * @param int $categoryid Category id.
     * @param string $name New category name.
     * @param string $info New description.
     * @param int $infoformat New description format.
     * @param int|null $parentcategoryid Optional new parent.
     * @param string|null $idnumber Optional idnumber.
     * @return array
     */
    public function update_category(
        int $categoryid,
        string $name,
        string $info = '',
        int $infoformat = FORMAT_HTML,
        ?int $parentcategoryid = null,
        ?string $idnumber = null
    ): array {
        global $DB;
        require_once($this->dirroot() . '/lib/questionlib.php');
        require_once($this->dirroot() . '/lib/filelib.php');

        if ($name === '') {
            throw new \moodle_exception('categorynamecantbeblank', 'question');
        }

        $oldcategory = $DB->get_record('question_categories', ['id' => $categoryid], '*', MUST_EXIST);
        $fromcontext = context::instance_by_id((int)$oldcategory->contextid, MUST_EXIST);
        external_api::validate_context($fromcontext);
        \require_capability('moodle/question:managecategory', $fromcontext);

        $lastcategoryincontext = $this->is_only_child_of_top_category_in_context($categoryid);
        $targetcontextid = (int)$oldcategory->contextid;
        $targetparentid = (int)$oldcategory->parent;

        if ($parentcategoryid !== null && !$lastcategoryincontext) {
            $this->require_not_descendant($categoryid, $parentcategoryid);
            $targetparentid = $parentcategoryid;
            $targetcontextid = (int)$DB->get_field('question_categories', 'contextid', ['id' => $parentcategoryid], MUST_EXIST);
        }

        $newstamprequired = false;
        if ((int)$oldcategory->contextid !== $targetcontextid) {
            $targetcontext = context::instance_by_id($targetcontextid, MUST_EXIST);
            external_api::validate_context($targetcontext);
            \require_capability('moodle/question:managecategory', $targetcontext);
            if ($DB->record_exists('question_categories', ['contextid' => $targetcontextid, 'stamp' => $oldcategory->stamp])) {
                $newstamprequired = true;
            }
        }

        $idnumber = arguments::trimmed_or_null($idnumber);
        if (
            $idnumber !== null &&
                $DB->record_exists_select(
                    'question_categories',
                    'idnumber = ? AND contextid = ? AND id <> ?',
                    [$idnumber, $targetcontextid, $categoryid]
                )
        ) {
            throw new \moodle_exception('idnumbertaken', 'error');
        }

        $category = (object)[
            'id' => $categoryid,
            'name' => $name,
            'info' => $info,
            'infoformat' => $infoformat,
            'parent' => $targetparentid,
            'contextid' => $targetcontextid,
            'idnumber' => $idnumber,
        ];
        if ($newstamprequired) {
            $category->stamp = \make_unique_id_code();
        }
        $DB->update_record('question_categories', $category);

        \move_question_set_references($categoryid, $categoryid, (int)$oldcategory->contextid, $targetcontextid);
        if ((int)$oldcategory->contextid !== $targetcontextid) {
            \question_move_category_to_context($categoryid, (int)$oldcategory->contextid, $targetcontextid);
        }

        $event = \core\event\question_category_updated::create_from_question_category_instance((object)[
            'id' => $categoryid,
            'contextid' => $targetcontextid,
        ]);
        $event->trigger();

        return $this->category_result($categoryid);
    }

    /**
     * Delete a category, optionally moving questions first.
     *
     * @param int $categoryid Category id.
     * @param int|null $movequestionstocategoryid Optional target category for existing questions.
     * @return array
     */
    public function delete_category(int $categoryid, ?int $movequestionstocategoryid = null): array {
        global $DB;
        require_once($this->dirroot() . '/lib/questionlib.php');
        require_once($this->dirroot() . '/lib/filelib.php');

        $this->require_can_delete_category($categoryid);
        $category = $DB->get_record('question_categories', ['id' => $categoryid], '*', MUST_EXIST);

        if (class_exists('\qbank_managecategories\helper')) {
            \qbank_managecategories\helper::question_remove_stale_questions_from_category($categoryid);
        }

        $questionids = $this->get_real_question_ids_in_category($categoryid);
        if ($questionids !== []) {
            if ($movequestionstocategoryid === null) {
                throw new \moodle_exception('cannotdeletecate', 'question');
            }
            (new question_service())->move_questions($questionids, $movequestionstocategoryid);
        }

        $transaction = $DB->start_delegated_transaction();
        $DB->set_field('question_categories', 'parent', $category->parent, ['parent' => $category->id]);
        $DB->delete_records('question_categories', ['id' => $category->id]);
        $event = \core\event\question_category_deleted::create_from_question_category_instance($category);
        $event->add_record_snapshot('question_categories', $category);
        $event->trigger();
        $transaction->allow_commit();

        return [
            'deleted' => true,
            'categoryid' => $categoryid,
            'movedquestionids' => array_values(array_map('intval', $questionids)),
        ];
    }

    /**
     * Return normalized category metadata.
     *
     * @param int $categoryid Category id.
     * @return array
     */
    private function category_result(int $categoryid): array {
        $category = $this->get_category($categoryid);

        return [
            'categoryid' => (int)$category->id,
            'contextid' => (int)$category->contextid,
            'parentcategoryid' => (int)$category->parent,
            'name' => (string)$category->name,
            'idnumber' => $category->idnumber ?? null,
        ];
    }

    /**
     * Fetch a category record.
     *
     * @param int $categoryid Category id.
     * @return stdClass
     */
    private function get_category(int $categoryid): stdClass {
        global $DB;
        return $DB->get_record('question_categories', ['id' => $categoryid], '*', MUST_EXIST);
    }

    /**
     * Compute the next sibling sortorder.
     *
     * @param int $parentcategoryid Parent category id.
     * @return int
     */
    private function next_category_sortorder(int $parentcategoryid): int {
        global $DB;
        $maxsort = $DB->get_field_sql(
            'SELECT MAX(sortorder) FROM {question_categories} WHERE parent = ?',
            [$parentcategoryid]
        );

        return ((int)$maxsort) + 1;
    }

    /**
     * Return the real question ids in a category.
     *
     * @param int $categoryid Category id.
     * @return array
     */
    private function get_real_question_ids_in_category(int $categoryid): array {
        global $DB;

        $questionids = $DB->get_records_sql(
            "SELECT q.id
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              WHERE qbe.questioncategoryid = :categoryid
                AND (q.parent = 0 OR q.parent = q.id)",
            ['categoryid' => $categoryid]
        );

        return array_values(array_map('intval', array_keys($questionids)));
    }

    /**
     * Ensure the category can be deleted safely.
     *
     * @param int $categoryid Category id.
     * @return void
     */
    private function require_can_delete_category(int $categoryid): void {
        global $DB;

        if ($this->is_top_category($categoryid)) {
            throw new \moodle_exception('cannotdeletetopcat', 'question');
        }
        if ($this->is_only_child_of_top_category_in_context($categoryid)) {
            throw new \moodle_exception('cannotdeletecate', 'question');
        }

        $contextid = (int)$DB->get_field('question_categories', 'contextid', ['id' => $categoryid], MUST_EXIST);
        $context = context::instance_by_id($contextid, MUST_EXIST);
        external_api::validate_context($context);
        \require_capability('moodle/question:managecategory', $context);
    }

    /**
     * Refuse a new parent that is the category itself or one of its descendants, which would create a cycle.
     *
     * @param int $categoryid Category being moved.
     * @param int $parentid Proposed parent.
     * @return void
     */
    private function require_not_descendant(int $categoryid, int $parentid): void {
        global $DB;

        $seen = [];
        $id = $parentid;
        while ($id > 0 && !isset($seen[$id])) {
            if ($id === $categoryid) {
                throw arguments::invalid('A question category cannot be moved under itself or a subcategory.');
            }
            $seen[$id] = true;
            $id = (int)$DB->get_field('question_categories', 'parent', ['id' => $id]);
        }
    }

    /**
     * Determine whether a category is a top category.
     *
     * @param int $categoryid Category id.
     * @return bool
     */
    private function is_top_category(int $categoryid): bool {
        global $DB;
        return 0 === (int)$DB->get_field('question_categories', 'parent', ['id' => $categoryid], MUST_EXIST);
    }

    /**
     * Determine whether a category is the only child of the top category.
     *
     * @param int $categoryid Category id.
     * @return bool
     */
    private function is_only_child_of_top_category_in_context(int $categoryid): bool {
        global $DB;

        return 1 === (int)$DB->count_records_sql(
            "SELECT count(*)
               FROM {question_categories} c
               JOIN {question_categories} p ON c.parent = p.id
               JOIN {question_categories} s ON s.parent = c.parent
              WHERE c.id = ? AND p.parent = 0",
            [$categoryid]
        );
    }

    /**
     * Return Moodle dirroot.
     *
     * @return string
     */
    private function dirroot(): string {
        global $CFG;
        return $CFG->dirroot;
    }
}
