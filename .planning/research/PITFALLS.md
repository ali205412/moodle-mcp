# Pitfalls Research

**Domain:** maximal permission-aware Moodle MCP connector for Moodle 4.2+  
**Researched:** 2026-04-21  
**Confidence:** MEDIUM

This file focuses on the failure modes most likely to force a rewrite, leak permissions, or make a "maximal" connector unusable in real Moodle deployments. "Hard blocker" means the roadmap should treat the pitfall as architecture-defining work, not cleanup. "Manageable complexity" means the risk is real but can be contained with explicit phase work.

## Critical Pitfalls

### Pitfall 1: Treating Service Scope As Authorization

**Risk source:** permissions  
**Blocker level:** Hard blocker

**What goes wrong:**
The connector exposes or allows tools because a token or service includes a function, even when the user lacks the course, module, group, visibility, enrolment, or ownership rights required for the specific object being acted on.

**Why it happens:**
Moodle external services look like an authorization boundary, but they are only one layer. `db/services.php` capability declarations are advisory, and real permission checks still depend on the most specific Moodle context plus runtime capability evaluation.

**How to avoid:**
Build a dedicated permission engine. For every exposed function or wrapper, define:
- the target object resolver
- the most specific Moodle context
- the exact capability set
- extra gates such as enrolment, group mode, conditional availability, object ownership, and hidden/visible state

Every wrapper should run `validate_parameters()`, `validate_context()`, and the same `require_capability()` or equivalent checks the Moodle UI path uses. If the context cannot be resolved safely, the tool should not be listed or callable.

**Warning signs:**
Different users receive nearly identical tool lists. Teachers see tools for courses they are not enrolled in. Discovery is keyed only by token or service. Tests prove that a tool works for an allowed user, but never prove that it is hidden or denied for a forbidden user.

**Phase to address:**
Phase 2: Permission Engine and Safe Tool Discovery

---

### Pitfall 2: Publishing An Over-Broad Tool Catalog

**Risk source:** permissions, module coverage, operational scale  
**Blocker level:** Hard blocker

**What goes wrong:**
The connector exposes a giant catalog of tools that includes forbidden actions, low-value internals, or actions that are technically callable but not safe for AI-driven discovery. The AI then plans around tools the user cannot truly use, or around tools that should never have been surfaced.

**Why it happens:**
Blind auto-harvesting is the easiest way to make a demo look "maximal." In practice, Moodle mixes real external functions with exporters, helpers, AJAX-oriented endpoints, mobile-oriented services, and plugin-specific oddities. A local scan of `MOODLE_405_STABLE` showed 67 `db/services.php` files, 292 `classes/external/*.php` files, and 106 exporter classes, which is enough to make naive harvesting noisy and misleading.

**How to avoid:**
Treat catalog publication as a classification problem, not a filesystem or database dump. A tool should be published only if all of the following are true:
- the component is installed and enabled
- the function or wrapper represents a real user action
- the current user is eligible in at least one real context
- the schema is strong enough to drive AI use safely
- the risk tier has been assigned

Use pagination for `tools/list`, human-readable titles, stable names, destructive-action markers, and user-facing descriptions. Hide internal or low-signal functions even if they technically exist.

**Warning signs:**
`tools/list` returns hundreds of opaque function names. The list includes exporters, duplicate aliases, or highly technical internals. Users repeatedly hit access errors immediately after choosing visible tools. Tool discovery time grows with installed plugins rather than with eligible user actions.

**Phase to address:**
Phase 2: Permission Engine and Safe Tool Discovery

---

### Pitfall 3: Assuming Existing Externals Equal Full Moodle Coverage

**Risk source:** module coverage  
**Blocker level:** Hard blocker

**What goes wrong:**
The connector claims maximal coverage but misses important UI actions, or ships wrappers that change data without matching Moodle side effects such as events, completion updates, grade recalculation, file handling, or read-state transitions.

**Why it happens:**
Moodle's external API surface is broad but uneven. Some actions already have clean external functions, some only exist in page scripts and forms, and some require "view" behavior or multi-step flows rather than a single CRUD call. Moodle's own guidance for web service work is to match the UI path exactly, including context checks and behavior.

