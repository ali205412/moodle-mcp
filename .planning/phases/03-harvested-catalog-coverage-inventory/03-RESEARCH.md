# Phase 3: Harvested Catalog & Coverage Inventory - Research

**Researched:** 2026-04-21  
**Confidence:** HIGH

## Source-Backed Findings

### 1. Installed external functions are represented in DB, but richer metadata lives in code

- Moodle stores installed external functions in `external_functions`, including `name`, `classname`, `methodname`, `classpath`, `component`, `capabilities`, and `services`.  
  Verified: `tmp/moodle/lib/db/install.xml`
- Moodle stores service membership in `external_services` and `external_services_functions`.  
  Verified: `tmp/moodle/lib/db/install.xml`
- `external_api::external_function_info()` enriches a DB record by loading the implementation class and then reading the component’s `db/services.php` to attach `description`, `type`, `allowed_from_ajax`, `loginrequired`, and `readonlysession`.  
  Verified: `tmp/moodle/lib/external/classes/external_api.php`

### 2. Service registration flows through `db/services.php` and upgrade sync

- `external_update_descriptions()` is the authoritative upgrade/install path that reads every component’s `db/services.php` and syncs `external_functions`, `external_services`, and `external_services_functions`.  
  Verified: `tmp/moodle/lib/upgradelib.php`
- `external_update_services()` adds externally-declared function membership to built-in services by shortname after component upgrades.  
  Verified: `tmp/moodle/lib/upgradelib.php`
- Representative component definitions like `mod/assign/db/services.php` show the raw metadata available for harvesting, including `type`, `capabilities`, `services`, `ajax`, and descriptions.  
  Verified: `tmp/moodle/mod/assign/db/services.php`

### 3. Installed component inventory is available from core component APIs

- `\core\component::get_component_list()` returns all installed plugins and core subsystems as frankenstyle component names plus paths.  
  Verified: `tmp/moodle/lib/classes/component.php`
- This means coverage reporting can distinguish domains with no external functions at all instead of only reporting on registered functions.

## Implications For This Phase

- The site-wide harvest should start from DB state for speed and truth about what is actually registered on the site.
- The catalog must call `external_function_info()` to recover normalized metadata that the DB alone does not preserve.
- Coverage reporting should operate at least at the domain/component layer, using installed component inventory to identify unsupported areas.
- Because the DB tables do not carry explicit update timestamps for every function row, cache invalidation should rely on a surface signature derived from counts/ids/site version rather than timestamp-only logic.

## Recommended Implementation Shape

### Catalog snapshot service

- Build a reusable service that harvests all registered external functions once into a cached snapshot.
- Include per-function provenance:
  - component
  - domain
  - classname
  - methodname
  - classpath
  - service ids / shortnames
  - capabilities
  - description
  - `type`
  - `allowed_from_ajax`
  - `loginrequired`
  - `readonlysession`
  - parameter and return schemas

### Tool-list projection

- Project the site-wide snapshot into service-scoped tool lists for the current transport/session.
- Add:
  - structured grouping
  - cursor-based pagination
  - MCP annotations for read-only/destructive/idempotent hints
  - vendor-specific provenance metadata

### Coverage reporting

- Compute domain-level coverage buckets:
  - harvested: registered and available through the catalog
  - wrapped: known wrapper registry entries
  - disabled: registered externals with no enabled service membership
  - unsupported: installed components/domains with no harvested or wrapped entries

## Risks

- `external_function_info()` can throw if a broken function registration points to missing code; the catalog builder should skip and record those failures rather than crashing the whole snapshot.
- Large sites may have many functions, so the snapshot should be cached and paginated before it reaches transport responses.
- Domain classification is partly an inference from frankenstyle component names; the mapping must be explicit and deterministic.

## Primary Sources

- `tmp/moodle/lib/db/install.xml`
- `tmp/moodle/lib/upgradelib.php`
- `tmp/moodle/lib/external/classes/external_api.php`
- `tmp/moodle/lib/classes/component.php`
- `tmp/moodle/lib/db/services.php`
- `tmp/moodle/mod/assign/db/services.php`
