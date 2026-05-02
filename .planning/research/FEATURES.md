# Feature Research

**Domain:** maximal Moodle MCP connector for Moodle core, modules, plugins, auth, transport, permissions, and connector UX  
**Researched:** 2026-04-21  
**Confidence:** HIGH

## Feature Landscape

A maximal Moodle MCP connector should start from Moodle's existing external API surface, not from custom wrappers. The connector's job is to harvest that surface automatically, enforce the real Moodle permission model, and then add wrappers only where Moodle still keeps legitimate user actions inside page controllers, forms, or UI-only workflows.

In the local Moodle 4.5 reference tree used for this research, the platform already declares a very large external surface across core, standard modules, admin tools, enrolment plugins, gradebook, question-bank extensions, and third-party-style plugin areas. That means "serious connector" table stakes are harvest-first, wrapper-second.

Three delivery layers should define the product boundary:

- **Automatic harvest:** core, standard modules, installed plugins, and site-specific externals declared in `db/services.php`.
- **Connector bridge:** transport, auth, session handling, permission filtering, file/draft orchestration, tool metadata, and operator UX.
- **Targeted wrappers:** high-value Moodle actions that users can perform in the UI but which are not already exposed through stable external functions.

## Domain Areas That Can Become Roadmap Phases

| Domain area | Primary actor | Coverage mode | What belongs here | Complexity | Major dependencies |
|-------------|---------------|---------------|-------------------|------------|--------------------|
| Identity and session bridge | All users | WRAPPER | Moodle-native login, SSO/session reuse, session-linked token bootstrap, permanent-token fallback, logout and renewal | HIGH | Transport, permission model |
| External surface harvest and plugin discovery | All users | AUTO | Scan declared services/functions, generate schemas, expose installed plugin externals, surface site-specific externals | MEDIUM | Identity, tool registry |
| Permission and context enforcement | All users | MIXED | Service scope, context validation, capabilities, enrolment/group ownership filtering, destructive-tool policy | HIGH | Identity bridge, harvest |
| Core learning and collaboration surface | Learners, teachers | MOSTLY AUTO | Courses, contents, completion, files, calendar, messaging, notes, blogs, profiles, preferences, private files | MEDIUM | Harvest, file handling, permissions |
| Activity workflows and assessment execution | Learners, teachers, graders | MIXED | Assign, forum, quiz, workshop, feedback, data, wiki, glossary, choice, survey, chat, scorm, h5pactivity, bigbluebutton, lti | HIGH | Files/drafts, permissions |
| Course authoring and structure | Teachers, managers | MIXED | Sections, modules, quick-create, move/hide/delete, copy/import, course settings, content module authoring | HIGH | Courseformat APIs, wrappers |
| Gradebook, question bank, badges, competencies | Teachers, admins | WRAPPER-HEAVY | Grade tree actions, grading forms, question bank CRUD, badge admin, learning plans, competency workflows | VERY HIGH | Forms, permissions, files |
| People and access administration | Managers, admins | MIXED | Users, enrolments, cohorts, groups, roles, role assignments, policy/privacy flows | HIGH | Permissions, identity bridge |
| Connector UX and operator controls | Users, operators | WRAPPER | Search, annotations, confirmations, dry-run, audit, progress, coverage reporting, feature policy | MEDIUM-HIGH | All prior areas |

## Table Stakes (Users Expect These)

### End-User Capability Surface

| Feature cluster | Why expected | Harvest mode | Complexity | Notes |
|-----------------|--------------|--------------|------------|-------|
| Personalized course discovery and navigation | An AI client must understand what courses, sections, and activities the current user can actually access | AUTO | MEDIUM | Built on `core_course_*`, `core_completion_*`, course content APIs, and activity visibility rules |
| File retrieval and file submission that actually works | Moodle usage is file-heavy; a connector that cannot read or attach files is incomplete | MIXED | HIGH | Use `core_files_*` plus dedicated upload/download endpoints and draft-area orchestration |
| Collaboration and communication | Users expect messages, calendar actions, forums, chat, notes, and personal content to be reachable | MOSTLY AUTO | MEDIUM | Strong existing surfaces in `core_message_*`, `core_calendar_*`, `mod_forum_*`, `mod_chat_*`, `core_notes_*`, and related APIs |
| Assessment participation | Assignment submission, quiz attempts, workshop participation, and feedback responses are core Moodle behavior | MOSTLY AUTO | HIGH | Strong existing externals already exist for assign, quiz, workshop, feedback, scorm, h5pactivity, and related modules |
| Personal profile, preferences, and private files | Users expect the connector to manage their own profile and private workspace, not just course content | MOSTLY AUTO | MEDIUM | Use `core_user_*`, private-files flows, notification preferences, and user device/preferences surfaces |
| Access explanation and eligibility feedback | The user needs to understand why a tool is visible, hidden, or failing | WRAPPER | MEDIUM | Connector should compute human-readable reasons from service scope, context, enrolment, and capability checks |

