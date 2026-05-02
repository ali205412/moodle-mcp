---
phase: 05-core-learning-surface-file-flows
plan: 02
subsystem: curated-surfaces
tags: [moodle, learning, personal, files, discovery]
requires:
  - phase: 05-01
    provides: file workflow descriptors
provides:
  - Curated surface metadata for learning, personal, and file tools
  - Workflow metadata on relevant harvested tools
affects: [discovery]
tech-stack:
  added: [surface metadata, workflow metadata]
  patterns: [curated harvested surface exposure]
key-files:
  modified:
    - classes/local/tool_provider.php
    - tests/tool_provider_test.php
requirements-completed: [CORE-01, CORE-02, CORE-03, CORE-04]
duration: 15min
completed: 2026-04-21
---

# Phase 5: Core Learning Surface & File Flows Summary

**Wave 2 curated learning/personal/file metadata**

## Accomplishments

- Added `x-moodle.surface` metadata for learning, personal, profile, private-file, and draft-file surfaces.
- Added workflow metadata from the wrapper registry onto relevant harvested tools.
- Extended discovery tests to cover the curated metadata path.

---
*Phase: 05-core-learning-surface-file-flows*
*Completed: 2026-04-21*
