# Architecture Research

**Domain:** Moodle-native MCP connector (webservice protocol plugin plus native login bootstrap)
**Researched:** 2026-04-21
**Confidence:** MEDIUM

## Standard Architecture

### System Overview

```text
┌──────────────────────────── Client Layer ─────────────────────────────┐
│ Claude Code and other MCP clients                                     │
│  - Streamable HTTP (primary)                                          │
│  - Legacy HTTP+SSE endpoints (optional compatibility)                 │
└──────────────────────┬───────────────────────────────┬────────────────┘
                       │ browser login / SSO           │ token/session-token MCP traffic
                       │                               │
            ┌──────────▼──────────┐          ┌─────────▼─────────┐
            │ Bootstrap Auth Path │          │ MCP Transport Path │
            │ launch.php/connect  │          │ server.php         │
            │ require_login()     │          │ WS_SERVER          │
            │ SSO redirect return │          │ Streamable HTTP    │
            │ connector token mint│          │ optional legacy SSE│
            └──────────┬──────────┘          └─────────┬─────────┘
                       │                               │
                       └──────────────┬────────────────┘
                                      ▼
                    ┌──────────────────────────────────────┐
                    │ Connector Application Core           │
                    │  - auth/session resolver             │
                    │  - managed service synchronizer      │
                    │  - catalog assembler                 │
                    │  - visibility/policy engine          │
                    │  - invocation dispatcher             │
                    │  - stream session manager            │
                    └──────────────┬──────────────┬────────┘
                                   │              │
                    ┌──────────────▼───┐      ┌───▼────────────────┐
                    │ Harvested         │      │ Wrapper Action      │
                    │ external catalog  │      │ registry            │
                    │ external_api info │      │ classes/external/*  │
                    │ service sync      │      │ plugin app services │
                    └──────────────┬────┘      └────┬────────────────┘
                                   │                │
                                   └───────┬────────┘
                                           ▼
                              Moodle Core APIs and Data
                     Access API, External API, webservice manager,
                     MUC, context tree, enrolment, sessions, plugins

Optional seam:
  Companion transport service can sit in front of "MCP Transport Path"
  for SSE/session replay only. It must not own auth, discovery, or execution.
```

### Component Responsibilities

| Component | Responsibility | Typical Implementation |
|-----------|----------------|------------------------|
| Bootstrap auth controller | Handle Moodle-native login, SSO return, context scoping, and connector capability gate before any MCP credential is issued | `launch.php` or `connect.php` plus `classes/local/auth/*` |
| Connector credential issuer | Mint session-linked connector credentials by default, permanent credentials only on explicit operator path | Bridge over `\core_external\util::generate_token()` / embedded-token APIs |
| Managed service synchronizer | Maintain one connector-owned external service as the canonical exposure surface for harvested externals and wrappers | `classes/local/catalog/harvest/service_sync.php` backed by `webservice` manager methods |
| Harvest indexer | Discover all candidate external functions, load metadata, map schemas, and mark deprecated/read/write/login/session flags | `classes/local/catalog/harvest/*` |
| Wrapper registry | Hold plugin-owned gap-filling actions separately from harvested externals, with optional callback-based contributions from other plugins | `classes/external/*` plus `classes/local/catalog/wrappers/*` |
| Tool catalog assembler | Merge managed-service harvest and wrappers into a unified MCP tool view while preserving provenance | `classes/local/catalog/tool_catalog.php` |
| Visibility and policy engine | Apply exact filters where Moodle gives an exact boundary, annotate advisory hints where it does not, and attach risk/confirmation metadata | `classes/local/policy/*` |
| Invocation dispatcher | Route calls to harvested externals or wrappers, normalize results, and shape MCP errors consistently | `classes/local/invoke/*` |
| Transport and stream manager | Implement Streamable HTTP headers/session handling, optional legacy SSE endpoints, event IDs, replay cursors, Origin validation, and permanent-vs-embedded token mode resolution | `classes/local/transport/*` and `classes/local/stream/*` |
| Compatibility bridge | Isolate Moodle-version-sensitive touchpoints so the rest of the connector stays free of scattered version checks | `classes/local/compat/*` |

## Recommended Project Structure

