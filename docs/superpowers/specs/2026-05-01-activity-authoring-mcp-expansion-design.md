# Moodle MCP: Activity Authoring & Protocol Expansion

## Context
Moodle's core architecture lacks web service APIs for authoring complex activities (like Assign, Quiz, Forum, Page, Lesson) and restricts data access for reporting due to its heavy reliance on server-rendered HTML. Exposing all 800+ raw Moodle functions via MCP creates context exhaustion for Claude.ai. The user wants full activity lifecycle management (creation, reading, reporting) using Moodle's internal `locallib.php` classes, plus a robust "Knowledgebase/Memory" system using the full capabilities of the MCP protocol (Resources and Prompts).

## Architectural Design

This expansion is broken down into three independent systems to maximize Claude.ai's capabilities while keeping the connector fast, secure, and lean.

### 1. Internal Class Parity Wrappers (The Big 5)
Instead of scraping HTML or relying solely on Admin tools, we will build custom `wrapper_` tools that instantiate Moodle's internal module classes (`locallib.php`).
- **Target Modules:** Assign, Quiz, Forum, Page, Lesson.
- **Write Path (Authoring):** Create a generic `wrapper_course_add_module` tool. It will accept the module type and a JSON settings object. The wrapper will simulate a moodleform submission and invoke Moodle's internal `instance_add()` function, ensuring all completion rules, grades, and dates are saved correctly.
- **Read Path (Reporting):** Create specific `wrapper_[module]_read_data` tools. These wrappers will instantiate the internal classes (e.g., `new assign($context, $cm, $course);`) and execute internal getters (`get_submissions()`, `get_user_grades()`) to serialize clean, structured JSON back to Claude.

### 2. Tool Discovery Engine (Solving Context Exhaustion)
Currently, Moodle forces up to 2000 native API functions directly into Claude's `tools/list`, bloating the context window and overwhelming the LLM with irrelevant tools.
- **The Pivot:** We will remove the 800+ raw Moodle functions from the immediate `tools/list` broadcast. 
- **The Discovery Wrapper:** We will expose a single, powerful tool: `wrapper_moodle_api_execute`. 
- **The Search Wrapper:** We will expose `wrapper_moodle_api_search`, allowing Claude to query the Moodle database for an API function by keyword (e.g., "get enrolled courses") to discover the exact function name and its expected parameters on the fly, and then execute it via the executor tool.

### 3. MCP Resources & Prompts (The Knowledgebase)
We will expand `server.php` and `protocol_headers.php` to natively support the MCP `resources` and `prompts` namespaces, turning the connector into a persistent knowledgebase for Claude.

- **Resources (Read-Only Memory & Context):**
  - Implement `resources/list` and `resources/read`.
  - Introduce a dedicated database table (`webservice_mcp_memory`) mapped to the authenticated user.
  - Expose a `moodle://user/memory` resource that Claude can read to retrieve saved notes, favorite filters, and contextual preferences from previous sessions.
  - Expose `moodle://course/{id}/summary` resources for fast context loading.

- **Prompts (Guided Workflows):**
  - Implement `prompts/list` and `prompts/get`.
  - Expose a `moodle_course_architect` prompt that pre-loads Claude with the instructions and JSON schemas required to author a complete course using the new Internal Class Parity Wrappers.
  - Expose a `moodle_grader_assistant` prompt tailored for reading and evaluating assignments.

- **Memory Writer Tool:**
  - Add a `wrapper_user_memory_save` tool allowing Claude to write or update Markdown notes into the `webservice_mcp_memory` table (closing the read/write loop for the Knowledgebase).

## Error Handling & Security
- All wrappers and resources will strictly inherit the current user's session privileges (`$USER`) and `context`.
- Any attempt to read a resource or call an internal class without the required `has_capability()` check will instantly abort via Moodle's native capability exception handler.
- Audit logging (`webservice_mcp_audit`) will be expanded to track `resource_read` and `prompt_get` actions alongside `tool_call`.

## Testing Strategy
- **Unit Tests:** `phpunit` tests will be written for the new `wrapper_course_add_module` tool to guarantee it successfully provisions a course module in the Moodle database.
- **Transport Tests:** The `transport_server_test.php` will be expanded to assert that `resources/list` and `prompts/list` correctly return the structured JSON schemas expected by the MCP specification.
- **Database Rollback:** All newly introduced database tables (`webservice_mcp_memory`) will be verified for proper teardown during test assertions.