### Operator and Admin Capability Surface

| Feature cluster | Why expected | Harvest mode | Complexity | Notes |
|-----------------|--------------|--------------|------------|-------|
| Automatic exposure of core, module, and plugin externals | A serious Moodle connector cannot require manual tool wiring for every installed component | AUTO | MEDIUM | This is the baseline feature that turns Moodle into a living tool catalog rather than a hand-curated integration |
| Course, category, section, and activity management | Teachers and managers expect to create, restructure, copy, hide, move, and remove course content | MIXED | HIGH | `core_course_*`, `core_courseformat_*`, `core_course_edit_module`, and `core_course_edit_section` cover much of this, but richer settings flows still need wrappers |
| Enrolments, groups, cohorts, roles, and user lookup | These are standard operator workflows in Moodle | MOSTLY AUTO | HIGH | Strong coverage exists in `core_enrol_*`, `enrol_manual_*`, `enrol_self_*`, `core_group_*`, `core_cohort_*`, `core_role_*`, and `core_user_*` |
| Grading and marking operations | Instructors expect grading, overrides, extensions, flags, and feedback actions | MIXED | HIGH | Assign and grade APIs are strong, but full gradebook tree/report editing is broader than current externals |
| Competencies, plans, and privacy/compliance | Enterprise and institutional Moodle sites expect competency and privacy workflows to be reachable | MOSTLY AUTO | HIGH | Strong existing coverage in `core_competency_*`, `tool_lp_*`, and `tool_dataprivacy_*` |
| Badge management | Badge issuance and administration are common operator asks on serious Moodle installs | WRAPPER-HEAVY | HIGH | Current externals cover some reads and enable/disable paths, but criteria, alignment, backpack, and broader admin flows remain UI-heavy |
| Question bank authoring | Quiz-heavy sites expect question lifecycle control, not just attempt execution | WRAPPER-HEAVY | VERY HIGH | Existing question-bank externals are thin; full create/edit/import/preview remains a major wrapper program |
| Async operator workflows | Copy, backup, sync, and other queued actions must be surfaced safely | MIXED | HIGH | Model progress and polling explicitly instead of pretending these are instant mutations |

### Auth, Transport, and Permissions

| Feature cluster | Why expected | Harvest mode | Complexity | Notes |
|-----------------|--------------|--------------|------------|-------|
| Moodle-native login and SSO compatibility | Real users should authenticate through the same login, SSO, MFA, and session model the site already uses | WRAPPER | HIGH | Manual token creation can remain a fallback, not the primary UX |
| Per-user MCP sessions tied to real Moodle identity | The product promise is "everything you are allowed to do", not "everything one service token can do" | MIXED | HIGH | Must bind discovery and calls to the authenticated user's Moodle identity and context |
| Modern remote transport support | A serious remote MCP connector must work with current and older clients | WRAPPER | HIGH | Streamable HTTP first, legacy SSE compatibility, and strict JSON-RPC/MCP compliance |
| Strict permission filtering and context validation | Maximal coverage is only safe if every tool is still bounded by real Moodle permissions | MIXED | HIGH | Combine service scope, context validation, capabilities, enrolment, and ownership checks |
| Draft/file orchestration | Many Moodle writes depend on draft areas, file placeholders, and dedicated upload/download endpoints | MIXED | HIGH | The connector must hide draft-item plumbing from AI clients |
| Session and origin security | Remote MCP endpoints are high-value targets | WRAPPER | HIGH | Enforce HTTPS-only sensitive flows, limited CORS, origin validation, renewal/expiry handling, and rate limiting |

