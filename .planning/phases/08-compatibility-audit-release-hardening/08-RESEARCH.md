# Phase 8: Compatibility, Audit & Release Hardening - Research

**Researched:** 2026-04-21  
**Confidence:** HIGH

## Findings

- `core_user::require_active_user()` exists in `origin/MOODLE_402_STABLE:lib/classes/user.php` through `origin/MOODLE_405_STABLE:lib/classes/user.php`.
- `core\session\manager::session_exists()` exists in `origin/MOODLE_402_STABLE:lib/classes/session/manager.php` through `origin/MOODLE_405_STABLE:lib/classes/session/manager.php`.
- `core_courseformat\base::session_cache_reset()` plus the `stateactions` methods used by the wrappers (`cm_move`, `section_move_after`, `section_add`, `section_delete`, `section_hide`, `section_show`, `cm_show`, `cm_hide`, `cm_stealth`, `cm_duplicate`, `cm_delete`) exist in all four supported stable branches.
- `core_external\util::generate_token_name()` only appears in `origin/MOODLE_405_STABLE:lib/external/classes/util.php`; it is absent from 4.2, 4.3, and 4.4.
- `tool_dataprivacy_create_data_request` is present in `origin/MOODLE_404_STABLE:admin/tool/dataprivacy/db/services.php` and `origin/MOODLE_405_STABLE:admin/tool/dataprivacy/db/services.php`, but not in the 4.2/4.3 branch checks. Privacy externals therefore have to remain opportunistic rather than assumed.

## Implication

- Compatibility work should target direct runtime dependencies, not harvested optional tools.
- Auditability belongs in the transport layer because discovery and tool calls already converge there.
- Tests should tolerate optional harvested surfaces that differ by branch while still validating them when they exist.
