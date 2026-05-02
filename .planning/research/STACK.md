# Stack Research

**Domain:** Maximal Moodle-native MCP connector for Moodle 4.2+
**Researched:** 2026-04-21
**Confidence:** HIGH

## Recommended Stack

### Core Technologies

| Technology | Version | Purpose | Why Recommended |
|------------|---------|---------|-----------------|
| Host Moodle plugin runtime (`webservice_mcp`) | Moodle 4.2+ | Main runtime for auth, discovery, execution, wrappers, and admin UX | Keep the authority inside Moodle. The connector should use Moodle as the system of record for users, enrolments, roles, capabilities, contexts, and installed plugins. |
| PHP in the host Moodle support window | Minimum syntax target: PHP 8.0 | Runtime language for all plugin-first logic | Moodle 4.2+ already owns the PHP runtime. Writing the connector in PHP avoids a split permission model and keeps deployment as a normal Moodle plugin install. |
| Moodle External Services API (`\core_external\external_api`) | 4.2+ namespaced API | Introspect external functions, validate inputs/outputs, implement wrappers | This is Moodle's self-describing API layer. It already models parameters, return values, deprecation, AJAX eligibility, login requirements, and wrapper authoring patterns. |
| Moodle external registry tables (`external_functions`, `external_services`, `external_services_functions`, `external_services_users`, `external_tokens`) | Core 4.2+ | Source of truth for installed external APIs, service membership, and legacy token mode | Automatic harvesting should read Moodle's own registry, not a parallel connector catalog. Moodle already populates these tables from every component's `db/services.php` on install and upgrade. |
| Plugin-owned connector grant/token layer | Plugin schema | User-scoped bearer auth for maximal connector mode after Moodle login/SSO | Core web service tokens are service-scoped. A maximal connector needs user-scoped, dynamically filtered discovery that is not limited by a single predeclared external service. |
| Moodle Cache API (MUC) | Core 4.2+ | Cache normalized function metadata and per-user filtered tool lists | `external_function_info()` plus full registry scans are too expensive to rebuild on every `tools/list`. Use MUC for registry and filter caches, with clear invalidation. |

### Supporting Libraries

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `\core_external\external_api::external_function_info()` | 4.2+ | Load executable metadata for every harvested function | Always. Treat it as the canonical metadata loader for class name, parameter schema, return schema, AJAX/login flags, and deprecation state. |
| `db/services.php` declarations | 4.2+ | Register harvested externals and plugin-owned wrappers | Always. Use this for all wrapper functions and any optional built-in service declarations. |
| `\core\session\manager` | 4.2+ | Session-safe login bootstrap and session lock release for streaming | Always. Authenticate first, then call `write_close()` before SSE or long-running tool execution so one connector request does not lock the user's whole Moodle session. |
| `require_login()`, `core_user::require_active_user()`, auth plugins, `auth_oauth2` login flow | 4.2+ | Reuse site login and SSO instead of inventing connector credentials | Always for interactive login. Let Moodle's existing login page and auth plugins handle SSO, MFA, and linked-login behavior. |
| `external_update_descriptions()` / `external_update_services()` | Core install/upgrade flow | Auto-register new externals from core and installed plugins | Always rely on this registry lifecycle. Do not hand-maintain a connector-specific function list. |
| `ajax`, `loginrequired`, `readonlysession`, `capabilities`, `services` flags in `db/services.php` | 4.2+ | Classify wrapper and harvested functions | Always on wrappers. Also consume these flags when building the connector's direct-call vs wrapper-only decision tree. |
| `external_create_service_token()` and embedded tokens | 4.2+ | Session-bound experiments for browser-embedded clients | Only for same-session or browser-embedded usage. Do not make this the primary remote connector auth model. |
| Optional companion gateway: Node.js LTS + TypeScript + `@modelcontextprotocol/sdk` 2.x | Fallback only | Public Streamable HTTP, legacy SSE compatibility, and OAuth-facing connector edge | Use only when remote client transport and OAuth UX requirements are strong enough to justify a second runtime. Keep it transport-only. |

### Development Tools