### Connector UX

| Feature cluster | Why expected | Harvest mode | Complexity | Notes |
|-----------------|--------------|--------------|------------|-------|
| Canonical tool taxonomy by Moodle component and action | AI clients need predictable discovery and stable naming | WRAPPER | MEDIUM | Preserve canonical Moodle component/function identity even when adding friendly aliases |
| Rich metadata and tool annotations | The model must be able to separate read-only, write, destructive, async, and file-requiring tools | WRAPPER | MEDIUM | This is essential once the connector exposes admin-grade actions |
| Confirmation, dry-run, and impact preview | Destructive and operator-grade actions need an explicit safety boundary | WRAPPER | MEDIUM-HIGH | Required for delete, enrolment, role, grade, badge, and course-structure changes |
| Human-readable errors and next-step hints | Raw Moodle exceptions are too opaque for AI-first use | WRAPPER | MEDIUM | Map exceptions to actionable guidance without hiding the underlying cause |
| Coverage and gap reporting | Operators need to know what is auto-harvested, wrapped, disabled, or not yet supported | WRAPPER | MEDIUM | Turn feature gaps into visible product state rather than silent absence |
| Long-running task UX | Some Moodle operations are queued, staged, or multi-step | WRAPPER | MEDIUM-HIGH | Include progress resources, polling tools, and resumable workflows |

## Differentiators (Competitive Advantage)

| Feature | Value proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Explicit harvest vs wrapper coverage model | Makes the connector auditable and roadmapable; operators can see what came from Moodle and what is connector-owned | MEDIUM | Coverage should be visible per component and per tool family |
| Wrapper framework built on Moodle-native patterns | Reuses real Moodle integration idioms instead of inventing a parallel RPC model | HIGH | Base wrappers on existing patterns such as submit-form externals, dynamic forms, and state-update APIs |
| Plugin ecosystem auto-expansion | Installed plugin externals should appear automatically without connector code changes | MEDIUM | This is the clearest path to "maximal" on real sites with mixed plugin stacks |
| Gap-closing wrapper program for known weak areas | Turns the product from "token mirror" into "best-in-class connector" | VERY HIGH | Highest-value gaps are question bank CRUD, badge admin, gradebook tree actions, and content-module authoring |
| Capability-aware safety envelopes on every mutating tool | Makes admin and destructive use realistic in AI clients | MEDIUM | Include read/write/destructive flags, impact preview, confirmation requirement, and audit identifiers |
| Session-aware auth bridge | Removes the need for users to create personal service tokens manually | HIGH | Use Moodle-native login/session flows first; token bootstrap is an implementation detail |
| Semantic tool bundles and aliases | Reduces the burden of a flat 1,000+ function surface for AI clients | MEDIUM-HIGH | Keep canonical function names while adding task-oriented entry points for common workflows |
| Async-aware modeling | Treats queued and provider-synced workflows honestly instead of pretending they are synchronous | MEDIUM | Important for copy/backup-style actions and plugin/provider sync tasks |

## Anti-Features (Commonly Requested, Often Problematic)

| Feature | Why requested | Why problematic | Alternative |
|---------|---------------|-----------------|-------------|
| "Just expose the current web-service list" | Cheapest path and easiest demo | Fails the maximal-product goal because many legitimate UI workflows remain outside the declared service surface | Use auto-harvest as the baseline, then add targeted wrappers for high-value gaps |
| Generic arbitrary form/page executor | Looks like one wrapper that can reach everything | Creates an opaque, unstable, and oversized security surface; hard to reason about capabilities and side effects | Build typed wrappers or vetted dynamic-form adapters per domain/class |
| Browser automation as the primary execution path | Seems like the fastest way to reach every UI action | Brittle across themes, JS flows, and SSO; weak auditability and poor long-term supportability | Keep browser automation out of the product path; use Moodle APIs and wrappers first |
| Connector-owned super-admin token or service account | Simplifies development and support | Breaks the per-user permission model and destroys the audit trail | Use per-user auth sessions/tokens; admin actions only when the real admin is authenticated |
| Raw mobile HTML/JS or AJAX fragments as first-class tools | Many plugins already support the mobile app and return UI content | AI clients need structured actions, not rendered UI fragments and client JS payloads | Use mobile callbacks as optional discovery or content hints, not as the main action interface |
| Arbitrary PHP, SQL, or filesystem execution | Appears to cover every remaining gap | Security and support disaster; bypasses Moodle's real permission and API model | Stay inside Moodle APIs, external functions, and typed wrapper endpoints |
| Implicit destructive execution | Reduces round trips for the model | Too risky once the connector reaches grades, roles, course structure, or user data | Require confirmation, dry-run, and impact previews for destructive/admin actions |
| Arbitrary impersonation | Admins want to "act as student" from one session | Dangerous audit model and easy to abuse | Use separate real-user sessions or explicit read-only inspection tools |
| Blanket admin-tree coverage via generic settings forms | Feels like the shortest path to full site administration | Site settings are broad, inconsistent, and often too risky to expose without per-family review | Expand site-admin coverage through typed, policy-gated families only |

