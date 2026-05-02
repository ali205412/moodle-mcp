---
phase: 01-identity-bootstrap-connector-credentials
plan: 01
subsystem: auth
tags: [moodle, php, credential, xmldb, connector]
requires: []
provides:
  - Plugin-managed connector credential persistence schema
  - Credential manager service for bootstrap and durable grants
  - PHPUnit coverage for credential lifecycle semantics
affects: [bootstrap, transport, auth, admin]
tech-stack:
  added: [plugin-managed connector credential table]
  patterns: [plugin-managed credential contract over Moodle token primitives]
key-files:
  created:
    - db/install.xml
    - db/upgrade.php
    - classes/local/auth/credential_manager.php
    - tests/credential_manager_test.php
  modified:
    - version.php
key-decisions:
  - "Connector credentials are plugin-managed records, not raw permanent-token UX."
  - "Bootstrap credentials are short-lived/session-linked by default; durable grants are explicit."
patterns-established:
  - "Auth state is split between browser bootstrap and token-authenticated transport."
  - "Moodle token semantics are reused internally but wrapped by plugin-owned lifecycle code."
requirements-completed: [AUTH-03]
duration: 35min
completed: 2026-04-21
---

# Phase 1: Identity Bootstrap & Connector Credentials Summary

**Plugin-managed connector credential schema and lifecycle service with explicit bootstrap-vs-durable grant semantics**

## Performance

- **Duration:** 35 min
- **Started:** 2026-04-21T13:10:00Z
- **Completed:** 2026-04-21T13:45:00Z
- **Tasks:** 3
- **Files modified:** 5

## Accomplishments
- Added a plugin-owned credential table and upgrade path for connector access.
- Implemented a credential manager that models service/context/session metadata explicitly.
- Added PHPUnit coverage for bootstrap issuance, durable grants, resolution, and revocation.

## Task Commits

No git commits were created because `commit_docs` is disabled and execution is staying local-only in this repository.

## Files Created/Modified
- `db/install.xml` - Defines `webservice_mcp_credential` persistence.
- `db/upgrade.php` - Creates the credential table on upgrade.
- `version.php` - Bumps plugin version to trigger the upgrade path.
- `classes/local/auth/credential_manager.php` - Implements connector credential issuance, resolution, listing, and revocation.
- `tests/credential_manager_test.php` - Defines the expected credential lifecycle behavior.

## Decisions Made
- Followed the locked decision to wrap Moodle token primitives rather than expose them directly.
- Encoded bootstrap and durable grants as distinct token types inside plugin-managed records.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

- Host environment lacked `php`; resolved by adding Docker-backed user-local `php` and `composer` wrappers.

## User Setup Required

None - no external service configuration required for this plan.

## Next Phase Readiness

- Bootstrap and transport planning can now consume a concrete credential manager instead of abstract auth assumptions.
- Full runtime verification still depends on executing the bootstrap flow inside a real Moodle environment.

---
*Phase: 01-identity-bootstrap-connector-credentials*
*Completed: 2026-04-21*
