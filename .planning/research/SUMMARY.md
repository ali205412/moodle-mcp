# Project Research Summary

**Project:** Moodle MCP
**Domain:** Moodle-native MCP connector for Moodle 4.2+
**Researched:** 2026-04-21
**Confidence:** MEDIUM

## Executive Summary

Moodle MCP is not just a thin adapter over Moodle web service tokens. The research consistently points to a larger product: a maximal Moodle-native MCP connector that lets a real Moodle user authenticate through the site's normal login and SSO flow, then discover and invoke the broadest safe set of Moodle actions that their actual identity permits. Experts build this kind of system by keeping Moodle itself as the authority for identity, capability checks, context resolution, installed-plugin discovery, and wrapper execution.

The recommended approach is plugin-first. Keep authentication bootstrap, tool harvesting, policy filtering, wrapper endpoints, and execution inside the Moodle plugin; use Moodle's External API, Access API, service registry, and MUC as the foundation; and make Streamable HTTP the default MCP transport. The connector should harvest existing core, module, and plugin externals first, then add typed wrapper externals only where important UI workflows are missing. If a companion Node/TypeScript service is ever introduced, it should stay transport-only and never become the authority for discovery or permissions.

The main risks are architectural, not cosmetic. The connector will fail if it treats service scope as authorization, auto-publishes a noisy catalog, or collapses Moodle login, SSO, and MCP auth into one improvised flow. The roadmap should therefore front-load auth and transport boundaries, harvested catalog infrastructure, and pessimistic permission filtering before broad feature expansion. Wrapper-heavy areas such as question bank authoring, gradebook editing, badge administration, and rich content authoring need deliberate later-phase research, not early assumptions.

## Key Findings

### Recommended Stack

The stack research is unusually clear: build on the existing `webservice_mcp` plugin and keep Moodle as the system of record. Target PHP 8.0 syntax as the minimum for the Moodle 4.2+ support promise, use `\core_external\external_api` as the canonical metadata and execution bridge, rely on Moodle's external service registry to discover installed APIs, and use MUC for catalog caching. Streamable HTTP should be the primary MCP transport, with legacy SSE only as a compatibility layer.

Critical version guidance is equally clear. The connector must not depend on Moodle Routing as its baseline because routing is 4.5+ only, while the project target is Moodle 4.2+. If transport or OAuth requirements eventually force a second runtime, the fallback is Node.js LTS with TypeScript and the official MCP SDK, but only as an edge relay above Moodle.

**Core technologies:**
- `webservice_mcp` plugin runtime: auth, discovery, execution, wrappers, and operator controls — keeps Moodle authoritative for users, permissions, and installed components.
- PHP 8.0 on Moodle 4.2+: lowest common denominator runtime target — preserves the 4.2+ compatibility promise without splitting logic into another stack.
- Moodle External Services API (`\core_external\external_api`): metadata loading, schema translation, validation, and execution — canonical source for harvested externals and wrapper contracts.
- Moodle external registry plus managed service sync: installed-function inventory and exposure boundary — lets the connector auto-expand with plugins without inventing a parallel catalog.
- Moodle Cache API (MUC): site harvest indexes and visible tool caches — required for performance, while leaving final authorization at call time.
- Streamable HTTP transport: primary remote MCP transport — matches current MCP and Claude Code guidance better than legacy SSE.
- Optional Node.js LTS + TypeScript + `@modelcontextprotocol/sdk`: transport relay only if OAuth or long-lived stream operations justify it — not a replacement for Moodle-side permission logic.

### Expected Features

The feature research shows a clear product boundary: automatic harvest first, connector bridge second, wrappers third. A credible launch should expose the installed external API surface per user, support Moodle-native login and real MCP transport, and cover the highest-value learner, teacher, and operator workflows before pursuing wrapper-heavy long-tail domains.

The strongest differentiators are not "more endpoints" in the abstract. They are provenance-aware coverage, capability-aware safety metadata, plugin ecosystem auto-expansion, and a disciplined wrapper program for real Moodle gaps. The research also strongly rejects generic page/form execution, browser automation as the primary strategy, super-admin service accounts, and any shortcut that hides destructive power behind a flat undifferentiated tool list.