## Harvest vs Wrapper Boundary

### Mostly Auto-Harvestable

- Core surfaces already declared in `lib/db/services.php`: courses, users, enrolments, groups, cohorts, roles, completion, files, calendar, messaging, comments, notes, blogs, tags, search, competencies, reporting, and many site/operator utilities.
- Standard modules with meaningful externals already in place: assign, forum, quiz, workshop, feedback, data, wiki, glossary, choice, survey, chat, scorm, h5pactivity, bigbluebutton, lti, and others.
- Installed plugin externals declared in their own `db/services.php`, including local plugins and admin tools.

### Hybrid: Auto-Harvest Plus Connector Logic

- Course editing and structure: existing `core_courseformat_*`, `core_course_edit_module`, and `core_course_edit_section` cover a lot, but module settings and rich authoring still need wrappers.
- File-bearing workflows: Moodle already exposes file APIs and endpoints, but the connector must manage draft areas, file placeholders, and next-step orchestration.
- Form-backed APIs that already have official externals: calendar event submit/update, enrolment edit forms, grading forms, data privacy forms, and similar flows.
- Dynamic forms: useful as an internal pattern when the target class is known and safe, but not a generic public "run any form" feature.

### Wrapper-Heavy

- Session and SSO to MCP auth bridge.
- Question bank create/edit/import/preview and broader authoring beyond today's thin extension externals.
- Badge administration beyond basic reads and enable/disable actions.
- Gradebook tree/report editing and other operator-heavy grading workflows outside the current declared APIs.
- Rich authoring for read-mostly content modules such as page, resource, url, folder, label, and book.
- Plugin-specific UI-only actions that have no stable external function today.

## Feature Dependencies

```text
Identity and session bridge
    -> Permission and context enforcement
        -> External surface harvest and plugin discovery
            -> Core learning and collaboration surface
            -> People and access administration
            -> Activity workflows and assessment execution
                -> File and draft orchestration
                -> Gradebook, question bank, badge, and competency wrappers
            -> Course authoring and structure

Connector UX and operator controls
    -> overlays every other area

Coverage reporting
    -> requires harvest registry
    -> requires wrapper registry
```

### Dependency Notes

- **Identity bridge requires transport support:** per-user discovery is not trustworthy until the connector can bind the MCP session to a real Moodle session or legitimate token bootstrap flow.
- **Permission enforcement requires identity first:** the connector cannot safely promise "everything you are allowed to do" until it can evaluate service scope, context, capability, enrolment, and ownership for the current user.
- **Core learning surface requires harvest first:** most read-heavy and participation-heavy value comes from existing externals, so the harvest engine is an early dependency.
- **File/draft orchestration is required by many write paths:** assignments, forum/blog edits, calendar forms, private files, and content authoring all depend on draft-area handling.
- **Course authoring depends on both auto and wrappers:** quick-create and courseformat state actions reduce the wrapper load, but they do not eliminate module-specific authoring gaps.
- **Gradebook/question-bank/badge work should come after the core write path is stable:** these are high-value but they are also the most wrapper-heavy and likely to need deeper phase-specific research.
- **Connector UX depends on real coverage data:** search, annotations, safety envelopes, and gap reporting are only useful once the connector knows what was harvested, wrapped, disabled, or unsupported.

## Recommended Rollout

### Build First

