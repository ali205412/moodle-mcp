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

namespace webservice_mcp\local\transport;

use moodle_url;

/**
 * Validate Origins for browser-facing transport endpoints.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class origin_validator {
    /**
     * Determine whether the supplied Origin is allowed.
     *
     * Empty origins are allowed for same-machine clients such as curl or non-browser callers.
     *
     * @param string|null $origin Request Origin header.
     * @return bool
     */
    public function is_origin_allowed(?string $origin): bool {
        global $CFG;

        if ($origin === null || trim($origin) === '') {
            return true;
        }

        $origin = $this->normalize_origin($origin);
        $siteorigin = $this->normalize_origin($CFG->wwwroot);
        if ($origin === $siteorigin) {
            return true;
        }

        foreach ($this->configured_origins() as $allowedorigin) {
            if ($origin === $allowedorigin) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the specific Origin value that should be echoed back.
     *
     * @param string|null $origin Request Origin header.
     * @return string|null
     */
    public function get_response_origin(?string $origin): ?string {
        if (!$this->is_origin_allowed($origin)) {
            return null;
        }

        return ($origin === null || trim($origin) === '') ? null : $this->normalize_origin($origin);
    }

    /**
     * Return configured allowlist values.
     *
     * @return array
     */
    public function configured_origins(): array {
        $raw = (string)get_config('webservice_mcp', 'allowedorigins');
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $origins = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $origins[] = $this->normalize_origin($part);
        }

        return array_values(array_unique($origins));
    }

    /**
     * Normalize an origin-like URL to scheme://host[:port].
     *
     * @param string $origin Raw origin/header value.
     * @return string
     */
    private function normalize_origin(string $origin): string {
        $url = new moodle_url($origin);
        $scheme = $url->get_scheme();
        $host = $url->get_host();
        $port = $url->get_port();

        return $port === null ? "{$scheme}://{$host}" : "{$scheme}://{$host}:{$port}";
    }
}
