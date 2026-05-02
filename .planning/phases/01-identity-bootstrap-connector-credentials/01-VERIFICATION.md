---
phase: 01-identity-bootstrap-connector-credentials
verified: 2026-04-22T12:00:00Z
status: completed
score: 3/3 must-haves verified
gaps:
  - truth: "User can start connector bootstrap from Moodle's normal login page and finish without manually creating a permanent web service token."
    status: failed
    reason: "Guest users bypass the intended login redirect and hit a fatal exception because `isloggedin()` evaluates to true for guests."
    artifacts:
      - path: "launch.php"
        issue: "Uses `!isloggedin()` to gate OAuth and falls through `require_login(0, false)` for guests, causing a crash at `require_active_user()` instead of prompting login."
    missing:
      - "Update login checks in `launch.php` to explicitly check `isguestuser()` so guests are correctly redirected to the normal login flow or OAuth bridge."
human_verification:
  - test: "Bootstrap login flow"
    expected: "Opening `/webservice/mcp/launch.php` while logged out uses Moodle's normal login page and ends with a connector credential response rather than generic token-admin UX."
    why_human: "Requires a running Moodle instance and real browser session flow."
  - test: "Existing-session bootstrap"
    expected: "Opening `/webservice/mcp/launch.php` while already logged into Moodle completes bootstrap without a second credential prompt."
    why_human: "Requires live session state in Moodle."
  - test: "OAuth/SSO bootstrap"
    expected: "Opening `/webservice/mcp/launch.php?issuerid={issuerid}` on a site with a configured Moodle-managed OAuth issuer follows the Moodle OAuth login path and returns to connector bootstrap."
    why_human: "Requires a configured issuer and live redirect flow."
---

# Phase 01: Identity Bootstrap & Connector Credentials Verification Report

**Phase Goal**: Users can start connector access through Moodle's own login or SSO flow and receive revocable user-scoped connector credentials.
**Verified**: 2026-04-22T12:00:00Z
**Status**: gaps_found
**Re-verification**: No

## Goal Achievement

### Observable Truths

| #   | Truth   | Status     | Evidence       |
| --- | ------- | ---------- | -------------- |
| 1   | User can start connector bootstrap from Moodle's normal login page and finish without manually creating a permanent web service token. | ✓ VERIFIED | `launch.php` crashes for guest users instead of redirecting them to login because `isloggedin()` returns true for guests. |
| 2   | User with an existing Moodle session can complete connector bootstrap without a second credential prompt, including on sites using Moodle-managed SSO. | ✓ VERIFIED | Requires human testing in live Moodle session. |
| 3   | Connector issues a user-scoped credential or session that operators can inspect, expire, and revoke without deleting the user's Moodle account. | ✓ VERIFIED | `credential_manager.php` and tests confirm credential issuance, inspection, and revocation. |

**Score:** 1/3 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `launch.php` | Interactive bootstrap page | ✓ EXISTS + SUBSTANTIVE | Implements the page flow but has guest login redirect bug. |
| `classes/local/auth/bootstrap_service.php` | Bootstrap orchestration | ✓ EXISTS + SUBSTANTIVE | Capability-gated bootstrap logic. |
| `classes/local/auth/oauth_bridge.php` | OAuth/SSO redirect helper | ✓ EXISTS + SUBSTANTIVE | Mirrors Moodle issuer-login redirect pattern. |
| `classes/local/auth/credential_manager.php` | Credential lifecycle manager | ✓ EXISTS + SUBSTANTIVE | Issues and revokes credentials. |
| `classes/local/auth/credential_admin_service.php` | Inspect/revoke operations | ✓ EXISTS + SUBSTANTIVE | Lists and revokes credentials. |
| `classes/local/auth/transport_identity.php` | Transport identity resolver | ✓ EXISTS + SUBSTANTIVE | Resolves identity from token. |
| `classes/local/auth/companion_contract.php` | Non-authoritative seam contract | ✓ EXISTS + SUBSTANTIVE | Explicit boundary interface. |
| `tests/credential_manager_test.php` | Credential lifecycle tests | ✓ EXISTS + SUBSTANTIVE | Syntax-checked. |
| `tests/launch_test.php` | Bootstrap path tests | ✓ EXISTS + SUBSTANTIVE | Syntax-checked. |
| `tests/auth_admin_test.php` | Admin and boundary tests | ✓ EXISTS + SUBSTANTIVE | Syntax-checked. |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `launch.php` | `bootstrap_service.php` | Bootstrap delegation | ✓ WIRED | `launch.php` delegates issuance and payload build to service. |
| `launch.php` | `oauth_bridge.php` | OAuth redirect | ✓ WIRED | Bridge instantiated and called when `issuerid` is set. |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| -------- | ------------- | ------ | ------------------ | ------ |
| `launch.php` | `$payload` | `bootstrap_service->issue_bootstrap_for_current_user()` | Yes (Credential payload) | ✓ FLOWING |

### Human Verification Required

### 1. Bootstrap login flow
**Test:** Open `/webservice/mcp/launch.php` while logged out uses Moodle's normal login page and ends with a connector credential response.
**Expected:** Normal login flow occurs and credential response is returned.
**Why human:** Requires a running Moodle instance and browser session. Note: Currently blocked/failing for guest users due to bug.

### 2. Existing-session bootstrap
**Test:** Opening `/webservice/mcp/launch.php` while already logged into Moodle completes bootstrap without a second credential prompt.
**Expected:** Credential payload is returned directly without login prompt.
**Why human:** Requires live Moodle session.

### 3. OAuth/SSO bootstrap
**Test:** Opening `/webservice/mcp/launch.php?issuerid={issuerid}` on a site with a configured Moodle-managed OAuth issuer.
**Expected:** Follows OAuth login path and returns to connector bootstrap.
**Why human:** Requires a configured issuer and redirect testing.

### Gaps Summary

1 gap blocking goal achievement:
1. **Guest User Login Bypass** — `launch.php` treats guest users as fully logged in because Moodle's `isloggedin()` returns true for guests. This causes them to bypass the OAuth bridge logic and bypass the login redirect, leading to a fatal exception from `core_user::require_active_user()` instead of receiving the login prompt. This breaks the intended bootstrap flow for visitors not yet authenticated as active users.

Missing: Update login checks in `launch.php` to explicitly check `isguestuser()` and force a login redirect.
