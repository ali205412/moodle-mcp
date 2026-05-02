---
phase: 03-harvested-catalog-coverage-inventory
plan: 01
subsystem: catalog-core
tags: [moodle, catalog, discovery, coverage, cache]
requires:
  - phase: 02
    provides: transport and cache patterns
provides:
  - Site-wide harvested catalog snapshot
  - Domain coverage summary
  - Wrapper registry boundary
affects: [discovery, cache, coverage]
tech-stack:
  added: [catalog snapshot cache, schema builder, wrapper registry]
  patterns: [DB-plus-external-function-info harvest, signature-based cache invalidation]
key-files:
  created:
    - classes/local/catalog/schema_builder.php
    - classes/local/catalog/catalog_builder.php
    - classes/local/catalog/wrapper_registry.php
    - tests/catalog_builder_test.php
  modified:
    - db/caches.php
key-decisions:
  - "Catalog truth comes from installed DB registration enriched by external_function_info, not from static source guesses alone."
  - "Coverage counts unsupported at the installed-component/domain layer and wrapped through an explicit registry boundary."
patterns-established:
  - "catalog_builder caches one site-wide snapshot behind a deterministic surface signature"
  - "wrapper_registry is the future seam for non-harvested wrapper coverage"
requirements-completed: [DISC-01, DISC-02, WRAP-03-foundation]
duration: 34min
completed: 2026-04-21
---

# Phase 3: Harvested Catalog & Coverage Inventory Summary

**Wave 1 harvested catalog foundation**

## Performance

- **Duration:** 34 min
- **Started:** 2026-04-21T17:31:00Z
- **Completed:** 2026-04-21T18:05:00Z
- **Tasks:** 2
- **Files modified:** 5

## Accomplishments

- Added a site-wide catalog builder that harvests installed external functions from DB state and enriches them with `external_function_info()`.
- Added a deterministic surface-signature cache boundary for catalog snapshots.
- Added domain coverage reporting with harvested, wrapped, disabled, and unsupported buckets plus an explicit wrapper registry seam.

## Files Created/Modified

- `classes/local/catalog/schema_builder.php` - Shared schema normalization for harvested parameter/return metadata.
- `classes/local/catalog/catalog_builder.php` - Site-wide catalog harvest, cache, and coverage summary engine.
- `classes/local/catalog/wrapper_registry.php` - Explicit wrapped-tool registry boundary for later phases.
- `tests/catalog_builder_test.php` - Covers harvested metadata and coverage bucket behavior.
- `db/caches.php` - Adds catalog snapshot cache definition.

## Issues Encountered

- None in local implementation. Live runtime validation is deferred to later phase verification, consistent with the current local-only workflow.

## Next Phase Readiness

- Wave 2 can now refactor `tool_provider.php` to project from the harvested snapshot instead of rebuilding raw DB queries on every discovery call.

---
*Phase: 03-harvested-catalog-coverage-inventory*
*Completed: 2026-04-21*
