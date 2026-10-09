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
 * Loads Moodle library files from inside methods.
 *
 * Library files such as lib/questionlib.php use $CFG at file scope. A require_once inside a method runs that
 * top-level code in the method's scope, so without `global $CFG` there it builds paths like '/question/engine/lib.php'
 * and fatals. This only shows on a cold request, because a library already loaded elsewhere is never re-included.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class moodle_lib {
    /**
     * Require Moodle files, given as paths relative to the Moodle root, with Moodle's globals in scope.
     *
     * @param string ...$paths Paths such as 'lib/questionlib.php'.
     * @return void
     */
    public static function load(string ...$paths): void {
        // phpcs:ignore moodle.Commenting.InlineComment.DocBlock
        global $CFG, $DB, $USER, $SESSION, $PAGE, $OUTPUT, $SITE, $COURSE;
        foreach ($paths as $path) {
            require_once($CFG->dirroot . '/' . ltrim($path, '/'));
        }
    }
}
