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

namespace webservice_mcp\local\oauth;

/**
 * OAuth protocol exception carrying an RFC-style error code and HTTP status.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class exception extends \Exception {
    /**
     * Constructor.
     *
     * @param string $oautherror OAuth error code.
     * @param int $httpstatus HTTP status code.
     * @param string $message Human-readable detail.
     */
    public function __construct(
        private string $oautherror,
        private int $httpstatus,
        string $message
    ) {
        parent::__construct($message);
    }

    /**
     * Return the OAuth error code.
     *
     * @return string
     */
    public function oauth_error(): string {
        return $this->oautherror;
    }

    /**
     * Return the HTTP status code.
     *
     * @return int
     */
    public function http_status(): int {
        return $this->httpstatus;
    }
}