| Tool | Purpose | Notes |
|------|---------|-------|
| `moodle-plugin-ci` `^4` | Version and database matrix CI | Expand the matrix to Moodle 4.2, 4.3, 4.4, and 4.5 with MariaDB and PostgreSQL. |
| PHPUnit (`advanced_testcase`, `externallib_advanced_testcase`) | Unit and integration tests | Cover harvesting, schema translation, auth grants, permission filters, and wrapper execution. |
| Behat | Browser login and SSO bootstrap tests | Use for the interactive login-to-connector-grant flow and any browser-mediated auth handoff. |
| Optional sidecar tests (`node:test` or `vitest`) | Gateway contract tests | Only if the fallback Node/TypeScript gateway exists. Use it to test Streamable HTTP, legacy SSE compatibility, and OAuth discovery. |

## Installation

```bash
# Plugin-first runtime
cp -R /path/to/moodle-mcp <moodle>/webservice/mcp

# CI tooling
composer create-project --no-interaction moodlehq/moodle-plugin-ci ci '^4'

# Optional companion gateway only when justified
npm install @modelcontextprotocol/sdk express zod
npm install -D typescript @types/express
```

## Recommended Implementation Approach

### 1. Default architecture: plugin-first

- Keep auth, permission checks, tool discovery, tool execution, wrapper logic, and audit behavior inside the Moodle plugin.
- Keep the current `webservice_base_server` path for legacy permanent-token usage and existing MCP compatibility.
- Add a second connector path in the same plugin for maximal mode:
  - interactive login/bootstrap endpoints
  - a Streamable HTTP MCP endpoint
  - optional legacy SSE compatibility endpoints only if an actual client still needs them
- Do not move business logic into a sidecar. If a sidecar exists, it should only terminate transport and delegate to Moodle.

### 2. Authentication model

Use these auth modes in this order:

1. **Primary for maximal mode:** Moodle login/SSO -> plugin-owned connector grant/token
   - Redirect through normal Moodle login using `require_login()` and whatever auth plugin the site already uses.
   - After successful login, mint a plugin-owned opaque bearer token bound to:
     - `userid`
     - expiry
     - revocation state
     - optional client/device label
     - optional session binding for stricter deployments
   - Use that bearer token on the MCP transport endpoint.

2. **Secondary for legacy/admin mode:** existing permanent web service tokens
   - Keep this for administrators, automation, and backwards compatibility with the current plugin architecture.
   - Treat this as service-scoped mode, not the maximal connector mode.

3. **Niche mode only:** embedded/session-linked web service tokens
   - Useful for browser-embedded or same-session clients.
   - Not recommended as the main remote connector auth mechanism because the token model is session-scoped and still fundamentally service-scoped.

Why not use core web service tokens as the main maximal-mode auth:

- They are built around external service membership.
- The project goal is broader than one static service definition.
- `webservice_base_server` is intentionally cookie-free, so direct Moodle session auth does not slot cleanly into the current web service server pipeline.

Why not use `login/token.php` as the main login path:

- It is username/password plus service-shortname oriented.
- It is not the right abstraction for "use whatever Moodle login and SSO this site already has."
- It pulls the connector back toward permanent token issuance instead of a user-scoped connector session.

### 3. Transport model

- **Primary transport:** Streamable HTTP on a single HTTPS endpoint.
- **Compatibility transport:** legacy SSE only when required by a real client.

Implementation rules:

- Implement the MCP transport as plugin scripts, not as a `route\api` baseline.
- Authenticate first, then call `\core\session\manager::write_close()` before opening an SSE stream or running long tool calls.
- Validate `Origin` on HTTP-based transport endpoints.
- Keep transport state minimal and stateless where possible. Persist only what the client actually needs for resumability or reconnects.

Why plugin scripts instead of Moodle routing:

- The Routing subsystem is only available **since Moodle 4.5**.
- The 4.5 API route group is therefore not safe as a 4.2+ baseline.
- In Moodle 4.5 source, the API route CORS middleware forces `Content-Type: application/json`, which makes it a poor fit for SSE or other non-JSON streaming responses.

Recommended split:

- `server.php` (or equivalent) remains the MCP transport entrypoint.
- Optional JSON metadata endpoints on 4.5+ may use `route\api`, but the streaming endpoint should stay outside that stack.

### 4. Automatic harvesting of core and plugin externals

Harvest from Moodle's registry, not from one configured service:

