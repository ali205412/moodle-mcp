# Requirements: Moodle MCP

**Defined:** 2026-04-21
**Core Value:** Any Moodle user can connect an AI client to Moodle and safely access the fullest possible set of actions without ever exceeding their real Moodle permissions.

## v1 Requirements

Requirements for the initial release. Each maps to roadmap phases.

### Authentication and Identity

- [x] **AUTH-01**: User can start connector access through Moodle's normal login flow instead of manually creating a permanent web service token.
- [x] **AUTH-02**: User with an existing valid Moodle session can complete connector bootstrap without a second credential prompt.
- [x] **AUTH-03**: Connector issues a revocable user-scoped credential or session for MCP access after successful Moodle authentication.
- [x] **AUTH-04**: Connector works on Moodle sites that authenticate users through Moodle-managed SSO providers.
- [x] **AUTH-05**: Operator can revoke, expire, and inspect connector access for a user without deleting that user's Moodle account.

### Transport and Session

- [x] **TRAN-01**: MCP client can initialize and invoke tools through a public HTTPS Streamable HTTP endpoint.
- [x] **TRAN-02**: Connector can expose legacy SSE compatibility mode when the site enables it for older clients.
- [x] **TRAN-03**: Connector handles preflight, Origin validation, and MCP header negotiation correctly for supported remote clients.
- [x] **TRAN-04**: Connector maintains isolated per-client session state needed for reconnect or resume without leaking state across users.
- [x] **TRAN-05**: Long-lived connector requests do not block the user's normal Moodle session activity.

### Catalog and Discovery

- [x] **DISC-01**: Connector automatically harvests installed core and standard-module external functions from the Moodle site.
- [x] **DISC-02**: Connector automatically harvests installed plugin and site-specific external functions when they are declared through Moodle's external-service system.
- [x] **DISC-03**: Every harvested or wrapped tool exposes normalized MCP metadata, including schema, provenance, and mutability hints.
- [x] **DISC-04**: Tool discovery remains usable on large sites through pagination, structured grouping, and catalog rebuild or cache invalidation when the site API surface changes.

### Permission and Safety

- [x] **PERM-01**: Tool visibility is filtered by the authenticated user's real identity, connector mode, and site policy before tools are shown.
- [x] **PERM-02**: Discovery uses context-aware eligibility rules for role, enrolment, group, availability, visibility, and ownership where those boundaries can be resolved safely.
- [x] **PERM-03**: Tool execution re-checks authoritative Moodle permissions and context at call time even when discovery suggested the tool was eligible.
- [x] **PERM-04**: Mutating or destructive tools expose risk level, confirmation requirements, and clear denial or restriction reasons.

### Core User Surface

- [x] **CORE-01**: User can discover and read the courses, sections, and activities they are actually allowed to access.
- [x] **CORE-02**: User can work with core personal surfaces such as calendar, messaging, notes, profile data, and private files when their Moodle permissions allow it.
- [x] **CORE-03**: User can read completion, progress, and other core learner context already available through Moodle APIs.
- [x] **CORE-04**: User can upload, download, and attach files through connector-managed flows that handle Moodle draft-area requirements correctly.

### Activity Workflows

- [x] **ACTY-01**: User can complete assignment workflows allowed by their role, including reading assignment context and submitting or managing submissions where permitted.
- [x] **ACTY-02**: User can complete forum workflows allowed by their role, including reading discussions and creating or managing forum content where permitted.
- [x] **ACTY-03**: User can complete quiz, workshop, and feedback workflows allowed by their role through harvested or wrapped tools.
- [x] **ACTY-04**: Connector exposes additional installed standard-module workflows such as chat, glossary, wiki, data, choice, survey, scorm, h5pactivity, bigbluebutton, and lti when those modules are present and the user is allowed to use them.

### Operator and Admin Surface

- [x] **OPER-01**: Authorized users can manage users, enrolments, groups, cohorts, and roles through connector tools when Moodle already exposes or the connector safely wraps those actions.
- [x] **OPER-02**: Authorized users can manage courses, categories, sections, and activity placement through harvested or wrapped tools.
- [x] **OPER-03**: Authorized users can use available competency, privacy, compliance, and other operator-grade utilities already exposed by the site, with explicit handling for long-running or async operations.

### Wrappers and Coverage

- [x] **WRAP-01**: Connector provides a wrapper framework for high-value Moodle user actions that are available in the UI but not exposed as stable external functions.
- [x] **WRAP-02**: Initial wrapper coverage closes priority gaps for course authoring and other proven high-value workflows identified by the connector's coverage inventory.
- [x] **WRAP-03**: Connector reports coverage status per domain, distinguishing harvested, wrapped, disabled, and unsupported actions.

