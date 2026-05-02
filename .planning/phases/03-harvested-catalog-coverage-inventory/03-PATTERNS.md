# Phase 03: Harvested Catalog & Coverage Inventory - Pattern Map

## File Classification

- `classes/local/catalog/catalog_builder.php` - service / harvester / cache builder
- `classes/local/catalog/wrapper_registry.php` - service / registry
- `classes/local/tool_provider.php` - projection / adapter
- `classes/local/transport/server.php` - transport integration point
- `db/caches.php` - cache configuration
- `tests/catalog_builder_test.php` - catalog harvester tests
- `tests/tool_provider_test.php` - projection and pagination tests

## Pattern Assignments

### `classes/local/catalog/catalog_builder.php`

- Pattern: site-wide snapshot builder over DB + `external_function_info()`
- Responsibilities:
  - harvest installed external functions and linked services
  - compute deterministic snapshot signature
  - cache normalized catalog entries
  - compute coverage-by-domain summary

### `classes/local/catalog/wrapper_registry.php`

- Pattern: explicit registry boundary for wrapper coverage
- Responsibilities:
  - list known wrapper descriptors by domain
  - return empty/minimal registry safely until wrapper phases add entries

### `classes/local/tool_provider.php`

- Pattern: projection adapter over cached catalog
- Responsibilities:
  - map service ids to tool slices
  - add pagination and grouping
  - return normalized MCP tool metadata

### `classes/local/transport/server.php`

- Pattern: transport control adapter
- Responsibilities:
  - pass `cursor`, `limit`, and grouping params from `tools/list`
  - include coverage/group metadata in list responses without changing auth rules

## Shared Patterns

### Source-Backed Metadata Enrichment

- DB rows provide what is registered now.
- `external_function_info()` provides what the function actually means.
- Both sources are required for a complete catalog entry.

### Signature-Based Cache Invalidation

- The catalog cache should be invalidated by a computed surface signature rather than manual purge only.

### Domain Classification

- Domain grouping must be deterministic and explicit from frankenstyle components.
- Exact component provenance should still be preserved alongside domain grouping.

## Notes For Planning

- Avoid putting catalog-building logic directly into `tool_provider.php`; keep that file as a read/project adapter.
- Keep wrapped coverage as a first-class registry even if Phase 3 starts with few or zero wrappers.
- Do not weaken Phase 2 transport scoping: the snapshot may be site-wide, but transport responses must remain service-scoped.
