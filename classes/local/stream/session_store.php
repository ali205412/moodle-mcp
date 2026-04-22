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

namespace webservice_mcp\local\stream;

/**
 * Plugin-owned MCP transport session state store.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session_store {
    /** Cache definition name. */
    private const CACHE = 'mcp_stream_session';

    /**
     * Create a new transport session.
     *
     * @param array $metadata Session metadata to store.
     * @return string
     */
    public function create_session(array $metadata): string {
        $sessionid = bin2hex(random_bytes(24));
        $cachekey = $this->cache_key($sessionid);
        $metadata['created'] = time();
        $metadata['updated'] = time();
        $metadata['expires'] = time() + $this->ttl();

        $this->cache()->set($cachekey, $metadata);
        return $sessionid;
    }

    /**
     * Retrieve a session by id.
     *
     * @param string $sessionid Session id.
     * @return array|null
     */
    public function get_session(string $sessionid): ?array {
        $cachekey = $this->cache_key($sessionid);
        $session = $this->cache()->get($cachekey);
        if (!is_array($session)) {
            return null;
        }

        if (!empty($session['expires']) && (int)$session['expires'] < time()) {
            $this->cache()->delete($cachekey);
            return null;
        }

        return $session;
    }

    /**
     * Update a session and touch its timestamp.
     *
     * @param string $sessionid Session id.
     * @param array $metadata Metadata to merge.
     * @return bool
     */
    public function touch_session(string $sessionid, array $metadata = []): bool {
        $session = $this->get_session($sessionid);
        if ($session === null) {
            return false;
        }

        $session = array_merge($session, $metadata, [
            'updated' => time(),
            'expires' => time() + $this->ttl(),
        ]);
        return $this->cache()->set($this->cache_key($sessionid), $session);
    }

    /**
     * Delete a session by id.
     *
     * @param string $sessionid Session id.
     * @return bool
     */
    public function delete_session(string $sessionid): bool {
        return $this->cache()->delete($this->cache_key($sessionid));
    }

    /**
     * Return the transport session cache instance.
     *
     * @return \cache_application
     */
    private function cache(): \cache_application {
        return \cache::make('webservice_mcp', self::CACHE);
    }

    /**
     * Resolve the effective session ttl.
     *
     * @return int
     */
    private function ttl(): int {
        $ttl = (int)get_config('webservice_mcp', 'transportsessionttl');
        return $ttl > 0 ? $ttl : 3600;
    }

    /**
     * Convert arbitrary session ids into cache-safe keys.
     *
     * @param string $sessionid Session id.
     * @return string
     */
    private function cache_key(string $sessionid): string {
        return 'session_' . sha1($sessionid);
    }
}
