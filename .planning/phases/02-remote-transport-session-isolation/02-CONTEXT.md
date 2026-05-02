# Phase 2: Remote Transport & Session Isolation - Context

**Gathered:** 2026-04-21
**Status:** Ready for planning

<domain>
## Phase Boundary

This phase upgrades the MCP transport layer so authenticated clients can connect over supported HTTPS transports without corrupting Moodle browsing sessions. It defines the primary Streamable HTTP path, the optional SSE compatibility path, the browser-safe CORS and preflight boundary, and the initial transport/session-state architecture that future phases will build on.

</domain>

<decisions>
## Implementation Decisions

### Transport Surface
- **D-01:** Keep plugin-script transport as the baseline and evolve the MCP transport behind the existing plugin entrypoint plus new dedicated transport classes rather than depending on 4.5-only routing.
- **D-02:** Make Streamable HTTP the primary contract on the plugin side, with explicit handling for protocol headers and transport session ids.
- **D-03:** Add SSE only as a compatibility adapter with separate endpoint semantics instead of overloading the same path and rules as the primary transport.
- **D-04:** The Moodle plugin remains authoritative for auth and execution; transport classes may call the companion seam, but the edge must not become the source of truth.

### Session Isolation and Browser Safety
- **D-05:** Authenticate first, then explicitly release the Moodle session write lock with `\core\session\manager::write_close()` before long-lived stream or wait-heavy transport work.
- **D-06:** Model truly read-only transport/invocation paths deliberately so later external calls can benefit from Moodle’s `readonlysession` behavior where appropriate; do not assume everything is read-only by default.
- **D-07:** Replace wildcard CORS with explicit Origin validation and allowlisting for browser-facing flows while preserving safe unauthenticated preflight handling.
- **D-08:** OPTIONS preflight must succeed before auth, and auth should apply only to real transport requests after browser negotiation.

### Runtime State and Code Boundary
- **D-09:** Keep MCP session and replay state in plugin-managed transport/stream state first; the companion seam may proxy or mirror later, but it must not own the source of truth.
- **D-10:** Split transport/session logic into `classes/local/transport/*` and `classes/local/stream/*`, leaving `classes/local/server.php` as legacy/token transport compatibility until it can be replaced cleanly.
- **D-11:** Do not use `route\\api` for primary transport in a 4.2+ project; reserve it only for optional JSON-only helper endpoints on 4.5+ if they become useful later.
- **D-12:** The companion seam may assist with transport/session replay concerns in Phase 2, but it must stay a transport-edge seam and never become the authority for auth, discovery, or execution.

### the agent's Discretion
- Exact session-id format, replay-buffer structure, and transport helper class names are open as long as they preserve the decisions above.
- The precise split between `classes/local/server.php` compatibility code and new transport/stream classes is flexible, but new work should trend toward dedicated transport modules rather than adding more monolith logic.

</decisions>

<specifics>
## Specific Ideas

- HTTP transport should be the path Claude Code and similar remote clients use first.
- SSE is still wanted, but only as compatibility, not as the center of the design.
- Preflight/CORS handling must stop breaking browser usage, and long-lived transport requests must not hold the Moodle session lock open.
- Transport state should stay plugin-owned first, even though the companion seam exists in the architecture.

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project and phase state
- `.planning/PROJECT.md` — project constraints, plugin-first posture, and source-of-truth rule.
- `.planning/REQUIREMENTS.md` — Phase 2 requirements `TRAN-01` through `TRAN-05`.
- `.planning/ROADMAP.md` — Phase 2 goal, dependency on Phase 1, and success criteria.
- `.planning/STATE.md` — current position after Phase 1 and the standing Moodle-source verification rule.

