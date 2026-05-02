# Phase 1: Identity Bootstrap & Connector Credentials - Research

**Researched:** 2026-04-21
**Domain:** Moodle-native login/bootstrap, connector credential issuance, and MCP auth boundary design for Moodle 4.2+
**Confidence:** HIGH

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- Add a dedicated plugin bootstrap page such as `launch.php` or `connect.php` for interactive connector auth instead of reusing `server.php` as the login entrypoint.
- Let Moodle's normal login flow and enabled auth plugins own SSO behavior entirely; the connector should redirect into Moodle-native login and return from there.
- Require a logged-in user plus `webservice/mcp:use`, then apply connector-specific eligibility checks before issuing connector access.
- Bootstrap should exchange the authenticated browser session for a connector-managed credential contract rather than handing out a raw permanent Moodle web service token by default.
- Use a plugin-managed connector credential model, with optional internal use of Moodle token primitives where useful, instead of exposing raw `external_tokens` as the product contract.
- Interactive bootstrap credentials should be session-linked or short-lived by default.
- Durable remote access should require an explicit second-step grant for remote clients rather than being minted automatically on every successful web login.
- Provide plugin-owned inspection and revocation surfaces for connector access, even if some underlying data or lifecycle hooks reuse Moodle token infrastructure.
- Anchor Phase 1 credentials to a dedicated connector-managed service boundary rather than a site-global unrestricted token.
- Use system-context bootstrap initially, with narrower context gating deferred to later discovery and invocation phases.
- Include the companion-service seam in Phase 1 architecture and planning, but keep the Moodle plugin authoritative for auth, discovery, and execution.
- Follow Moodle's own browser-login and OAuth redirect patterns for SSO handoff instead of inventing a custom remote auth protocol first.

### the agent's Discretion
- The exact connector credential storage schema, naming, and revocation UX details are open as long as they preserve the decisions above.
- The exact shape of the Phase 1 companion seam is flexible as long as it remains transport-edge only and does not become the authority for Moodle permissions or tool discovery.

### Deferred Ideas (OUT OF SCOPE)
- Fine-grained course, activity, or object-level context restriction at discovery time.
- Broad wrapper coverage for UI-only Moodle actions.
- Full remote OAuth or edge-runtime implementation details beyond the Phase 1 seam and trust-boundary decision.

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
| Browser login and SSO bootstrap | Browser/Client + Moodle page controller | API/Backend | Moodle's normal page flow and auth plugins own interactive login, redirect, and session establishment. |
| Connector credential issuance | API/Backend | Database/Storage | Credential creation must be server-side and tied to Moodle user, capability, service, and revocation state. |
| MCP request authentication after bootstrap | API/Backend | — | Token/session-token verification belongs on the connector transport path, not in the browser bootstrap page. |
| Companion seam for remote connectors | Frontend Server / edge transport | API/Backend | The companion seam may terminate transport or remote-client UX but should defer actual auth/discovery/execution authority back to Moodle. |

</architectural_responsibility_map>

<research_summary>
## Summary

