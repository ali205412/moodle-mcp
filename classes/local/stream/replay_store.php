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
 * Minimal replay/event buffer abstraction for transport sessions.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class replay_store {
    /** Cache definition name. */
    private const CACHE = 'mcp_event_replay';

    /**
     * Append an event to the replay buffer for a session.
     *
     * @param string $sessionid Session id.
     * @param array $event Event payload.
     * @return int Event sequence number.
     */
    public function append_event(string $sessionid, array $event): int {
        $cachekey = $this->cache_key($sessionid);
        $payload = $this->payload($cachekey);
        $events = $payload['events'];
        $eventid = count($events) + 1;
        $event['id'] = $eventid;
        $event['created'] = time();
        $events[] = $event;
        $payload['events'] = $events;
        $payload['expires'] = time() + $this->ttl();
        $this->cache()->set($cachekey, $payload);

        return $eventid;
    }

    /**
     * Get all events after the given id.
     *
     * @param string $sessionid Session id.
     * @param int $afterid Return events after this sequence number.
     * @return array
     */
    public function get_events(string $sessionid, int $afterid = 0): array {
        $events = $this->payload($this->cache_key($sessionid))['events'];

        if ($afterid <= 0) {
            return $events;
        }

        return array_values(array_filter($events, static fn(array $event): bool => (int)($event['id'] ?? 0) > $afterid));
    }

    /**
     * Clear replay events for a session.
     *
     * @param string $sessionid Session id.
     * @return bool
     */
    public function clear(string $sessionid): bool {
        return $this->cache()->delete($this->cache_key($sessionid));
    }

    /**
     * Return replay cache instance.
     *
     * @return \cache_application
     */
    private function cache(): \cache_application {
        return \cache::make('webservice_mcp', self::CACHE);
    }

    /**
     * Load the stored replay payload, applying manual expiry.
     *
     * @param string $cachekey Replay cache key.
     * @return array
     */
    private function payload(string $cachekey): array {
        $payload = $this->cache()->get($cachekey);

        if (!is_array($payload)) {
            return [
                'expires' => time() + $this->ttl(),
                'events' => [],
            ];
        }

        // Backward-compatibility for earlier list-only payloads.
        if (!array_key_exists('events', $payload)) {
            return [
                'expires' => time() + $this->ttl(),
                'events' => $payload,
            ];
        }

        if (!empty($payload['expires']) && (int)$payload['expires'] < time()) {
            $this->cache()->delete($cachekey);
            return [
                'expires' => time() + $this->ttl(),
                'events' => [],
            ];
        }

        $payload['events'] = is_array($payload['events']) ? $payload['events'] : [];
        return $payload;
    }

    /**
     * Resolve the effective replay ttl.
     *
     * @return int
     */
    private function ttl(): int {
        $ttl = (int)get_config('webservice_mcp', 'replayttl');
        return $ttl > 0 ? $ttl : 3600;
    }

    /**
     * Convert arbitrary session ids into cache-safe keys.
     *
     * @param string $sessionid Session id.
     * @return string
     */
    private function cache_key(string $sessionid): string {
        return 'replay_' . sha1($sessionid);
    }
}