```text
classes/
├── local/
│   ├── auth/             # Bootstrap login flow, connector capability gate, token/session issuance
│   ├── transport/        # Streamable HTTP controller, legacy SSE compatibility, JSON-RPC envelopes
│   ├── stream/           # MCP session IDs, event buffers, replay cursors
│   ├── catalog/
│   │   ├── harvest/      # External discovery, schema extraction, managed-service sync
│   │   ├── wrappers/     # Wrapper descriptors and optional plugin contributions
│   │   └── cache/        # MUC-backed site/service catalog caches
│   ├── policy/           # Visibility rules, risk tags, confirmation requirements
│   ├── invoke/           # External executor, wrapper executor, result normalization
│   └── compat/           # Moodle 4.2+ shims and future deprecation fences
├── external/             # Plugin-owned wrapper externals exposed through db/services.php
└── privacy/              # Existing privacy provider
db/
├── access.php            # Connector capabilities
├── caches.php            # Site index, service catalog, stream-state definitions
└── services.php          # Wrapper functions and connector-managed service declaration
server.php                # WS_SERVER MCP endpoint; token/session-token only
launch.php                # Normal Moodle session + SSO bootstrap
sse.php                   # Optional legacy SSE endpoint
message.php               # Optional legacy SSE POST back-channel
settings.php              # Origin allowlist, managed service config, auth mode, hardening toggles
```

### Structure Rationale

- **`server.php`:** Keep it as the token-authenticated protocol entrypoint only. Do not mix Moodle cookie login into this file because `WS_SERVER` forces `NO_MOODLE_COOKIES`.
- **`launch.php`:** Put all Moodle-native login and SSO behavior here so the connector can reuse `require_login()` and whatever auth plugins the site already uses.
- **`classes/local/catalog/`:** Discovery, indexing, provenance, and service-sync logic change for different reasons than invocation or transport; they need their own boundary.
- **`classes/external/`:** Every wrapper action should be a first-class Moodle external function, not a bespoke page controller. That keeps wrappers consistent with core execution and security rules.
- **`classes/local/compat/`:** All version checks and core bridge code belong here. The rest of the codebase should depend on stable local interfaces, not on Moodle version branches.
- **`db/caches.php`:** Caches are not incidental here; the connector needs explicit cache areas for harvest indexes, service catalogs, and streaming state.

## Architectural Patterns

### Pattern 1: Two-Path Auth Architecture

**What:** Separate browser/session login bootstrap from MCP transport execution. Use Moodle cookies only on the bootstrap path. Use connector tokens or session-linked embedded tokens on the MCP endpoint.
**When to use:** Always, if the connector must support Moodle-native login or SSO and still behave like a normal Moodle webservice protocol plugin.
**Trade-offs:** One extra bootstrap round-trip, but much less risk than trying to make `server.php` both a Moodle page and a webservice endpoint.

**Example:**
```php
// launch.php: normal Moodle session path.
require_once(__DIR__ . '/../../config.php');
require_login(0, false);
require_capability('webservice/mcp:use', $restrictedcontext);

$token = \core_external\util::generate_token(
    EXTERNAL_TOKEN_EMBEDDED,
    $service,
    $USER->id,
    $restrictedcontext
);

// server.php: token-only MCP path.
define('WS_SERVER', true);
require('../../config.php');
$authmethod = $resolver->resolve_authmethod_from_token($rawtoken);
$server = new \webservice_mcp\local\transport\server($authmethod);
$server->run();
```

### Pattern 2: Dual Catalog With Provenance

**What:** Maintain harvested externals and wrapper endpoints as separate registries and merge them only at the last possible moment when building the MCP catalog.
**When to use:** Any maximal connector that must expose both native Moodle externals and plugin-owned actions filling UI/API gaps.
**Trade-offs:** Slightly more metadata to manage, but much cleaner invalidation, testing, conflict handling, and roadmap sequencing.

**Example:**
```php
$harvested = [
    'id' => 'harvest:core_course_get_courses',
    'name' => 'core_course_get_courses',
    'origin' => 'harvest',
    'executor' => ['type' => 'external', 'function' => 'core_course_get_courses'],
];

$wrapper = [
    'id' => 'wrapper:webservice_mcp_duplicate_course',
    'name' => 'mcp_duplicate_course',
    'origin' => 'wrapper',
    'executor' => ['type' => 'wrapper', 'function' => 'webservice_mcp_duplicate_course'],
];
```

