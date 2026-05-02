# Phase 4: Permission-Gated Discovery & Safety - Context

**Phase:** 4  
**Name:** Permission-Gated Discovery & Safety  
**Prepared:** 2026-04-21

## Phase Boundary

### In Scope

- Filter tool visibility using authenticated identity, connector mode, explicit capability requirements, and resolvable site policy.
- Add discovery-time eligibility metadata for boundaries that can be resolved safely.
- Re-check authoritative Moodle permission/context at call time and expose clearer denial/restriction metadata.
- Add risk-level and confirmation metadata for mutating/destructive tools.

### Out Of Scope

- Full wrapper implementation for unsupported actions.
- Perfect discovery-time resolution of every course/module/ownership/group rule when the required target context is not yet known.
- Final operator/admin wrapper workflows (later phases).

## Locked Decisions

- Phase 4 must not claim generic discovery-time certainty where Moodle only exposes call-time checks.
- Discovery must still become stricter than a flat catalog by using explicit capabilities, restricted context, and module-specific access-information externals when available.
- Risk signaling should inform users about destructive or mutating tools without blocking legitimate capabilities by default.
- Call-time denial responses must stay Moodle-authoritative; the plugin should enrich explanations, not override core permission decisions.

## Canonical References

### Project State

- `.planning/PROJECT.md`
- `.planning/ROADMAP.md`
- `.planning/REQUIREMENTS.md`
- `.planning/STATE.md`
- `.planning/phases/03-harvested-catalog-coverage-inventory/03-VERIFICATION.md`

### Current Plugin Files To Evolve

- `classes/local/catalog/catalog_builder.php`
- `classes/local/tool_provider.php`
- `classes/local/transport/server.php`
- `tests/tool_provider_test.php`
- `tests/transport_server_test.php`

### Moodle Source Of Truth

- `tmp/moodle/lib/external/classes/external_api.php`
- `tmp/moodle/lib/accesslib.php`
- `tmp/moodle/lib/moodlelib.php`
- representative access-information functions:
  - `tmp/moodle/mod/forum/externallib.php`
  - `tmp/moodle/mod/assign/externallib.php`
  - `tmp/moodle/mod/quiz/classes/external.php`
  - `tmp/moodle/mod/workshop/classes/external.php`
  - `tmp/moodle/calendar/externallib.php`

## Existing Code Insights

- The harvested catalog already includes capabilities, loginrequired, readonlysession, mutability, and provenance metadata.
- Discovery is still flat with respect to user-specific eligibility beyond service scope.
- Transport execution already re-checks authoritative Moodle access via normal external function loading/execution, but denial explanations are still generic.

## Specific Ideas

- Add a visibility/eligibility resolver that hides tools when explicit required capabilities fail in the restricted context.
- Build a companion-map between action tools and `*_access_information` tools so discovery can annotate richer eligibility when a module already exposes it.
- Add risk metadata derived from mutability, destructive hints, and high-risk capability patterns.
- Enrich transport error output with normalized restriction reasons for common permission/context exceptions.

## Deferred Ideas

- Fine-grained per-record ownership checks for arbitrary objects before a target id/context is supplied.
- Confirmation UX beyond metadata flags and denial reasons.
