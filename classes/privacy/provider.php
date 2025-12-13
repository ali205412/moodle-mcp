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

namespace webservice_mcp\privacy;

use core_privacy\local\metadata\null_provider;

/**
 * Privacy provider for the MCP web service plugin.
 *
 * This plugin does not store any personal data. It acts as a protocol
 * implementation for exposing existing Moodle web service functions
 * via the Model Context Protocol (MCP) over JSON-RPC 2.0.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements null_provider {
    /**
     * Get the language string identifier for the privacy metadata.
     *
     * This plugin does not store any user data, so it implements
     * the null_provider interface to explicitly declare this.
     *
     * @return string Language string key explaining why no data is stored.
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