**How to avoid:**
Create a component-by-component action inventory with four buckets:
- existing external function is sufficient
- existing external function exists but needs wrapper normalization
- no external exists, wrapper required
- not safely exposable in the current architecture

For wrapper endpoints, trace the exact UI code path and shared library calls. Preserve transactions, draft file flows, format fields, events, completion logic, conditional activity checks, and group restrictions. If the wrapper cannot faithfully match the UI semantics, do not market it as equivalent coverage.

**Warning signs:**
Manual UI actions work but no corresponding tool exists. A wrapper updates records but does not trigger the same event trail. File-related operations fail around draft areas or format handling. Coverage claims are based on counting functions instead of auditing user actions.

**Phase to address:**
Phase 3: Coverage Inventory and Wrapper Framework

---

### Pitfall 4: Collapsing Moodle Auth, SSO, And MCP Auth Into One Ad Hoc Flow

**Risk source:** auth  
**Blocker level:** Hard blocker

**What goes wrong:**
The connector mixes Moodle web service tokens, browser sessions, SSO redirects, embedded login flows, and MCP bearer auth into a single improvised contract. That produces login loops, admin-only edge cases, leaked tokens, invalid session reuse, or incompatible client behavior.

**Why it happens:**
Moodle already has multiple auth shapes: normal browser sessions, token creation flows, embedded/session-bound tokens, mobile launch behavior, IP restrictions, and token expiry. Modern MCP remote auth expects explicit bearer-token behavior and metadata discovery. Reusing `wstoken` URLs or passing MCP tokens through to downstream services looks simple but creates the wrong trust boundary.

**How to avoid:**
Define the auth layers explicitly:
- user identity is established by Moodle-native login and SSO
- the connector exposes its own remote access contract for MCP clients
- downstream Moodle actions execute as the authenticated Moodle user
- query-string tokens are disabled or treated as legacy-only fallback

If MCP OAuth is implemented, validate audience and scopes correctly, publish Protected Resource Metadata, and never pass the client token through as a Moodle token. Keep browser-window fallback for SSO providers that fail in embedded flows.

**Warning signs:**
Connection instructions tell users to paste `?wstoken=` URLs into clients. Admin users need undocumented exceptions to connect. SSO works only for some identity providers or only in embedded mode. The same bearer token is accepted by the connector and by unrelated downstream APIs.

**Phase to address:**
Phase 1: Auth Boundary and Remote Session Design

---

### Pitfall 5: Building Transport Around Legacy SSE Or Browser-Hostile HTTP Handling

**Risk source:** transport, auth  
**Blocker level:** Hard blocker

**What goes wrong:**
Modern MCP clients fail to connect reliably, browsers break on preflight, reverse proxies strip critical headers, sessions cannot be resumed, or PHP workers get pinned by long-lived streams. The server appears to work in narrow tests but not in real deployments.

**Why it happens:**
The current MCP standard centers Streamable HTTP. Legacy HTTP+SSE remains only for backwards compatibility. A correct implementation must handle POST and GET behavior, protocol-version headers, session IDs, 404 reinitialize behavior, optional DELETE termination, origin validation, and browser preflight without authenticating OPTIONS first.

**How to avoid:**
Make Streamable HTTP the primary contract. Add legacy SSE only for compatibility with clients that still need it. Test the full transport matrix:
- POST initialize and JSON-RPC requests
- GET SSE stream behavior
- `MCP-Protocol-Version`
- `MCP-Session-Id`
- OPTIONS preflight before auth
- 401, 403, 404, and 405 handling
- reconnect and resume behavior behind a reverse proxy

Reject invalid `Origin` headers and never rely on wildcard CORS in production.

**Warning signs:**
Bearer auth works in curl but not in browser-based clients. OPTIONS requests are authenticated and fail before CORS completes. Clients reconnect indefinitely after stream closure. The server ignores protocol version headers or never returns session lifecycle errors cleanly.

**Phase to address:**
Phase 1: Transport Compatibility Layer

---

### Pitfall 6: Claiming Moodle 4.2+ Support While Coding To One Branch

**Risk source:** Moodle versioning  
**Blocker level:** Manageable complexity with high blast radius

