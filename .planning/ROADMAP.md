# Roadmap: Moodle MCP

## Overview

This roadmap turns the current token-scoped Moodle MCP adapter into a Moodle-native connector that authenticates real users, serves supported remote MCP transports, publishes a harvested and permission-gated catalog, expands into core learner and operator workflows, and then hardens the result for Moodle 4.2-4.5 production use.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Identity Bootstrap & Connector Credentials** - Moodle-native login, SSO bootstrap, and revocable connector credentials. (completed 2026-04-21)
- [x] **Phase 2: Remote Transport & Session Isolation** - Public MCP transport support with safe session boundaries. (completed 2026-04-21)
- [x] **Phase 3: Harvested Catalog & Coverage Inventory** - Automatic external harvesting with normalized metadata and coverage reporting. (completed 2026-04-21)
- [x] **Phase 4: Permission-Gated Discovery & Safety** - User-scoped visibility, call-time authorization, and risk signaling. (completed 2026-04-21)
- [x] **Phase 5: Core Learning Surface & File Flows** - Core learner, collaboration, and file workflows exposed through the connector. (completed 2026-04-21)
- [x] **Phase 6: Activity Workflows & Wrapper Foundation** - High-value activity workflows plus reusable wrappers for missing actions. (completed 2026-04-21)
- [x] **Phase 7: Operator Workflows & Authoring Gaps** - Admin and course-authoring actions, including priority wrapper coverage gaps. (completed 2026-04-21)
- [x] **Phase 8: Compatibility, Audit & Release Hardening** - Cross-version support, performance safeguards, auditability, and end-to-end verification. (completed 2026-04-21)
- [x] **Phase 9: Full Coverage & UI Parity** - Question bank, gradebook, and badge parity wrappers plus explicit parity audit coverage. (completed 2026-04-22)
- [x] **Phase 10: Activity Authoring & Protocol Expansion** - Internal class parity wrappers, tool discovery engine, and MCP resources/prompts for persistent knowledgebase memory. (completed 2026-05-01)

## Phase Details

### Phase 1: Identity Bootstrap & Connector Credentials
**Goal**: Users can start connector access through Moodle's own login or SSO flow and receive revocable user-scoped connector credentials.
**Depends on**: Nothing (first phase)
**Requirements**: AUTH-01, AUTH-02, AUTH-03, AUTH-04, AUTH-05
**Success Criteria** (what must be TRUE):
  1. User can start connector bootstrap from Moodle's normal login page and finish without manually creating a permanent web service token.
  2. User with an existing Moodle session can complete connector bootstrap without a second credential prompt, including on sites using Moodle-managed SSO.
  3. Connector issues a user-scoped credential or session that operators can inspect, expire, and revoke without deleting the user's Moodle account.
**Plans**: TBD
**UI hint**: yes

### Phase 2: Remote Transport & Session Isolation
**Goal**: Authenticated MCP clients can connect over supported HTTPS transports with isolated connector sessions that do not interfere with normal Moodle browsing.
**Depends on**: Phase 1
**Requirements**: TRAN-01, TRAN-02, TRAN-03, TRAN-04, TRAN-05
**Success Criteria** (what must be TRUE):
  1. MCP clients can initialize and invoke tools through a public HTTPS Streamable HTTP endpoint with correct MCP header negotiation.
  2. Sites that enable compatibility mode can serve legacy SSE clients without changing auth or permission semantics.
  3. Preflight handling, Origin validation, and reconnect or resume state work correctly for supported remote clients without leaking session state across users.
  4. Long-lived connector requests do not block or corrupt the user's normal Moodle session activity.
**Plans**: TBD

### Phase 3: Harvested Catalog & Coverage Inventory
**Goal**: The connector maintains a normalized site-wide inventory of installed Moodle capabilities and can report coverage state by domain.
**Depends on**: Phase 2
**Requirements**: DISC-01, DISC-02, DISC-03, DISC-04, WRAP-03
**Success Criteria** (what must be TRUE):
  1. The connector automatically harvests core, standard-module, installed-plugin, and site-specific external functions when they are declared through Moodle's external-service system.
  2. Every harvested tool exposes normalized MCP metadata, including schema, provenance, and mutability hints.
  3. Tool discovery remains usable on large sites through structured grouping, pagination, and catalog rebuild or cache invalidation when the site API surface changes.
  4. Operator-visible coverage status distinguishes harvested, wrapped, disabled, and unsupported actions by domain.