### Pattern 3: Authoritative Invocation, Advisory Discovery

**What:** Use discovery-time filters only where Moodle exposes exact, stable boundaries. Treat declared capability strings and schema heuristics as hints. Let actual external or wrapper execution remain the source of truth.
**When to use:** Always for harvested externals, because many Moodle permissions are context- and object-specific.
**Trade-offs:** Some tools may stay visible until call time even when the user cannot use them in a given object context. That is acceptable if the connector clearly distinguishes exact versus advisory signals and always re-checks on invocation.

**Example:**
```php
// Safe for discovery:
$tool['servicebound'] = true;
$tool['restrictedcontextok'] = $policy->context_scope_allows($tool, $identity);

// Advisory only:
$tool['capabilityHints'] = $function->capabilities; // never final auth

// Final authority:
return $externalexecutor->call($tool['executor']['function'], $args);
```

### Pattern 4: Edge-Only Companion Service Seam

**What:** If a companion service is ever required, place it above the connector core as a transport relay only. It may terminate SSE, hold replay buffers, and manage MCP stream sessions, but it must not decide what tools exist or whether a tool call is allowed.
**When to use:** Only if Moodle hosting or reverse proxies make long-lived Streamable HTTP/SSE sessions unreliable, or if horizontal stream fanout becomes an operational requirement.
**Trade-offs:** Extra deployment and observability surface, but no split-brain on permissions because Moodle remains the source of truth.

**Example:**
```php
interface connector_backend {
    public function list_tools(identity $identity): array;
    public function call_tool(identity $identity, string $toolname, array $args): array;
}

// Companion may proxy this interface.
// It must not implement its own ACL or wrapper logic.
```

## Permission-Resolution Boundaries

| Boundary | Exact? | Where it belongs | Cache boundary | Notes |
|----------|--------|------------------|----------------|-------|
| Bootstrap connector entry gate (`require_login`, SSO completion, explicit `webservice/mcp:use` or equivalent) | Exact | `launch.php` / auth controller | No cross-request auth cache | Required because session-token auth in core webservice flow does not enforce the protocol capability for you |
| Token validity (expiry, IP restriction, user active/suspended/deleted, restricted context, service id) | Exact | transport auth resolver / core webservice auth | Request only | This is a hard security boundary and should come from Moodle core logic |
| Managed-service membership and protocol enablement | Exact | service synchronizer + transport bootstrap | Application cache is fine | Determines which harvested/wrapper functions are even eligible to be shown |
| Declared `capabilities` string in `db/services.php` | Advisory only | catalog annotation | Application cache is fine | Useful for docs and risk tags; not safe for authorization |
| Wrapper static eligibility (feature flag, exact global capability, connector mode) | Exact | wrapper registry / policy engine | Request or very short-lived session cache | Wrapper descriptors can provide exact filters because the plugin owns them |
| Course/module/object-specific access (`validate_context`, enrolment, availability, groups, `require_capability`) | Exact | inside harvested external or wrapper external execution | Never trust cross-request cache for allow/deny | This is the final permission boundary |
| Destructive confirmation and human-in-the-loop gates | Exact | invocation dispatcher / wrapper policy | Request only | Needed for dangerous actions even when capability checks pass |

## Caching and Indexing Boundaries

| Cache | Scope | Key | Contents | Invalidated by | Must never be used for |
|-------|-------|-----|----------|----------------|------------------------|
| `site_harvest_index` | Application | site identifier + connector revision | Candidate harvested externals, schema maps, wrapper fingerprints | plugin upgrade, cache purge, connector settings change, explicit rebuild | final authorization |
| `managed_service_catalog` | Application | service id + harvest revision | Service-bound tool descriptors after sync | service resync, cache purge, explicit rebuild | final authorization |
| `visible_catalog` | Request by default, optional short-lived session cache | user id + service id + restricted context id + language + catalog revision | `tools/list` payload after visibility filtering | every request by default, or TTL expiry if session cached | tool-call allow/deny |
| `mcp_stream_session` | Application or shared cache/DB | MCP session id | stream session metadata, chosen transport, last activity, current stream ids | session DELETE, idle timeout, cache purge | user authentication |
| `mcp_event_replay` | Application or shared cache/DB | MCP session id + stream id | SSE event ids and replay buffer | stream close, idle timeout, cache purge | user authentication or permission decisions |

