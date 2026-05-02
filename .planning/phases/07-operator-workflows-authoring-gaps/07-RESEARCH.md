# Phase 7: Operator Workflows & Authoring Gaps - Research

**Researched:** 2026-04-21  
**Confidence:** HIGH

## Findings

- `tmp/moodle/lib/db/services.php` already registers a broad operator surface: `core_course_*`, `core_courseformat_*`, `core_enrol_*`, `core_group_*`, `core_cohort_*`, `core_role_*`, and a large `core_competency_*` family.
- `tmp/moodle/admin/tool/dataprivacy/db/services.php` adds a second operator-grade surface for privacy requests and registry management that is not visible if we only think in terms of core services.
- `tmp/moodle/course/externallib.php` exposes `core_course_edit_module` and `core_course_edit_section`, but those endpoints are designed for Moodle UI flows and return HTML or opaque JSON payloads rather than typed MCP-friendly structures.
- `tmp/moodle/course/format/classes/external/update_course.php`, `tmp/moodle/course/format/classes/external/create_module.php`, `tmp/moodle/course/format/classes/external/new_module.php`, and `tmp/moodle/course/format/classes/stateactions.php` provide structured state-action primitives for course authoring and are the best source-backed foundation for wrappers.
- `tmp/moodle/enrol/externallib.php`, `tmp/moodle/enrol/manual/externallib.php`, `tmp/moodle/group/externallib.php`, `tmp/moodle/cohort/externallib.php`, and `tmp/moodle/user/externallib.php` confirm that most operator workflows are already harvestable and mainly need curated discovery.

## Implication

- Phase 7 should stay harvest-first for operator/admin coverage.
- Wrappers should focus on course authoring operations where the raw harvested surface is awkward for MCP clients, not on re-implementing operator areas that Moodle already exposes cleanly enough.
