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

use core_external\external_api;

/**
 * Legacy SSE compatibility controller over plugin-owned transport state.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sse_controller extends server {
    /** SSE compatibility endpoint allowed methods. */
    protected const ALLOW = 'GET, OPTIONS';

    /**
     * Run the SSE compatibility flow.
     *
     * @return void
     */
    public function run(): void {
        raise_memory_limit(MEMORY_EXTRA);
        external_api::set_timeout();

        $this->httpmethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->rawheaders = $this->protocolheaders->read_headers();
        $origin = $this->rawheaders['origin'] ?? null;

        if (!$this->originvalidator->is_origin_allowed($origin)) {
            $this->responseorigin = null;
            $this->send_transport_error(403, -32000, 'Origin not allowed.');
            return;
        }

        $this->responseorigin = $this->originvalidator->get_response_origin($origin);

        if (!get_config('webservice_mcp', 'enablelegacysse')) {
            $this->send_transport_error(404, -32601, 'Legacy SSE compatibility is disabled.');
            return;
        }

        try {
            if ($this->httpmethod === 'OPTIONS') {
                $this->send_preflight_response();
                return;
            }

            if ($this->httpmethod !== 'GET') {
                $this->send_method_not_allowed();
                return;
            }

            if (!$this->prepare_stateful_request()) {
                return;
            }

            $this->authenticate_user();
            $this->release_transport_session();

            if (!$this->load_transport_session_or_respond((string)$this->transportrequest['sessionid'])) {
                return;
            }

            $this->emit_sse_headers();
            $this->stream_events();
        } catch (\Throwable $exception) {
            abort_all_db_transactions();
            $this->session_cleanup($exception);
            $this->send_error($exception);
        }
    }

    /**
     * Emit SSE-compatible response headers.
     *
     * @return void
     */
    protected function emit_sse_headers(): void {
        $this->send_header('Content-Type: text/event-stream; charset=utf-8');
        $this->send_header('Cache-Control: no-cache, no-transform');
        $this->send_header('Connection: keep-alive');
        $this->send_header('X-Accel-Buffering: no');
        $this->send_header('Access-Control-Allow-Methods: ' . static::ALLOW);
        $this->send_header('Access-Control-Allow-Headers: ' . static::ALLOW_HEADERS);
        $this->send_header('Access-Control-Expose-Headers: MCP-Session-Id');
        $this->send_header('Vary: Origin');

        if ($this->responseorigin !== null) {
            $this->send_header('Access-Control-Allow-Origin: ' . $this->responseorigin);
        }

        $this->set_status(200);
    }

    /**
     * Stream replayed events for the resolved transport session.
     *
     * @return void
     */
    protected function stream_events(): void {
        $sessionid = (string)$this->transportrequest['sessionid'];
        $afterid = $this->last_event_id();
        $events = $this->replaystore->get_events($sessionid, $afterid);

        if ($events === []) {
            $this->emit_sse_event(0, 'heartbeat', []);
        } else {
            foreach ($events as $event) {
                $payload = $event['payload'] ?? $event['data'] ?? $event;
                $this->emit_sse_event((int)($event['id'] ?? 0), (string)($event['type'] ?? 'message'), $payload);
            }
        }

        $this->sessionstore->touch_session($sessionid, [
            'lastsse' => time(),
        ]);
    }

    /**
     * Emit one SSE event frame.
     *
     * @param int $id Event id.
     * @param string $eventname Event name.
     * @param mixed $payload Event payload.
     * @return void
     */
    protected function emit_sse_event(int $id, string $eventname, mixed $payload): void {
        if ($id > 0) {
            $this->emit('id: ' . $id . "\n");
        }

        $this->emit('event: ' . $this->sanitize_event_name($eventname) . "\n");

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }

        foreach (explode("\n", $json) as $line) {
            $this->emit('data: ' . $line . "\n");
        }

        $this->emit("\n");
        @ob_flush();
        @flush();
    }

    /**
     * Parse the Last-Event-ID header if present.
     *
     * @return int
     */
    protected function last_event_id(): int {
        $header = trim((string)($this->rawheaders['last-event-id'] ?? ''));
        if ($header === '' || !ctype_digit($header)) {
            return 0;
        }

        return (int)$header;
    }

    /**
     * Normalise event names to SSE-safe characters.
     *
     * @param string $eventname Event name.
     * @return string
     */
    private function sanitize_event_name(string $eventname): string {
        $eventname = preg_replace('/[^a-zA-Z0-9:_-]/', '-', $eventname) ?? 'message';
        return $eventname === '' ? 'message' : $eventname;
    }
}
