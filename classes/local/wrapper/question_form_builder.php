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

use stdClass;

/**
 * Builds question_type::save_question() form payloads for supported qtypes.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_form_builder {
    /**
     * Build a form-like payload suitable for question_type::save_question().
     *
     * @param string $qtype Supported qtype.
     * @param array $payload User payload.
     * @param stdClass|null $existing Existing question data when editing.
     * @param stdClass $category Target category record.
     * @return stdClass
     */
    public function build_question_form(string $qtype, array $payload, ?stdClass $existing, stdClass $category): stdClass {
        $form = $existing ? $this->question_form_from_existing($existing) : $this->default_question_form($qtype);

        $form->category = $category->id . ',' . $category->contextid;
        if (array_key_exists('name', $payload)) {
            $form->name = (string)$payload['name'];
        }
        if (array_key_exists('idnumber', $payload)) {
            $form->idnumber = arguments::trimmed_or_null((string)$payload['idnumber']);
        }
        if (array_key_exists('questiontext', $payload)) {
            $form->questiontext = [
                'text' => (string)$payload['questiontext'],
                'format' => (int)($payload['questiontextformat'] ?? ($form->questiontext['format'] ?? FORMAT_HTML)),
            ] + $form->questiontext;
        }
        if (array_key_exists('generalfeedback', $payload)) {
            $form->generalfeedback = [
                'text' => (string)$payload['generalfeedback'],
                'format' => (int)($payload['generalfeedbackformat'] ?? ($form->generalfeedback['format'] ?? FORMAT_HTML)),
            ] + $form->generalfeedback;
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
            default => throw arguments::invalid(
                'Unsupported question type "' . $qtype . '"; supported: shortanswer, truefalse, essay, description.'
            ),
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
            default => throw arguments::invalid(
                'Unsupported question type "' . $qtype . '"; supported: shortanswer, truefalse, essay, description.'
            ),
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
        $form->questiontext = $this->with_files([
            'text' => (string)($questiondata->questiontext ?? ''),
            'format' => (int)($questiondata->questiontextformat ?? FORMAT_HTML),
        ], $questiondata, 'question', 'questiontext', (int)$questiondata->id);
        $form->defaultmark = (float)($questiondata->defaultmark ?? 1);
        $form->generalfeedback = $this->with_files([
            'text' => (string)($questiondata->generalfeedback ?? ''),
            'format' => (int)($questiondata->generalfeedbackformat ?? FORMAT_HTML),
        ], $questiondata, 'question', 'generalfeedback', (int)$questiondata->id);
        $form->idnumber = $questiondata->idnumber ?? null;
        $form->status = (string)($questiondata->status ?? $form->status);
        $form->hint = [];

        foreach ($questiondata->hints ?? [] as $hint) {
            $form->hint[] = $this->with_files([
                'text' => (string)($hint->hint ?? ''),
                'format' => (int)($hint->hintformat ?? FORMAT_HTML),
            ], $questiondata, 'question', 'hint', (int)$hint->id);
        }

        return match ((string)$questiondata->qtype) {
            'shortanswer' => $this->shortanswer_form_from_existing($form, $questiondata),
            'truefalse' => $this->truefalse_form_from_existing($form, $questiondata),
            'essay' => $this->essay_form_from_existing($form, $questiondata),
            'description' => $form,
            default => throw arguments::invalid(
                'Question ' . (int)$questiondata->id . ' is of type "' . $questiondata->qtype
                . '", which this wrapper cannot edit; '
                . 'supported: shortanswer, truefalse, essay, description. Use the Moodle question bank for other types.'
            ),
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
            $form->feedback[] = $this->with_files([
                'text' => (string)($answer->feedback ?? ''),
                'format' => (int)($answer->feedbackformat ?? FORMAT_HTML),
            ], $questiondata, 'question', 'answerfeedback', (int)$answer->id);
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
            $form->usecase = arguments::to_bool($payload['usecase']);
        }

        if (array_key_exists('answers', $payload)) {
            if (!is_array($payload['answers']) || $payload['answers'] === []) {
                throw arguments::invalid('answers must be a non-empty list of {answer, fraction, feedback} objects.');
            }

            $form->answer = [];
            $form->fraction = [];
            $form->feedback = [];
            foreach ($payload['answers'] as $answer) {
                if (!is_array($answer)) {
                    throw arguments::invalid(
                        'Each entry in answers must be an object with answer, fraction (0-1) and optional feedback.'
                    );
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
        $form->feedbacktrue = $this->with_files([
            'text' => (string)($trueanswer->feedback ?? ''),
            'format' => (int)($trueanswer->feedbackformat ?? FORMAT_HTML),
        ], $questiondata, 'question', 'answerfeedback', (int)($trueanswer->id ?? 0));
        $form->feedbackfalse = $this->with_files([
            'text' => (string)($falseanswer->feedback ?? ''),
            'format' => (int)($falseanswer->feedbackformat ?? FORMAT_HTML),
        ], $questiondata, 'question', 'answerfeedback', (int)($falseanswer->id ?? 0));

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
            $form->correctanswer = (string)(int)arguments::to_bool($payload['correctanswer']);
        }
        if (array_key_exists('feedbacktrue', $payload)) {
            $form->feedbacktrue = [
                'text' => (string)$payload['feedbacktrue'],
                'format' => (int)($payload['feedbacktrueformat'] ?? FORMAT_HTML),
            ] + $form->feedbacktrue;
        }
        if (array_key_exists('feedbackfalse', $payload)) {
            $form->feedbackfalse = [
                'text' => (string)$payload['feedbackfalse'],
                'format' => (int)($payload['feedbackfalseformat'] ?? FORMAT_HTML),
            ] + $form->feedbackfalse;
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
        $form->graderinfo = $this->with_files([
            'text' => (string)($options->graderinfo ?? ''),
            'format' => (int)($options->graderinfoformat ?? FORMAT_HTML),
        ], $questiondata, 'qtype_essay', 'graderinfo', (int)$questiondata->id);
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
        foreach (
            [
            'responseformat',
            'responserequired',
            'responsefieldlines',
            'attachments',
            'attachmentsrequired',
            'maxbytes',
            'filetypeslist',
            ] as $field
        ) {
            if (array_key_exists($field, $payload)) {
                $form->{$field} = $payload[$field];
            }
        }

        if (array_key_exists('graderinfo', $payload)) {
            $form->graderinfo = [
                'text' => (string)$payload['graderinfo'],
                'format' => (int)($payload['graderinfoformat'] ?? FORMAT_HTML),
            ] + $form->graderinfo;
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
            throw arguments::invalid('hints must be a list of strings or {text, format} objects.');
        }

        $normalized = [];
        foreach ($hints as $hint) {
            if (is_string($hint)) {
                $normalized[] = ['text' => $hint, 'format' => FORMAT_HTML];
                continue;
            }
            if (!is_array($hint)) {
                throw arguments::invalid('Each hint must be a string or a {text, format} object.');
            }
            $normalized[] = [
                'text' => (string)($hint['text'] ?? ''),
                'format' => (int)($hint['format'] ?? FORMAT_HTML),
            ];
        }

        return $normalized;
    }

    /**
     * Copy a stored editor field's files into a fresh draft area, as the question edit form does, so saving the
     * new version carries embedded images and attachments over.
     *
     * @param array $editor Editor value (text, format).
     * @param stdClass $questiondata Stored question data (provides contextid).
     * @param string $component File component.
     * @param string $filearea File area.
     * @param int $itemid Item id of the stored files.
     * @return array Editor value with itemid.
     */
    private function with_files(array $editor, stdClass $questiondata, string $component, string $filearea, int $itemid): array {
        $draftitemid = 0;
        \file_prepare_draft_area(
            $draftitemid,
            (int)$questiondata->contextid,
            $component,
            $filearea,
            $itemid,
            ['subdirs' => true]
        );

        return $editor + ['itemid' => $draftitemid];
    }
}
