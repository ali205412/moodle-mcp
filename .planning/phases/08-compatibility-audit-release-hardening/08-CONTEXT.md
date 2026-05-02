# Phase 8: Compatibility, Audit & Release Hardening - Context

## Goal

Make the connector defensible across Moodle 4.2-4.5, preserve large-site discovery safeguards, add operator-usable audit identifiers, and leave the local test surface in a release-hardened state.

## Baseline

- Phases 1-7 already delivered auth/bootstrap, remote transport, catalog harvesting, permission gating, core/user surfaces, activity workflows, operator/admin curation, and typed course-authoring wrappers.
- The remaining work is mostly cross-version proof, auditability, and integrated verification rather than new product scope.

## Moodle Sources Checked

- `origin/MOODLE_402_STABLE:course/format/classes/base.php`
- `origin/MOODLE_402_STABLE:course/format/classes/stateactions.php`
- `origin/MOODLE_402_STABLE:lib/classes/session/manager.php`
- `origin/MOODLE_402_STABLE:lib/classes/user.php`
- `origin/MOODLE_402_STABLE:lib/db/services.php`
- `origin/MOODLE_403_STABLE:course/format/classes/base.php`
- `origin/MOODLE_403_STABLE:course/format/classes/stateactions.php`
- `origin/MOODLE_403_STABLE:lib/classes/session/manager.php`
- `origin/MOODLE_403_STABLE:lib/classes/user.php`
- `origin/MOODLE_403_STABLE:lib/db/services.php`
- `origin/MOODLE_404_STABLE:course/format/classes/base.php`
- `origin/MOODLE_404_STABLE:course/format/classes/stateactions.php`
- `origin/MOODLE_404_STABLE:admin/tool/dataprivacy/db/services.php`
- `origin/MOODLE_404_STABLE:lib/db/services.php`
- `origin/MOODLE_405_STABLE:lib/external/classes/util.php`
- `origin/MOODLE_405_STABLE:course/format/classes/base.php`
- `origin/MOODLE_405_STABLE:course/format/classes/stateactions.php`
- `origin/MOODLE_405_STABLE:admin/tool/dataprivacy/db/services.php`
- `origin/MOODLE_405_STABLE:lib/db/services.php`

## Phase Focus

1. Remove or guard any 4.5-only runtime dependency.
2. Persist audit records for discovery and tool execution and return stable audit ids to clients.
3. Strengthen tests around connector flow and large-service discovery limits.
