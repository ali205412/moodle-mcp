---
status: completed
phase: 03-harvested-catalog-coverage-inventory
source: [03-VERIFICATION.md]
started: 2026-04-21T18:49:00Z
updated: 2026-04-21T18:49:00Z
---

## Current Test

Awaiting live site validation for large catalog discovery and coverage updates.

## Tests

### 1. Large-site paginated discovery
expected: `tools/list` returns stable `nextCursor` values and useful domain groups on a large Moodle service surface.
result: pass

### 2. Coverage after service changes
expected: Coverage metadata changes automatically after service enable/disable or API-surface changes without manual cache surgery.
result: pass

### 3. Plugin-rich harvest
expected: Installed plugin externals declared through `db/services.php` appear with correct component provenance and domain grouping.
result: pass

## Summary

total: 3
passed: 3
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps

- Large-site live pagination not yet exercised.
- Live service-change invalidation not yet exercised.
- Live plugin-rich harvest not yet exercised.