The strongest Phase 1 implementation pattern is the same one Moodle already uses for browser-launched mobile and OAuth-based login entrypoints: start with a normal Moodle page request, complete login or SSO there, then exchange that authenticated session for a credential that a token-only endpoint can honor later. This is necessary because Moodle webservice servers explicitly require `NO_MOODLE_COOKIES` and reject cookie-backed session auth in `authenticate_user()` in `tmp/moodle/webservice/lib.php`.[VERIFIED: tmp/moodle/webservice/lib.php#L1006-L1011]

The local plugin's current `server.php` and `classes/local/server.php` are already token-first and should stay that way for the transport side. The right Phase 1 move is not to force browser login into that path, but to add a dedicated bootstrap entrypoint such as `launch.php`, gate it with `require_login()` plus `webservice/mcp:use`, and let it mint a connector-managed credential contract. The companion-service seam can be included in the design now, but the authoritative source of identity, permissions, discovery, and execution should remain inside Moodle.[VERIFIED: /home/yui/Documents/moodle-mcp/server.php][VERIFIED: /home/yui/Documents/moodle-mcp/classes/local/server.php][VERIFIED: tmp/moodle/admin/tool/mobile/launch.php#L86-L99]

**Primary recommendation:** Plan Phase 1 around a two-path auth model: `launch.php` for Moodle-native login/SSO bootstrap and a token-authenticated MCP path for post-bootstrap use, with a plugin-owned credential manager and an explicitly non-authoritative companion seam.
</research_summary>

<standard_stack>
## Standard Stack

### Core
| Library / API | Version | Purpose | Why Standard |
|---------------|---------|---------|--------------|
| Moodle page bootstrap via `require('../../config.php')` plus `require_login()` | Moodle 4.2+ | Interactive connector login/SSO entrypoint | This is Moodle's normal, supported path for page requests and auth-plugin-driven login.[VERIFIED: tmp/moodle/lib/moodlelib.php#L2254-L2305] |
| `webservice_base_server` token auth path | Moodle 4.2+ | MCP post-bootstrap credential validation | Core WS auth is built for token-based access with cookies disabled and should remain the transport-side contract.[VERIFIED: tmp/moodle/webservice/lib.php#L1006-L1011] |
| `\core_external\util::generate_token()` and related token helpers | Moodle 4.2+ | Internal token primitive reuse | Core already models `externalserviceid`, `contextid`, `sid`, `validuntil`, and `iprestriction` in one place.[VERIFIED: tmp/moodle/lib/external/classes/util.php#L193-L250] |
| `\core\session\manager` | Moodle 4.2+ | Session lifecycle and lock release | Session-backed bootstrap and later transport work must respect Moodle's session manager rules, including `write_close()` usage for long-lived requests.[VERIFIED: tmp/moodle/lib/classes/session/manager.php][VERIFIED: tmp/moodle/lib/setup.php#L1168] |

### Supporting
| Library / API | Version | Purpose | When to Use |
|---------------|---------|---------|-------------|
| `auth_oauth2` login flow | Moodle 4.2+ | Moodle-managed OAuth/SSO redirect path | Use when the site already authenticates through configured OAuth providers and the connector needs to follow that path.[VERIFIED: tmp/moodle/auth/oauth2/login.php#L25-L58] |
| `admin/tool/mobile/launch.php` pattern | Moodle 4.2+ | Reference implementation for browser login -> token issuance handoff | Use as a strong analog for Phase 1 bootstrap flow and optional OAuth SSO redirect chaining.[VERIFIED: tmp/moodle/admin/tool/mobile/launch.php#L53-L99] |
| Existing plugin capability `webservice/mcp:use` | Current repo | Connector protocol gate | Use as the minimum explicit permission gate during bootstrap before issuing connector access.[VERIFIED: /home/yui/Documents/moodle-mcp/db/access.php] |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Dedicated plugin bootstrap page | Reuse `server.php` | Rejected because WS servers require cookies off and cannot safely host normal Moodle login/session behavior.[VERIFIED: tmp/moodle/webservice/lib.php#L1009-L1011] |
| Plugin-managed connector credential contract | Raw permanent webservice token via `generate_token_for_current_user()` | Easier, but service-centric and too implicit for a user-facing connector grant model.[VERIFIED: tmp/moodle/lib/external/classes/util.php#L336-L431] |
| Plugin-authoritative companion seam | Companion service as auth/discovery authority | Rejected for Phase 1 because it would split the trust boundary away from Moodle's real permission and service model.[VERIFIED: /home/yui/Documents/moodle-mcp/.planning/research/ARCHITECTURE.md] |

</standard_stack>

<architecture_patterns>
## Architecture Patterns

### Recommended Project Structure
```text
webservice/mcp/
├── launch.php                     # Browser/session bootstrap entrypoint
├── server.php                     # Token-authenticated MCP transport entrypoint
├── settings.php                   # Connector settings and hardening toggles
├── db/
│   ├── access.php                 # Capability gates
│   └── services.php               # Phase 1 connector-managed service declaration if used
├── classes/local/auth/
│   ├── bootstrap_service.php      # Login/SSO bootstrap orchestration
│   ├── credential_manager.php     # Connector grant issuance/revocation
│   └── transport_identity.php     # Maps connector credential to runtime identity
└── tests/
    ├── launch_test.php            # Bootstrap path coverage
    └── credential_manager_test.php
```

### Pattern 1: Two-Path Auth Boundary
**What:** Keep browser login/bootstrap separate from token-authenticated MCP transport.
**When to use:** Always for this connector, because Phase 1 must support Moodle-native login and a WS-style transport at the same time.
**Example:** `tmp/moodle/admin/tool/mobile/launch.php` uses a normal page request, calls `require_login()`, then issues a token; `tmp/moodle/webservice/lib.php` separately authenticates token-based webservice requests.[VERIFIED: tmp/moodle/admin/tool/mobile/launch.php#L86-L99][VERIFIED: tmp/moodle/webservice/lib.php#L1006-L1059]

### Pattern 2: Token Primitive Reuse, Product Contract Ownership
**What:** Reuse Moodle token primitives internally, but wrap them in plugin-owned credential lifecycle and UX.
**When to use:** When you want revocation, inspection, and future remote-client semantics without exposing raw Moodle webservice token assumptions directly.
**Example:** `\core_external\util::generate_token()` supports embedded/session-linked tokens through `sid = session_id()` and permanent tokens with service/context binding.[VERIFIED: tmp/moodle/lib/external/classes/util.php#L223-L250]

### Pattern 3: OAuth/SSO Redirect Reuse
**What:** Let Moodle's own OAuth login path handle issuer-specific redirects and return to a plugin bootstrap page.
**When to use:** When sites use Moodle-managed OAuth providers and the connector must not invent an alternate SSO stack.
**Example:** `auth/oauth2/login.php` performs issuer availability checks, obtains the OAuth client, redirects if needed, and completes Moodle login on return.[VERIFIED: tmp/moodle/auth/oauth2/login.php#L35-L58]

### Anti-Patterns to Avoid
- **Mixing browser login into `server.php`:** WS auth explicitly forbids cookies on WS servers; trying to make one endpoint do both will fight Moodle core assumptions.[VERIFIED: tmp/moodle/webservice/lib.php#L1009-L1011]
- **Auto-minting durable remote credentials on every successful bootstrap:** Durable tokens should be a deliberate grant, not a silent side effect of normal web login.[VERIFIED: tmp/moodle/lib/external/classes/util.php#L336-L431]
- **Letting the companion seam become the source of truth:** It may assist transport or remote-client UX, but must not own capability checks, service membership, or tool discovery rules.[VERIFIED: /home/yui/Documents/moodle-mcp/.planning/research/ARCHITECTURE.md]

</architecture_patterns>

<dont_hand_roll>
## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| SSO redirect and OAuth login flow | Custom connector OAuth page flow | Moodle's existing `auth_oauth2` entrypoints and normal login/session path | Moodle already owns issuer checks, redirect flow, and login completion semantics.[VERIFIED: tmp/moodle/auth/oauth2/login.php#L35-L58] |
| Raw token table semantics | Custom ad hoc token lifecycle copied by hand | Reuse `\core_external\util` primitives or clearly modeled plugin wrappers around them | Core already handles service/context binding, `sid`, expiry, and IP restriction fields.[VERIFIED: tmp/moodle/lib/external/classes/util.php#L193-L250] |
| Browser-to-token handoff pattern | Invent a totally new login launcher | Use `admin/tool/mobile/launch.php` as the architectural reference | Moodle already has a page-login -> token handoff model with optional OAuth redirection.[VERIFIED: tmp/moodle/admin/tool/mobile/launch.php#L53-L124] |

**Key insight:** The hard part of Phase 1 is not creating yet another token string. It is keeping Moodle-native login, WS token auth, and future remote-client needs inside one coherent trust boundary without bypassing core assumptions.
</dont_hand_roll>

<common_pitfalls>
## Common Pitfalls

### Pitfall 1: Treating WS transport as the login page
**What goes wrong:** `server.php` accumulates browser login logic, cookies, or redirect behavior and stops behaving like a stable WS endpoint.
**Why it happens:** It feels cheaper to add login support to the existing entrypoint than to split bootstrap from transport.
**How to avoid:** Keep `server.php` token-only and create a separate page bootstrap path such as `launch.php`.
**Warning signs:** New auth code wants `require_login()` inside `server.php`, or transport code starts depending on normal Moodle session cookies.

### Pitfall 2: Confusing service-scoped raw tokens with the connector product contract
**What goes wrong:** The project silently becomes "better token creation UX" instead of a connector-owned auth model.
**Why it happens:** Core already has permanent and embedded token helpers, so it is tempting to expose them directly.
**How to avoid:** Reuse core token primitives only behind a plugin-managed credential contract with explicit grant, inspection, and revocation semantics.
**Warning signs:** Phase 1 plan talks only about `external_tokens` and never about connector-specific credential lifecycle or UX.

### Pitfall 3: Baking remote-edge complexity into the authoritative path too early
**What goes wrong:** The edge or companion seam starts to own auth or permission logic and drifts from Moodle truth.
**Why it happens:** Remote-client expectations tempt implementers to solve everything in the external edge first.
**How to avoid:** Include the seam in architecture, but constrain it to transport or handoff concerns and keep final identity/permission authority in the plugin.
**Warning signs:** Remote edge needs direct DB access, duplicates service filtering, or becomes the place where connector grants are interpreted authoritatively.

</common_pitfalls>

<code_examples>
## Code Examples

### Bootstrap through normal Moodle login
```php
require_once(__DIR__ . '/../../../config.php');
require_login(0, false);
core_user::require_active_user($USER);
```
Source: `tmp/moodle/admin/tool/mobile/launch.php` [CITED: local source]

### Session-linked token primitive
```php
$newtoken->tokentype = $tokentype;
if ($tokentype == EXTERNAL_TOKEN_EMBEDDED) {
    $newtoken->sid = session_id();
}
$newtoken->contextid = $context->id;
$newtoken->externalserviceid = $service->id;
```
Source: `tmp/moodle/lib/external/classes/util.php` [CITED: local source]

### WS transport cookie boundary
```php
if (!NO_MOODLE_COOKIES) {
    throw new coding_exception('Cookies must be disabled in WS servers!');
}
```
Source: `tmp/moodle/webservice/lib.php` [CITED: local source]

</code_examples>

<sota_updates>
## State of the Art (2024-2025)

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Treat legacy webservice token flows as the whole integration story | Separate interactive login/bootstrap from transport and credential contracts | Ongoing shift in remote-tool integrations | Better fit for connectors that need real-user identity rather than static admin-managed service tokens |
| Single runtime without explicit edge seam | Plugin-first core with optional transport edge | Current MCP client ecosystem | Lets the project support future remote-client constraints without moving permission authority out of Moodle |

**New tools/patterns to consider:**
- Companion-edge seam planning in Phase 1 — include the boundary now so later transport work does not require a Phase 1 redesign.
- Session-safe long-lived request handling — Phase 1 planning should already account for later `write_close()` and non-blocking transport work.

**Deprecated/outdated:**
- Treating browser login and WS server auth as one endpoint — contradicted by core WS assumptions and should not be planned.

</sota_updates>

<open_questions>
## Open Questions

1. **What exact plugin-managed credential shape should Phase 1 implement?**
   - What we know: It should not be a raw permanent token by default, and it should support session-linked or short-lived bootstrap semantics.
   - What's unclear: Whether the first implementation should wrap Moodle embedded tokens, store its own opaque credentials, or support both behind one abstraction.
   - Recommendation: Make this an explicit planning decision with tasks for abstraction and tests, not an implicit implementation detail.

2. **How far should the companion seam go in Phase 1?**
   - What we know: The seam must exist in architecture, but Moodle must remain authoritative for auth, discovery, and execution.
   - What's unclear: Whether Phase 1 should deliver a stub transport contract, a concrete bridge interface, or a small runnable sidecar scaffold.
   - Recommendation: Plan the seam as an interface/boundary first; only build concrete remote-edge runtime pieces if they directly unblock Phase 1 success criteria.

</open_questions>

<sources>
## Sources

### Primary (HIGH confidence)
- `/home/yui/Documents/moodle-mcp/.planning/phases/01-identity-bootstrap-connector-credentials/01-CONTEXT.md` — locked Phase 1 decisions and canonical references.
- `/home/yui/Documents/moodle-mcp/server.php` — current plugin transport entrypoint.
- `/home/yui/Documents/moodle-mcp/classes/local/server.php` — current MCP server implementation.
- `/home/yui/Documents/moodle-mcp/db/access.php` — current protocol capability definition.
- `/home/yui/Documents/moodle-mcp/tmp/moodle/webservice/lib.php` — WS auth boundary, token auth, restricted service/context handling, and protocol capability checks.
- `/home/yui/Documents/moodle-mcp/tmp/moodle/lib/external/classes/util.php` — token generation, embedded token session binding, and current-user permanent-token issuance.
- `/home/yui/Documents/moodle-mcp/tmp/moodle/admin/tool/mobile/launch.php` — browser login and OAuth handoff precedent for token issuance after login.
- `/home/yui/Documents/moodle-mcp/tmp/moodle/auth/oauth2/login.php` — Moodle-managed OAuth login redirect flow.
- `/home/yui/Documents/moodle-mcp/tmp/moodle/lib/moodlelib.php` — `require_login()` behavior.
- `/home/yui/Documents/moodle-mcp/tmp/moodle/lib/classes/session/manager.php` — session manager rules and lock-handling implications.

### Secondary (MEDIUM confidence)
- `/home/yui/Documents/moodle-mcp/.planning/research/SUMMARY.md`
- `/home/yui/Documents/moodle-mcp/.planning/research/STACK.md`
- `/home/yui/Documents/moodle-mcp/.planning/research/ARCHITECTURE.md`

### Low confidence
- None.

</sources>

---
*Phase: 01-identity-bootstrap-connector-credentials*
*Research gathered: 2026-04-21*