**Recommended rule:** cache discovery aggressively, cache visibility carefully, and never cache authorization as if it were exact across requests. Invocation must always be authoritative.

## Stable vs Fragile Across Moodle 4.2+

| Surface | Stability | Recommendation |
|---------|-----------|----------------|
| `db/services.php` declarations and service discovery into Moodle's webservice tables | Stable | Use as the canonical contract for wrapper functions and managed connector service definitions |
| Namespaced external functions in `classes/external/*` extending `\core_external\external_api` | Stable from 4.2+ | Make this the default wrapper implementation pattern |
| `\core_external\external_api::external_function_info()`, `validate_parameters()`, `validate_context()`, `call_external_function()` | Stable core bridge | Build harvested metadata and internal execution adapters on this surface |
| Access API (`context_*`, `has_capability`, `require_capability`, `require_login`) | Stable | Use for exact permission checks and bootstrap auth |
| MUC (`db/caches.php`, `cache::make`) | Stable | Use for site/service indexes and optional stream state |
| `webservice` manager methods (`get_external_functions`, `get_external_service_by_shortname`, service add/remove) | Stable enough and better than scattered table reads | Keep raw `external_*` table access contained inside harvest source classes only |
| Embedded/permanent token utilities in `\core_external\util` | Stable enough for 4.2+ | Prefer these for connector credential issuance |
| Legacy `lib/externallib.php` alias path | Fragile and already on the deprecation path | Keep any fallback inside `compat/` only; do not make it the main architecture |
| Page controllers, form submit scripts, or JS AJAX endpoints as wrapper backends | Fragile | Do not build wrappers by calling UI scripts; wrap stable internal APIs or create dedicated application services |
| Declared function `capabilities` as security truth | Fragile | Treat as advisory metadata only |
| Legacy HTTP+SSE as the only remote transport | Fragile | Support it only as a compatibility layer; make Streamable HTTP the primary architecture |

## Data Flow

### Request Flow

```text
[Browser Login / SSO]
    ↓
[launch.php]
    ↓
[managed service + restricted context + connector token]
    ↓
[server.php Streamable HTTP or legacy SSE]
    ↓
[catalog / policy / dispatcher]
    ↓
[harvested external or wrapper external]
    ↓
[Moodle core APIs]
    ↓
[MCP result + optional progress/log SSE events]
```

### State Management

```text
[MUC Application Cache]
    ↓
site_harvest_index
managed_service_catalog
mcp_stream_session
mcp_event_replay

[Request Cache]
    ↓
identity resolution
visible catalog resolution
tool descriptor lookup

[Optional Session Cache]
    ↓
recent tools/list payload (UX only, short TTL, advisory only)
```

### Key Data Flows

1. **Auth and bootstrap**
   - The user starts in a normal Moodle page flow, not the MCP transport endpoint.
   - `launch.php` calls `require_login()`, lets Moodle/auth plugins complete SSO, and explicitly checks connector capability before issuing any MCP credential.
   - The default credential should be a session-linked embedded token scoped to a managed connector service and the narrowest practical Moodle context. Permanent tokens stay as an explicit legacy/operator path.
   - `Mcp-Session-Id` is created later during MCP initialization and is transport state only, not an auth credential.

2. **Harvest, sync, and discovery**
   - Harvest indexer discovers all candidate externals site-wide using Moodle's external metadata APIs and marks wrappers separately.
   - Managed service synchronizer materializes the connector's real exposure surface as one Moodle external service. That is the bridge between "all installed externals" and "functions a connector token can actually execute".
   - `tools/list` intersects the harvest index with the managed service, the user's token service, and the token's restricted context.
   - Exact filters remove deprecated or out-of-scope tools. Advisory metadata stays attached but never authorizes.

