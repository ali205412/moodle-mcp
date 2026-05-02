---
phase: 04-permission-gated-discovery-safety
verified: 2026-04-21T17:05:00Z
status: completed
score: 4/4 must-haves source-verified
---

# Phase 04: Permission-Gated Discovery & Safety Verification Report

**Phase Goal:** Users only see and execute tools that remain valid for their real Moodle identity, context, and risk boundaries.
**Verified:** 2026-04-21T17:05:00Z
**Status:** human_needed

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Discovery hides tools when explicit capability checks can already be resolved safely in the current restricted context. | ✓ VERIFIED | `classes/local/discovery/eligibility_resolver.php` uses `get_capability_info()` and `has_capability()` with safe context-level rules, and `tests/tool_provider_test.php` covers a missing system-level capability case. |
| 2 | Discovery annotates deferred role/enrolment/group/availability/visibility/ownership boundaries instead of pretending to resolve them when only call-time/module-specific checks exist. | ✓ VERIFIED | `classes/local/discovery/eligibility_resolver.php` adds deferred call-time checks plus service-scoped access-information companion hints backed by upstream `*_access_information` externals. |
| 3 | Tools expose structured risk and confirmation metadata, and site policy can hide high-risk tools from discovery. | ✓ VERIFIED | `classes/local/discovery/risk_analyzer.php`, `settings.php`, `lang/en/webservice_mcp.php`, and `tests/tool_provider_test.php` implement and cover risk classification plus the high-risk policy toggle. |
| 4 | Transport execution remains Moodle-authoritative while returning clearer structured restriction metadata for common denials. | ✓ VERIFIED | `classes/local/transport/server.php` enriches JSON-RPC error data with restriction details, and `tests/transport_server_test.php` covers capability and restricted-context errors. |

**Score:** 4/4 truths source-verified, 0/4 exercised through live Moodle UAT yet

## Requirements Coverage

| Requirement | Status | Blocking Issue |
|-------------|--------|----------------|
| PERM-01: Tool visibility is filtered by the authenticated user's real identity, connector mode, and site policy before tools are shown. | ✓ SATISFIED | Live end-to-end browser/client validation pending |
| PERM-02: Discovery uses context-aware eligibility rules for role, enrolment, group, availability, visibility, and ownership where those boundaries can be resolved safely. | ✓ SATISFIED | Live activity-specific validation pending |
| PERM-03: Tool execution re-checks authoritative Moodle permissions and context at call time even when discovery suggested the tool was eligible. | ✓ SATISFIED | Live end-to-end denial validation pending |
| PERM-04: Mutating or destructive tools expose risk level, confirmation requirements, and clear denial or restriction reasons. | ✓ SATISFIED | Live client behavior validation pending |

## Automated Checks Run

- `rtk php -l` passed for:
  - `classes/local/discovery/risk_analyzer.php`
  - `classes/local/discovery/eligibility_resolver.php`
  - `classes/local/tool_provider.php`
  - `classes/local/transport/server.php`
  - `settings.php`
  - `lang/en/webservice_mcp.php`
  - `tests/tool_provider_test.php`
  - `tests/transport_server_test.php`
- Phase grep checks passed for eligibility, risk, access-information, and restriction metadata markers.

## Source-Of-Truth Checks

Concrete Moodle sources inspected during implementation and verification:

- `tmp/moodle/lib/external/classes/external_api.php`
- `tmp/moodle/lib/accesslib.php`
- `tmp/moodle/lib/moodlelib.php`
- `tmp/moodle/mod/forum/externallib.php`
- `tmp/moodle/mod/assign/externallib.php`
- `tmp/moodle/mod/quiz/classes/external.php`
- `tmp/moodle/mod/workshop/classes/external.php`
- `tmp/moodle/calendar/externallib.php`

## Human Verification Required

### 1. Safe visibility filtering against real roles
**Test:** Compare discovery output for a manager/admin user versus a normal user on the same site and service.
**Expected:** Tools requiring safely resolvable system-level permissions disappear for the lower-privilege user.

### 2. Access-information companion flow
**Test:** On a module exposing `*_access_information`, inspect discovery metadata and then call the companion tool with a real target id.
**Expected:** Discovery advertises the companion tool, and the companion response gives the finer-grained activity/context eligibility detail.

### 3. Denial metadata
**Test:** Call a tool that should fail for capability or context reasons through the MCP transport.
**Expected:** The JSON-RPC error includes structured `restriction` metadata in `error.data`.

## Gaps Summary

**Implementation gap:** none found in reviewed source.  
**Validation gap:** live role-specific, activity-specific, and denial-path validation are still pending.

---
*Verified: 2026-04-21T17:05:00Z*
*Verifier: the agent*