**What goes wrong:**
The connector works on the developer's reference branch but breaks or silently degrades on older supported versions. Tool schemas, namespaces, helper classes, capability names, or service registries drift across versions and installed plugin sets.

**Why it happens:**
Moodle service declarations are discovered during install and upgrade, not at random runtime points. APIs and compatibility shims shift across versions. Installed modules and plugin-provided externals vary by site. A connector built only against 4.5 behavior will miss real 4.2 and 4.3 constraints.

**How to avoid:**
Maintain an explicit support matrix for 4.2, 4.3, 4.4, and 4.5+. Add a compatibility layer for version-specific API usage. Rebuild or invalidate discovery state on plugin install, uninstall, and upgrade. Snapshot function registries and generated schemas per supported Moodle version.

**Warning signs:**
CI runs only against one Moodle branch. New plugin installs do not show up until a manual repair or cache purge. Tool schemas differ after upgrade with no code change. Code relies on helpers or namespaced classes absent from 4.2.

**Phase to address:**
Phase 4: Compatibility Matrix and Upgrade Resilience

---

### Pitfall 7: Caching The Wrong Boundary, Or Not Caching At All

**Risk source:** operational scale, permissions  
**Blocker level:** Manageable complexity

**What goes wrong:**
The connector becomes too slow for real sites, or cache hits return catalogs and decisions that no longer match the user's current roles, enrolments, services, or installed modules.

**Why it happens:**
Maximal discovery is expensive. The connector has to combine service scope, installed components, external-function metadata, user context, role assignments, enrolments, and module availability. Recomputing everything on every `tools/list` is too costly, but naive caches keyed only by token or user leak stale permissions.

**How to avoid:**
Cache at multiple layers with strict invalidation inputs:
- Moodle version and plugin hash
- installed component set
- external service scope
- user identity
- effective permission fingerprint
- connector schema version

Invalidate on role changes, enrolment changes, service-function changes, plugin upgrades, session expiry, and catalog-affecting settings changes. Keep tool results compact and paginate large lists.

**Warning signs:**
`tools/list` latency scales with site size. Users keep seeing tools after losing access. Operators fix permission issues by purging all caches. Large sites produce huge initialize or tool-list payloads. Database load spikes under repeated discovery.

**Phase to address:**
Phase 5: Caching, Pagination, and Auditability

---

### Pitfall 8: Shipping On Happy-Path Tests

**Risk source:** auth, permissions, transport, Moodle versioning, operational scale  
**Blocker level:** Hard blocker for production release

**What goes wrong:**
The connector passes unit tests but fails under real HTTP entrypoints, real role matrices, custom auth plugins, reverse proxies, large catalogs, or cross-version Moodle fixtures. The worst leaks are often negative cases that were never asserted.

**Why it happens:**
These systems fail at boundaries: HTTP transport, browser behavior, session expiry, SSO redirects, and per-context permission denial. Reflection-heavy tests and thin unit suites rarely cover those interactions.

**How to avoid:**
Define a release gate that includes:
- real HTTP tests against the public entrypoint
- role and context matrix tests
- negative permission tests that assert tools are absent, not just denied
- SSO smoke tests with browser-window fallback
- reverse-proxy and CORS tests
- multi-version Moodle CI
- large-catalog and large-payload tests
- wrapper side-effect regression tests

Treat "user cannot see or call this tool" as first-class behavior, not an afterthought.

**Warning signs:**
Tests use reflection instead of exercising the entrypoint. No fixture site includes custom auth or plugin modules. Only successful calls are covered. Manual QA discovers login failures or permission leaks after release.

**Phase to address:**
Phase 6: Verification Matrix and Release Gates

## Technical Debt Patterns