3. **Filtering and invocation**
   - The dispatcher resolves the tool by stable internal id or public name and checks exact connector policy first.
   - Transport auth resolves the raw credential into permanent-token or session-token mode before handing off to the core webservice authentication flow.
   - Harvested externals execute through a core-bridge adapter. Wrapper tools execute as plugin external functions and then hand off to internal service classes.
   - Wrappers must follow the standard external-function sequence: validate parameters, validate context, require capability, call internal API, validate response.
   - Cached visibility is never enough to authorize execution.

4. **Streaming and resumability**
   - Streamable HTTP is primary: POST for requests, GET for optional SSE notifications and replay.
   - Legacy HTTP+SSE compatibility, if needed, lives in separate transport adapters so it can be removed without touching catalog or policy code.
   - Stream manager stores per-session stream ids and replay cursors. `Last-Event-ID` resumes a stream buffer, not a user session.
   - If PHP hosting proves unreliable for long-lived streams, move only the stream-session and replay responsibilities to a companion service.

## Roadmap Build Order

1. **Extract a transport-independent connector core**
   - Move catalog, policy, and execution logic out of the current monolithic `classes/local/server.php`.
   - Keep the existing token-based endpoint working while the internals are modularized.

2. **Add Moodle-native bootstrap auth**
   - Introduce `launch.php` and session-linked connector token issuance.
   - Enforce connector capability and restricted-context selection here.

3. **Build the harvest index and managed service sync**
   - Discover installed externals, create a connector-owned service boundary, and persist site/service catalogs in MUC.
   - This is the foundation for maximal coverage without abandoning Moodle's native service model.

4. **Add provenance-aware tool catalog and policy engine**
   - Merge harvest and wrappers late, preserve origin, and separate exact filters from advisory metadata.
   - This is where permission-aware discovery becomes defensible.

5. **Implement high-value wrapper externals**
   - Add plugin-owned wrapper actions only for real coverage gaps.
   - Each wrapper should sit on top of an internal application service, not a page script.

6. **Add Streamable HTTP session management and optional legacy SSE**
   - Support `Mcp-Session-Id`, replay cursors, and Origin validation.
   - Keep SSE compatibility behind a transport adapter boundary.

7. **Introduce a companion service only if transport operations demand it**
   - If long-lived streams, replay, or horizontal scaling become the bottleneck, offload transport state only.
   - Do not split discovery, policy, or execution out of Moodle unless plugin-first architecture has genuinely been exhausted.

## Scaling Considerations

| Scale | Architecture Adjustments |
|-------|--------------------------|
| 0-1k users | Pure plugin deployment is fine. Use application caches for harvest/service catalogs, keep stream state in Moodle cache, and default to plugin-handled Streamable HTTP |
| 1k-100k users | Make service sync explicit, keep visibility filtering request-scoped, add short replay buffers for streams, and harden Origin/CORS/config toggles. Treat legacy SSE as compatibility only |
| 100k+ users | Expect transport operations to become the first pain point. Move stream session/replay handling to a companion edge service if proxies or PHP workers cannot sustain long-lived streams. Keep auth/catalog/policy/execution in Moodle |

### Scaling Priorities

1. **First bottleneck:** repeated harvest/schema generation
   - Fix with application-scoped MUC caches and explicit service-sync revisions.
2. **Second bottleneck:** long-lived stream handling and replay buffers
   - Fix with transport adapters, bounded event buffers, and the optional companion-service seam if hosting realities require it.

## Anti-Patterns

### Anti-Pattern 1: Making `server.php` Cookie-Aware

**What people do:** Try to run `require_login()` or depend on Moodle cookies directly inside the MCP transport endpoint.
**Why it's wrong:** In Moodle, `WS_SERVER` implies `NO_MOODLE_COOKIES`, so mixing page-session logic into the webservice endpoint fights core assumptions and breaks clean auth boundaries.
**Do this instead:** Keep browser login and SSO in `launch.php`; issue a connector token there; keep `server.php` token-only.

### Anti-Pattern 2: Using Declared Capability Strings as Real Authorization

**What people do:** Read the `capabilities` string from `db/services.php` and treat it as a definitive allow/deny rule.
**Why it's wrong:** It is advisory metadata, not a full context-aware permission evaluation. It ignores enrolment, groups, activity availability, overrides, and object-level checks.
**Do this instead:** Use capability strings as hints only. Let the external or wrapper execute its own `validate_context()` and `require_capability()` flow.

