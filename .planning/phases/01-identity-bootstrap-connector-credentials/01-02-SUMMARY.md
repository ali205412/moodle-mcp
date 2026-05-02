---
phase: 01-identity-bootstrap-connector-credentials
plan: 02
subsystem: auth
tags: [moodle, bootstrap, oauth2, sso, launch]
requires:
  - phase: 01-01
    provides: plugin-managed credential issuance
provides:
  - Browser bootstrap entrypoint for connector access
  - OAuth/SSO bridge that follows Moodle login patterns
  - PHPUnit coverage for bootstrap and OAuth handoff helpers
affects: [launch, login, oauth, connector bootstrap]
tech-stack:
  added: [launch.php bootstrap path]
  patterns: [Moodle-native login flow, OAuth wantsurl handoff]
key-files:
  created:
    - launch.php
    - classes/local/auth/bootstrap_service.php
    - classes/local/auth/oauth_bridge.php
    - tests/launch_test.php
  modified:
    - lang/en/webservice_mcp.php
key-decisions:
  - "Interactive connector bootstrap must be a normal Moodle page flow, not a WS server."
  - "OAuth/SSO handoff follows Moodle-native redirect patterns."
patterns-established:
  - "launch.php owns browser/session bootstrap."
  - "oauth_bridge.php mirrors Moodle issuer-based login entrypoints."
requirements-completed: [AUTH-01, AUTH-02, AUTH-04]
duration: 32min
completed: 2026-04-21
---

# Phase 1: Identity Bootstrap & Connector Credentials Summary

**Browser-first connector bootstrap with Moodle-native login and OAuth/SSO handoff support**

## Performance

- **Duration:** 32 min
- **Started:** 2026-04-21T13:46:00Z
- **Completed:** 2026-04-21T14:18:00Z
- **Tasks:** 3
- **Files modified:** 5

## Accomplishments
- Added `launch.php` as a dedicated bootstrap entrypoint outside the token-only MCP server.
- Implemented bootstrap and OAuth bridge services that follow Moodle login and issuer redirect patterns.
- Added bootstrap-path PHPUnit coverage for capability gating, payload issuance, and OAuth login URL behavior.

## Task Commits

No git commits were created because `commit_docs` is disabled and execution is staying local-only in this repository.

## Files Created/Modified
- `launch.php` - Browser/session bootstrap entrypoint for connector auth.
- `classes/local/auth/bootstrap_service.php` - Capability-gated bootstrap and payload issuance service.
- `classes/local/auth/oauth_bridge.php` - Moodle OAuth redirect bridge back into bootstrap.
- `lang/en/webservice_mcp.php` - Adds launch-related strings.
- `tests/launch_test.php` - Covers bootstrap access and OAuth handoff helper behavior.

## Decisions Made

- Preserved `server.php` as token-authenticated transport instead of folding login into it.
- Followed Moodle's `auth/oauth2/login.php` and `admin/tool/mobile/launch.php` patterns rather than inventing a connector-native SSO protocol.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

- Runtime validation is still limited to syntax and source inspection until a fuller Moodle execution environment is wired.

## User Setup Required

None - no external service configuration required for this plan.

## Next Phase Readiness

- Bootstrap flow can now issue plugin-managed credentials through a normal Moodle page.
- Remaining transport-side identity loading and admin inspection/revocation work can build on concrete bootstrap behavior.

---
*Phase: 01-identity-bootstrap-connector-credentials*
*Completed: 2026-04-21*
