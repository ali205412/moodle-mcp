# Phase 2: Remote Transport & Session Isolation - Research

**Researched:** 2026-04-21
**Domain:** Streamable HTTP transport, SSE compatibility, browser-safe CORS/preflight handling, and session isolation for Moodle MCP
**Confidence:** HIGH

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- Keep plugin-script transport as the baseline and evolve the MCP transport behind the existing plugin entrypoint plus new dedicated transport classes rather than depending on 4.5-only routing.
- Make Streamable HTTP the primary contract on the plugin side, with explicit handling for protocol headers and transport session ids.
- Add SSE only as a compatibility adapter with separate endpoint semantics instead of overloading the same path and rules as the primary transport.
- The Moodle plugin remains authoritative for auth and execution; transport classes may call the companion seam, but the edge must not become the source of truth.
- Authenticate first, then explicitly release the Moodle session write lock with `\core\session\manager::write_close()` before long-lived stream or wait-heavy transport work.
- Model truly read-only transport/invocation paths deliberately so later external calls can benefit from Moodle’s `readonlysession` behavior where appropriate; do not assume everything is read-only by default.
- Replace wildcard CORS with explicit Origin validation and allowlisting for browser-facing flows while preserving safe unauthenticated preflight handling.
- OPTIONS preflight must succeed before auth, and auth should apply only to real transport requests after browser negotiation.
- Keep MCP session and replay state in plugin-managed transport/stream state first; the companion seam may proxy or mirror later, but it must not own the source of truth.
- Split transport/session logic into `classes/local/transport/*` and `classes/local/stream/*`, leaving `classes/local/server.php` as legacy/token transport compatibility until it can be replaced cleanly.
- Do not use `route\api` for primary transport in a 4.2+ project; reserve it only for optional JSON-only helper endpoints on 4.5+ if they become useful later.
- The companion seam may assist with transport/session replay concerns in Phase 2, but it must stay a transport-edge seam and never become the authority for auth, discovery, or execution.

### the agent's Discretion
- Exact session-id format, replay-buffer structure, and transport helper class names are open as long as they preserve the decisions above.
- The precise split between `classes/local/server.php` compatibility code and new transport/stream classes is flexible, but new work should trend toward dedicated transport modules rather than adding more monolith logic.

### Deferred Ideas (OUT OF SCOPE)
- Catalog harvesting, tool provenance, and tool-list pagination.
- Fine-grained permission visibility and risk signaling.
- Broad companion-edge runtime ownership.

</user_constraints>

<project_constraints>
## Project Constraints (from AGENTS.md)

- Prefix repo shell commands with `rtk`.
- Treat `tmp/moodle` as the primary source of truth for Moodle behavior.
- Before planning or implementing Moodle-dependent behavior, inspect the relevant local Moodle source files directly.
- Cite the concrete Moodle source files checked alongside local plugin files when making implementation decisions.
- Call out version-specific behavior instead of assuming the Moodle 4.5 tree applies unchanged to all supported versions.

</project_constraints>

<architectural_responsibility_map>
## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Streamable HTTP request/response handling | API/Backend | Frontend Server | The plugin endpoint must own request parsing, header validation, session id issuance, and response shaping. |
| Browser-safe CORS and preflight handling | API/Backend | Frontend Server | The transport endpoint must negotiate preflight and validate Origin before real auth. |
| MCP session and replay state | API/Backend | Database/Storage | Session/replay state belongs in plugin-managed caches/state first, not in an external edge as the source of truth. |
| Legacy SSE compatibility | API/Backend | Frontend Server | SSE can be exposed as a compatibility adapter around plugin-owned transport/session state. |
| Companion seam for replay or fanout assistance | Frontend Server | API/Backend | The seam may proxy transport/session concerns, but must not own auth, discovery, or execution authority. |

</architectural_responsibility_map>

<research_summary>
## Summary