**Must have (table stakes):**
- Moodle-native login and SSO bootstrap with per-user connector sessions — the connector has to reflect the user's real Moodle identity.
- Automatic harvest of core, module, installed-plugin, and site-specific externals — this is the baseline for "maximal" coverage.
- Strict per-user discovery and execution filtering — service scope, context, capabilities, enrolments, ownership, and visibility all matter.
- Core user surface: courses, content, completion, calendar, messaging, profiles, private files, and file-bearing workflows — this is the minimum useful daily surface.
- High-value activity workflows: assignments, forums, quizzes, workshops, and feedback — these are central Moodle actions with strong existing external coverage.
- Basic operator surface: users, enrolments, groups, cohorts, roles, and course/category management — administrators and teachers expect these workflows.

**Should have (competitive):**
- Coverage reporting that distinguishes harvested tools, wrapper tools, disabled tools, and unsupported gaps — makes the connector auditable and roadmapable.
- Risk metadata, confirmation paths, dry-run previews, and human-readable errors for mutating tools — makes admin-grade AI usage safer.
- Provenance-aware tool taxonomy, aliases, and richer annotations — reduces the burden of a large raw Moodle function surface.
- Typed wrapper support for course authoring gaps and async workflows — closes the most painful gaps without abandoning Moodle-native patterns.

**Defer (v2+):**
- Question bank CRUD and preview workflows beyond today's thin externals — high value, but wrapper-heavy and domain-specific.
- Gradebook tree/report editing beyond existing grading APIs — complex operator domain with weak native external coverage.
- Badge administration expansion and broad plugin-specific UI wrappers — important, but not necessary before the core connector model is proven.
- Generic admin-tree coverage, browser automation, or arbitrary form/page executors — unsafe shortcuts, not launch features.

### Architecture Approach

The architecture research recommends a strict two-path design: use a normal Moodle page flow for login and SSO bootstrap, then switch to a token-only MCP transport path for execution. Behind that, keep harvested externals and wrapper endpoints in separate registries, merge them late into a provenance-aware tool catalog, and let execution remain authoritative even when discovery uses advisory hints. This creates a clean boundary between identity, catalog assembly, policy filtering, invocation, and transport concerns.

**Major components:**
1. Bootstrap auth controller and credential issuer — runs Moodle-native login and SSO, gates connector access, and issues connector credentials without mixing cookies into the MCP transport endpoint.
2. Harvest index, managed service synchronizer, and wrapper registry — discovers installed externals, registers connector-owned wrapper actions, and preserves provenance between harvested and wrapper tools.
3. Tool catalog assembler and policy engine — merges descriptors late, applies exact filters where possible, attaches advisory/risk metadata, and builds the visible per-user tool list.
4. Invocation dispatcher and transport/stream manager — routes tool calls to harvested externals or wrappers, enforces final checks, and handles Streamable HTTP plus optional SSE lifecycle concerns.

### Critical Pitfalls

1. **Treating service scope as authorization** — build a dedicated permission engine with context and object resolution; let wrappers and externals perform final runtime checks.
2. **Publishing an over-broad tool catalog** — classify and curate tools before publication, paginate discovery, and hide low-signal or unsafe internals even if they technically exist.
3. **Collapsing Moodle auth, SSO, and MCP auth into one ad hoc flow** — keep login/bootstrap separate from token-authenticated transport, and do not make query-string tokens the primary connection story.
4. **Building transport around legacy SSE or browser-hostile HTTP behavior** — make Streamable HTTP primary, handle OPTIONS/CORS/session lifecycle correctly, and keep SSE as compatibility only.
5. **Assuming existing externals equal full Moodle coverage** — maintain a coverage inventory and add typed wrappers only when they can reproduce real UI semantics such as files, events, completion, and transactions.

## Implications for Roadmap

Based on research, suggested phase structure:

### Phase 1: Auth Boundary and Transport Foundation
**Rationale:** Every later phase depends on a real Moodle user identity and a transport contract that modern MCP clients can actually use.
**Delivers:** `launch.php`-style Moodle login and SSO bootstrap, connector credential issuance, Streamable HTTP endpoint, optional SSE adapter, Origin/CORS/preflight handling, session lifecycle semantics.
**Addresses:** Moodle-native login and SSO compatibility, per-user MCP sessions, modern remote transport support.
**Avoids:** The auth-collapse pitfall and the legacy-SSE-first transport pitfall.

