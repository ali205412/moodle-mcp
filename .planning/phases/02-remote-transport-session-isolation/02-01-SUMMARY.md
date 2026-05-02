---
phase: 02-remote-transport-session-isolation
plan: 01
subsystem: transport-state
tags: [moodle, transport, cors, cache, session, replay]
requires:
  - phase: 01
    provides: connector credentials and transport identity baseline
provides:
  - Explicit Origin allowlist settings
  - Plugin-owned transport session and replay stores
  - Cache definitions for transport state
affects: [transport, settings, cache]
tech-stack:
  added: [MUC application caches, transport settings, origin validator]
  patterns: [plugin-owned transport state, explicit Origin validation]
key-files:
  created:
    - db/caches.php
    - classes/local/transport/origin_validator.php
    - classes/local/stream/session_store.php
    - classes/local/stream/replay_store.php
    - tests/transport_state_test.php
  modified:
    - settings.php
    - lang/en/webservice_mcp.php
key-decisions:
  - "Transport session and replay state stays plugin-owned from the start."
  - "Browser-facing transport uses explicit Origin allowlisting instead of wildcard CORS."
patterns-established:
  - "session_store owns MCP session metadata with TTL-aware expiry"
  - "replay_store buffers transport responses for compatibility replays"
requirements-completed: []
duration: 19min
completed: 2026-04-21
---

# Phase 2: Remote Transport & Session Isolation Summary

**Wave 1 transport state and browser policy foundations**

## Performance

- **Duration:** 19 min
- **Started:** 2026-04-21T15:07:00Z
- **Completed:** 2026-04-21T15:26:00Z
- **Tasks:** 3
- **Files modified:** 7

## Accomplishments

- Added admin settings for allowed Origins, legacy SSE compatibility, transport session TTL, and replay TTL.
- Added plugin-owned MUC-backed session and replay stores with transport-focused tests.
- Replaced wildcard transport policy assumptions with an explicit Origin validator that is reusable across primary HTTP and SSE endpoints.

## Task Commits

No git commits were created because planning/execution artifacts remain local-only for this repository.

## Files Created/Modified

- `db/caches.php` - Defines plugin-owned cache buckets for MCP transport session and replay state.
- `classes/local/transport/origin_validator.php` - Validates browser Origins against same-site and explicit allowlist rules.
- `classes/local/stream/session_store.php` - Stores MCP session metadata with touch/delete lifecycle.
- `classes/local/stream/replay_store.php` - Stores replayable transport events for reconnect and compatibility flows.
- `tests/transport_state_test.php` - Covers origin policy and state-store lifecycle behavior.
- `settings.php` - Adds transport Origin/SSE/session/replay settings.
- `lang/en/webservice_mcp.php` - Adds transport settings strings.

## Decisions Made

- Session and replay state is plugin-owned, not delegated to a companion edge or process-local memory.
- Origin validation is explicit and configurable rather than implied by permissive wildcard headers.

## Deviations from Plan

- Cache TTL handling was strengthened beyond the initial static cache definitions so transport TTL settings can be honored by the store layer.

## Issues Encountered

- None during source implementation. Live remote-client validation was deferred to phase-level verification.

## User Setup Required

- Sites that want browser-origin access must populate the transport Origin allowlist appropriately.

## Next Phase Readiness

- Wave 2 can build the primary Streamable HTTP endpoint directly on the new Origin/session/replay primitives.

---
*Phase: 02-remote-transport-session-isolation*
*Completed: 2026-04-21*
