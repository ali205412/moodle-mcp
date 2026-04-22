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
use moodle_url;
use question_bank;
use stdClass;

/**
 * Wrapper implementations for question-bank parity gaps.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_bank_service {
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

        $idnumber = $this->normalize_optional_string($idnumber);
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
            $targetparentid = $parentcategoryid;
            $targetcontextid = (int)$DB->get_field('question_categories', 'contextid', ['id' => $parentcategoryid], MUST_EXIST);
        }

        $newstamprequired = false;
        if ((int)$oldcategory->contextid !== $targetcontextid) {
            $targetcontext = context::instance_by_id($targetcontextid, MUST_EXIST);
            \require_capability('moodle/question:managecategory', $targetcontext);
            if ($DB->record_exists('question_categories', ['contextid' => $targetcontextid, 'stamp' => $oldcategory->stamp])) {
                $newstamprequired = true;
            }
        }

        $idnumber = $this->normalize_optional_string($idnumber);
        if ($idnumber !== null &&
                $DB->record_exists_select(
                    'question_categories',
                    'idnumber = ? AND contextid = ? AND id <> ?',
                    [$idnumber, $targetcontextid, $categoryid]
                )) {
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
            $this->move_questions($questionids, $movequestionstocategoryid);
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
     * Move questions into another category.
     *
     * @param array $questionids Question ids.
     * @param int $targetcategoryid Target category id.
     * @return array
     */
    public function move_questions(array $questionids, int $targetcategoryid): array {
        global $DB;
        require_once($this->dirroot() . '/lib/questionlib.php');

        $questionids = $this->normalize_ids($questionids);
        if ($questionids === []) {
            throw new \moodle_exception('invalidparameter');
        }

        $targetcategory = $DB->get_record('question_categories', ['id' => $targetcategoryid], '*', MUST_EXIST);
        $targetcontext = context::instance_by_id((int)$targetcategory->contextid, MUST_EXIST);
        external_api::validate_context($targetcontext);
        \require_capability('moodle/question:add', $targetcontext);

        foreach ($questionids as $questionid) {
            $question = question_bank::load_question($questionid);
            if (!\question_has_capability_on($question, 'edit')) {
                throw new \required_capability_exception($targetcontext, 'moodle/question:editall', 'nopermissions', '');
            }
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
        require_once($this->dirroot() . '/lib/questionlib.php');

        $questionids = $this->normalize_ids($questionids);
        if ($questionids === []) {
            throw new \moodle_exception('invalidparameter');
        }

        foreach ($questionids as $questionid) {
            $question = question_bank::load_question($questionid);
            if (!\question_has_capability_on($question, 'edit')) {
                $context = context::instance_by_id((int)$question->contextid, MUST_EXIST);
                throw new \required_capability_exception($context, 'moodle/question:editall', 'nopermissions', '');
            }
            \question_delete_question($questionid);
        }

        return [
            'deleted' => true,
            'questionids' => $questionids,
        ];
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
        $category = $this->get_category($categoryid);
        $context = context::instance_by_id((int)$category->contextid, MUST_EXIST);
        external_api::validate_context($context);
        \require_capability('moodle/question:add', $context);

        $qtype = $this->supported_qtype((string)($payload['qtype'] ?? ''));
        $form = $this->build_question_form($qtype, $payload, null, $category);

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

        $form = $this->build_question_form((string)$existing->qtype, $payload, $existing, $category);
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
     * Import questions from standard Moodle text formats.
     *
     * @param int $categoryid Target category id.
     * @param string $format Supported format shortname.
     * @param string $content Raw import content.
     * @param bool $catfromfile Whether categories from the file are honoured.
     * @param bool $contextfromfile Whether contexts from the file are honoured.
     * @return array
     */
    public function import_questions(
        int $categoryid,
        string $format,
        string $content,
        bool $catfromfile = false,
        bool $contextfromfile = false
    ): array {
        global $SITE;
        require_once($this->dirroot() . '/question/format.php');

        $format = strtolower(trim($format));
        if (!in_array($format, ['gift', 'xml'], true)) {
            throw new \moodle_exception('invalidparameter');
        }

        $formatfile = $this->dirroot() . '/question/format/' . $format . '/format.php';
        if (!is_readable($formatfile)) {
            throw new \moodle_exception('invalidparameter');
        }
        require_once($formatfile);

        $classname = 'qformat_' . $format;
        if (!class_exists($classname)) {
            throw new \moodle_exception('invalidparameter');
        }

        $category = $this->get_category($categoryid);
        $context = context::instance_by_id((int)$category->contextid, MUST_EXIST);
        external_api::validate_context($context);
        \require_capability('moodle/question:add', $context);

        /** @var \qformat_default $importer */
        $importer = new $classname();
        if (!$importer->provide_import()) {
            throw new \moodle_exception('invalidparameter');
        }

        $tmpdir = \make_temp_directory('webservice_mcp/question-imports');
        $tmpfile = $tmpdir . '/import-' . \random_string(12) . '.' . $format;
        file_put_contents($tmpfile, $content);

        $coursecontext = $context->get_course_context(false);
        $course = $coursecontext ? \get_course($coursecontext->instanceid) : $SITE;

        $importer->setCategory($category);
        $importer->setCourse($course);
        $importer->setContexts([$context]);
        $importer->setFilename($tmpfile);
        $importer->setRealfilename('mcp-import.' . $format);
        $importer->setCatfromfile($catfromfile);
        $importer->setContextfromfile($contextfromfile);
        $importer->setStoponerror(true);
        $importer->set_display_progress(false);

        $output = '';
        $success = false;
        try {
            ob_start();
            $success = $importer->importpreprocess() && $importer->importprocess();
            $output = trim((string)ob_get_clean());
        } catch (\Throwable $exception) {
            if (ob_get_level() > 0) {
                $output = trim((string)ob_get_clean());
            }
            @unlink($tmpfile);
            throw $exception;
        }

        @unlink($tmpfile);

        return [
            'status' => $success,
            'format' => $format,
            'categoryid' => $categoryid,
            'questionids' => array_values(array_map('intval', $importer->questionids ?? [])),
            'output' => $output,
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
     * Build a form-like payload suitable for question_type::save_question().
     *
     * @param string $qtype Supported qtype.
     * @param array $payload User payload.
     * @param stdClass|null $existing Existing question data when editing.
     * @param stdClass $category Target category record.
     * @return stdClass
     */
    private function build_question_form(string $qtype, array $payload, ?stdClass $existing, stdClass $category): stdClass {
        $form = $existing ? $this->question_form_from_existing($existing) : $this->default_question_form($qtype);

        $form->category = $category->id . ',' . $category->contextid;
        if (array_key_exists('name', $payload)) {
            $form->name = (string)$payload['name'];
        }
        if (array_key_exists('idnumber', $payload)) {
            $form->idnumber = $this->normalize_optional_string((string)$payload['idnumber']);
        }
        if (array_key_exists('questiontext', $payload)) {
            $form->questiontext = [
                'text' => (string)$payload['questiontext'],
                'format' => (int)($payload['questiontextformat'] ?? ($form->questiontext['format'] ?? FORMAT_HTML)),
            ];
        }
        if (array_key_exists('generalfeedback', $payload)) {
            $form->generalfeedback = [
                'text' => (string)$payload['generalfeedback'],
                'format' => (int)($payload['generalfeedbackformat'] ?? ($form->generalfeedback['format'] ?? FORMAT_HTML)),
            ];
        }
        if (array_key_exists('defaultmark', $payload)) {
            $form->defaultmark = (float)$payload['defaultmark'];
        }
        if (array_key_exists('status', $payload)) {
            $form->status = (string)$payload['status'];
        }
        if (array_key_exists('penalty', $payload)) {
            $form->penalty = (float)$payload['penalty'];
        }
        if (array_key_exists('hints', $payload)) {
            $form->hint = $this->normalize_hints($payload['hints']);
        }

        return match ($qtype) {
            'shortanswer' => $this->apply_shortanswer_payload($form, $payload),
            'truefalse' => $this->apply_truefalse_payload($form, $payload),
            'essay' => $this->apply_essay_payload($form, $payload),
            'description' => $form,
            default => throw new \moodle_exception('invalidparameter'),
        };
    }

    /**
     * Return a sane default form payload for new questions.
     *
     * @param string $qtype Supported qtype.
     * @return stdClass
     */
    private function default_question_form(string $qtype): stdClass {
        $form = (object)[
            'name' => '',
            'questiontext' => ['text' => '', 'format' => FORMAT_HTML],
            'defaultmark' => 1.0,
            'generalfeedback' => ['text' => '', 'format' => FORMAT_HTML],
            'idnumber' => null,
            'status' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
            'hint' => [],
        ];

        return match ($qtype) {
            'shortanswer' => (object)array_merge((array)$form, [
                'usecase' => false,
                'answer' => [],
                'fraction' => [],
                'feedback' => [],
            ]),
            'truefalse' => (object)array_merge((array)$form, [
                'penalty' => 1,
                'correctanswer' => '1',
                'feedbacktrue' => ['text' => '', 'format' => FORMAT_HTML],
                'feedbackfalse' => ['text' => '', 'format' => FORMAT_HTML],
            ]),
            'essay' => (object)array_merge((array)$form, [
                'responseformat' => 'editor',
                'responserequired' => 1,
                'responsefieldlines' => 10,
                'attachments' => 0,
                'attachmentsrequired' => 0,
                'maxbytes' => 0,
                'filetypeslist' => '',
                'graderinfo' => ['text' => '', 'format' => FORMAT_HTML],
                'responsetemplate' => ['text' => '', 'format' => FORMAT_HTML],
            ]),
            'description' => $form,
            default => throw new \moodle_exception('invalidparameter'),
        };
    }

    /**
     * Convert current question data to a form-like object so updates can be partial.
     *
     * @param stdClass $questiondata Current question data.
     * @return stdClass
     */
    private function question_form_from_existing(stdClass $questiondata): stdClass {
        $form = $this->default_question_form((string)$questiondata->qtype);
        $form->name = (string)($questiondata->name ?? '');
        $form->questiontext = [
            'text' => (string)($questiondata->questiontext ?? ''),
            'format' => (int)($questiondata->questiontextformat ?? FORMAT_HTML),
        ];
        $form->defaultmark = (float)($questiondata->defaultmark ?? 1);
        $form->generalfeedback = [
            'text' => (string)($questiondata->generalfeedback ?? ''),
            'format' => (int)($questiondata->generalfeedbackformat ?? FORMAT_HTML),
        ];
        $form->idnumber = $questiondata->idnumber ?? null;
        $form->status = (string)($questiondata->status ?? $form->status);
        $form->hint = [];

        foreach ($questiondata->hints ?? [] as $hint) {
            $form->hint[] = [
                'text' => (string)($hint->hint ?? ''),
                'format' => (int)($hint->hintformat ?? FORMAT_HTML),
            ];
        }

        return match ((string)$questiondata->qtype) {
            'shortanswer' => $this->shortanswer_form_from_existing($form, $questiondata),
            'truefalse' => $this->truefalse_form_from_existing($form, $questiondata),
            'essay' => $this->essay_form_from_existing($form, $questiondata),
            'description' => $form,
            default => throw new \moodle_exception('invalidparameter'),
        };
    }

    /**
     * Populate a shortanswer form from current stored data.
     *
     * @param stdClass $form Base form.
     * @param stdClass $questiondata Stored question data.
     * @return stdClass
     */
    private function shortanswer_form_from_existing(stdClass $form, stdClass $questiondata): stdClass {
        $form->usecase = (bool)($questiondata->options->usecase ?? false);
        $form->answer = [];
        $form->fraction = [];
        $form->feedback = [];

        foreach ($questiondata->options->answers ?? [] as $answer) {
            $form->answer[] = (string)($answer->answer ?? '');
            $form->fraction[] = (string)($answer->fraction ?? '0');
            $form->feedback[] = [
                'text' => (string)($answer->feedback ?? ''),
                'format' => (int)($answer->feedbackformat ?? FORMAT_HTML),
            ];
        }

        return $form;
    }

    /**
     * Apply shortanswer payload overrides.
     *
     * @param stdClass $form Form object.
     * @param array $payload Raw payload.
     * @return stdClass
     */
    private function apply_shortanswer_payload(stdClass $form, array $payload): stdClass {
        if (array_key_exists('usecase', $payload)) {
            $form->usecase = (bool)$payload['usecase'];
        }

        if (array_key_exists('answers', $payload)) {
            if (!is_array($payload['answers']) || $payload['answers'] === []) {
                throw new \moodle_exception('invalidparameter');
            }

            $form->answer = [];
            $form->fraction = [];
            $form->feedback = [];
            foreach ($payload['answers'] as $answer) {
                if (!is_array($answer)) {
                    throw new \moodle_exception('invalidparameter');
                }
                $form->answer[] = (string)($answer['answer'] ?? '');
                $form->fraction[] = (string)($answer['fraction'] ?? '0');
                $form->feedback[] = [
                    'text' => (string)($answer['feedback'] ?? ''),
                    'format' => (int)($answer['feedbackformat'] ?? FORMAT_HTML),
                ];
            }
        }

        return $form;
    }

    /**
     * Populate a truefalse form from current stored data.
     *
     * @param stdClass $form Base form.
     * @param stdClass $questiondata Stored question data.
     * @return stdClass
     */
    private function truefalse_form_from_existing(stdClass $form, stdClass $questiondata): stdClass {
        $form->penalty = (float)($questiondata->penalty ?? 1);
        $answers = array_values((array)($questiondata->options->answers ?? []));
        $trueanswer = $answers[$questiondata->options->trueanswer ?? 0] ?? ($answers[0] ?? null);
        $falseanswer = $answers[$questiondata->options->falseanswer ?? 1] ?? ($answers[1] ?? null);

        $truefraction = (float)($trueanswer->fraction ?? 0);
        $falsefraction = (float)($falseanswer->fraction ?? 0);
        $form->correctanswer = $truefraction >= $falsefraction ? '1' : '0';
        $form->feedbacktrue = [
            'text' => (string)($trueanswer->feedback ?? ''),
            'format' => (int)($trueanswer->feedbackformat ?? FORMAT_HTML),
        ];
        $form->feedbackfalse = [
            'text' => (string)($falseanswer->feedback ?? ''),
            'format' => (int)($falseanswer->feedbackformat ?? FORMAT_HTML),
        ];

        return $form;
    }

    /**
     * Apply truefalse payload overrides.
     *
     * @param stdClass $form Form object.
     * @param array $payload Raw payload.
     * @return stdClass
     */
    private function apply_truefalse_payload(stdClass $form, array $payload): stdClass {
        if (array_key_exists('correctanswer', $payload)) {
            $form->correctanswer = (string)((int)!empty($payload['correctanswer']));
        }
        if (array_key_exists('feedbacktrue', $payload)) {
            $form->feedbacktrue = [
                'text' => (string)$payload['feedbacktrue'],
                'format' => (int)($payload['feedbacktrueformat'] ?? FORMAT_HTML),
            ];
        }
        if (array_key_exists('feedbackfalse', $payload)) {
            $form->feedbackfalse = [
                'text' => (string)$payload['feedbackfalse'],
                'format' => (int)($payload['feedbackfalseformat'] ?? FORMAT_HTML),
            ];
        }
        if (array_key_exists('penalty', $payload)) {
            $form->penalty = (float)$payload['penalty'];
        }

        return $form;
    }

    /**
     * Populate an essay form from current stored data.
     *
     * @param stdClass $form Base form.
     * @param stdClass $questiondata Stored question data.
     * @return stdClass
     */
    private function essay_form_from_existing(stdClass $form, stdClass $questiondata): stdClass {
        $options = $questiondata->options ?? new stdClass();
        $form->responseformat = (string)($options->responseformat ?? 'editor');
        $form->responserequired = (int)($options->responserequired ?? 1);
        $form->responsefieldlines = (int)($options->responsefieldlines ?? 10);
        $form->attachments = (int)($options->attachments ?? 0);
        $form->attachmentsrequired = (int)($options->attachmentsrequired ?? 0);
        $form->maxbytes = (int)($options->maxbytes ?? 0);
        $form->filetypeslist = (string)($options->filetypeslist ?? '');
        $form->graderinfo = [
            'text' => (string)($options->graderinfo ?? ''),
            'format' => (int)($options->graderinfoformat ?? FORMAT_HTML),
        ];
        $form->responsetemplate = [
            'text' => (string)($options->responsetemplate ?? ''),
            'format' => (int)($options->responsetemplateformat ?? FORMAT_HTML),
        ];

        return $form;
    }

    /**
     * Apply essay payload overrides.
     *
     * @param stdClass $form Form object.
     * @param array $payload Raw payload.
     * @return stdClass
     */
    private function apply_essay_payload(stdClass $form, array $payload): stdClass {
        foreach ([
            'responseformat',
            'responserequired',
            'responsefieldlines',
            'attachments',
            'attachmentsrequired',
            'maxbytes',
            'filetypeslist',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $form->{$field} = $payload[$field];
            }
        }

        if (array_key_exists('graderinfo', $payload)) {
            $form->graderinfo = [
                'text' => (string)$payload['graderinfo'],
                'format' => (int)($payload['graderinfoformat'] ?? FORMAT_HTML),
            ];
        }
        if (array_key_exists('responsetemplate', $payload)) {
            $form->responsetemplate = [
                'text' => (string)$payload['responsetemplate'],
                'format' => (int)($payload['responsetemplateformat'] ?? FORMAT_HTML),
            ];
        }

        return $form;
    }

    /**
     * Normalize hints payload.
     *
     * @param mixed $hints Raw hints payload.
     * @return array
     */
    private function normalize_hints(mixed $hints): array {
        if (!is_array($hints)) {
            throw new \moodle_exception('invalidparameter');
        }

        $normalized = [];
        foreach ($hints as $hint) {
            if (is_string($hint)) {
                $normalized[] = ['text' => $hint, 'format' => FORMAT_HTML];
                continue;
            }
            if (!is_array($hint)) {
                throw new \moodle_exception('invalidparameter');
            }
            $normalized[] = [
                'text' => (string)($hint['text'] ?? ''),
                'format' => (int)($hint['format'] ?? FORMAT_HTML),
            ];
        }

        return $normalized;
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
     * Return normalized question metadata.
     *
     * @param int $questionid Question id.
     * @return array
     */
    private function question_result(int $questionid): array {
        global $DB;
        require_once($this->dirroot() . '/lib/questionlib.php');

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
     * Normalize a possibly empty string.
     *
     * @param string|null $value Raw string.
     * @return string|null
     */
    private function normalize_optional_string(?string $value): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * Normalize an id list to unique positive integers.
     *
     * @param array $ids Raw ids.
     * @return array
     */
    private function normalize_ids(array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        return array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
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
