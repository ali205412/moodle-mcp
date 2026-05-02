# Phase 7: Operator Workflows & Authoring Gaps - Context

## Goal

Expose the harvested operator/admin Moodle surface with useful MCP curation, then close the remaining priority course-authoring gaps with typed wrappers that preserve Moodle permission checks.

## Baseline

- Phases 1-6 already provide Moodle-native auth/bootstrap, Streamable HTTP plus SSE compatibility, harvested catalog discovery, permission/risk metadata, core learner/file surfaces, activity workflows, and a reusable wrapper foundation.
- The remaining Phase 7 gap is not basic connectivity. It is operator/admin discoverability plus typed authoring coverage where Moodle still exposes browser-era AJAX endpoints rather than MCP-friendly responses.

## Moodle Sources Checked

- `tmp/moodle/lib/db/services.php`
- `tmp/moodle/course/externallib.php`
- `tmp/moodle/course/format/classes/external/update_course.php`
- `tmp/moodle/course/format/classes/external/create_module.php`
- `tmp/moodle/course/format/classes/external/new_module.php`
- `tmp/moodle/course/format/classes/stateactions.php`
- `tmp/moodle/enrol/externallib.php`
- `tmp/moodle/enrol/manual/externallib.php`
- `tmp/moodle/enrol/self/externallib.php`
- `tmp/moodle/group/externallib.php`
- `tmp/moodle/cohort/externallib.php`
- `tmp/moodle/user/externallib.php`
- `tmp/moodle/user/classes/external/search_identity.php`
- `tmp/moodle/admin/tool/dataprivacy/db/services.php`

## Phase Focus

1. Curate harvested operator surfaces for users, enrolments, groups, cohorts, roles, courses, categories, competencies, and privacy workflows.
2. Annotate tools that initiate async or long-running work so clients can treat them differently.
3. Add typed wrappers over `core_courseformat` state actions for section/module authoring flows that are still awkward when consumed through raw harvested editor endpoints.