- [ ] Identity and session bridge plus modern MCP transport support
- [ ] Automatic harvest of all installed externals with per-user permission filtering
- [ ] Core end-user surface: courses, files, completion, calendar, messaging, user/profile, private files
- [ ] High-value activity workflows: assign, forum, quiz, workshop, feedback
- [ ] Basic operator surface: courses, enrolments, groups, cohorts, users, roles

### Build Next

- [ ] Course authoring wrappers for modules and settings not fully covered by current externals
- [ ] Gradebook and marking workflow expansion beyond current grading APIs
- [ ] Question bank wrapper program
- [ ] Badge and compliance/operator workflow expansion
- [ ] Coverage reporting, confirmations, dry-run, and richer audit tooling

### Defer Until the Core Model Is Proven

- [ ] Broad plugin-specific UI wrappers with no demonstrated site demand
- [ ] Generic admin-tree coverage
- [ ] Mobile-content compatibility layers as a primary action strategy
- [ ] Any browser-automation fallback promoted into a product feature

## Feature Prioritization Matrix

| Feature cluster | User value | Implementation cost | Priority |
|-----------------|------------|---------------------|----------|
| Identity and session bridge | HIGH | HIGH | P1 |
| External harvest and plugin discovery | HIGH | MEDIUM | P1 |
| Permission and context enforcement | HIGH | HIGH | P1 |
| Core learning and collaboration surface | HIGH | MEDIUM | P1 |
| Activity workflows and assessment execution | HIGH | HIGH | P1 |
| People and access administration | HIGH | HIGH | P1 |
| Course authoring and structure | HIGH | HIGH | P2 |
| Gradebook, question bank, badges, competencies | HIGH | VERY HIGH | P2 |
| Connector UX and operator controls | MEDIUM-HIGH | MEDIUM-HIGH | P2 |
| Broad plugin wrapper framework | MEDIUM | HIGH | P3 |

**Priority key:**

- P1: Must-have to make the connector credibly useful
- P2: Should-have to make it best-in-class
- P3: Valuable after the core model is proven on real sites

## Sources

- Required project context:
  - `.planning/PROJECT.md`
  - `.planning/codebase/ARCHITECTURE.md`
  - `.planning/codebase/INTEGRATIONS.md`
- Upstream Moodle 4.5 reference tree:
  - `tmp/moodle/lib/db/services.php`
  - `tmp/moodle/course/externallib.php`
  - `tmp/moodle/course/format/classes/external/create_module.php`
  - `tmp/moodle/course/format/classes/external/new_module.php`
  - `tmp/moodle/course/format/classes/external/update_course.php`
  - `tmp/moodle/enrol/externallib.php`
  - `tmp/moodle/calendar/externallib.php`
  - `tmp/moodle/lib/form/classes/external/dynamic_form.php`
  - `tmp/moodle/admin/tool/mobile/classes/external.php`
  - `tmp/moodle/login/token.php`
  - `tmp/moodle/webservice/lib.php`
  - Representative module service files under `tmp/moodle/mod/*/db/services.php`
  - Question-bank extension service files under `tmp/moodle/question/bank/*/db/services.php`
- Official Moodle developer documentation:
  - `https://moodledev.io/docs/4.4/apis/subsystems/external`
  - `https://moodledev.io/docs/4.4/apis/subsystems/external/writing-a-service`
  - `https://moodledev.io/docs/4.5/apis/subsystems/external/security`
  - `https://moodledev.io/docs/4.5/apis/subsystems/external/files`
  - `https://moodledev.io/docs/4.5/apis/subsystems/access`
  - `https://moodledev.io/docs/4.5/apis/plugintypes/local`
  - `https://moodledev.io/docs/4.5/apis/plugintypes/communication`
  - `https://moodledev.io/docs/4.5/apis/subsystems/communication`
  - `https://phpdoc.moodledev.io/4.5/dd/d12/group__core__webservice.html`
- Official MCP references for transport/auth expectations:
  - `https://modelcontextprotocol.io/specification/2025-06-18/basic/transports`
  - `https://modelcontextprotocol.io/specification/2025-03-26/changelog`
  - `https://modelcontextprotocol.io/specification/2025-03-26/basic/authorization`

---
*Feature research for: maximal Moodle MCP connector*
*Researched: 2026-04-21*