### Compatibility and Hardening

- [x] **COMP-01**: Connector works across the supported Moodle 4.2, 4.3, 4.4, and 4.5 release line.
- [x] **COMP-02**: Discovery and execution remain performant on plugin-heavy sites without returning stale or over-broad permissions because of unsafe caching.
- [x] **COMP-03**: Connector has end-to-end tests for login or SSO bootstrap, transport behavior, permission denial cases, and representative harvested and wrapped tool execution.
- [x] **COMP-04**: Connector emits operator-usable audit identifiers or logs for discovery and mutating tool execution.

## Phase 9 Requirements

Promoted from deferred wrapper backlog into the active roadmap and completed in Phase 9.

### Advanced Wrapper Domains

- [x] **WRAP-04**: Authorized users can perform deep question-bank authoring workflows such as create, edit, import, preview, and organizational management through typed wrappers.
- [x] **WRAP-05**: Authorized users can perform gradebook tree and report editing workflows beyond the grading APIs already exposed by Moodle.
- [x] **WRAP-06**: Authorized users can perform expanded badge-administration workflows through typed wrappers.
- [x] **WRAP-07**: Connector can add broad plugin-specific UI wrappers beyond the core or standard-module surface when real site demand justifies them.

### Connector UX and Edge Runtime

- **EDGE-01**: Connector can optionally run behind a thin Node or TypeScript transport relay when plugin-only transport or auth UX proves insufficient for target MCP clients.
- **UX-01**: Connector provides semantic task aliases or bundles on top of canonical Moodle tool names for common high-level workflows.

## Out of Scope

Explicitly excluded. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Browser automation as the primary execution path | Too brittle for Moodle themes, JS flows, and SSO; the project must stay plugin-first and API-first |
| Generic arbitrary form or page executor | Unsafe, opaque, and too hard to reason about for permissions and side effects |
| Connector-owned super-admin service account | Violates the core per-user permission model and destroys trustworthy auditing |
| Arbitrary PHP, SQL, or filesystem execution | Outside the Moodle API model and an unacceptable security surface |
| Mandatory companion service from day one | The project must push Moodle's plugin system as far as possible before introducing extra infrastructure |
| Locking the first roadmap to Moodle 4.5-only APIs | Conflicts with the 4.2+ compatibility target |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| AUTH-01 | Phase 1 | Complete |
| AUTH-02 | Phase 1 | Complete |
| AUTH-03 | Phase 1 | Complete |
| AUTH-04 | Phase 1 | Complete |
| AUTH-05 | Phase 1 | Complete |
| TRAN-01 | Phase 2 | Complete |
| TRAN-02 | Phase 2 | Complete |
| TRAN-03 | Phase 2 | Complete |
| TRAN-04 | Phase 2 | Complete |
| TRAN-05 | Phase 2 | Complete |
| DISC-01 | Phase 3 | Complete |
| DISC-02 | Phase 3 | Complete |
| DISC-03 | Phase 3 | Complete |
| DISC-04 | Phase 3 | Complete |
| PERM-01 | Phase 4 | Complete |
| PERM-02 | Phase 4 | Complete |
| PERM-03 | Phase 4 | Complete |
| PERM-04 | Phase 4 | Complete |
| CORE-01 | Phase 5 | Complete |
| CORE-02 | Phase 5 | Complete |
| CORE-03 | Phase 5 | Complete |
| CORE-04 | Phase 5 | Complete |
| ACTY-01 | Phase 6 | Complete |
| ACTY-02 | Phase 6 | Complete |
| ACTY-03 | Phase 6 | Complete |
| ACTY-04 | Phase 6 | Complete |
| OPER-01 | Phase 7 | Complete |
| OPER-02 | Phase 7 | Complete |
| OPER-03 | Phase 7 | Complete |
| WRAP-01 | Phase 6 | Complete |
| WRAP-02 | Phase 7 | Complete |
| WRAP-03 | Phase 3 | Complete |
| WRAP-04 | Phase 9 | Complete |
| WRAP-05 | Phase 9 | Complete |
| WRAP-06 | Phase 9 | Complete |
| WRAP-07 | Phase 9 | Complete |
| COMP-01 | Phase 8 | Complete |
| COMP-02 | Phase 8 | Complete |
| COMP-03 | Phase 8 | Complete |
| COMP-04 | Phase 8 | Complete |
| EDGE-01 | Phase 10 | Complete |
| UX-01 | Phase 10 | Complete |

**Coverage:**
- v1 requirements: 36 total
- Mapped to phases: 38
- Unmapped: 0 ✓

---
*Requirements defined: 2026-04-21*
*Last updated: 2026-04-21 after initial definition*
