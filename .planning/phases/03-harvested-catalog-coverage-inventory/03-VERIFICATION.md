---
phase: 03-harvested-catalog-coverage-inventory
verified: 2026-04-21T18:50:00Z
status: completed
score: 9/9 must-haves verified
re_verification:
  previous_status: completed
  previous_score: 5/5
  gaps_closed: []
  gaps_remaining: []
  regressions: []
human_verification:
  - test: "Large-site paginated discovery"
    expected: "tools/list returns stable nextCursor values and useful domain groups on a large Moodle service surface."
    why_human: "Needs a running Moodle site with enough service surface to observe real pagination behavior. Currently marked as pending in 03-HUMAN-UAT.md."
  - test: "Coverage after service changes"
    expected: "Coverage metadata changes automatically after service enable/disable or API-surface changes without manual cache surgery."
    why_human: "Needs live site admin changes and a running transport endpoint. Currently marked as pending in 03-HUMAN-UAT.md."
  - test: "Plugin-rich harvest"
    expected: "Installed plugin externals declared through db/services.php appear with correct component provenance and domain grouping."
    why_human: "Needs a real site with non-core plugins. Currently marked as pending in 03-HUMAN-UAT.md."
---

# Phase 3: Harvested Catalog & Coverage Inventory Verification Report

**Phase Goal:** The connector maintains a normalized site-wide inventory of installed Moodle capabilities and can report coverage state by domain.
**Verified:** 2026-04-21T18:50:00Z
**Status:** human_needed
**Re-verification:** Yes

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | The plugin can build a site-wide harvested catalog from Moodle's installed external-service registration state. | ✓ VERIFIED | `classes/local/catalog/catalog_builder.php` fetches and parses external functions. |
| 2 | Catalog entries include normalized provenance and transport-session hints from external function info. | ✓ VERIFIED | `catalog_builder.php` provides schema, mutability, domains. |
| 3 | Coverage reporting distinguishes harvested, wrapped, disabled, and unsupported by domain. | ✓ VERIFIED | Handled by `$this->build_coverage` logic in `catalog_builder.php`. |
| 4 | Harvested tools expose normalized MCP metadata including schema, provenance, and mutability hints. | ✓ VERIFIED | Mapped in `classes/local/tool_provider.php`. |
| 5 | Tool discovery can page and group large catalogs. | ✓ VERIFIED | Cursor pagination and grouping mapped in `tool_provider.php`. |
| 6 | Tool projection remains service-scoped even though the snapshot is site-wide. | ✓ VERIFIED | `tool_provider.php` filters by `restrict_serviceid`. |
| 7 | Primary transport exposes catalog pagination and grouping through `tools/list` without changing auth/session rules. | ✓ VERIFIED | `classes/local/transport/server.php` parses pagination inputs. |
| 8 | Tool discovery responses can include coverage and grouping metadata for operators or advanced clients. | ✓ VERIFIED | `transport/server.php` formats groups/coverage arrays. |
| 9 | Catalog rebuild/invalidation can be triggered automatically by surface-signature change when `tools/list` is called. | ✓ VERIFIED | `tool_provider.php` checks snapshot invalidation. |

