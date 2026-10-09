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

namespace webservice_mcp\local\mcp;

/**
 * A JSON-RPC protocol-level error with its HTTP status.
 *
 * Tool execution failures are not protocol errors; they become CallToolResult with isError.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class protocol_exception extends \Exception {
    /** Invalid JSON. */
    public const PARSE_ERROR = -32700;
    /** Malformed JSON-RPC envelope. */
    public const INVALID_REQUEST = -32600;
    /** Unknown method. */
    public const METHOD_NOT_FOUND = -32601;
    /** Invalid params, unknown tool/prompt/resource (2026-07-28). */
    public const INVALID_PARAMS = -32602;
    /** Internal error. */
    public const INTERNAL_ERROR = -32603;
    /** Resource not found for legacy (2025-xx) revisions. */
    public const RESOURCE_NOT_FOUND_LEGACY = -32002;
    /** Headers do not match the body (2026-07-28). */
    public const HEADER_MISMATCH = -32020;
    /** Request needs a client capability that was not declared (2026-07-28). */
    public const MISSING_CLIENT_CAPABILITY = -32021;
    /** Unsupported protocol version (2026-07-28). */
    public const UNSUPPORTED_PROTOCOL_VERSION = -32022;

    /** @var int JSON-RPC error code. */
    public int $rpccode;

    /** @var int HTTP status. */
    public int $httpstatus;

    /** @var array|null Optional error data. */
    public ?array $data;

    /**
     * Constructor.
     *
     * @param int $rpccode JSON-RPC error code.
     * @param string $message Message.
     * @param int $httpstatus HTTP status.
     * @param array|null $data Optional error data.
     */
    public function __construct(int $rpccode, string $message, int $httpstatus = 200, ?array $data = null) {
        parent::__construct($message);
        $this->rpccode = $rpccode;
        $this->httpstatus = $httpstatus;
        $this->data = $data;
    }
}