- Read every record in `external_functions`.
- For each function:
  - load metadata with `external_function_info()`
  - drop deprecated functions
  - normalize parameter and return descriptions into MCP JSON Schema
  - capture execution metadata:
    - component
    - class
    - method
    - read/write type
    - declared capabilities
    - `ajax`
    - `loginrequired`
    - `readonlysession`
    - built-in service membership

Build the connector registry in three classes:

- **Direct-call externals**
  - Stable external functions that can be executed through the connector runner with no wrapper.
- **Session-sensitive externals**
  - Functions that are only sensible in session-backed mode, or which should stay out of legacy token mode.
- **Wrapper-required actions**
  - UI-only actions or multi-step workflows that need plugin-owned externals.

Cache strategy:

- Use MUC for the normalized registry.
- Invalidate on plugin upgrade, cache purge, or connector configuration change.
- Use a separate short-lived per-user cache for filtered tool lists.

### 5. Permission-aware tool filtering

Filter in layers:

1. **Mode filter**
   - Permanent-token mode: only service-attached functions for the token's service.
   - Maximal connector mode: full harvested registry.

2. **Static metadata filter**
   - Exclude deprecated functions.
   - Exclude wrappers or externals explicitly disabled by site policy.
   - Exclude functions the connector cannot safely classify yet.

3. **Coarse permission filter**
   - Service `requiredcapability`
   - function-declared `capabilities`
   - connector-level allow/deny policy

4. **Context-aware filter**
   - Resolve actual user-relevant contexts: system, course category, course, activity, user, cohort, question bank, gradebook, blog, files, and plugin-specific contexts.
   - Use component-specific access adapters where generic filtering is too coarse.

5. **Call-time enforcement**
   - Underlying externals and wrappers still enforce permissions at execution time.
   - Discovery should be pessimistic; execution remains authoritative.

Recommendation:

- Hide a tool unless the connector can make a defensible case that the user may execute it somewhere meaningful.
- Do not promise "all installed externals" in discovery if the permission filter is still too weak to prove access.

### 6. Wrapper strategy for UI-only actions

- Implement wrappers as normal Moodle external functions under `classes/external` and register them in `db/services.php`.
- Do not use ad-hoc scripts as the main wrapper mechanism.
- In every wrapper:
  - validate parameters
  - resolve the narrowest context
  - call `validate_context()`
  - enforce `require_capability()` or equivalent
  - call the same core API or domain logic the UI already trusts
- Set `ajax`, `loginrequired`, and `readonlysession` deliberately.
- Prefer wrapper functions for:
  - multi-step UI workflows
  - actions that currently only exist behind forms or sesskey flows
  - actions that need normalized inputs/outputs for LLM use
  - destructive operations that need extra guardrails and clearer metadata

### 7. Companion service: when it is actually justified

Do **not** start with a companion service.

Add one only when at least one of these is true:

- You need first-class remote OAuth login for clients like Claude Code `/mcp` and do not want to build a generic OAuth 2.1 authorization server inside Moodle.
- You need public Streamable HTTP with reconnects, resumability, and many concurrent long-lived connections beyond what a typical PHP-FPM Moodle deployment handles comfortably.
- You need legacy SSE plus modern Streamable HTTP support with transport churn isolated away from the Moodle plugin.

If you add a companion service, use:

- **Node.js LTS**
- **TypeScript**
- **official `@modelcontextprotocol/sdk`**

And constrain it to:

- transport termination
- OAuth-facing connector UX
- connection/session state
- optional fanout/reconnect logic

Do **not** let the sidecar:

- evaluate Moodle permissions on its own
- read or write the Moodle database directly
- duplicate wrapper business logic

Instead, the sidecar should call internal plugin endpoints for:

