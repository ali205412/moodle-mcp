# Phase 10: Activity Authoring & Protocol Expansion - Research

**Researched:** 2026-05-01
**Domain:** Moodle Architecture, Activity Modules, MCP Protocol, Web Services
**Confidence:** HIGH

## Summary

The Phase 10 expansion introduces robust Moodle activity authoring and reporting by creating parity wrappers over internal `locallib.php` classes, circumventing the limitations of server-rendered HTML. It addresses the 800+ function context exhaustion problem by implementing a dynamic Discovery Engine (`wrapper_moodle_api_search` and `wrapper_moodle_api_execute`). Finally, it upgrades the core MCP protocol implementation by bringing native support for `resources` and `prompts`, backed by a new persistent database table (`webservice_mcp_memory`).

**Primary recommendation:** Use Moodle's `add_moduleinfo()` logic for simulating moodleform submissions, implement dynamic parameter casting with `external_function_info()`, and augment `server.php` to natively route `resources/*` and `prompts/*` JSON-RPC methods.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Activity Authoring | API / Backend | Database | Moodle core wrappers handle `course_modules` registry and individual mod tables. |
| Reporting / Read Data | API / Backend | Database | Internal `locallib.php` classes (`assign`, `quiz`, etc.) execute direct DB queries and format output. |
| Tool Discovery | API / Backend | — | Dynamic parameter casting and function lookup requires deep backend API integration. |
| MCP Resources & Prompts | API / Backend | Database | Native protocol routing in `server.php` reading from/writing to `webservice_mcp_memory`. |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `course/modlib.php` | Moodle Core | Activity Authoring (Write Path) | Standard way to simulate moodleform submissions and handle `course_modules` registry (`add_moduleinfo()`). |
| `mod_assign\local\request` | Moodle Core | Reporting (Read Path) | Internal getters like `get_submissions()` are the source of truth. |
| `external_api::external_function_info` | Moodle Core | Tool Discovery | Provides the exact parameter schema and return types for dynamic validation and casting. |
| `webservice_mcp_memory` | Custom DB | Knowledgebase Memory | Persistent storage for `resources` namespace. |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `add_moduleinfo()` | Custom DB inserts into `mdl_course_modules` | Custom inserts miss cache invalidation, gradebook syncing, and event triggers. |
| `wrapper_moodle_api_execute` | Exposing all 800+ functions | Bloats context window and overwhelms the LLM. Dynamic execution is lean. |

## Architecture Patterns

### Pattern 1: Internal Class Parity Wrappers (Adapter)
**What:** Instantiating internal module classes (`new assign($context, $cm, $course)`) directly instead of making separate REST calls or scraping HTML.
**When to use:** When reporting tools need complex grade or submission data that is otherwise restricted.
**Example:**
```php
require_once($CFG->dirroot . '/mod/assign/locallib.php');
$assign = new \assign($context, $cm, $course);
$submissions = $assign->get_submissions();
```

### Pattern 2: Tool Discovery & Dynamic Execution
**What:** Finding an external function by name, fetching its parameter schema, dynamically validating JSON against it, and executing.
**When to use:** In `wrapper_moodle_api_execute` to prevent context exhaustion from broadcasting all 800 native tools.
**Example:**
```php
$info = \external_api::external_function_info($functionrecord);
$cleanparams = \external_api::validate_parameters($info->parameters_desc, $jsonparams);
$result = call_user_func_array($info->classname . '::' . $info->methodname, array_values($cleanparams));
return \external_api::clean_returnvalue($info->returns_desc, $result);
```

### Pattern 3: Protocol Expansion in `server.php`
**What:** Adding case switches in `handle_transport_method` for `resources/list`, `resources/read`, `prompts/list`, and `prompts/get`.
**When to use:** Extending the base MCP protocol to act as a persistent knowledgebase.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Simulating module creation | Custom DB inserts | `add_moduleinfo()` or `create_module()` | Misses critical core logic (calendar events, gradebook, caches). |
| Parameter Validation | Custom JSON schema validators | `external_api::validate_parameters` | Moodle already handles deep nested structure casting correctly. |
| Security/Capability checks | Raw `$DB->get_records` | `require_capability()` / Moodle APIs | Essential to prevent unauthorized data access within wrappers. |

## Common Pitfalls

### Pitfall 1: Bypassing `add_moduleinfo()`
**What goes wrong:** Creating an activity via direct DB inserts leaves the course cache stale and the gradebook unsynchronized.
**Why it happens:** Developers assume inserting into `mdl_quiz` and `mdl_course_modules` is sufficient.
**How to avoid:** Always use Moodle's core activity creation functions.
**Warning signs:** Activities appear in DB but are invisible on the course page or gradebook.

### Pitfall 2: Missing Capability Checks in `resources/read`
**What goes wrong:** A user reads a memory or prompt they do not own.
**Why it happens:** `webservice_mcp_memory` queries do not filter by `$USER->id`.
**How to avoid:** Ensure queries explicitly include `userid = ?` matching the current authenticated transport session.

## Code Examples

### Simulating a Module Form Submission
```php
// Source: Moodle core course/modlib.php
require_once($CFG->dirroot.'/course/modlib.php');
$moduleinfo = new \stdClass();
$moduleinfo->course = $course->id;
$moduleinfo->modulename = 'assign';
$moduleinfo->name = 'Test Assignment';
// Add required assignment settings...
$moduleinfo = add_moduleinfo($moduleinfo, $course);
```

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `add_moduleinfo()` is the safest way to invoke Moodle's internal `instance_add()` | Architecture Patterns | If incorrect, module creation might throw errors regarding missing `$mform` objects. |

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Moodle Core | All Wrappers | ✓ | — | — |

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit |
| Config file | `phpunit.xml` |
| Quick run command | `vendor/bin/phpunit --filter {TestClassName}` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| REQ-01 | `wrapper_course_add_module` provisions module | unit | `vendor/bin/phpunit --filter wrapper_course_add_module_test` | ❌ Wave 0 |
| REQ-02 | `resources/list` returns schema | unit | `vendor/bin/phpunit --filter transport_server_test` | ✅ Wave 0 |
| REQ-03 | Database teardown of `webservice_mcp_memory` | unit | `vendor/bin/phpunit --filter memory_table_test` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** Quick run command
- **Per wave merge:** Full suite command
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/wrapper_course_add_module_test.php`
- [ ] `tests/fixtures/testable_sse_controller.php` update for resources/prompts.

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Moodle core `$USER` |
| V3 Session Management | yes | Moodle core `session_manager` |
| V4 Access Control | yes | Moodle core `has_capability` / `require_capability` |
| V5 Input Validation | yes | `external_api::validate_parameters` |
| V6 Cryptography | no | — |

### Known Threat Patterns for Moodle Wrappers

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| IDOR on Memory Read | Elevation of Privilege | Ensure `userid` check on all `webservice_mcp_memory` SELECTs |
| Unauthorized Tool Call | Elevation of Privilege | Re-check `has_capability` before executing any dynamic API function |
| Context Exhaustion DOS | Denial of Service | Implement `search` and `execute` wrappers instead of broadcasting 800 tools |

## Sources

### Primary (HIGH confidence)
- `docs/superpowers/specs/2026-05-01-activity-authoring-mcp-expansion-design.md` - Core spec.
- Moodle Core Docs - `add_moduleinfo()` and `external_function_info`.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - Directly follows Moodle plugin development guidelines.
- Architecture: HIGH - Maps directly to the provided design document.
- Pitfalls: HIGH - Known issues with Moodle internal APIs.

**Research date:** 2026-05-01
**Valid until:** 2026-06-01