**Plans**: TBD

### Phase 4: Permission-Gated Discovery & Safety
**Goal**: Users only see and execute tools that remain valid for their real Moodle identity, context, and risk boundaries.
**Depends on**: Phase 3
**Requirements**: PERM-01, PERM-02, PERM-03, PERM-04
**Success Criteria** (what must be TRUE):
  1. User-visible tools are filtered by real identity, connector mode, and site policy before discovery results are shown.
  2. Discovery respects resolvable role, enrolment, group, availability, visibility, and ownership boundaries instead of publishing a flat raw catalog.
  3. Tool execution re-checks authoritative Moodle permissions and context at call time and returns clear denial or restriction reasons when access is blocked.
  4. Mutating or destructive tools expose risk level and required confirmation before execution.
**Plans**: TBD

### Phase 5: Core Learning Surface & File Flows
**Goal**: Users can work with the core Moodle learning, personal, and file surfaces they are actually allowed to access.
**Depends on**: Phase 4
**Requirements**: CORE-01, CORE-02, CORE-03, CORE-04
**Success Criteria** (what must be TRUE):
  1. User can discover and read the courses, sections, activities, completion, progress, and other learner context they are allowed to access.
  2. User can access calendar, messaging, notes, profile data, and private files through the connector when Moodle permissions allow it.
  3. User can upload, download, and attach files through connector-managed flows that handle Moodle draft-area requirements correctly.
**Plans**: TBD

### Phase 6: Activity Workflows & Wrapper Foundation
**Goal**: Users can complete high-value activity workflows, and the connector has a reusable wrapper framework for missing but important Moodle actions.
**Depends on**: Phase 5
**Requirements**: ACTY-01, ACTY-02, ACTY-03, ACTY-04, WRAP-01
**Success Criteria** (what must be TRUE):
  1. User can read assignment and forum context and submit, create, or manage content where their role allows it.
  2. User can complete quiz, workshop, and feedback workflows through harvested tools or typed wrappers when Moodle permissions allow it.
  3. Additional installed standard-module workflows become available automatically when those modules are present and the authenticated user is allowed to use them.
  4. High-value Moodle actions that exist in the UI but lack stable externals can be exposed through a reusable wrapper framework without bypassing Moodle permission checks.
**Plans**: TBD

### Phase 7: Operator Workflows & Authoring Gaps
**Goal**: Authorized operators can manage Moodle structure, access, and priority course-authoring gaps safely through harvested and wrapped tools.
**Depends on**: Phase 6
**Requirements**: OPER-01, OPER-02, OPER-03, WRAP-02
**Success Criteria** (what must be TRUE):
  1. Authorized users can manage users, enrolments, groups, cohorts, and roles through connector tools when Moodle or safe wrappers expose those actions.
  2. Authorized users can manage courses, categories, sections, activity placement, and other priority course-authoring gaps closed by initial wrapper coverage.
  3. Authorized users can use competency, privacy, compliance, and other operator-grade utilities with explicit handling for long-running or asynchronous work.
**Plans**: TBD

### Phase 8: Compatibility, Audit & Release Hardening
**Goal**: The connector is release-ready across supported Moodle versions and large-site conditions with auditability and end-to-end verification.
**Depends on**: Phase 7
**Requirements**: COMP-01, COMP-02, COMP-03, COMP-04
**Success Criteria** (what must be TRUE):
  1. The connector works across Moodle 4.2, 4.3, 4.4, and 4.5 without depending on 4.5-only features.
  2. Discovery and execution stay performant on plugin-heavy sites without unsafe stale caching or over-broad permission results.
  3. End-to-end tests cover login or SSO bootstrap, transport behavior, permission denial cases, and representative harvested and wrapped tool execution.
  4. Operators can trace discovery and mutating tool execution through usable audit identifiers or logs.
