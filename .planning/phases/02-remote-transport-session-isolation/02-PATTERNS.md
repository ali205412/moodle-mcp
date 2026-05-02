# Phase 02: Remote Transport & Session Isolation - Pattern Map

**Mapped:** 2026-04-21
**Files analyzed:** 8
**Analogs found:** 8 / 8

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `db/caches.php` | config | request-response | `tmp/moodle/cache/stores/file/lib.php` and Moodle cache definitions | partial |
| `classes/local/transport/server.php` | controller | request-response / streaming | `classes/local/server.php` | exact |
| `classes/local/transport/protocol_headers.php` | utility | request-response | `tmp/moodle/lib/external/classes/external_api.php` metadata handling | partial |
| `classes/local/transport/origin_validator.php` | middleware/service | request-response | `tmp/moodle/lib/classes/router/middleware/cors_middleware.php` | role-match |
| `classes/local/stream/session_store.php` | service | streaming / request-response | `classes/local/auth/credential_manager.php` | role-match |
| `classes/local/stream/replay_store.php` | service | streaming | `classes/local/auth/credential_manager.php` | flow-match |
| `sse.php` | controller | streaming | `launch.php` plus `server.php` | partial |
| `tests/transport_server_test.php` / `tests/transport_state_test.php` | test | request-response / streaming | `tests/server_test.php` | exact |

## Pattern Assignments

### `classes/local/transport/server.php` (controller, request-response / streaming)

**Analog:** `classes/local/server.php`

**Use this pattern for:**
- request lifecycle orchestration
- parse/auth/dispatch flow
- JSON-RPC payload shaping
- compatibility inheritance from the existing transport shell

**What to copy:**
- Constructor and `wsname` setup style
- request parsing and MCP method branching patterns
- centralized header/error helpers

**What to change deliberately:**
- preflight must be handled before auth
- Origin handling must move out of wildcard headers
- session-id and protocol-header handling must become first-class
- long-lived requests must release the Moodle session lock

### `classes/local/transport/origin_validator.php` (middleware/service, request-response)

**Analogs:** `classes/local/server.php`, `tmp/moodle/lib/classes/router/middleware/cors_middleware.php`

**Use this pattern for:**
- centralizing CORS allowlist logic
- separating policy from raw header emission

**What to copy:**
- response-header composition from router middleware
- simple request inspection style from current plugin transport

**What to avoid:**
- unconditional `Access-Control-Allow-Origin: *`
- hardcoding JSON response behavior into all transport modes

### `classes/local/transport/protocol_headers.php` (utility, request-response)

**Analogs:** `tmp/moodle/lib/external/classes/external_api.php`, `classes/local/request.php`

**Use this pattern for:**
- parsing and validating transport headers like `MCP-Protocol-Version`, `MCP-Session-Id`, `Mcp-Method`, and `Mcp-Name`
- keeping transport validation separate from business/auth logic

**What to copy:**
- small focused validation helpers from `classes/local/request.php`
- explicit field checking style from Moodle's external metadata handling

### `classes/local/stream/session_store.php` (service, streaming / request-response)

**Analog:** `classes/local/auth/credential_manager.php`

**Use this pattern for:**
- MUC-backed or plugin-managed storage wrappers
- issuing opaque ids
- resolving records by token/id
- deleting or expiring state records cleanly

**Why this is the closest analog:**
- credential manager already wraps a lower-level storage contract into plugin-owned semantics, which is the same architectural move transport session state needs

### `classes/local/stream/replay_store.php` (service, streaming)

**Analog:** `classes/local/auth/credential_manager.php`

**Use this pattern for:**
- append/get/prune-style lifecycle around replay events
- isolating state policy from transport controller logic

**Planning note:**
- Phase 2 can keep replay minimal, but the abstraction should exist now so later reconnect logic does not require transport rewrites

### `db/caches.php` (config, request-response)

**Analogs:** Moodle cache plugin/store config files and the architecture recommendation in `.planning/research/ARCHITECTURE.md`

**Use this pattern for:**
- declaring cache definitions for transport session and replay state
- keeping cache names/versioning explicit and plugin-scoped

**What to include:**
- one cache for MCP session metadata
- one cache for replay/event buffer data

### `sse.php` (controller, streaming)

**Analogs:** `server.php`, `launch.php`

**Use this pattern for:**
- a small dedicated entrypoint like the existing plugin scripts
- wiring into transport services rather than carrying large logic in the script file itself

**What to avoid:**
- reimplementing auth logic separately from the transport server/services
- mixing browser bootstrap behavior into the compatibility endpoint

### `tests/transport_server_test.php` / `tests/transport_state_test.php` (test, request-response / streaming)

**Analog:** `tests/server_test.php`

**Use this pattern for:**
- `advanced_testcase` style
- reflection-based testing where transport helpers are not yet public
- focused method-level assertions rather than full environment bootstraps

**What to add beyond current tests:**
- preflight-before-auth behavior
- Origin allow/deny behavior
- session-id issuance/validation behavior
- transport-side `write_close()` flow where testable

## Shared Patterns

### Split Entrypoint Discipline
- `launch.php` remains browser/session bootstrap.
- `server.php` and `sse.php` remain transport entrypoints.
- Heavy transport logic belongs in `classes/local/transport/*` and `classes/local/stream/*`, not in script files.

### Moodle Source Authority
- Use `tmp/moodle` for transport/session behavior, not generic web examples.
- Router middleware is informative for CORS behavior, but not a transport baseline because this plugin must stay 4.2+ compatible.

### State Wrapper Pattern
- Keep raw storage/cache interactions behind plugin-owned service classes.
- Controllers should delegate to state services rather than building ad hoc arrays inline.

## Recommended Read Order For Planner

1. `02-CONTEXT.md`
2. `02-RESEARCH.md`
3. `classes/local/server.php`
4. `server.php`
5. `launch.php`
6. `classes/local/auth/transport_identity.php`
7. `tmp/moodle/webservice/lib.php`
8. `tmp/moodle/lib/classes/session/manager.php`
9. `tmp/moodle/lib/classes/router/middleware/cors_middleware.php`
10. `tests/server_test.php`

## Notes For Planning

- The planner should bias toward additive transport classes first, then narrow edits to `classes/local/server.php` and `server.php`.
- Preflight, Origin validation, protocol headers, and session-id handling should be separated into distinct tasks so they can be verified independently.
- Replay support can be thin in Phase 2, but the abstraction should still be introduced now.

---
*Phase: 02-remote-transport-session-isolation*
*Pattern mapping gathered: 2026-04-21*
