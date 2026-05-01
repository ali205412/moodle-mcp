# Phase 10: Activity Authoring & Protocol Expansion

**Goal:** The connector fully manages activity lifecycles using internal `locallib.php` classes, solves context exhaustion with a Tool Discovery Engine, and implements persistent knowledgebase capabilities via MCP Resources and Prompts.

## Strategy

1. **Protocol Expansion (Memory & Discovery)**
   - Create a new `webservice_mcp_memory` table for storing key-value persistent markdown.
   - Expand `server.php` to handle the `resources/*` and `prompts/*` JSON-RPC namespaces.
   - Refactor `tool_provider.php` to hide raw Moodle functions, preventing the 800+ tool context explosion.
   - Add discovery wrappers (`wrapper_moodle_api_search`, `wrapper_moodle_api_execute`) so Claude can dynamically look up and run native functions as needed.
   - Add memory wrappers (`wrapper_user_memory_save`) so Claude can persist state between sessions.

2. **Activity Authoring (The Write Path)**
   - Build a universal `wrapper_course_add_module` tool.
   - Moodle requires modules to be created via `moodleform` logic calling `instance_add()`. The wrapper will format the incoming JSON payload into the exact expected internal object structure and trigger Moodle's native module creation hooks.

3. **Activity Reporting (The Read Path)**
   - Build targeted `wrapper_[module]_read_data` tools for the top 5 activities (Assign, Quiz, Forum, Page, Lesson).
   - These wrappers will directly instantiate the Moodle internal module classes (e.g., `new \assign()`) found in their respective `locallib.php` files to cleanly extract structured payload data without scraping HTML.

## Verification
- Transport tests must verify that `resources/list` and `prompts/list` correctly return the structured payloads according to the MCP specification.
- PHPUnit integration tests must simulate `wrapper_course_add_module` and verify that the module appears in the course database with the correct configuration.
- PHPUnit tests must ensure the discovery engine correctly searches the external functions table and accurately maps capability gating.

## Tasks

- [ ] Task 10.1: Database migration for `webservice_mcp_memory`
- [ ] Task 10.2: Implement MCP Resources and Prompts in `server.php`
- [ ] Task 10.3: Build Tool Discovery Engine and hide raw tools
- [ ] Task 10.4: Build Memory Writer wrapper
- [ ] Task 10.5: Build universal Activity Authoring wrapper (`instance_add`)
- [ ] Task 10.6: Build internal-class Reporting wrappers (`locallib.php` data extraction)
