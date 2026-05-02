# Phase 1: Identity Bootstrap & Connector Credentials - Context

**Gathered:** 2026-04-21
**Status:** Ready for planning

<domain>
## Phase Boundary

This phase establishes how a real Moodle user begins connector access, completes Moodle-native login or SSO bootstrap, and receives revocable connector credentials without manually creating a permanent web service token. It also fixes the initial trust boundary between browser-session bootstrap, token-authenticated MCP entrypoints, and any early companion-service seam included for remote-client support.

</domain>

<decisions>
## Implementation Decisions

### Identity Bootstrap Path
- **D-01:** Add a dedicated plugin bootstrap page such as `launch.php` or `connect.php` for interactive connector auth instead of reusing `server.php` as the login entrypoint.
- **D-02:** Let Moodle's normal login flow and enabled auth plugins own SSO behavior entirely; the connector should redirect into Moodle-native login and return from there.
- **D-03:** Require a logged-in user plus `webservice/mcp:use`, then apply connector-specific eligibility checks before issuing connector access.
- **D-04:** Bootstrap should exchange the authenticated browser session for a connector-managed credential contract rather than handing out a raw permanent Moodle web service token by default.

### Connector Credential Model
- **D-05:** Use a plugin-managed connector credential model, with optional internal use of Moodle token primitives where useful, instead of exposing raw `external_tokens` as the product contract.
- **D-06:** Interactive bootstrap credentials should be session-linked or short-lived by default.
- **D-07:** Durable remote access should require an explicit second-step grant for remote clients rather than being minted automatically on every successful web login.
- **D-08:** Provide plugin-owned inspection and revocation surfaces for connector access, even if some underlying data or lifecycle hooks reuse Moodle token infrastructure.

### Initial Trust Boundary
- **D-09:** Anchor Phase 1 credentials to a dedicated connector-managed service boundary rather than a site-global unrestricted token.
- **D-10:** Use system-context bootstrap initially, with narrower context gating deferred to later discovery and invocation phases.
- **D-11:** Include the companion-service seam in Phase 1 architecture and planning, but keep the Moodle plugin authoritative for auth, discovery, and execution.
- **D-12:** Follow Moodle's own browser-login and OAuth redirect patterns for SSO handoff instead of inventing a custom remote auth protocol first.

### the agent's Discretion
- The exact connector credential storage schema, naming, and revocation UX details are open as long as they preserve the decisions above.
- The exact shape of the Phase 1 companion seam is flexible as long as it remains transport-edge only and does not become the authority for Moodle permissions or tool discovery.

</decisions>

<specifics>
## Specific Ideas

- Be careful with SSO-heavy Moodle sites; the connector must work when users authenticate through Moodle-managed SSO providers instead of local passwords.
- The connector should feel like "if you are already logged into Moodle, it just works" for bootstrap where site policy allows it.
- The project should preserve a plugin-first path while still planning for real remote-client expectations such as Claude Code connector support.

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project intent and locked scope
- `.planning/PROJECT.md` — project constraints, source-of-truth rule, and plugin-first posture for the Moodle MCP connector.
- `.planning/REQUIREMENTS.md` — Phase 1 requirements `AUTH-01` through `AUTH-05` and the compatibility/security boundaries that planning must satisfy.
- `.planning/ROADMAP.md` — Phase 1 goal, dependencies, and success criteria.
- `.planning/STATE.md` — current blockers and explicit rule that Moodle-dependent decisions must inspect `tmp/moodle`.
- `.planning/research/SUMMARY.md` — research synthesis identifying auth boundary design as an early-phase risk and plugin-first recommendation.
- `.planning/research/STACK.md` — recommended stack for auth bootstrap, token handling, and when a companion edge is justified.
- `.planning/research/ARCHITECTURE.md` — proposed two-path auth architecture, connector credential issuer, and edge-only companion seam.

### Current plugin baseline
- `server.php` — current MCP entrypoint is a `WS_SERVER` using permanent-token auth only.
- `classes/local/server.php` — current plugin transport/auth behavior and limitation to token-based MCP handling.
- `db/access.php` — defines the `webservice/mcp:use` capability that the connector bootstrap must respect.

### Moodle auth, session, and token source of truth
- `tmp/moodle/webservice/lib.php` — core WS auth behavior, token authentication, protocol capability checks, restricted context/service handling, and the `NO_MOODLE_COOKIES` requirement for WS servers.
- `tmp/moodle/lib/external/classes/util.php` — token generation and current-user token issuance behavior, including embedded token `sid` binding, `externalserviceid`, `contextid`, `validuntil`, and `iprestriction`.
- `tmp/moodle/lib/moodlelib.php` — `require_login()` behavior and normal Moodle login/session flow expectations.
- `tmp/moodle/login/index.php` — core login page behavior and standard web login flow.
- `tmp/moodle/auth/oauth2/login.php` — Moodle-native OAuth login redirect flow for SSO providers.
- `tmp/moodle/admin/tool/mobile/launch.php` — precedent for browser login + optional OAuth SSO handoff + token issuance after login.
- `tmp/moodle/lib/classes/session/manager.php` — session behavior, `NO_MOODLE_COOKIES` implications, and `write_close()` handling for session-safe follow-on transport work.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `server.php` provides the existing protocol entrypoint and protocol-enablement guard that later transport work must extend rather than replace blindly.
- `classes/local/server.php` already adapts `webservice_base_server` for MCP methods and can remain the token-authenticated transport path once bootstrap is split out.
- `db/access.php` already defines `webservice/mcp:use`, which should stay part of the initial connector access gate.

### Established Patterns
- The plugin currently follows Moodle's web service protocol pattern: `WS_SERVER`, no cookies, token extraction, inherited `webservice_base_server` auth, and JSON-RPC wrapping.
- The repo already treats Moodle source as authoritative via `tmp/moodle`, and future Phase 1 planning must continue citing those files explicitly.
- The roadmap and research both favor a plugin-first structure with later harvested catalog and permission logic, so Phase 1 should not overreach into later phase concerns.

### Integration Points
- New interactive bootstrap code should live alongside the plugin entrypoints as dedicated files such as `launch.php` or `connect.php`.
- Phase 1 planning will likely introduce new auth-focused classes under a path like `classes/local/auth/` while leaving `classes/local/server.php` as the MCP transport core.
- Any companion seam added in Phase 1 must integrate above the plugin transport, not around Moodle's authorization logic.

</code_context>

<deferred>
## Deferred Ideas

- Fine-grained course, activity, or object-level context restriction at discovery time — belongs to later permission and catalog phases.
- Broad wrapper coverage for UI-only Moodle actions — belongs to later wrapper-focused phases.
- Full remote OAuth or edge-runtime implementation details beyond the Phase 1 seam and trust-boundary decision — defer until planning confirms the minimum viable Phase 1 shape.

</deferred>

---
*Phase: 01-identity-bootstrap-connector-credentials*
*Context gathered: 2026-04-21*
