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
use question_bank;
use stdClass;

/**
 * Question authoring, preview, move and delete.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_service {
    /** @var question_form_builder */
    private question_form_builder $formbuilder;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->formbuilder = new question_form_builder();
    }

    /**
     * Move questions into another category.
     *
     * @param array $questionids Question ids.
     * @param int $targetcategoryid Target category id.
     * @return array
     */
    public function move_questions(array $questionids, int $targetcategoryid): array {
        global $DB;
        moodle_lib::load('lib/questionlib.php');
        moodle_lib::load('lib/filelib.php');

        $questionids = arguments::ids($questionids);
        if ($questionids === []) {
            throw new \moodle_exception('invalidparameter');
        }

        // Mirrors qbank_bulkmove: 'add' in the target context, 'move' on every source question.
        $targetcategory = $DB->get_record('question_categories', ['id' => $targetcategoryid], '*', MUST_EXIST);
        $targetcontext = context::instance_by_id((int)$targetcategory->contextid, MUST_EXIST);
        external_api::validate_context($targetcontext);
        \require_capability('moodle/question:add', $targetcontext);

        foreach ($this->questions_with_context($questionids) as $question) {
            external_api::validate_context(context::instance_by_id((int)$question->contextid, MUST_EXIST));
            \question_require_capability_on($question, 'move');
        }

        \question_move_questions_to_category($questionids, $targetcategoryid);

        return [
            'moved' => true,
            'questionids' => $questionids,
            'targetcategoryid' => $targetcategoryid,
        ];
    }

    /**
     * Delete one or more authored questions.
     *
     * @param array $questionids Question ids.
     * @return array
     */
    public function delete_questions(array $questionids): array {
        global $DB;
        moodle_lib::load('lib/questionlib.php');
        moodle_lib::load('lib/filelib.php');

        $questionids = arguments::ids($questionids);
        if ($questionids === []) {
            throw new \moodle_exception('invalidparameter');
        }

        // Check every question before deleting any, so a permission failure leaves the bank untouched.
        $questions = $this->questions_with_context($questionids);
        foreach ($questions as $question) {
            external_api::validate_context(context::instance_by_id((int)$question->contextid, MUST_EXIST));
            \question_require_capability_on($question, 'edit');
        }

        $deleted = [];
        $hidden = [];
        foreach ($questions as $question) {
            \question_delete_question((int)$question->id);
            // Questions still used by an activity are hidden instead of deleted.
            if ($DB->record_exists('question', ['id' => $question->id])) {
                $hidden[] = (int)$question->id;
            } else {
                $deleted[] = (int)$question->id;
            }
        }

        return [
            'deleted' => $hidden === [],
            'questionids' => $questionids,
            'deletedquestionids' => $deleted,
            'hiddenquestionids' => $hidden,
        ];
    }

    /**
     * Load questions with their category context, failing if any id is unknown.
     *
     * @param int[] $questionids Question ids.
     * @return stdClass[]
     */
    private function questions_with_context(array $questionids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($questionids);
        $questions = $DB->get_records_sql(
            "SELECT q.*, qc.contextid
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE q.id {$insql}",
            $params
        );
        if (count($questions) !== count($questionids)) {
            throw arguments::invalid('One or more questions do not exist.');
        }

        return array_values($questions);
    }

    /**
     * Create a new authored question.
     *
     * Supported qtypes are limited to safe, explicitly mapped forms.
     *
     * @param int $categoryid Target category id.
     * @param array $payload Question form payload.
     * @return array
     */
    public function create_question(int $categoryid, array $payload): array {
        moodle_lib::load('lib/questionlib.php');
        moodle_lib::load('lib/filelib.php');
        $category = $this->get_category($categoryid);
        $context = context::instance_by_id((int)$category->contextid, MUST_EXIST);
        external_api::validate_context($context);
        \require_capability('moodle/question:add', $context);

        $qtype = $this->supported_qtype((string)($payload['qtype'] ?? ''));
        $form = $this->formbuilder->build_question_form($qtype, $payload, null, $category);

        $question = new stdClass();
        $question->qtype = $qtype;
        $question->createdby = 0;
        $question->idnumber = $form->idnumber ?? null;
        $question->status = $form->status ?? \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;

        $saved = question_bank::get_qtype($qtype)->save_question($question, $form);

        return $this->question_result((int)$saved->id);
    }

    /**
     * Create a new version of an existing authored question.
     *
     * @param int $questionid Existing question id.
     * @param array $payload Partial form payload.
     * @return array
     */
    public function update_question(int $questionid, array $payload): array {
        moodle_lib::load('lib/questionlib.php');
        moodle_lib::load('lib/filelib.php');
        $question = question_bank::load_question($questionid);
        if (!\question_has_capability_on($question, 'edit')) {
            $context = context::instance_by_id((int)$question->contextid, MUST_EXIST);
            throw new \required_capability_exception($context, 'moodle/question:editall', 'nopermissions', '');
        }

        $existing = question_bank::load_question_data($questionid);
        $category = $this->get_category((int)$existing->category);
        $context = context::instance_by_id((int)$category->contextid, MUST_EXIST);
        external_api::validate_context($context);

        if (isset($payload['categoryid']) && (int)$payload['categoryid'] !== (int)$existing->category) {
            throw new \moodle_exception('invalidparameter');
        }

        $form = $this->formbuilder->build_question_form((string)$existing->qtype, $payload, $existing, $category);
        $saved = question_bank::get_qtype($existing->qtype)->save_question($existing, $form);
        $result = $this->question_result((int)$saved->id);
        $result['previousquestionid'] = $questionid;

        return $result;
    }

    /**
     * Return a Moodle preview URL for an authored question.
     *
     * @param int $questionid Question id.
     * @return array
     */
    public function preview_question(int $questionid): array {
        moodle_lib::load('lib/questionlib.php');
        moodle_lib::load('lib/filelib.php');
        if (!class_exists('\qbank_previewquestion\helper')) {
            throw new \moodle_exception('invalidparameter');
        }

        $question = question_bank::load_question($questionid);
        if (!\question_has_capability_on($question, 'use')) {
            $context = context::instance_by_id((int)$question->contextid, MUST_EXIST);
            throw new \required_capability_exception($context, 'moodle/question:useall', 'nopermissions', '');
        }

        $context = context::instance_by_id((int)$question->contextid, MUST_EXIST);
        external_api::validate_context($context);
        $url = \qbank_previewquestion\helper::question_preview_url($questionid, null, null, null, null, $context);

        return [
            'questionid' => $questionid,
            'previewurl' => $url->out(false),
        ];
    }

    /**
     * Normalize and validate the supported qtype set.
     *
     * @param string $qtype Requested qtype.
     * @return string
     */
    private function supported_qtype(string $qtype): string {
        $qtype = strtolower(trim($qtype));
        if (!in_array($qtype, ['shortanswer', 'truefalse', 'essay', 'description'], true)) {
            throw new \moodle_exception('invalidparameter');
        }

        return $qtype;
    }

    /**
     * Return normalized question metadata.
     *
     * @param int $questionid Question id.
     * @return array
     */
    private function question_result(int $questionid): array {
        global $DB;
        moodle_lib::load('lib/questionlib.php');
        moodle_lib::load('lib/filelib.php');

        $question = question_bank::load_question_data($questionid);
        $entry = \get_question_bank_entry($questionid);
        $version = $DB->get_record('question_versions', ['questionid' => $questionid], '*', MUST_EXIST);

        return [
            'questionid' => $questionid,
            'questionbankentryid' => (int)$entry->id,
            'versionid' => (int)$version->id,
            'version' => (int)$version->version,
            'status' => (string)$version->status,
            'qtype' => (string)$question->qtype,
            'categoryid' => (int)$entry->questioncategoryid,
            'contextid' => (int)$question->contextid,
            'name' => (string)$question->name,
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
}
