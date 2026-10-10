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
 * Fake loopback transport for session bridge tests.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace webservice_mcp;

use webservice_mcp\local\ui\http_transport;
use webservice_mcp\local\ui\session_bridge;

/**
 * Fake loopback transport: serves scripted pages and redeems login keys like ui/login.php.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class session_bridge_fake_transport implements http_transport {
    /** @var array Recorded requests. */
    public array $requests = [];

    /** @var int Logins served. */
    public int $logins = 0;

    /** @var string Address ui/login.php sees as the client. */
    public string $remoteaddr = '127.0.0.1';

    /** @var \Closure fn(string $method, string $url, array $headers, $body): array page responses. */
    public \Closure $pages;

    /**
     * Constructor.
     *
     * @param \Closure $pages Page handler.
     */
    public function __construct(\Closure $pages) {
        $this->pages = $pages;
    }

    /**
     * Serve a request.
     *
     * @param string $method Method.
     * @param string $url URL.
     * @param array $headers Headers.
     * @param string|array|null $body Body.
     * @param int $maxbytes Max bytes.
     * @return array
     */
    public function request(string $method, string $url, array $headers, string|array|null $body, int $maxbytes): array {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if (str_contains($url, '/webservice/mcp/ui/login.php')) {
            parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
            $saved = $_SERVER['REMOTE_ADDR'] ?? null;
            $_SERVER['REMOTE_ADDR'] = $this->remoteaddr;
            try {
                session_bridge::redeem_login_key((int)$query['userid'], (string)$query['key']);
            } catch (\Throwable $exception) {
                return ['status' => 403, 'headers' => [], 'body' => ''];
            } finally {
                if ($saved === null) {
                    unset($_SERVER['REMOTE_ADDR']);
                } else {
                    $_SERVER['REMOTE_ADDR'] = $saved;
                }
            }
            $this->logins++;
            return ['status' => 204, 'headers' => ['set-cookie' => [
                'MoodleSession=anon; path=/moodle/',
                'MoodleSession=sid' . $this->logins . '; path=/moodle/; HttpOnly',
            ]], 'body' => ''];
        }
        return ($this->pages)($method, $url, $headers, $body);
    }
}
