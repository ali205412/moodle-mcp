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

namespace webservice_mcp\local\files;

/**
 * The assignment "Download all submissions" file list, without the downloader's streaming-and-exit step.
 *
 * Reuses \mod_assign\downloader's selection (users, groups, blind marking, folder layout, plugin files) so an
 * export contains exactly what the core action would; export_service writes the zip to a file instead.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_export_downloader extends \mod_assign\downloader {
    /**
     * Files selected by load_filelist(): path in zip => stored_file, or [content] for text submissions.
     *
     * @return array
     */
    public function files(): array {
        return $this->filesforzipping ?? [];
    }
}
