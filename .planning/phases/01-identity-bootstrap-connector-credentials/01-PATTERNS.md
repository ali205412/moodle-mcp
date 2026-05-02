# Phase 01: Identity Bootstrap & Connector Credentials - Pattern Map

**Mapped:** 2026-04-21
**Files analyzed:** 7
**Analogs found:** 7 / 7

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `launch.php` | controller | request-response | `tmp/moodle/admin/tool/mobile/launch.php` | exact |
| `classes/local/auth/bootstrap_service.php` | service | request-response | `tmp/moodle/admin/tool/mobile/launch.php` | flow-match |
| `classes/local/auth/credential_manager.php` | service | request-response | `tmp/moodle/lib/external/classes/util.php` | exact |
| `classes/local/auth/transport_identity.php` | service | request-response | `tmp/moodle/webservice/lib.php` | exact |
| `classes/local/auth/oauth_bridge.php` | service | request-response | `tmp/moodle/auth/oauth2/login.php` | exact |
| `settings.php` | config | request-response | `tmp/moodle/admin/tool/mobile/launch.php` | partial |
| `tests/launch_test.php` / `tests/auth_bootstrap_test.php` | test | request-response | `tests/server_test.php` | role-match |

## Pattern Assignments

### `launch.php` (controller, request-response)

**Analog:** `tmp/moodle/admin/tool/mobile/launch.php`

**Use this pattern for:**
- `require('../../config.php')` bootstrap
- early parameter validation
- optional OAuth/SSO redirect handoff
- `require_login(0, false)` plus `core_user::require_active_user($USER)`
- issuing post-login credentials only after a real Moodle session exists

**Imports/bootstrap pattern:**
- Follow Moodle page-controller structure, not `WS_SERVER`.
- Keep cookie/session-aware logic here, not in `server.php`.

**Core flow to copy:**
- validate input
- resolve configured service/connector mode
- optionally redirect through Moodle OAuth login
- require login
- require active user
- issue connector credential
- return/redirect to the remote client handoff target

**Error-handling pattern:**
- throw `moodle_exception` on invalid config or invalid request state
- use Moodle page flow / redirect pattern for interactive failures, not JSON-RPC envelopes

### `classes/local/auth/bootstrap_service.php` (service, request-response)

**Analogs:** `tmp/moodle/admin/tool/mobile/launch.php`, `tmp/moodle/lib/moodlelib.php`

**Use this pattern for:**
- factoring `launch.php` page flow into a testable service
- encapsulating `require_login`, `core_user::require_active_user`, capability gate, and return-target assembly

**Key behavior to copy:**
- respect Moodle's normal login/session lifecycle
- avoid inventing alternate auth state outside the Moodle session
- keep browser bootstrap and token-authenticated transport separated

**Integration points:**
- called by `launch.php`
- may call a credential manager to mint short-lived/session-linked connector access

### `classes/local/auth/credential_manager.php` (service, request-response)

**Analog:** `tmp/moodle/lib/external/classes/util.php`

**Use this pattern for:**
- modeling service-bound, context-bound credentials
- handling `validuntil`, `iprestriction`, optional `sid`, and revocation metadata
- wrapping Moodle token primitives without exposing raw token semantics as the product contract

**Core pattern to copy:**
- service/capability check before credential issuance
- record context and service linkage
- treat session-linked credentials distinctly from durable credentials
- generate a display/name/audit identifier separately from the raw secret when needed

**Do not copy blindly:**
- direct raw `external_tokens` UX as the end-user contract
- implicit permanent-token issuance on ordinary login

### `classes/local/auth/transport_identity.php` (service, request-response)

**Analog:** `tmp/moodle/webservice/lib.php`

**Use this pattern for:**
- resolving runtime identity from connector credentials
- loading restricted context/service metadata
- validating expiry, IP restrictions, and session-linked credentials

**Core pattern to copy:**
- validate credential before user lookup
- resolve restricted context/service early
- keep post-bootstrap transport token-authenticated
- preserve a clear split between interactive bootstrap auth and MCP transport auth

**Key source anchors:**
- `authenticate_user()`
- `authenticate_by_token()`
- restricted context and service initialization

### `classes/local/auth/oauth_bridge.php` (service, request-response)

**Analog:** `tmp/moodle/auth/oauth2/login.php`

**Use this pattern for:**
- optional issuer-specific OAuth redirect handoff when the site uses Moodle-managed OAuth login
- return URL construction back into the connector bootstrap page

**Core pattern to copy:**
- issuer availability checks
- build return URL pointing back to the connector bootstrap page
- let Moodle's OAuth client/session flow complete login

### `settings.php` (config, request-response)

**Analogs:** current plugin config files such as `db/access.php`, plus Moodle admin page patterns

**Use this pattern for:**
- exposing connector bootstrap/credential settings
- feature toggles for companion seam, durable grants, and later transport controls

**Pattern guidance:**
- keep operational toggles in Moodle admin settings rather than hardcoded constants
- settings should describe connector behavior, not duplicate token records or service tables directly

### `tests/launch_test.php` / `tests/auth_bootstrap_test.php` (test, request-response)

**Analogs:** `tests/server_test.php`, `tests/request_test.php`

**Use this pattern for:**
- PHPUnit class layout
- `advanced_testcase` setup and teardown
- reflection/testing style for isolated auth helpers when needed

**What to add beyond current tests:**
- login/bootstrap path coverage
- durable vs short-lived/session-linked credential issuance rules
- capability gate failures for missing `webservice/mcp:use`
- session-bound credential invalidation behavior

## Shared Patterns

### Capability Gate
- Reuse the existing plugin capability `webservice/mcp:use` from `db/access.php` as the first explicit connector gate.
- Keep more detailed identity/eligibility checks in auth services rather than scattering them across entrypoints.

### Split Entry Point Model
- `server.php` remains the token-authenticated MCP transport boundary.
- `launch.php` becomes the browser/session-aware bootstrap boundary.
- Do not mix JSON-RPC transport concerns into the bootstrap page or browser login concerns into `server.php`.

### Moodle Source Authority
- For any auth/session/token decision, copy behavior from `tmp/moodle` first and only abstract it after understanding the exact source pattern.
- Prefer local Moodle source analogs over general PHP or framework examples for this phase.

## Recommended Read Order For Planner

1. `01-CONTEXT.md`
2. `01-RESEARCH.md`
3. `server.php`
4. `classes/local/server.php`
5. `db/access.php`
6. `tmp/moodle/admin/tool/mobile/launch.php`
7. `tmp/moodle/auth/oauth2/login.php`
8. `tmp/moodle/lib/external/classes/util.php`
9. `tmp/moodle/webservice/lib.php`
10. `tests/server_test.php`

## Notes For Planning

- Phase 1 should plan around adding the bootstrap boundary and credential manager first, then integrating them with the existing MCP transport path.
- The planner should avoid tasks that directly mutate `server.php` into a session-aware login controller.
- The planner should explicitly reference the Moodle analog files above in task `read_first` sections so execution stays grounded in source.

---
*Phase: 01-identity-bootstrap-connector-credentials*
*Pattern mapping gathered: 2026-04-21*
