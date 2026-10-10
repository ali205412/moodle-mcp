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

use webservice_mcp\local\wrapper\builtin_definitions as defs;

/**
 * Built-in question bank and gradebook wrapper definitions.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assessment_definitions {
    /**
     * Question bank wrappers.
     *
     * @return definition[]
     */
    public static function question_definitions(): array {
        $category = defs::obj([
            'categoryid' => ['type' => 'integer'],
            'contextid' => ['type' => 'integer'],
            'parentcategoryid' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'idnumber' => ['type' => ['string', 'null']],
        ]);
        $question = [
            'questionid' => ['type' => 'integer'],
            'questionbankentryid' => ['type' => 'integer'],
            'versionid' => ['type' => 'integer'],
            'version' => ['type' => 'integer'],
            'status' => ['type' => 'string'],
            'qtype' => ['type' => 'string'],
            'categoryid' => ['type' => 'integer'],
            'contextid' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
        ];
        $payloaddoc = 'Question fields: name, questiontext, questiontextformat, generalfeedback, defaultmark, idnumber, '
            . 'status (ready|hidden|draft), penalty, hints[]. shortanswer: usecase, answers[{answer, fraction, feedback}]; '
            . 'truefalse: correctanswer, feedbacktrue, feedbackfalse; essay: responseformat, responserequired, '
            . 'responsefieldlines, attachments, attachmentsrequired, maxbytes, filetypeslist, graderinfo, responsetemplate.';

        return [
            defs::def(
                'wrapper_question_create_category',
                'Create question category',
                'Create a question-bank category in a context (system, category, course or module context id).',
                ['moodle/question:managecategory'],
                defs::obj(
                    ['contextid' => defs::id('Context id.'), 'name' => ['type' => 'string'],
                    'parentcategoryid' => defs::id('Parent category id; defaults to the context top category.'),
                    'info' => ['type' => 'string'], 'infoformat' => ['type' => 'integer'], 'idnumber' => ['type' => 'string']],
                    ['contextid', 'name']
                ),
                $category,
                defs::WRITE
            ),
            defs::def(
                'wrapper_question_update_category',
                'Update question category',
                'Rename or re-describe a question-bank category, optionally moving it under another parent category.',
                ['moodle/question:managecategory'],
                defs::obj(['categoryid' => defs::id('Category id.'), 'name' => ['type' => 'string'], 'info' => ['type' => 'string'],
                    'infoformat' => ['type' => 'integer'], 'parentcategoryid' => defs::id('New parent category id.'),
                    'idnumber' => ['type' => 'string']], ['categoryid', 'name']),
                $category,
                defs::WRITE
            ),
            defs::def(
                'wrapper_question_delete_category',
                'Delete question category',
                'Delete a question-bank category. If it still holds questions, movequestionstocategoryid is required.',
                ['moodle/question:managecategory'],
                defs::obj(['categoryid' => defs::id('Category id.'),
                    'movequestionstocategoryid' => defs::id('Category receiving remaining questions.')], ['categoryid']),
                defs::obj(['deleted' => ['type' => 'boolean'], 'categoryid' => ['type' => 'integer'],
                    'movedquestionids' => defs::ids('')]),
                defs::DESTRUCTIVE
            ),
            defs::def(
                'wrapper_question_move_questions',
                'Move questions',
                'Move questions to another question-bank category. Requires the move capability on each question.',
                [],
                defs::obj(
                    ['questionids' => defs::ids('Question ids.'), 'targetcategoryid' => defs::id('Target category id.')],
                    ['questionids', 'targetcategoryid']
                ),
                defs::obj(['moved' => ['type' => 'boolean'], 'questionids' => defs::ids(''),
                    'targetcategoryid' => ['type' => 'integer']]),
                defs::IDEMPOTENT_WRITE
            ),
            defs::def(
                'wrapper_question_delete_questions',
                'Delete questions',
                'Delete questions. Questions still used by a quiz cannot be deleted; Moodle hides them instead '
                    . '(reported in hiddenquestionids).',
                [],
                defs::obj(['questionids' => defs::ids('Question ids.')], ['questionids']),
                defs::obj(['deleted' => ['type' => 'boolean', 'description' => 'True when every question was deleted.'],
                    'questionids' => defs::ids(''), 'deletedquestionids' => defs::ids(''), 'hiddenquestionids' => defs::ids('')]),
                defs::DESTRUCTIVE
            ),
            defs::def(
                'wrapper_question_create_question',
                'Create question',
                'Create a shortanswer, truefalse, essay or description question in a category.',
                ['moodle/question:add'],
                defs::obj(['categoryid' => defs::id('Question category id.'), 'payload' => [
                    'type' => 'object',
                    'description' => $payloaddoc,
                    'properties' => [
                        'qtype' => ['type' => 'string', 'enum' => ['shortanswer', 'truefalse', 'essay', 'description']],
                        'name' => ['type' => 'string'],
                        'questiontext' => ['type' => 'string'],
                        'generalfeedback' => ['type' => 'string'],
                        'defaultmark' => ['type' => 'number'],
                    ],
                    'required' => ['qtype', 'name', 'questiontext'],
                ]], ['categoryid', 'payload']),
                defs::obj($question),
                defs::WRITE
            ),
            defs::def(
                'wrapper_question_update_question',
                'Update question',
                'Save a new version of a supported question. Only the payload fields given are changed.',
                [],
                defs::obj(
                    ['questionid' => defs::id('Question id.'),
                    'payload' => ['type' => 'object', 'description' => $payloaddoc]],
                    ['questionid', 'payload']
                ),
                defs::obj($question + ['previousquestionid' => ['type' => 'integer']]),
                defs::WRITE
            ),
            defs::def(
                'wrapper_question_preview_question',
                'Question preview URL',
                'Return the Moodle preview URL for a question the user can use.',
                [],
                defs::obj(['questionid' => defs::id('Question id.')], ['questionid']),
                defs::obj(['questionid' => ['type' => 'integer'], 'previewurl' => ['type' => 'string']]),
                defs::READ
            ),
            defs::def(
                'wrapper_question_import_questions',
                'Import questions',
                'Import questions into a category from GIFT or Moodle XML text.',
                ['moodle/question:add'],
                defs::obj(
                    ['categoryid' => defs::id('Question category id.'),
                    'format' => ['type' => 'string', 'enum' => ['gift', 'xml']],
                    'content' => ['type' => 'string', 'description' => 'Raw GIFT or Moodle XML content.'],
                    'catfromfile' => ['type' => 'boolean'], 'contextfromfile' => ['type' => 'boolean']],
                    ['categoryid', 'format', 'content']
                ),
                defs::obj(['status' => ['type' => 'boolean'], 'format' => ['type' => 'string'],
                    'categoryid' => ['type' => 'integer'],
                    'questionids' => defs::ids(''), 'output' => ['type' => 'string']]),
                defs::WRITE
            ),
        ];
    }

    /**
     * Gradebook wrappers.
     *
     * @return definition[]
     */
    public static function gradebook_definitions(): array {
        $item = defs::obj([
            'itemid' => ['type' => 'integer'],
            'courseid' => ['type' => 'integer'],
            'itemtype' => ['type' => 'string'],
            'itemname' => ['type' => 'string'],
            'parentcategoryid' => ['type' => ['integer', 'null']],
            'sortorder' => ['type' => 'integer'],
        ]);
        $category = defs::obj([
            'categoryid' => ['type' => 'integer'],
            'courseid' => ['type' => 'integer'],
            'parentcategoryid' => ['type' => ['integer', 'null']],
            'name' => ['type' => 'string'],
            'sortorder' => ['type' => 'integer'],
        ]);
        $courseid = defs::id('Course id.');
        $itempayload = ['type' => 'object', 'description' => 'Fields: itemname, idnumber, gradetype (value|scale|text), '
            . 'scaleid, grademax, grademin, gradepass, parentcategoryid, weightoverride, aggregationcoef, aggregationcoef2, '
            . 'hidden, hiddenuntil, locked, locktime, rescalegrades (update only).'];

        return [
            defs::def(
                'wrapper_gradebook_create_manual_item',
                'Create manual grade item',
                'Create a manual gradebook item in a course.',
                ['moodle/grade:manage'],
                defs::obj(['courseid' => $courseid, 'payload' => $itempayload], ['courseid', 'payload']),
                $item,
                defs::WRITE
            ),
            defs::def(
                'wrapper_gradebook_update_manual_item',
                'Update manual grade item',
                'Update a manual gradebook item. Only the payload fields given are changed.',
                ['moodle/grade:manage'],
                defs::obj(
                    ['courseid' => $courseid, 'itemid' => defs::id('Grade item id.'), 'payload' => $itempayload],
                    ['courseid', 'itemid', 'payload']
                ),
                $item,
                defs::WRITE
            ),
            defs::def(
                'wrapper_gradebook_move_item',
                'Move grade item',
                'Move a manual grade item into another grade category and/or after another grade item.',
                ['moodle/grade:manage'],
                defs::obj(
                    ['courseid' => $courseid, 'itemid' => defs::id('Grade item id.'),
                    'parentcategoryid' => defs::id('Target grade category id.'),
                    'afteritemid' => defs::id('Place after this item.')],
                    ['courseid', 'itemid']
                ),
                $item,
                defs::WRITE
            ),
            defs::def(
                'wrapper_gradebook_delete_items',
                'Delete manual grade items',
                'Delete manual gradebook items and their grades.',
                ['moodle/grade:manage'],
                defs::obj(['courseid' => $courseid, 'itemids' => defs::ids('Grade item ids.')], ['courseid', 'itemids']),
                defs::obj(['deleted' => ['type' => 'boolean'], 'itemids' => defs::ids('')]),
                defs::DESTRUCTIVE
            ),
            defs::def(
                'wrapper_gradebook_update_category',
                'Update grade category',
                'Update a gradebook category (name, aggregation, droplow, category total settings).',
                ['moodle/grade:manage'],
                defs::obj(['courseid' => $courseid, 'categoryid' => defs::id('Grade category id.'), 'payload' => [
                    'type' => 'object',
                    'description' => 'Fields: fullname, aggregation, aggregateonlygraded, aggregateoutcomes, droplow, '
                        . 'parentcategoryid, and category total fields itemname, iteminfo, idnumber, gradetype, grademax, '
                        . 'grademin, gradepass, display, decimals, hiddenuntil, locktime, weightoverride, aggregationcoef2.',
                ]], ['courseid', 'categoryid', 'payload']),
                $category,
                defs::WRITE
            ),
            defs::def(
                'wrapper_gradebook_move_category',
                'Move grade category',
                'Move a gradebook category under another parent and/or after another category or item.',
                ['moodle/grade:manage'],
                defs::obj(['courseid' => $courseid, 'categoryid' => defs::id('Grade category id.'),
                    'parentcategoryid' => defs::id('New parent category id.'),
                    'aftercategoryid' => defs::id('Place after category.'),
                    'afteritemid' => defs::id('Place after grade item.')], ['courseid', 'categoryid']),
                $category,
                defs::WRITE
            ),
            defs::def(
                'wrapper_gradebook_delete_categories',
                'Delete grade categories',
                'Delete gradebook categories (not the course category).',
                ['moodle/grade:manage'],
                defs::obj(
                    ['courseid' => $courseid, 'categoryids' => defs::ids('Grade category ids.')],
                    ['courseid', 'categoryids']
                ),
                defs::obj(['deleted' => ['type' => 'boolean'], 'categoryids' => defs::ids('')]),
                defs::DESTRUCTIVE
            ),
        ];
    }
}
