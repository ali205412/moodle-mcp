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

use context;
use stdClass;

/**
 * Per-request facts the dispatcher needs: who is calling, where, and what the client supports.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class call_context {
    /** Stateless per-request revision (2026-07-28+). */
    public const ERA_MODERN = 'modern';

    /** Initialize/session revisions (2024-11-05 .. 2025-11-25). */
    public const ERA_LEGACY = 'legacy';

    /**
     * Constructor.
     *
     * @param string $era ERA_MODERN or ERA_LEGACY.
     * @param string $protocolversion Negotiated protocol version.
     * @param stdClass|null $user Authenticated user (null only for unauthenticated server/discover).
     * @param context|null $restrictedcontext Token context restriction.
     * @param int|null $serviceid Connector/external service id.
     * @param bool $connector Whether this is a plugin connector credential (wrappers, files, prompts allowed).
     * @param string $connectormode Connector mode label for discovery.
     * @param array $clientcapabilities Client capabilities declared for this request (modern) or at initialize (legacy).
     * @param string|null $credentialid Credential family id (stable across token refresh), used to bind file tickets.
     * @param \Closure|null $scopecheck fn(bool $write): void, throws when the OAuth scope is insufficient.
     */
    public function __construct(
        /** @var string ERA_MODERN or ERA_LEGACY. */
        public string $era,
        /** @var string Negotiated protocol version. */
        public string $protocolversion,
        /** @var stdClass|null Authenticated user (null only for unauthenticated server/discover). */
        public ?stdClass $user = null,
        /** @var context|null Token context restriction. */
        public ?context $restrictedcontext = null,
        /** @var int|null Connector/external service id. */
        public ?int $serviceid = null,
        /** @var bool Whether this is a plugin connector credential (wrappers, files, prompts allowed). */
        public bool $connector = false,
        /** @var string Connector mode label for discovery. */
        public string $connectormode = 'external_token',
        /** @var array Client capabilities declared for this request (modern) or at initialize (legacy). */
        public array $clientcapabilities = [],
        /** @var string|null Credential family id (stable across token refresh), used to bind file tickets. */
        public ?string $credentialid = null,
        /** @var \Closure|null fn(bool $write): void, throws when the OAuth scope is insufficient. */
        public ?\Closure $scopecheck = null,
    ) {
    }

    /**
     * Throw when the current token lacks the scope for a read or write.
     *
     * @param bool $write Whether write scope is needed.
     * @return void
     */
    public function require_scope(bool $write): void {
        if ($this->scopecheck !== null) {
            ($this->scopecheck)($write);
        }
    }

    /**
     * Whether the client declared form elicitation support.
     *
     * @return bool
     */
    public function supports_form_elicitation(): bool {
        if (!array_key_exists('elicitation', $this->clientcapabilities)) {
            return false;
        }
        $elicitation = (array)$this->clientcapabilities['elicitation'];
        // An empty elicitation object means form-only support.
        return $elicitation === [] || array_key_exists('form', $elicitation);
    }
}
