# Phase 3: Harvested Catalog & Coverage Inventory - Context

**Phase:** 3  
**Name:** Harvested Catalog & Coverage Inventory  
**Prepared:** 2026-04-21

## Phase Boundary

### In Scope

- Build a site-wide harvested catalog of Moodle external functions from the installed site state.
- Normalize harvested tool metadata with provenance, mutability, and transport/session hints.
- Make `tools/list` usable on large sites through grouping, pagination, and cached catalog snapshots.
- Produce operator-visible coverage reporting by domain with harvested, wrapped, disabled, and unsupported buckets.

### Out Of Scope

- New wrapper endpoints for unsupported actions beyond the metadata/reporting scaffolding needed to count them later.
- Deep permission-visibility heuristics beyond the existing service and runtime restrictions.
- New admin UI pages unless needed to unblock the core catalog/reporting path.

## Locked Decisions

- Phase 3 must treat `tmp/moodle` as the source of truth for how external functions are registered and described.
- Harvesting should use Moodle’s installed external-service system, not static guesses from repo memory.
- The catalog should be site-wide, but tool exposure for a live MCP session must still remain service- and permission-scoped.
- Catalog rebuild/invalidation should be automatic from detectable API-surface changes, not manual-only.
- Coverage reporting must distinguish harvested, wrapped, disabled, and unsupported by domain even if wrapped counts are initially zero.

## Canonical References

### Project State

- `.planning/PROJECT.md`
- `.planning/ROADMAP.md`
- `.planning/REQUIREMENTS.md`
- `.planning/STATE.md`
- `.planning/phases/02-remote-transport-session-isolation/02-VERIFICATION.md`

### Current Plugin Files To Evolve

- `classes/local/tool_provider.php`
- `classes/local/transport/server.php`
- `tests/tool_provider_test.php`
- `db/caches.php`

### Moodle Source Of Truth

- `tmp/moodle/lib/db/install.xml`
- `tmp/moodle/lib/upgradelib.php`
- `tmp/moodle/lib/external/classes/external_api.php`
- `tmp/moodle/lib/classes/component.php`
- `tmp/moodle/lib/db/services.php`
- representative component service definitions such as `tmp/moodle/mod/assign/db/services.php`

## Existing Code Insights

### Reusable Assets

- Phase 2 already created MUC-backed state and cache patterns that can be reused for catalog snapshots.
- `tool_provider.php` already converts parameter and return descriptions into JSON Schema.
- The transport server already centralizes `tools/list` response handling, so Phase 3 can wire catalog pagination/grouping without touching auth/session semantics.

### Gaps To Close

- `tool_provider.php` currently harvests only service-linked function names and emits a flat tool list with no provenance, mutability hints, grouping, coverage, or cache invalidation.
- There is no catalog snapshot or coverage-reporting layer yet.
- There is no wrapper registry abstraction yet, so wrapped coverage must start as an explicit empty or minimal registry rather than ad hoc future code.

## Specific Ideas

- Create a catalog builder/service that combines DB registration state (`external_functions`, `external_services`, `external_services_functions`) with `external_api::external_function_info()` to enrich type/description/session hints.
- Cache the resulting site-wide snapshot behind a deterministic “API surface signature” so rebuilds happen automatically when services/functions/components change.
- Derive normalized domain groups from frankenstyle components and exact component provenance from the harvested function info.
- Expose paginated `tools/list` results with `nextCursor`, `groups`, and `coverage` metadata while keeping the actual tool array service-scoped.

## Deferred Ideas

- Full unsupported-action inventories per exact Moodle screen or UI workflow.
- Wrapper implementation for course authoring and operator gaps beyond the registry/coverage hooks needed now.
