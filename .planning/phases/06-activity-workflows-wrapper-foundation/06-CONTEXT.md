# Phase 6: Activity Workflows & Wrapper Foundation - Context

**Phase:** 6  
**Prepared:** 2026-04-21

## Goal

Users can complete high-value activity workflows, and the connector has a reusable wrapper framework for missing but important Moodle actions.

## Source Of Truth

- `tmp/moodle/mod/assign/db/services.php`
- `tmp/moodle/mod/forum/db/services.php`
- `tmp/moodle/mod/quiz/db/services.php`
- `tmp/moodle/mod/workshop/db/services.php`
- `tmp/moodle/mod/feedback/db/services.php`
- representative standard modules:
  - `tmp/moodle/mod/chat/db/services.php`
  - `tmp/moodle/mod/glossary/db/services.php`
  - `tmp/moodle/mod/wiki/db/services.php`
  - `tmp/moodle/mod/data/db/services.php`
  - `tmp/moodle/mod/choice/db/services.php`
  - `tmp/moodle/mod/survey/db/services.php`
  - `tmp/moodle/mod/scorm/db/services.php`
  - `tmp/moodle/mod/h5pactivity/db/services.php`
  - `tmp/moodle/mod/bigbluebuttonbn/db/services.php`
  - `tmp/moodle/mod/lti/db/services.php`

## Phase Shape

- Harvested activity externals already cover much of the user-facing workflow surface.
- Phase 6 should add curated activity workflow metadata and a real wrapper framework layer.
- Wrapper coverage itself stays minimal here; the framework is the reusable base for later UI-only gaps.