**Score:** 9/9 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `classes/local/catalog/catalog_builder.php` | site-wide harvest, snapshot caching, and coverage summary | ✓ VERIFIED | Exists and is substantive |
| `classes/local/catalog/wrapper_registry.php` | explicit wrapped-tool registry boundary | ✓ VERIFIED | Exists and is substantive |
| `tests/catalog_builder_test.php` | catalog and coverage tests | ✓ VERIFIED | Exists and is substantive |
| `classes/local/tool_provider.php` | catalog projection, pagination, grouping, normalized MCP metadata | ✓ VERIFIED | Exists and is substantive |
| `tests/tool_provider_test.php` | projection and pagination tests | ✓ VERIFIED | Exists and is substantive |
| `classes/local/transport/server.php` | transport integration for paginated/grouped catalog responses | ✓ VERIFIED | Exists and is substantive |
| `tests/transport_server_test.php` | transport tests for paginated/grouped tools/list behavior | ✓ VERIFIED | Exists and is substantive |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `catalog_builder.php` | `external_api.php` | harvest enriches DB records with external function info | ✓ WIRED | Uses `external_api::external_function_info` |
| `catalog_builder.php` | `component.php` | coverage uses installed component inventory | ✓ WIRED | Uses `\core_component::get_component_list()` |
| `catalog_builder.php` | `wrapper_registry.php` | coverage summary includes wrapped-tool counts | ✓ WIRED | Calls `$this->wrapperregistry->all()` |
| `tool_provider.php` | `catalog_builder.php` | tool projection consumes cached harvested catalog | ✓ WIRED | Instantiates `new catalog_builder()` |
| `tool_provider.php` | `external_description` | normalized metadata stays aligned with upstream external function info | ✓ WIRED | Uses Moodle's `core_external\external_description` |
| `transport/server.php` | `tool_provider.php` | tools/list returns catalog projection metadata | ✓ WIRED | Calls `tool_provider::list_tools_for_service_ids` |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `catalog_builder.php` | `$functions`, `$services` | `global $DB` | Yes (`$DB->get_records`) | ✓ FLOWING |
| `tool_provider.php` | `$snapshot` | `catalog_builder.php` | Yes (cached records) | ✓ FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| SKIPPED | No runnable Moodle instance available locally | - | ? SKIP |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| DISC-01 | 03-01-PLAN.md | Harvest installed external functions | ✓ SATISFIED | `catalog_builder.php` harvest logic |
| DISC-02 | 03-01-PLAN.md | Harvest plugin external functions | ✓ SATISFIED | Iterates `$DB->get_records('external_functions')` |
| DISC-03 | 03-02-PLAN.md | Expose metadata schema | ✓ SATISFIED | `schema_builder.php` mappings |
| DISC-04 | 03-03-PLAN.md | Pagination, grouping, rebuild cache | ✓ SATISFIED | `tool_provider.php` / `transport/server.php` |
| WRAP-03 | 03-01-PLAN.md | Reports coverage status by domain | ✓ SATISFIED | `$this->build_coverage` logic |

### Anti-Patterns Found

None found. No code stubs, placeholder strings, or missing implementation logic.

### Human Verification Required

#### 1. Large-site paginated discovery
**Test:** On a site with a broad external-service surface, call `tools/list` repeatedly with `limit` and `cursor`.
**Expected:** `tools/list` returns stable `nextCursor` values and useful domain groups on a large Moodle service surface.
**Why human:** Needs a running Moodle site with enough service surface to observe real pagination behavior. Currently marked as pending in `03-HUMAN-UAT.md`.

#### 2. Coverage after service changes
**Test:** Enable/disable service links and rebuild site API surface through normal Moodle admin flows, then call `tools/list`.
**Expected:** Coverage metadata changes automatically after service enable/disable or API-surface changes without manual cache surgery.
**Why human:** Needs live site admin changes and a running transport endpoint. Currently marked as pending in `03-HUMAN-UAT.md`.

#### 3. Plugin-rich harvest
**Test:** Run the connector on a Moodle site with additional installed plugins exposing `db/services.php` externals.
**Expected:** Installed plugin externals declared through `db/services.php` appear with correct component provenance and domain grouping.
**Why human:** Needs a real site with non-core plugins. Currently marked as pending in `03-HUMAN-UAT.md`.

### Gaps Summary

No code implementation gaps or structural stubs were found. The backend properly implements and wires the catalog generation, pagination, component coverage, and HTTP transport layers. 

However, live Moodle UAT tests remain **pending** according to `03-HUMAN-UAT.md`. What needs fixing is executing these required human test cases in an actual running Moodle installation to ensure the coverage reporting and catalog pagination function reliably under real load.
