# Phase 5: Core Learning Surface & File Flows - Context

**Phase:** 5  
**Name:** Core Learning Surface & File Flows  
**Prepared:** 2026-04-21

## Phase Boundary

### In Scope

- Ensure core learning surfaces are exposed coherently through the connector: courses, contents, activities, completion, and learner context.
- Ensure personal surfaces are exposed coherently: calendar, messaging, notes, profile-related user data, and private files.
- Add connector-managed file workflow support where draft/private file flows need orchestration across multiple Moodle primitives.

### Out Of Scope

- Broad authoring wrappers for activities or course structure changes (later phases).
- Full operator/admin workflows (later phases).
- Non-core plugin activity wrappers beyond what is already harvested.

## Locked Decisions

- Harvest first: if Moodle already exposes the surface cleanly through externals, Phase 5 should reuse it rather than wrapping it.
- Wrap only where Moodle’s current file/draft/private-file flow requires multi-step orchestration that is awkward for MCP clients.
- All work remains constrained by the Phase 4 identity-aware discovery and call-time safety rules.

## Canonical References

### Project State

- `.planning/PROJECT.md`
- `.planning/ROADMAP.md`
- `.planning/REQUIREMENTS.md`
- `.planning/STATE.md`
- `.planning/phases/04-permission-gated-discovery-safety/04-VERIFICATION.md`

### Current Plugin Files To Evolve

- `classes/local/catalog/wrapper_registry.php`
- `classes/local/tool_provider.php`
- `classes/local/transport/server.php`

### Moodle Source Of Truth

- `tmp/moodle/lib/db/services.php`
- `tmp/moodle/files/externallib.php`
- `tmp/moodle/user/externallib.php`
- `tmp/moodle/user/classes/external/prepare_private_files_for_edition.php`
- `tmp/moodle/user/classes/external/update_private_files.php`
- `tmp/moodle/webservice/upload.php`
- `tmp/moodle/webservice/pluginfile.php`
- `tmp/moodle/lib/filelib.php`

## Existing Code Insights

- The harvested catalog already includes many of the required Phase 5 surfaces:
  - `core_course_get_contents`
  - `core_course_get_courses_by_field`
  - `core_completion_*`
  - `core_calendar_*`
  - `core_message_*`
  - `core_notes_*`
  - `core_user_get_private_files_info`
  - `core_user_prepare_private_files_for_edition`
  - `core_user_update_private_files`
  - `core_files_get_files`
  - `core_files_upload`
  - `core_files_get_unused_draft_itemid`
  - `core_files_delete_draft_files`
- The main remaining value-add is likely workflow composition and MCP-friendly guidance rather than recreating the underlying APIs.

## Specific Ideas

- Add curated workflow metadata for common read surfaces so clients can find the core learner/personal tools more easily.
- Add wrapper descriptors for draft/private-file flows that document or orchestrate:
  - get draft item id
  - upload files
  - inspect draft/private file state
  - finalize private files
- Consider thin connector-owned wrappers only if existing externals still leave an MCP-hostile gap.