### Anti-Pattern 3: Collapsing Harvested and Wrapper Tools Into One Mutable List

**What people do:** Store every tool in one undifferentiated registry with no origin metadata.
**Why it's wrong:** Harvested externals and wrappers have different invalidation rules, different security contracts, and different roadmap sequencing.
**Do this instead:** Keep separate registries, merge late, and preserve provenance in every descriptor.

### Anti-Pattern 4: Building Wrappers by Calling UI Pages or AJAX Scripts

**What people do:** Simulate form submissions or call page controllers directly because the UI already performs the action.
**Why it's wrong:** Those paths are the most fragile across Moodle minors, often depend on page/session state, and are harder to secure and test.
**Do this instead:** Expose a real wrapper external and have it call a dedicated internal application service or stable underlying API.

## Integration Points

### External Services

| Service | Integration Pattern | Notes |
|---------|---------------------|-------|
| Moodle External API | Bridge adapter around `external_api::external_function_info()` and execution wrappers | Source of truth for harvested schemas and core execution semantics |
| Moodle webservice manager | Use `webservice` manager methods for service lookup and service-function sync | Better boundary than scattering raw `external_*` table queries |
| Moodle Access and Session APIs | Bootstrap uses `require_login()`; wrappers use `validate_context()` and capability checks; token issuance uses `\core_external\util` | Exact permission and identity boundary |
| MUC | Application/request/session cache definitions in `db/caches.php` | Use for catalogs and stream state, never final auth |
| MCP clients | Streamable HTTP primary, optional legacy SSE compatibility | Validate `Origin`, support `MCP-Protocol-Version`, `Mcp-Session-Id`, and `Last-Event-ID` |
| Optional companion service | Thin transport relay only | Never own tool discovery, permission checks, or wrapper execution |

### Internal Boundaries

| Boundary | Communication | Notes |
|----------|---------------|-------|
| Bootstrap auth ↔ credential issuer | direct PHP API | Issue tokens only after normal Moodle auth and explicit connector capability checks |
| Harvest indexer ↔ managed service synchronizer | immutable descriptor data | Sync service exposure from harvest; do not let runtime policy mutate harvest state |
| Catalog assembler ↔ policy engine | descriptor objects + identity context | Policy annotates and filters, but does not change descriptor provenance |
| Policy engine ↔ invocation dispatcher | allow/deny + risk metadata | Dispatcher still performs final execution-time checks |
| Transport layer ↔ optional companion service | HTTP/JSON-RPC over a narrow backend interface | Companion may proxy transport state only; Moodle plugin remains source of truth |

## Sources

- Moodle External Services docs: `https://moodledev.io/docs/5.0/apis/subsystems/external`
- Moodle Function Declarations docs: `https://moodledev.io/docs/5.0/apis/subsystems/external/description`
- Moodle Function Definitions docs: `https://moodledev.io/docs/5.1/apis/subsystems/external/functions`
- Moodle External Security docs: `https://moodledev.io/docs/5.1/apis/subsystems/external/security`
- Moodle Access API docs: `https://moodledev.io/docs/5.0/apis/subsystems/access`
- Moodle Cache API docs: `https://moodledev.io/docs/5.0/apis/subsystems/muc`
- Moodle component communication guidance: `https://moodledev.io/general/development/policies/component-communication`
- MCP transport spec: `https://modelcontextprotocol.io/specification/2025-06-18/basic/transports`
- Local Moodle core references:
  - `tmp/moodle/lib/external/classes/external_api.php`
  - `tmp/moodle/webservice/lib.php`
  - `tmp/moodle/lib/external/classes/util.php`
  - `tmp/moodle/lib/externallib.php`
  - `tmp/moodle/lib/ajax/service.php`
  - `tmp/moodle/admin/tool/mobile/launch.php`
  - `tmp/moodle/lib/setup.php`
- Local plugin references:
  - `server.php`
  - `classes/local/server.php`
  - `classes/local/tool_provider.php`
  - `db/access.php`

---
*Architecture research for: Moodle-native MCP connector*
*Researched: 2026-04-21*
