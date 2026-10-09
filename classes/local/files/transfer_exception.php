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
 * A file transfer failure that maps to a specific HTTP status on the download/upload endpoints.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transfer_exception extends \moodle_exception {
    /** @var int HTTP status. */
    public int $status;

    /**
     * Constructor.
     *
     * @param int $status HTTP status.
     * @param string $errorcode Short machine-readable code.
     * @param string $message Human-readable message.
     */
    public function __construct(int $status, string $errorcode, string $message) {
        $this->status = $status;
        parent::__construct('error', 'moodle', '', null, $message);
        $this->errorcode = $errorcode;
        $this->message = $message;
    }
}
