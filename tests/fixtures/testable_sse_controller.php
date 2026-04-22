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
 * Fixture SSE controller used by PHPUnit transport tests.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace webservice_mcp;

use stdClass;
use webservice_mcp\local\transport\sse_controller;

/**
 * Test double for SSE controller behavior.
 *
 * @package     webservice_mcp
 */
final class testable_sse_controller extends sse_controller {
    /** @var string */
    public string $capturedbody = '';

    /** @var array */
    public array $capturedheaders = [];

    /** @var int */
    public int $capturedstatus = 200;

    /** @var bool */
    public bool $authcalled = false;

    /**
     * Apply a public token without reading request globals.
     *
     * @param string $token Connector token.
     * @return void
     */
    public function set_public_token_for_test(string $token): void {
        $this->publictoken = $token;
        $this->token = $token;
    }

    /**
     * Apply a resolved identity.
     *
     * @param stdClass $identity Transport identity.
     * @return void
     */
    public function apply_identity_for_test(stdClass $identity): void {
        $this->authenticate_transport_identity($identity);
    }

    /**
     * Seed transport request metadata.
     *
     * @param array $requestdata Transport request data.
     * @return void
     */
    public function set_transport_request_for_test(array $requestdata): void {
        $this->transportrequest = $requestdata;
    }

    /**
     * Create a session through the protected helper.
     *
     * @return string
     */
    public function create_transport_session_for_test(): string {
        return $this->create_transport_session();
    }

    /**
     * Append a replay event.
     *
     * @param string $sessionid Session id.
     * @param array $event Event data.
     * @return int
     */
    public function append_replay_event_for_test(string $sessionid, array $event): int {
        return $this->replaystore->append_event($sessionid, $event);
    }

    /**
     * Track auth calls during controller runs.
     *
     * @return void
     */
    protected function authenticate_user(): void {
        $this->authcalled = true;
        parent::authenticate_user();
    }

    /**
     * Capture headers for assertions.
     *
     * @param string $header Header line.
     * @return void
     */
    protected function send_header(string $header): void {
        $this->capturedheaders[] = $header;
    }

    /**
     * Capture response status for assertions.
     *
     * @param int $status Status code.
     * @return void
     */
    protected function set_status(int $status): void {
        $this->capturedstatus = $status;
    }

    /**
     * Capture output instead of echoing to stdout.
     *
     * @param string $body Response body chunk.
     * @return void
     */
    protected function emit(string $body): void {
        $this->capturedbody .= $body;
    }

    /**
     * Avoid mutating the real test-process PHP session.
     *
     * @return void
     */
    protected function close_session_for_transport(): void {
    }
}