### Phase 2: Harvest Index and Managed Service Catalog
**Rationale:** Maximal coverage should come from existing Moodle externals before any wrapper expansion; the connector needs a canonical site-wide inventory.
**Delivers:** installed external discovery, schema normalization, managed connector service sync, provenance markers for harvested versus wrapper tools, MUC-backed catalog caches.
**Addresses:** automatic exposure of core, module, and plugin externals; canonical tool taxonomy; rich metadata.
**Uses:** Moodle External API, service registry, and MUC from the recommended stack.
**Implements:** the harvest indexer, managed service synchronizer, and wrapper registry boundaries.

### Phase 3: Permission Engine and Safe Tool Discovery
**Rationale:** Discovery is dangerous until the connector can prove why a tool is visible and why it should stay hidden elsewhere.
**Delivers:** exact versus advisory policy layers, context resolvers, per-user visible catalog filtering, destructive-risk tags, confirmation requirements, paginated `tools/list`, access explanations.
**Addresses:** strict permission and context enforcement, safe catalog publication, better connector UX.
**Uses:** Access API, wrapper static eligibility, and authoritative call-time checks.
**Implements:** the tool catalog assembler and policy engine.

### Phase 4: Core User Surface and File-Orchestrated Workflows
**Rationale:** Once auth, harvest, and permission boundaries are stable, the fastest path to user value is the large set of already-exposed read and participation workflows.
**Delivers:** courses, content, completion, calendar, messaging, profiles, private files, file upload/download and draft orchestration, plus high-value activity flows for assign, forum, quiz, workshop, and feedback.
**Addresses:** core learning and collaboration surface, assessment participation, file retrieval and submission.
**Avoids:** false coverage claims caused by ignoring draft files, format fields, or other Moodle workflow side effects.

### Phase 5: Operator Surface and Course Authoring
**Rationale:** Operator-grade mutations belong after the connector has already proven safe identity, discovery, and confirmation patterns.
**Delivers:** users, enrolments, groups, cohorts, roles, course/category/section/activity management, initial typed wrappers for authoring gaps, async workflow handling for long-running actions.
**Addresses:** people and access administration, course authoring and structure, destructive action safety.
**Avoids:** broad destructive exposure and fragile wrappers built on UI scripts instead of stable application logic.

### Phase 6: Advanced Wrapper Programs
**Rationale:** The highest-value remaining gaps are also the least standard and most likely to force redesign if rushed.
**Delivers:** component-by-component coverage inventory, wrapper framework hardening, targeted wrappers for question bank, gradebook, badges, competencies, privacy workflows, and high-demand plugin-specific gaps.
**Addresses:** true maximal coverage and the strongest product differentiators.
**Avoids:** the "existing externals equal full coverage" pitfall and wrapper behavior mismatches that skip events, completion, or transactions.

### Phase 7: Compatibility, Performance, and Release Hardening
**Rationale:** Moodle 4.2+ support, large plugin catalogs, reverse proxies, and negative authorization behavior are release criteria, not polish.
**Delivers:** Moodle 4.2-4.5 CI matrix, compat-layer cleanup, cache invalidation hooks, pagination, audit logging, proxy/CORS validation, large-catalog tests, negative permission tests, SSO smoke tests, transport regression gate.
**Addresses:** upgrade resilience, auditability, large-site readiness, and production release confidence.
**Avoids:** version drift, stale-catalog bugs, and happy-path-only releases.

### Phase Ordering Rationale

- Auth and transport come first because the connector cannot safely promise per-user discovery or execution until it can bind MCP requests to a real Moodle identity.
- Harvest and managed service sync come before wrappers because the majority of useful Moodle coverage already exists in declared externals; the roadmap should capture that value before inventing new APIs.
- Permission filtering must precede broad feature exposure because a large raw tool list is worse than an incomplete one if it teaches the model to rely on unsafe or unusable actions.
- Core user and operator surfaces should be delivered before wrapper-heavy domains so the project proves the model on stable, well-documented externals and shared file/safety patterns.
- Compatibility, caching, and verification deserve their own late phase, but their boundaries should be designed early because they influence every preceding phase.