Shortcuts that seem attractive early, but create long-term failures.

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| Use external service membership as the main permission filter | Fastest path to a working demo | Permission leakage across courses, modules, and contexts | Never |
| Auto-publish every discovered external function or class | Looks "maximal" immediately | Opaque catalogs, broken AI planning, unsafe/destructive tool exposure | Never |
| Keep query-string `wstoken` auth as the primary connection story | Zero-friction manual testing | Token leakage via logs, history, copied URLs, and docs | Local throwaway debugging only |
| Hard-code against one Moodle branch | Fastest initial implementation | Hidden 4.2/4.3 regressions and upgrade breakage | Short-lived spike only |
| Cache tool lists by user or token alone | Easy performance win | Stale permissions after role, enrolment, or service changes | Never |
| Copy UI page logic directly into wrappers instead of extracting shared logic | Rapid feature coverage | Version drift, missing side effects, and duplicated bugs | Short-lived spike only |

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| Moodle service discovery | Expecting `db/services.php` changes to appear instantly | Bump plugin version, run upgrade, and rescan registry state |
| Custom auth and SSO plugins | Assuming embedded-browser login works everywhere | Test browser-window and embedded modes; rely on Moodle's native login flow and correct `wantsurl` handling |
| MCP remote clients | Implementing only legacy SSE endpoints | Implement Streamable HTTP first; keep SSE only for backwards compatibility |
| Reverse proxies and WAFs | Forgetting to forward `Authorization`, `Origin`, `MCP-Protocol-Version`, and `MCP-Session-Id` | Test behind the real proxy stack and document required header forwarding and method support |
| Installed plugin modules | Treating "installed component" as "safe tool to expose" | Classify each action for schema quality, permission logic, and user value before publication |

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| Rebuilding the full catalog on every `tools/list` | Slow initialize, repeated DB reads, CPU spikes | Cache per version, component set, service scope, and permission fingerprint | Once a site has many plugins, many courses, or repeated AI reconnections |
| Returning one giant unpaginated tool list | Timeouts, model confusion, huge payloads | Support pagination and publish only high-value eligible tools | Once the visible tool count reaches the high hundreds |
| Duplicating large Moodle results in both text and structured content | Large response bodies and memory usage | Keep `structuredContent` authoritative and generate concise text summaries | Large reports, gradebook payloads, file metadata, and admin outputs |
| Holding long-lived SSE streams with PHP worker assumptions | Worker starvation and reconnect storms | Prefer Streamable HTTP semantics, explicit timeouts, and tested reconnect behavior | Dozens of concurrent sessions on modest PHP-FPM pools |

## Security Mistakes

| Mistake | Risk | Prevention |
|---------|------|------------|
| Primary reliance on query-string tokens | High credential leakage through logs and copied URLs | Use bearer headers, redact logs, and disable URL-token auth where possible |
| Trusting `db/services.php` capability hints as enforcement | Privilege escalation or overexposure | Enforce permissions with real context resolution and runtime capability checks |
| Passing client tokens through to downstream services | Confused deputy problems and broken audit boundaries | Validate audience and mint or use server-side credentials appropriate to the downstream system |
| Wildcard CORS plus auth-before-OPTIONS | Cross-origin abuse and broken browser auth | Validate origins, allow safe unauthenticated preflight, and keep explicit allowlists |
| Exposing destructive tools without user-facing friction | AI-assisted accidental data loss | Mark risky tools clearly, add confirmation paths, and log every destructive invocation |
| Logging raw tool arguments, headers, or traces | Secret and PII exposure | Redact credentials, tokens, private fields, and high-risk parameters in logs and telemetry |

## UX Pitfalls

| Pitfall | User Impact | Better Approach |
|---------|-------------|-----------------|
| Tool names mirror raw Moodle function names | Users and models cannot tell which tools matter | Use stable machine-safe names plus human-readable titles and descriptions |
| Forbidden tools are visible and fail only at call time | Users stop trusting the connector | Filter at discovery time and expose only currently eligible tools |
| SSO works only in one login mode | Operators think the connector is broken on their site | Detect and document browser-window fallback for incompatible auth plugins |
| Tool outputs are raw IDs or HTML blobs | Models misuse results and chain bad follow-up calls | Normalize summaries, include context labels, and preserve structured output |
| Destructive tools look identical to read-only tools | Accidental high-impact actions | Add risk labels, confirmation expectations, and clear descriptions of side effects |

## "Looks Done But Isn't" Checklist

