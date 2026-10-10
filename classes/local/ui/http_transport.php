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

namespace webservice_mcp\local\ui;

/**
 * One HTTP request to this Moodle site over the loopback interface; the session bridge's only network seam.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface http_transport {
    /**
     * Send a request without following redirects.
     *
     * @param string $method GET or POST.
     * @param string $url Absolute URL (already policy-checked).
     * @param array $headers Request header lines ("Name: value").
     * @param string|array|null $body Url-encoded string, multipart field array, or null.
     * @param int $maxbytes Largest body to accept.
     * @return array ['status' => int, 'headers' => [lowercase name => string[]], 'body' => string]
     * @throws \webservice_mcp\local\files\transfer_exception On network failure or an oversized body.
     */
    public function request(string $method, string $url, array $headers, string|array|null $body, int $maxbytes): array;
}