### Research Flags

Phases likely needing deeper research during planning:
- **Phase 1:** site-specific SSO behavior, Claude Code remote auth expectations, and reverse-proxy/header-forwarding constraints can vary by deployment.
- **Phase 5:** content-module authoring and async copy/import workflows vary across modules and course format APIs; wrapper scope needs tighter inventory work.
- **Phase 6:** question bank, gradebook, badge administration, and plugin-specific UI gaps are wrapper-heavy and do not have one standard upstream pattern.
- **Phase 7:** large-site cache invalidation and transport behavior behind real proxy stacks should be validated against expected deployment shapes before final hardening scope is fixed.

Phases with standard patterns (skip research-phase):
- **Phase 2:** harvesting installed externals, normalizing schemas, and syncing a connector-managed service are strongly supported by Moodle's documented external-service patterns.
- **Phase 3:** provenance-aware catalog assembly and pessimistic discovery rules can be planned directly from the current research without another broad discovery pass.
- **Phase 4:** core courses, files, completion, calendar, messaging, and the main activity modules already have strong documented external surfaces and known file-handling patterns.

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | Strongly supported by official Moodle docs, MCP specs, Claude Code guidance, and local Moodle source inspection. |
| Features | HIGH | The broad coverage claims are grounded in local Moodle service inventories plus consistent official API documentation. |
| Architecture | MEDIUM | The boundaries are coherent and well-supported, but the exact bootstrap-token design and managed-service composition are still architectural synthesis rather than a turnkey upstream recipe. |
| Pitfalls | MEDIUM | The failure modes are credible and well-grounded, but their real severity depends on site-specific auth plugins, proxies, plugin mix, and wrapper demand. |

**Overall confidence:** MEDIUM

### Gaps to Address

- **Remote credential model:** decide whether maximal mode uses plugin-issued opaque bearer tokens, embedded Moodle tokens, or an OAuth-capable edge relay; validate against target MCP clients before locking the roadmap.
- **Permission heuristics on real sites:** test tool visibility on a fixture with custom roles, groups, hidden activities, and third-party plugins before promising broad automatic discovery.
- **Wrapper-heavy domains:** perform explicit domain inventories for question bank, gradebook, badge admin, and rich content authoring so later phases are driven by real action gaps rather than function counts.
- **Deployment assumptions:** confirm reverse-proxy behavior, header forwarding, long-lived connection limits, and session handling on likely hosting targets before deciding whether a companion service is necessary.
- **Catalog size controls:** define pagination and publication thresholds early so large plugin-heavy sites do not produce unusable `tools/list` payloads.

## Sources

### Primary (HIGH confidence)
- `/home/yui/Documents/moodle-mcp/.planning/research/STACK.md` — stack, transport, version, and runtime recommendations backed by official docs and local Moodle source.
- `/home/yui/Documents/moodle-mcp/.planning/research/FEATURES.md` — feature prioritization, harvest-versus-wrapper boundaries, and rollout guidance.
- Official Moodle developer docs on External Services, Access API, and MUC — external functions, security, service declarations, caching, and compatibility boundaries.
- MCP specification and Claude Code documentation — Streamable HTTP, authorization expectations, Origin validation, and SSE deprecation.
- `/home/yui/Documents/moodle-mcp/tmp/moodle` — local Moodle reference source for webservice auth behavior, service registration, routing limits, and external API internals.

### Secondary (MEDIUM confidence)
- `/home/yui/Documents/moodle-mcp/.planning/research/ARCHITECTURE.md` — recommended component boundaries, data flow, and staged build order.
- `/home/yui/Documents/moodle-mcp/.planning/research/PITFALLS.md` — failure modes, phase mapping, and release-gate risks.
- `/home/yui/Documents/moodle-mcp/.planning/PROJECT.md` — product scope, constraints, and compatibility targets.

### Tertiary (LOW confidence)
- None — this summary relies on primary research and secondary synthesis only.

---
*Research completed: 2026-04-21*
*Ready for roadmap: yes*