- tool discovery
- tool invocation
- wrapper execution
- grant exchange / auth state

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|-------------|-------------|-------------------------|
| Plugin scripts for MCP transport | `route\api` endpoints | Only for 4.5+ JSON helper endpoints. Do not use as the 4.2+ baseline, and do not use for SSE transport. |
| Harvest from `external_functions` | Service-scoped discovery only | Use service-only discovery when the site intentionally wants admin-curated exposure and does not want a maximal connector. |
| Streamable HTTP as primary transport | Legacy SSE as primary transport | Only when an unavoidable client still requires SSE. Keep it as a compatibility shim, not the center of the design. |
| Plugin-owned connector grants | Reuse `external_tokens` for everything | Use core tokens only for legacy token mode or browser-embedded/session-linked cases. They are too service-centric for maximal mode. |
| Plugin-first runtime | Sidecar-first runtime | Use sidecar-first only if transport or OAuth requirements dominate and the site accepts a two-runtime deployment. |

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| `login/token.php` as the main connector auth path | It is username/password and service-shortname oriented, not "use the site's real login and SSO flow" oriented | Normal Moodle login/SSO followed by plugin-issued connector grants |
| `route\api` as the 4.2+ transport baseline | Routing is 4.5+ only | Plugin scripts under the existing plugin endpoint structure |
| `route\api` for SSE | The API middleware stack is JSON-centric in 4.5 source | Dedicated plugin streaming endpoint |
| `override_webservice_execution` as the main composition model | It is a global interception hook, not a clean connector architecture | Explicit wrappers plus an explicit connector call runner |
| Permanent-token-only architecture | It fails the login/SSO-first requirement and keeps discovery trapped behind service membership | User-scoped connector grants with legacy token mode retained separately |
| Direct database access from an optional sidecar | It duplicates Moodle's permission model and makes the sidecar authoritative by accident | Internal plugin HTTP calls back into Moodle |
| Browser automation as the default wrapper strategy | It is fragile and violates plugin-first | Plugin-owned external wrappers calling core APIs directly |

## Stack Patterns by Variant

**If you must support Moodle 4.2 to 4.4:**

- Use plugin scripts for all MCP transport endpoints.
- Build everything on the External Services API and plugin code.
- Do not depend on `route\api`.

**If the deployment is Moodle 4.5+ only:**

- Keep the main MCP transport as plugin scripts anyway.
- Optionally use `route\api` for JSON-only helper endpoints such as:
  - health
  - metadata
  - admin inspection
  - internal debug endpoints

**If the client is Claude Code with manual header auth:**

- Expose a public HTTPS Streamable HTTP endpoint.
- Use plugin-issued bearer tokens in `Authorization` headers.
- This remains fully plugin-first.

**If the client must use native Claude Code OAuth login from `/mcp`:**

- Add a thin Node/TypeScript gateway or build equivalent OAuth endpoints in-plugin.
- Prefer the gateway unless the project explicitly wants to own a generic OAuth 2.1 authorization server inside Moodle.

**If the site wants strict admin-curated exposure instead of maximal discovery:**

- Keep the current service-token architecture.
- Filter to `external_services_functions` only.
- Expose maximal mode as an opt-in connector profile, not the default.

## Version Compatibility

| Package A | Compatible With | Notes |
|-----------|-----------------|-------|
| Plugin code targeting PHP 8.0 syntax | Moodle 4.2, 4.3, 4.4, 4.5 | Use the lowest common denominator syntax for the 4.2+ support promise. |
| `\core_external\external_api` namespaced/autoloaded classes | Moodle 4.2+ | This is the correct baseline for this project because Moodle 4.2 restructured the External API into namespaced autoloaded classes. |
| `lib/externallib.php` compatibility pathway | Moodle <4.2 only | Not needed for this project unless the scope expands backward. |
| Moodle Routing subsystem | Moodle 4.5+ only | Useful for auxiliary JSON endpoints, not for the 4.2+ transport baseline. |
| Streamable HTTP MCP transport | Current MCP clients | Primary transport for new remote clients. |
| HTTP+SSE MCP transport | Legacy clients only | Deprecated in current MCP and Claude Code guidance. Keep only as compatibility. |
| Claude Code remote HTTP servers | Current Claude Code | Recommended transport for remote servers. |
| Claude Code remote SSE servers | Current Claude Code | Still accepted, but deprecated in docs. |
| Anthropic Messages API MCP connector | Public HTTPS MCP servers | Supports Streamable HTTP and SSE; only tool calls are currently supported. |

## Critical Claims and Confidence

