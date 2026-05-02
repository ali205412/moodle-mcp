# Phase 7: Operator Workflows & Authoring Gaps - Patterns

## Existing Patterns To Reuse

- `classes/local/catalog/wrapper_registry.php`
  Use curated workflow descriptors to group related tools without inventing new catalog storage.
- `classes/local/tool_provider.php`
  Keep all surface/workflow/execution projection in the discovery layer so transports stay thin.
- `classes/local/wrapper/definition.php`, `classes/local/wrapper/manager.php`
  Add new wrappers as immutable definitions plus a single execution dispatcher.
- `classes/local/wrapper/course_authoring_service.php`
  Put Moodle-course authoring behavior behind typed helper methods rather than embedding logic in transport handlers.
- `classes/local/transport/server.php`
  Preserve new discovery metadata in `tools/list` responses and keep wrapper execution in the same tool-call path.

## Pattern Decision

Phase 7 continues the Phase 6 wrapper architecture rather than introducing a second wrapper mechanism. Harvested operator tools stay harvested; only authoring-gap wrappers use plugin-owned execution code.