The current transport contract is behind the spec and the client expectations. The draft MCP Streamable HTTP transport allows stateful sessions via `MCP-Session-Id`, requires that clients resend that header on subsequent requests when the server uses sessions, and defines the error behavior for missing or invalid session ids. The same transport also requires `MCP-Protocol-Version` on subsequent HTTP requests and `Mcp-Method` / `Mcp-Name` request headers on relevant POST requests. [CITED: https://modelcontextprotocol.io/specification/draft/basic/transports]

Claude Code’s current remote MCP guidance recommends HTTP for remote servers and explicitly marks remote SSE as deprecated, while still supporting it where needed. Anthropic’s MCP connector docs likewise require a publicly exposed HTTP server and support both Streamable HTTP and SSE transports, but currently only use tool calls from the broader MCP feature set. [CITED: https://code.claude.com/docs/en/mcp] [CITED: https://platform.claude.com/docs/en/agents-and-tools/mcp-connector]

On the Moodle side, Phase 2 must preserve the hard boundary that webservice servers run with `NO_MOODLE_COOKIES`, while browser bootstrap continues through normal page/session flow. The current plugin already has the right separation after Phase 1, but its transport implementation still authenticates before preflight handling, still uses wildcard CORS, and still keeps transport/session logic in one class. The right next step is to introduce transport and stream helper classes, plugin-managed transport state, and an explicit Origin/session header contract without moving authority out of Moodle. [VERIFIED: /home/yui/Documents/moodle-mcp/classes/local/server.php] [VERIFIED: /home/yui/Documents/moodle-mcp/tmp/moodle/webservice/lib.php] [VERIFIED: /home/yui/Documents/moodle-mcp/tmp/moodle/lib/classes/session/manager.php]

**Primary recommendation:** Implement a plugin-side Streamable HTTP transport layer with explicit `MCP-Session-Id` handling, safe unauthenticated OPTIONS preflight, explicit Origin allowlisting, post-auth `write_close()` for long-lived transport work, and a separate legacy SSE compatibility adapter.
</research_summary>

<standard_stack>
## Standard Stack

### Core
| Library / API | Version | Purpose | Why Standard |
|---------------|---------|---------|--------------|
| Plugin-script HTTP transport endpoints | Moodle 4.2+ | Primary transport entrypoints | The project must support 4.2+, while Moodle routing is only available since 4.5 and its API middleware is JSON-centric.[VERIFIED: /home/yui/Documents/moodle-mcp/tmp/moodle/lib/classes/router/route_loader_interface.php][VERIFIED: /home/yui/Documents/moodle-mcp/tmp/moodle/lib/classes/router/middleware/cors_middleware.php] |
| `\core\session\manager::write_close()` | Moodle 4.2+ | Release session lock after auth | Moodle explicitly documents `write_close()` as the mechanism to unblock parallel requests and avoid holding the session open unnecessarily.[VERIFIED: /home/yui/Documents/moodle-mcp/tmp/moodle/lib/classes/session/manager.php#L699-L759] |
| Plugin-managed transport/session store using MUC | Moodle 4.2+ | Session metadata and optional replay state | MUC is the stable Moodle-side storage boundary for application/request/session cache definitions in a 4.2+ plugin.[VERIFIED: /home/yui/Documents/moodle-mcp/.planning/research/ARCHITECTURE.md] |
| Streamable HTTP session headers (`MCP-Session-Id`, `MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name`) | Current MCP draft | Transport compliance | These headers are part of the current Streamable HTTP contract and affect request validation and client compatibility.[CITED: https://modelcontextprotocol.io/specification/draft/basic/transports] |

### Supporting
| Library / API | Version | Purpose | When to Use |
|---------------|---------|---------|-------------|
| `readonlysession` metadata on external functions | Moodle 4.2+ | Mark truly read-only external calls | Use when later transport/invocation code can safely avoid write-lock semantics on external calls.[VERIFIED: /home/yui/Documents/moodle-mcp/tmp/moodle/lib/external/classes/external_api.php#L146-L160] |
| Explicit Origin allowlist config | Current repo | Browser-safe remote use | Use on transport endpoints instead of wildcard CORS to avoid the current unsafe default.[VERIFIED: /home/yui/Documents/moodle-mcp/classes/local/server.php#L425-L434] |
| Optional 4.5+ `route\api` helper endpoints | Moodle 4.5+ only | JSON-only helper endpoints | Use only if a later helper endpoint is clearly JSON-only and not the transport baseline.[VERIFIED: /home/yui/Documents/moodle-mcp/tmp/moodle/lib/classes/router/route_loader_interface.php] |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Plugin-script Streamable HTTP baseline | `route\api` transport endpoints | Rejected because routing is 4.5+ only and the CORS middleware forces `Content-Type: application/json`, which is a poor fit for SSE or mixed transport behavior.[VERIFIED: /home/yui/Documents/moodle-mcp/tmp/moodle/lib/classes/router/middleware/cors_middleware.php] |
| Explicit plugin session state | In-process ephemeral state only | Simpler, but weaker for reconnect/resume semantics and harder to evolve toward companion-assisted replay. |
| SSE compatibility adapter | SSE as the primary transport | Rejected because Claude Code recommends HTTP and deprecates remote SSE where HTTP exists.[CITED: https://code.claude.com/docs/en/mcp] |

</standard_stack>

<architecture_patterns>
## Architecture Patterns

### Recommended Project Structure
```text
classes/
├── local/
│   ├── transport/
│   │   ├── server.php            # Streamable HTTP transport orchestration
│   │   ├── origin_validator.php  # Explicit Origin allowlist handling
│   │   └── protocol_headers.php  # MCP transport header parsing/validation
│   ├── stream/
│   │   ├── session_store.php     # Plugin-managed MCP session state
│   │   └── replay_store.php      # Optional replay/event buffer abstraction
│   └── auth/
│       └── transport_identity.php
├── local/server.php              # Legacy/token transport compatibility shell
└── ...
db/
├── caches.php                    # Session/replay cache definitions
└── ...
server.php                        # Main transport endpoint
sse.php                           # Legacy SSE compatibility endpoint
```

### Pattern 1: Preflight Before Auth
**What:** Handle `OPTIONS` before any auth attempt and only authenticate real transport requests afterward.
**When to use:** Always for browser-facing transport endpoints.
**Example:** The current plugin already special-cases `OPTIONS` in `handle_mcp_method()`, but Phase 2 must move that branch ahead of auth in the request lifecycle to avoid browser failures.[VERIFIED: /home/yui/Documents/moodle-mcp/classes/local/server.php#L200-L205]

### Pattern 2: Stateful Streamable HTTP With Plugin-Owned Session IDs
**What:** Issue `MCP-Session-Id` at initialization, require it on subsequent requests, and let the plugin own the state store.
**When to use:** When reconnect/resume semantics are required, which they are for this phase.
**Example:** The MCP draft allows stateful sessions, 400 on missing session id when required, 404 on invalid session ids after termination, and optional DELETE for termination.[CITED: https://modelcontextprotocol.io/specification/draft/basic/transports]

### Pattern 3: Post-Auth Session Release
**What:** Authenticate, set up runtime identity, then call `\core\session\manager::write_close()` before any long-lived stream or wait-heavy response handling.
**When to use:** For GET/SSE endpoints and any request path that can keep the connection open long enough to block parallel Moodle activity.
**Example:** `write_close()` is the core session manager API for releasing the lock and allowing other scripts to proceed.[VERIFIED: /home/yui/Documents/moodle-mcp/tmp/moodle/lib/classes/session/manager.php#L699-L759]

### Anti-Patterns to Avoid
- **Wildcard CORS on authenticated remote transport:** The current `Access-Control-Allow-Origin: *` posture is not acceptable for browser-facing authenticated transport and should be replaced by explicit validation.[VERIFIED: /home/yui/Documents/moodle-mcp/classes/local/server.php#L425-L434]
- **Authenticating OPTIONS requests:** This causes browser preflight failures and directly matches the transport pitfall identified in project research.[VERIFIED: /home/yui/Documents/moodle-mcp/.planning/research/PITFALLS.md]
- **Keeping all transport logic in `classes/local/server.php`:** Phase 2 is exactly the point where transport/session responsibilities should move into dedicated modules.

</architecture_patterns>

<dont_hand_roll>
## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Session-lock release semantics | Direct `session_write_close()` calls or ad hoc shutdown behavior | `\core\session\manager::write_close()` | Moodle explicitly wraps the session lifecycle and warns against raw PHP session handling.[VERIFIED: /home/yui/Documents/moodle-mcp/tmp/moodle/lib/classes/session/manager.php] |
| Version detection from memory | Hardcoded assumptions about current MCP headers | Verified transport header handling using the current MCP transport spec | Header names and behavior have changed across spec versions and must be checked directly.[CITED: https://modelcontextprotocol.io/specification/draft/basic/transports] |
| Browser-safe CORS policy | Keep `*` or ad hoc header hacks | Explicit Origin validator + allowlist settings | Authenticated browser use needs deterministic allow/deny behavior. |

**Key insight:** Phase 2 is mostly about ordering and boundaries. The existing plugin already has enough MCP structure to evolve; the failure mode is not “missing framework,” it is getting auth/preflight/session/CORS order wrong.
</dont_hand_roll>

<common_pitfalls>
## Common Pitfalls

### Pitfall 1: OPTIONS still runs through auth
**What goes wrong:** Browser preflight fails before the real request is ever sent.
**Why it happens:** The endpoint treats `OPTIONS` as just another MCP request.
**How to avoid:** Short-circuit `OPTIONS` before auth and before JSON-RPC method handling.
**Warning signs:** Browser clients fail while curl succeeds.

### Pitfall 2: Session lock remains open during long-lived transport work
**What goes wrong:** A single remote transport request blocks the user’s normal Moodle browsing session.
**Why it happens:** The code authenticates and then holds the Moodle session open throughout streaming or long waits.
**How to avoid:** Release the write lock with `\core\session\manager::write_close()` once auth and identity setup are complete.
**Warning signs:** Parallel Moodle tabs stall or session-lock debugging warnings appear.

### Pitfall 3: Transport state lives only in process memory
**What goes wrong:** Reconnect or session resumption breaks under restarts or multi-process deployment.
**Why it happens:** Stateful transport is bolted on without a plugin-owned state store.
**How to avoid:** Store session metadata and replay data in plugin-managed caches/state first, even if the first implementation is minimal.
**Warning signs:** Session ids work only inside one worker lifetime or disappear after restart.

</common_pitfalls>

<code_examples>
## Code Examples

### Current plugin CORS hotspot to replace
```php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
```
Source: `classes/local/server.php` [CITED: local source]

### Moodle session release API
```php
public static function write_close() {
    ...
    self::$handler->write_close();
    self::$sessionactive = false;
}
```
Source: `tmp/moodle/lib/classes/session/manager.php` [CITED: local source]

### Streamable HTTP session behavior
```text
1. A server using Streamable HTTP MAY assign a session ID in `MCP-Session-Id`.
2. Clients MUST include `MCP-Session-Id` on subsequent requests if returned.
3. Missing required session id SHOULD yield HTTP 400.
4. Invalid/terminated session id MUST yield HTTP 404.
```
Source: MCP transports spec [CITED: https://modelcontextprotocol.io/specification/draft/basic/transports]

</code_examples>

<sota_updates>
## State of the Art (2024-2025)

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Remote SSE as a normal first-choice transport | Remote HTTP is recommended; SSE is deprecated but still supported for compatibility | Current Claude Code transport guidance | Phase 2 should treat SSE as compatibility only, not as the main path.[CITED: https://code.claude.com/docs/en/mcp] |
| Implicit or unspecified HTTP session behavior | Explicit `MCP-Session-Id`, `MCP-Protocol-Version`, `Mcp-Method`, and `Mcp-Name` handling in Streamable HTTP | Current MCP draft transport contract | Phase 2 planning must include concrete header validation rather than generic “HTTP support.”[CITED: https://modelcontextprotocol.io/specification/draft/basic/transports] |

**New tools/patterns to consider:**
- Plugin-managed session/replay state with MUC-backed storage.
- Separate compatibility endpoint for legacy SSE instead of muddling the primary transport contract.

**Deprecated/outdated:**
- Wildcard CORS plus auth-before-OPTIONS on authenticated remote endpoints.
- Treating remote SSE as the preferred transport for Claude Code when HTTP is available.

</sota_updates>

<open_questions>
## Open Questions

1. **How much replay behavior should Phase 2 implement immediately?**
   - What we know: The architecture and context require plugin-managed session state first.
   - What's unclear: Whether Phase 2 should implement minimal session metadata only or an initial replay buffer as well.
   - Recommendation: Plan session metadata and a replay abstraction now; implement enough replay support to avoid redesign, but avoid overbuilding unsolicited event machinery before later phases need it.

2. **Should the main Streamable HTTP endpoint be stateful-only or optionally stateless?**
   - What we know: The current phase explicitly requires isolated per-client session state for reconnect/resume.
   - What's unclear: Whether to support a stateless compatibility mode in the same transport class for simpler clients.
   - Recommendation: Default to stateful for Phase 2, but isolate the session store behind an abstraction so a later stateless mode can be added cleanly if needed.

</open_questions>

<sources>
## Sources

### Primary (HIGH confidence)
- `/home/yui/Documents/moodle-mcp/.planning/phases/02-remote-transport-session-isolation/02-CONTEXT.md`
- `/home/yui/Documents/moodle-mcp/classes/local/server.php`
- `/home/yui/Documents/moodle-mcp/server.php`
- `/home/yui/Documents/moodle-mcp/tmp/moodle/webservice/lib.php`
- `/home/yui/Documents/moodle-mcp/tmp/moodle/lib/classes/session/manager.php`
- `/home/yui/Documents/moodle-mcp/tmp/moodle/lib/external/classes/external_api.php`
- `/home/yui/Documents/moodle-mcp/tmp/moodle/lib/classes/router/route_loader_interface.php`
- `/home/yui/Documents/moodle-mcp/tmp/moodle/lib/classes/router/middleware/cors_middleware.php`
- `/home/yui/Documents/moodle-mcp/tmp/moodle/lib/classes/router/middleware/moodle_bootstrap_middleware.php`
- `https://modelcontextprotocol.io/specification/draft/basic/transports`
- `https://code.claude.com/docs/en/mcp`
- `https://platform.claude.com/docs/en/agents-and-tools/mcp-connector`

### Secondary (MEDIUM confidence)
- `/home/yui/Documents/moodle-mcp/.planning/research/STACK.md`
- `/home/yui/Documents/moodle-mcp/.planning/research/ARCHITECTURE.md`
- `/home/yui/Documents/moodle-mcp/.planning/research/PITFALLS.md`
- `/home/yui/Documents/moodle-mcp/.planning/research/SUMMARY.md`

### Low confidence
- None.

</sources>

---
*Phase: 02-remote-transport-session-isolation*
*Research gathered: 2026-04-21*
