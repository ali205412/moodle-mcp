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
 * Question import from GIFT and Moodle XML.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_import_service {
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
        require_once($this->dirroot() . '/lib/questionlib.php');
        require_once($this->dirroot() . '/lib/filelib.php');
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
     * Return Moodle dirroot.
     *
     * @return string
     */
    private function dirroot(): string {
        global $CFG;
        return $CFG->dirroot;
    }
}
