# Phase 5: Core Learning Surface & File Flows - Research

**Researched:** 2026-04-21  
**Confidence:** HIGH

## Source-Backed Findings

### 1. Most core learning/personal reads already exist as externals

- `lib/db/services.php` already registers:
  - course content and listing APIs
  - completion and progress APIs
  - calendar APIs
  - message APIs
  - notes APIs
  - private file info/preparation/update APIs
  - file browsing/upload/draft helpers
- This means Phase 5 does not need to invent most of the read surface.

### 2. File flows are multi-step but mostly already exposed

- `core_files_upload` accepts base64 `filecontent` and only uploads into the user draft area.  
  Verified: `tmp/moodle/files/externallib.php`
- `core_files_get_unused_draft_itemid` exists to seed draft flows.  
  Verified: `tmp/moodle/lib/db/services.php`
- `core_user_prepare_private_files_for_edition` and `core_user_update_private_files` already provide a clean private-files edit/finalize flow.  
  Verified: `tmp/moodle/user/classes/external/prepare_private_files_for_edition.php`, `tmp/moodle/user/classes/external/update_private_files.php`
- `webservice/upload.php` and `webservice/pluginfile.php` remain token-based HTTP endpoints for upload/download, but for MCP tool calls the existing externals may already be more coherent because they stay inside the external-function execution model.

### 3. Draft-area correctness is the real Phase 5 integration challenge

- Moodle file APIs revolve around draft item ids and post-update moves from draft to final area.  
  Verified: `tmp/moodle/lib/filelib.php`
- For MCP clients, the connector should ideally make the expected sequence and allowed targets explicit, even if the underlying steps are still Moodle primitives.

## Recommended Approach

- Use the harvested catalog and wrapper registry to elevate the Phase 5 core surfaces:
  - learner reads
  - personal surfaces
  - file workflows
- Add wrapper descriptors and/or thin helper tools for file workflow composition only where existing externals are too low-level for MCP clients.
- Keep uploads within supported draft/private-file flows instead of inventing arbitrary file-write targets.

## Primary Sources

- `tmp/moodle/lib/db/services.php`
- `tmp/moodle/files/externallib.php`
- `tmp/moodle/user/externallib.php`
- `tmp/moodle/user/classes/external/prepare_private_files_for_edition.php`
- `tmp/moodle/user/classes/external/update_private_files.php`
- `tmp/moodle/webservice/upload.php`
- `tmp/moodle/webservice/pluginfile.php`
- `tmp/moodle/lib/filelib.php`
