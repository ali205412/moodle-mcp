# Moodle MCP

## What This Is

Moodle MCP is a Moodle 4.2+ connector project that turns a Moodle site into a maximal Model Context Protocol server for AI clients such as Claude Code. It builds on the existing plugin so authenticated users can sign in through Moodle's native login and SSO flows, connect over the transports required by modern MCP clients, and discover or invoke the fullest possible set of Moodle actions they are actually allowed to perform.

The goal is not just to expose today's narrow web service surface. The goal is to leverage Moodle's plugin system as far as possible so the connector can represent core Moodle, installed modules, plugin-provided APIs, and new wrapper endpoints for user actions that Moodle supports in the UI but does not already expose cleanly through existing external functions.

## Core Value

Any Moodle user can connect an AI client to Moodle and safely access the fullest possible set of actions without ever exceeding their real Moodle permissions.

## Requirements

### Validated

- ✓ Expose service-scoped Moodle external functions as MCP tools over JSON-RPC — existing
- ✓ Generate MCP tool schemas from Moodle external descriptions — existing
- ✓ Execute Moodle external functions through MCP `tools/call` requests — existing
- ✓ Authenticate with Moodle web service tokens and restrict discovery to the token's service scope — existing

### Active

- [ ] User can authenticate to the MCP connector through Moodle-native login flows that work with standard Moodle sessions and site-specific SSO setups
- [ ] User can connect from Claude Code or other compatible MCP clients using supported connector transports, including SSE
- [ ] User sees tools filtered automatically by their Moodle identity, service scope, enrolments, roles, capabilities, and context-level permissions
- [ ] The connector exposes the maximum feasible action surface across Moodle core, standard activity modules, installed plugins, and site-specific externals
- [ ] The plugin fills gaps with plugin-owned wrapper endpoints when important Moodle user actions are possible in the UI but not available through existing external APIs
- [ ] Installed module and plugin externals are surfaced automatically when present and allowed for the authenticated user
- [ ] The connector safely exposes destructive and administrative actions when, and only when, the authenticated user is genuinely allowed to perform them in Moodle
- [ ] The system remains compatible with Moodle 4.2+ while preferring a pure-plugin architecture before introducing any companion service

### Out of Scope

- Bypassing Moodle capability checks, role rules, or enrolment restrictions — violates the core security model
- Restricting the project to only the current token-scoped external function surface — too narrow for the project's stated goal
- Locking the project to Moodle 4.5 only — the project must remain usable on Moodle 4.2+
- Leading with browser automation or scraping as the primary execution path — stable Moodle plugin and API integrations should come first
- Introducing a standalone companion service before the Moodle plugin path is pushed as far as it can go — only use a service when plugin constraints prove it is necessary

## Context

The existing repository already contains a working Moodle MCP plugin in `server.php`, `classes/local/server.php`, `classes/local/request.php`, and `classes/local/tool_provider.php`. It currently exposes service-scoped Moodle external functions as MCP tools, translates Moodle external descriptions to JSON Schema, and executes tool calls through Moodle's `webservice_base_server` flow.

The codebase map in `.planning/codebase/` establishes the current baseline: this is a thin Moodle protocol adapter, not yet a full user-scoped connector platform. Its current auth model is token-centric, its tool discovery is limited to functions already attached to a Moodle external service, and it does not yet provide SSE transport, Claude Code connector behavior, or Moodle-native user login flows.

The upstream reference platform is now available locally in `tmp/moodle` on branch `MOODLE_405_STABLE`. Initial inspection shows Moodle already has a broad external API surface across core areas and many modules, but not every user action is exposed through stable externals. Reaching the project's goal will require both automatic harvesting of existing externals and targeted wrapper endpoints for capability-safe coverage gaps.

Primary users are Moodle site operators who want to expose their Moodle instance to AI clients, and Moodle end users who should be able to connect using their real Moodle identity. Authentication must work even when the site uses SSO, which means the connector should defer to Moodle's own login and session systems rather than invent a separate identity model.

## Constraints

- **Compatibility**: Moodle 4.2+ — the project must support the existing plugin baseline and not become 4.5-only
- **Architecture**: Prefer pure Moodle plugin deployment — only introduce a companion service if plugin-only delivery cannot satisfy connector or transport requirements
- **Authentication**: Reuse Moodle-native login, session, and SSO flows — sites may authenticate users through external identity providers
- **Security**: Never expose tools beyond the authenticated user's real Moodle permissions — this is the non-negotiable rule
- **Connector Support**: Claude Code connector compatibility and SSE transport are first-class goals — the connector must be usable by real MCP clients, not just test scripts
- **Coverage**: Maximal action coverage is the target — new wrappers are acceptable when Moodle's existing external APIs are insufficient

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Prefer Moodle's plugin system and existing external APIs first | Maximize leverage of the host platform before adding custom infrastructure | — Pending |
| Support Moodle 4.2+ instead of targeting only Moodle 4.5 | Broader installability matters more than a version-specific shortcut | — Pending |
| Reuse Moodle login, session, and SSO behavior for connector auth | Sites may already depend on SSO and existing Moodle sessions | — Pending |
| Expose all actions the user can legitimately perform, including destructive and administrative ones | The project goal is maximal capability coverage, with permission filtering as the safety mechanism | — Pending |
| Fill functional gaps with plugin-owned wrappers when core externals are missing | Existing external APIs do not cover every user action needed for a truly maximal connector | — Pending |
| Treat companion services as fallback architecture, not the default | The project should remain as Moodle-native as possible unless proven blocked | — Pending |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `$gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `$gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-04-21 after initialization*
