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

/**
 * Offline antivirus test double: flags files named INFECTED*, never looks up IP locations.
 *
 * Enable with $CFG->antiviruses = 'mcpfilestest'.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace antivirus_mcpfilestest;

/**
 * Offline antivirus test double.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scanner extends \core\antivirus\scanner {
    /**
     * Always configured.
     *
     * @return bool
     */
    public function is_configured() {
        return true;
    }

    /**
     * Flag files whose name starts with INFECTED.
     *
     * @param string $file Path.
     * @param string $filename Name.
     * @return int
     */
    public function scan_file($file, $filename) {
        return strpos($filename, 'INFECTED') === 0 ? self::SCAN_RESULT_FOUND : self::SCAN_RESULT_OK;
    }

    /**
     * Data is always clean.
     *
     * @param string $data Data.
     * @return int
     */
    public function scan_data($data) {
        return self::SCAN_RESULT_OK;
    }

    /**
     * Fixed incident text; the core version performs a network IP location lookup.
     *
     * @param string $file Path.
     * @param string $filename Name.
     * @param string $notice Notice.
     * @param bool $virus Whether a virus was found.
     * @return string
     */
    public function get_incident_details($file = '', $filename = '', $notice = '', $virus = true) {
        return 'Test incident: ' . $filename;
    }
}