| Claim | Confidence | Notes |
|------|------------|-------|
| Installed core and plugin externals should be harvested from Moodle's own registry, not a duplicate catalog | HIGH | Verified in local Moodle source: install/upgrade populates `external_functions` and related tables from every component's `db/services.php`. |
| The External API namespaced/autoloaded structure is safe as the 4.2+ baseline | HIGH | Verified in official Moodle docs for 4.5, which explicitly state the restructure happened in Moodle 4.2. |
| Moodle Routing is 4.5+ only and therefore cannot be the 4.2+ connector baseline | HIGH | Verified in official 4.5 routing docs ("Since 4.5"). |
| Moodle 4.5 API routes are a poor fit for SSE | HIGH | Verified in local 4.5 source: API CORS middleware sets `Content-Type: application/json`. |
| Streamable HTTP should be the primary transport and SSE only a compatibility shim | HIGH | Verified in current MCP spec, current Claude Code docs, and the official MCP TypeScript SDK docs. |
| A companion service is justified only for public transport/OAuth concerns, not for core permission logic | MEDIUM-HIGH | This is an architectural recommendation derived from verified Moodle and MCP constraints. |
| Moodle core does not appear to provide a generic MCP-ready OAuth authorization server | MEDIUM | In current 4.5 source, OAuth support is issuer/client oriented; I did not find a generic OAuth 2.1 authorization server or RFC9728 protected-resource implementation outside LTI-specific endpoints. |

## Sources

- Local Moodle 4.5 source: `tmp/moodle/webservice/lib.php`
  - web service auth modes
  - `NO_MOODLE_COOKIES` requirement in `authenticate_user()`
  - service membership checks in `load_function_info()`
  - `get_external_functions()`
- Local Moodle 4.5 source: `tmp/moodle/lib/upgradelib.php`
  - `external_update_descriptions()`
  - `external_update_services()`
- Local Moodle 4.5 source: `tmp/moodle/lib/external/classes/external_api.php`
  - autoload and fallback loading
  - `external_function_info()`
  - AJAX/login/readonlysession metadata handling
- Local Moodle 4.5 source: `tmp/moodle/lib/ajax/service.php`
  - `ajax`, `loginrequired`, `readonlysession` behavior
- Local Moodle 4.5 source: `tmp/moodle/lib/classes/router/middleware/cors_middleware.php`
  - API route JSON content type behavior
- Local Moodle 4.5 source: `tmp/moodle/lib/classes/router/route_loader_interface.php`
  - API route prefix `/api/rest/v2`
- Local Moodle 4.5 source: `tmp/moodle/login/token.php`
  - username/password token issuance model
- Local Moodle 4.5 source: `tmp/moodle/admin/tool/mobile/launch.php`
  - browser-login and OAuth SSO handoff pattern for token issuance
- Official Moodle docs: `https://moodledev.io/docs/4.5/apis/subsystems/external/functions`
  - external function structure
  - 4.2 restructure note
- Official Moodle docs: `https://moodledev.io/docs/5.2/apis/subsystems/external/security`
  - `validate_parameters()`
  - `validate_context()`
  - capability enforcement guidance
- Official Moodle docs: `https://moodledev.io/docs/4.5/apis/subsystems/external/advanced/custom-services`
  - service declaration behavior
- Official Moodle docs: `https://moodledev.io/docs/4.5/apis/subsystems/routing`
  - Routing introduced in 4.5
- Official Claude Code docs: `https://code.claude.com/docs/en/mcp`
  - HTTP recommended for remote servers
  - SSE deprecated
  - OAuth support in Claude Code
- Official Anthropic docs: `https://platform.claude.com/docs/en/agents-and-tools/mcp-connector`
  - public HTTPS requirement
  - Streamable HTTP and SSE support
  - tool-calls-only limitation
- Official MCP spec: `https://modelcontextprotocol.io/specification/2025-06-18/basic/transports`
  - Streamable HTTP is the replacement for legacy HTTP+SSE
  - Origin validation and auth guidance
- Official MCP spec: `https://modelcontextprotocol.io/specification/2025-06-18/basic/authorization`
  - OAuth 2.1 resource server model
  - protected resource metadata
  - `WWW-Authenticate` guidance
- Official MCP TypeScript SDK docs: `https://ts.sdk.modelcontextprotocol.io/documents/server.html`
  - Streamable HTTP as the recommended remote transport
  - legacy SSE compatibility patterns

---
*Stack research for: maximal Moodle-native MCP connector*
*Researched: 2026-04-21*