### Prior phase outputs that constrain transport work
- `.planning/phases/01-identity-bootstrap-connector-credentials/01-CONTEXT.md` — locked auth/bootstrap decisions that Phase 2 must preserve.
- `.planning/phases/01-identity-bootstrap-connector-credentials/01-RESEARCH.md` — Phase 1 transport/auth boundary research.
- `.planning/phases/01-identity-bootstrap-connector-credentials/01-01-SUMMARY.md` — credential manager and persistence outputs Phase 2 should reuse.
- `.planning/phases/01-identity-bootstrap-connector-credentials/01-02-SUMMARY.md` — bootstrap entrypoint and OAuth bridge outputs Phase 2 should preserve.
- `.planning/phases/01-identity-bootstrap-connector-credentials/01-03-SUMMARY.md` — transport identity and companion seam baseline.
- `.planning/phases/01-identity-bootstrap-connector-credentials/01-VERIFICATION.md` — deferred human validation items that Phase 2 must not invalidate.

### Current plugin files to evolve
- `server.php` — current MCP entrypoint and existing `WS_SERVER` token transport.
- `classes/local/server.php` — current MCP request routing, current CORS headers, and current auth ordering.
- `launch.php` — browser bootstrap path added in Phase 1; transport work must stay separate from it.
- `classes/local/auth/transport_identity.php` — plugin-owned credential-to-runtime identity adapter from Phase 1.
- `classes/local/auth/companion_contract.php` — non-authoritative companion seam contract from Phase 1.
- `settings.php` — connector config surface that can be extended for Phase 2 transport settings.

### Moodle source of truth for transport/session behavior
- `tmp/moodle/webservice/lib.php` — WS auth boundary, token auth, restricted context/service behavior, and `NO_MOODLE_COOKIES` rules.
- `tmp/moodle/lib/classes/session/manager.php` — session lock handling and `write_close()` semantics.
- `tmp/moodle/lib/external/classes/external_api.php` — `readonlysession`, `loginrequired`, and external call behavior.
- `tmp/moodle/lib/classes/router/route_loader_interface.php` — 4.5 routing group boundary.
- `tmp/moodle/lib/classes/router/middleware/cors_middleware.php` — current route CORS behavior and its JSON bias.
- `tmp/moodle/lib/classes/router/middleware/moodle_bootstrap_middleware.php` — routing/bootstrap and cookie behavior on 4.5 routes.

### Research syntheses for transport planning
- `.planning/research/STACK.md` — transport recommendations, plugin-script baseline, and routing cautions.
- `.planning/research/ARCHITECTURE.md` — transport/stream manager, cache boundaries, and companion seam role.
- `.planning/research/PITFALLS.md` — transport/CORS/session failure modes to guard against.
- `.planning/research/SUMMARY.md` — roadmap-level transport conclusions.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `classes/local/server.php` already centralizes request parsing and MCP method routing, so it can act as the legacy compatibility shell while transport classes are split out.
- `launch.php` and Phase 1 auth services already separate browser bootstrap from token transport, which Phase 2 must preserve.
- `classes/local/auth/transport_identity.php` provides a starting point for plugin-side transport credential resolution.

### Established Patterns
- The project is explicitly split into browser bootstrap and token transport after Phase 1; Phase 2 must not collapse those boundaries again.
- The repo requires every Moodle-dependent decision to be grounded in `tmp/moodle`.
- The current plugin still has permissive wildcard CORS and token-first method flow in `classes/local/server.php`, so the Phase 2 plan should treat those as concrete migration targets.

### Integration Points
- New transport classes should live under `classes/local/transport/`.
- New transport/session state helpers should live under `classes/local/stream/`.
- Transport/session caches will likely need `db/caches.php`.
- `server.php` should become a thinner handoff into transport code, not a growing pile of transport logic.

</code_context>

<deferred>
## Deferred Ideas

- Catalog harvesting, tool provenance, and tool-list pagination belong to Phase 3.
- Fine-grained permission visibility and risk signaling belong to Phase 4.
- Broad companion-edge runtime ownership is explicitly out of scope; only a transport-edge seam is allowed here.

</deferred>

---
*Phase: 02-remote-transport-session-isolation*
*Context gathered: 2026-04-21*
