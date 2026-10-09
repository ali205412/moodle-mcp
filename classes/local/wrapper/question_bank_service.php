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

/**
 * Facade over the question-bank wrapper services used by the wrapper manager.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_bank_service {
    /** @var question_category_service */
    private question_category_service $categories;

    /** @var question_service */
    private question_service $questions;

    /** @var question_import_service */
    private question_import_service $importer;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->categories = new question_category_service();
        $this->questions = new question_service();
        $this->importer = new question_import_service();
    }

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
        return $this->categories->create_category($contextid, $name, $parentcategoryid, $info, $infoformat, $idnumber);
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
        return $this->categories->update_category($categoryid, $name, $info, $infoformat, $parentcategoryid, $idnumber);
    }

    /**
     * Delete a category, optionally moving questions first.
     *
     * @param int $categoryid Category id.
     * @param int|null $movequestionstocategoryid Optional target category for existing questions.
     * @return array
     */
    public function delete_category(int $categoryid, ?int $movequestionstocategoryid = null): array {
        return $this->categories->delete_category($categoryid, $movequestionstocategoryid);
    }

    /**
     * Move questions into another category.
     *
     * @param array $questionids Question ids.
     * @param int $targetcategoryid Target category id.
     * @return array
     */
    public function move_questions(array $questionids, int $targetcategoryid): array {
        return $this->questions->move_questions($questionids, $targetcategoryid);
    }

    /**
     * Delete questions; questions in use are hidden instead.
     *
     * @param array $questionids Question ids.
     * @return array
     */
    public function delete_questions(array $questionids): array {
        return $this->questions->delete_questions($questionids);
    }

    /**
     * Create a new question.
     *
     * @param int $categoryid Target category id.
     * @param array $payload Question payload.
     * @return array
     */
    public function create_question(int $categoryid, array $payload): array {
        return $this->questions->create_question($categoryid, $payload);
    }

    /**
     * Save a new version of a question.
     *
     * @param int $questionid Existing question id.
     * @param array $payload Partial payload.
     * @return array
     */
    public function update_question(int $questionid, array $payload): array {
        return $this->questions->update_question($questionid, $payload);
    }

    /**
     * Return a Moodle preview URL for a question.
     *
     * @param int $questionid Question id.
     * @return array
     */
    public function preview_question(int $questionid): array {
        return $this->questions->preview_question($questionid);
    }

    /**
     * Import questions from GIFT or Moodle XML.
     *
     * @param int $categoryid Target category id.
     * @param string $format Format shortname.
     * @param string $content Raw content.
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
        return $this->importer->import_questions($categoryid, $format, $content, $catfromfile, $contextfromfile);
    }
}
