---
phase: 02-remote-transport-session-isolation
plan: 02
subsystem: transport-http
tags: [moodle, mcp, http, streamable-http, session]
requires:
  - phase: 02-01
    provides: transport state stores and Origin validation
provides:
  - Dedicated Streamable HTTP transport controller
  - MCP header validation and session-id lifecycle
  - Primary entrypoint handoff away from the legacy monolith
affects: [transport, auth, discovery]
tech-stack:
  added: [transport protocol header helper, transport server orchestration]
  patterns: [preflight before auth, post-auth write_close, session-bound transport]
key-files:
  created:
    - classes/local/transport/protocol_headers.php
    - classes/local/transport/server.php
    - tests/transport_server_test.php
  modified:
    - server.php
    - classes/local/server.php
    - classes/local/tool_provider.php
    - classes/local/stream/session_store.php
    - classes/local/stream/replay_store.php
    - db/caches.php
key-decisions:
  - "Primary HTTP transport is stateful and issues MCP session ids on initialize."
  - "Plugin credentials remain the public contract while the plugin remains authoritative for auth and execution."
patterns-established:
  - "transport server validates headers before execution and binds sessions to token/user/context/service"
  - "JSON-RPC control responses are recorded into replay state for compatibility consumers"
requirements-completed: [TRAN-01, TRAN-03, TRAN-04, TRAN-05]
duration: 86min
completed: 2026-04-21
---

# Phase 2: Remote Transport & Session Isolation Summary

**Wave 2 primary Streamable HTTP transport**

## Performance

- **Duration:** 86 min
- **Started:** 2026-04-21T15:27:00Z
- **Completed:** 2026-04-21T16:53:00Z
- **Tasks:** 3
- **Files modified:** 9

## Accomplishments

- Replaced the main `server.php` entrypoint with a dedicated transport controller that handles OPTIONS before auth, validates MCP transport headers, and enforces plugin-owned session ids.
- Added post-auth `\core\session\manager::write_close()` handling so transport requests no longer need to hold the Moodle session lock through wait-heavy paths.
- Extended tool discovery to operate on resolved service ids, making the new transport controller independent from raw external-token lookup for its primary tool-list path.

## Task Commits

No git commits were created because planning/execution artifacts remain local-only for this repository.

## Files Created/Modified

- `classes/local/transport/protocol_headers.php` - Validates `MCP-Protocol-Version`, `MCP-Session-Id`, `Mcp-Method`, and `Mcp-Name`.
- `classes/local/transport/server.php` - Implements the primary Streamable HTTP transport lifecycle.
- `server.php` - Delegates the endpoint to the new transport server.
- `classes/local/server.php` - Keeps the legacy JSON-RPC shell reusable while fixing JSON-RPC error framing for subclass consumers.
- `classes/local/tool_provider.php` - Adds service-id scoped tool discovery used by the transport controller.
- `classes/local/stream/session_store.php` - Honors transport-session TTL directly in the store layer.
- `classes/local/stream/replay_store.php` - Honors replay TTL directly in the store layer.
- `db/caches.php` - Defers expiry authority to the transport state stores instead of fixed cache ttl.
- `tests/transport_server_test.php` - Covers preflight ordering, header validation, session issuance, and write-close behavior.

## Decisions Made

- The primary endpoint does not overload GET with legacy behavior; GET remains out-of-contract for the main HTTP path while SSE is handled separately.
- Transport sessions are bound to the caller token, user, context, service, and negotiated protocol version, not just an opaque id.

## Deviations from Plan

- `tool_provider.php` needed a service-id based discovery entrypoint so the new transport flow could remain plugin-auth authoritative without depending on raw external-token lookup.

## Issues Encountered

- Docker-backed `moodle-plugin-ci` bootstrap succeeded, but no installed Moodle CI test site/database was available yet for real PHPUnit execution.

## User Setup Required

- The configured connector service identifier must map to an enabled Moodle external service for live transport use.

## Next Phase Readiness

- The primary transport now has a replay/session foundation that the legacy SSE compatibility adapter can reuse directly.

---
*Phase: 02-remote-transport-session-isolation*
*Completed: 2026-04-21*