**Plans**: TBD

### Phase 9: Full Coverage & UI Parity
**Goal**: Close the remaining UI-only Moodle action gaps so the connector reaches practical parity with what real users can do in Moodle, while preserving Moodle-native permissions, side effects, and auditability.
**Depends on**: Phase 8
**Requirements**: WRAP-04, WRAP-05, WRAP-06, WRAP-07
**Success Criteria** (what must be TRUE):
  1. Question-bank parity is expanded beyond the current harvested surface to cover high-value authoring workflows such as create, edit, import, preview, category management, and other UI-only actions through typed wrappers or newly harvested upstream externals.
  2. Gradebook parity expands beyond the currently harvested grading APIs to cover tree/report editing, grade-item and category management, outcome/scale workflows, and other high-value instructor operations where Moodle still relies on UI-driven paths.
  3. Badge administration parity expands beyond the current harvested badge endpoints to cover the remaining high-value badge-management workflows that users can perform in the Moodle UI.
  4. A source-backed parity audit against `tmp/moodle` identifies the remaining unsupported user actions across core, standard-module, and high-demand plugin workflows, and each remaining gap is either wrapped, harvested, or explicitly documented with a reason.
**Plans**: 09-01, 09-02, 09-03

### Phase 10: Activity Authoring & Protocol Expansion
**Goal**: The connector fully manages activity lifecycles using internal `locallib.php` classes, solves context exhaustion with a Tool Discovery Engine, and implements persistent knowledgebase capabilities via MCP Resources and Prompts.
**Depends on**: Phase 9
**Requirements**: 
- `wrapper_course_add_module` (instantiates module forms via `instance_add`)
- `wrapper_[module]_read_data` (instantiates internal classes like `assign` and `quiz`)
- `wrapper_moodle_api_search` and `wrapper_moodle_api_execute` (discovery engine)
- `webservice_mcp_memory` database table
- Support for `resources/list`, `resources/read`, `prompts/list`, and `prompts/get`
**Success Criteria** (what must be TRUE):
  1. Claude can author complex modules (Assign, Quiz, Forum, Page, Lesson) and read their internal structured data without HTML scraping.
  2. The 800+ raw Moodle API functions are hidden from `tools/list` to prevent context exhaustion, replaced by a dynamic Search/Execute API discovery wrapper.
  3. Claude can read user-scoped context from `moodle://user/memory` and write back to it using a dedicated memory tool.
  4. Claude can retrieve specialized prompting instructions via MCP `prompts/get`.
**Plans**: 4 plans
- [x] 10-01-PLAN.md — Core Database & Memory Infrastructure
- [x] 10-02-PLAN.md — Activity Authoring & Reporting Services
- [x] 10-03-PLAN.md — Tool Discovery Engine
- [x] 10-04-PLAN.md — Protocol Routing & Manager Wiring

## Progress

**Execution Order:**
Phases execute in numeric order: 1 -> 2 -> 3 -> 4 -> 5 -> 6 -> 7 -> 8 -> 9 -> 10

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Identity Bootstrap & Connector Credentials | 3/3 | Complete    | 2026-04-21 |
| 2. Remote Transport & Session Isolation | 3/3 | Complete    | 2026-04-21 |
| 3. Harvested Catalog & Coverage Inventory | 3/3 | Complete    | 2026-04-21 |
| 4. Permission-Gated Discovery & Safety | 3/3 | Complete    | 2026-04-21 |
| 5. Core Learning Surface & File Flows | 3/3 | Complete    | 2026-04-21 |
| 6. Activity Workflows & Wrapper Foundation | 3/3 | Complete    | 2026-04-21 |
| 7. Operator Workflows & Authoring Gaps | 3/3 | Complete    | 2026-04-21 |
| 8. Compatibility, Audit & Release Hardening | 3/3 | Complete    | 2026-04-21 |
| 9. Full Coverage & UI Parity | 3/3 | Complete    | 2026-04-22 |
| 10. Activity Authoring & Protocol Expansion | 4/4 | Complete    | 2026-05-01 |