- [ ] **Permission filtering:** Verify context-level capability checks, enrolment checks, hidden/visible state, group restrictions, conditional activity access, and siteadmin behavior.
- [ ] **Tool catalog safety:** Verify that internal helpers, exporters, deprecated externals, and no-context actions are not published as normal tools.
- [ ] **Transport support:** Verify Streamable HTTP POST and GET behavior, unauthenticated OPTIONS handling, session restart on `404`, and backwards compatibility only where needed.
- [ ] **SSO and auth:** Verify browser-window fallback, custom auth plugin `wantsurl` behavior, token expiry and logout invalidation, and admin edge cases.
- [ ] **Wrapper fidelity:** Verify events, completion updates, grade recalculation, draft file handling, format fields, and transaction boundaries.
- [ ] **Version support:** Verify 4.2 through 4.5+ fixtures, upgrade-triggered rediscovery, plugin install or uninstall changes, and capability deprecations.
- [ ] **Release hardening:** Verify pagination, rate limits, audit logs, negative permission tests, and large-catalog behavior.

## Recovery Strategies

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|----------------|
| Permission leakage | HIGH | Hide the affected tools immediately, rotate active sessions or tokens if exposure is plausible, review audit logs, add a failing regression case, and redeploy with stricter context checks |
| Broken SSO or login loops | HIGH | Switch to browser-window login, verify session cookies and proxy headers, inspect custom auth `wantsurl` handling, and keep a temporary ops fallback only while the root cause is fixed |
| Transport incompatibility | MEDIUM | Add or repair the compatibility endpoint, fix origin and preflight handling, test target clients explicitly, and force session reinitialize paths |
| Version drift after upgrade | MEDIUM | Run Moodle upgrade, purge stale discovery state, diff registries and schemas against the previous version, and patch the compatibility layer |
| Stale discovery cache | MEDIUM | Flush connector caches, invalidate permission fingerprints, resend tool-list change signals, and add invalidation hooks for the missed state change |
| Wrapper behavior mismatch | HIGH | Disable the wrapper, trace the matching UI path, reintroduce missing events or transactions, and ship with regression tests before re-enabling |

## Pitfall-to-Phase Mapping

| Pitfall | Prevention Phase | Verification |
|---------|------------------|--------------|
| Treating service scope as authorization | Phase 2: Permission Engine and Safe Tool Discovery | Role and context matrix proves tools disappear as permissions narrow |
| Publishing an over-broad tool catalog | Phase 2: Permission Engine and Safe Tool Discovery | `tools/list` is paginated, curated, and materially different per user and site state |
| Assuming existing externals equal full coverage | Phase 3: Coverage Inventory and Wrapper Framework | Coverage matrix shows which actions use native externals, wrappers, or remain unsupported |
| Collapsing Moodle auth, SSO, and MCP auth | Phase 1: Auth Boundary and Remote Session Design | Login works across session, SSO, expiry, logout, and bearer-auth scenarios without URL tokens |
| Building transport around legacy SSE or browser-hostile handling | Phase 1: Transport Compatibility Layer | Target MCP clients pass POST, GET, OPTIONS, reconnect, and session lifecycle tests behind real proxies |
| Claiming 4.2+ support while coding to one branch | Phase 4: Compatibility Matrix and Upgrade Resilience | CI and fixture sites pass on every supported Moodle branch |
| Caching the wrong boundary, or not caching at all | Phase 5: Caching, Pagination, and Auditability | Tool-list latency is stable while permissions still update correctly after state changes |
| Shipping on happy-path tests | Phase 6: Verification Matrix and Release Gates | Release checklist includes negative permission, auth, transport, wrapper, and version regression coverage |

## Sources

- Moodle Developer Resources: External Services, Function Declarations, Security, and Access API
- Moodle Docs: Moodle app guide for admins, Moodle app FAQ, and web service contribution guidance
- Model Context Protocol specification: Transports, Authorization, Tools, Pagination, and Security Best Practices
- Local project context: `/home/yui/Documents/moodle-mcp/.planning/PROJECT.md`
- Local codebase concerns: `/home/yui/Documents/moodle-mcp/.planning/codebase/CONCERNS.md`
- Local testing patterns: `/home/yui/Documents/moodle-mcp/.planning/codebase/TESTING.md`
- Local Moodle reference tree: `/home/yui/Documents/moodle-mcp/tmp/moodle` on `MOODLE_405_STABLE`

---
*Pitfalls research for: maximal permission-aware Moodle MCP connector*
*Researched: 2026-04-21*
