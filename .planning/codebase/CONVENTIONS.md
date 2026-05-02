# Coding Conventions

**Analysis Date:** 2026-04-21

## Naming Patterns

**Files:**
- Use Moodle-reserved root filenames for plugin hooks and entry points: `server.php`, `lib.php`, `locallib.php`, `version.php`, and `db/access.php`.
- Put autoloaded implementation classes in lowercase directories under `classes/`; filenames match the class purpose in lowercase or snake_case: `classes/local/request.php`, `classes/local/server.php`, `classes/local/tool_provider.php`, `classes/privacy/provider.php`.
- Name PHPUnit files `*_test.php` under `tests/`: `tests/request_test.php`, `tests/server_test.php`, `tests/client_test.php`, `tests/tool_provider_test.php`.

**Functions:**
- Name instance methods with snake_case verb phrases: `set_token()` in `lib.php`, `from_raw_input()` in `classes/local/request.php`, `extract_tool_call()` and `send_tools_list_response()` in `classes/local/server.php`.
- Prefix PHPUnit methods with `test_` and describe the exact case being checked: `test_invalid_request_wrong_jsonrpc_version()` in `tests/request_test.php`, `test_extract_tool_call_valid()` in `tests/server_test.php`.
- Avoid free functions. The current plugin exposes behavior through classes in `lib.php`, `locallib.php`, and `classes/`.

**Variables:**
- Use lowercase compound variable names without separators for locals and properties: `$serverurl` and `$requestjson` in `lib.php`, `$requiredfields` in `classes/local/tool_provider.php`, `$validatedvalues` in `classes/local/server.php`.
- Keep Moodle globals and superglobals in their conventional names: `$CFG` in `locallib.php`, `$DB` in `classes/local/tool_provider.php`, `$USER` in `tests/tool_provider_test.php`, `$_SERVER`, `$_GET`, and `$_POST` in `classes/local/server.php` and `tests/server_test.php`.
- Declare internal constants in uppercase with underscores: `PROTOCOL_VERSION`, `SERVER_NAME`, and `SERVER_VERSION` in `classes/local/server.php`.

**Types:**
- New autoloaded classes under `classes/` use plugin namespaces such as `webservice_mcp\local` and `webservice_mcp\privacy`: `classes/local/request.php`, `classes/local/server.php`, `classes/local/tool_provider.php`, `classes/privacy/provider.php`.
- Within those namespaces, class names stay lowercase or snake_case to match the existing Moodle style: `request`, `server`, `tool_provider`, `provider`.
- Legacy root-level classes that must be loadable from Moodle hook files use plugin-prefixed snake_case names instead of namespaces: `webservice_mcp_client` in `lib.php`, `webservice_mcp_test_client` in `locallib.php`.

## Code Style

**Formatting:**
- Follow Moodle PHP formatting enforced through `.github/workflows/ci.yml`: `moodle-plugin-ci phplint`, `moodle-plugin-ci phpmd`, `moodle-plugin-ci phpcs`, and `moodle-plugin-ci phpdoc`.
- Use 4-space indentation and place opening braces on the same line as declarations and control statements, as shown in `classes/local/server.php` and `tests/request_test.php`.
- Use short arrays `[]` and keep trailing commas in multiline arrays, as shown in `lib.php`, `classes/local/server.php`, and `tests/tool_provider_test.php`.
- Keep the standard Moodle GPL header and a package-level PHPDoc block at the top of every PHP file, including `server.php`, `classes/local/request.php`, and `tests/client_test.php`.
- Add `declare(strict_types=1);` in namespaced implementation files under `classes/`, matching `classes/local/request.php`, `classes/local/server.php`, `classes/local/tool_provider.php`, and `classes/privacy/provider.php`.
- Guard included Moodle PHP files with `defined('MOODLE_INTERNAL') || die();`, matching `version.php`, `locallib.php`, `db/access.php`, and the PHPUnit files under `tests/`. Skip that guard on the direct HTTP entry point `server.php`.

**Linting:**
- Use `.github/workflows/ci.yml` as the source of truth; no repo-local `composer.json`, `phpunit.xml`, or `.phpcs.xml` was detected in `/home/yui/Documents/moodle-mcp`.
- Keep PHP CodeSniffer warnings to at most one in CI with `moodle-plugin-ci phpcs --max-warnings 1` from `.github/workflows/ci.yml`.
- Keep PHPDoc warnings at zero with `moodle-plugin-ci phpdoc --max-warnings 0` from `.github/workflows/ci.yml`.
- Expect validation to include `moodle-plugin-ci validate`, `savepoints`, `mustache`, and `grunt`, even though the current plugin code lives in PHP files such as `server.php` and `classes/local/server.php`.

## Import Organization

**Order:**
1. In autoloaded classes, place `declare(strict_types=1);`, then the `namespace`, then one `use` import per line: `classes/local/server.php`, `classes/local/tool_provider.php`, `classes/local/request.php`.
2. Import Moodle/core classes before PHP reflection or utility classes when both are needed: `advanced_testcase`, `moodle_exception`, then `Exception` and `ReflectionClass` in `tests/server_test.php`; `context_system`, `core_external\...`, then `ReflectionClass` and `stdClass` in `tests/tool_provider_test.php`.
3. Import plugin classes explicitly rather than relying on relative names when crossing namespaces: `use webservice_mcp\local\request;` and `use webservice_mcp\local\server;` in `tests/server_test.php`.

**Path Aliases:**
- Not used. The code relies on PHP namespaces plus Moodle bootstrap `require_once` calls such as `require_once($CFG->dirroot . '/webservice/mcp/lib.php');` in `tests/client_test.php` and `require_once("$CFG->dirroot/webservice/lib.php");` in `locallib.php`.

## Error Handling

**Patterns:**
- Validate input as early as possible and throw `moodle_exception` with a language string key from `lang/en/webservice_mcp.php`: `classes/local/request.php` and `classes/local/server.php`.
- Centralize transport-level failures in `classes/local/server.php` through `generate_error()`, `send_error()`, and `safe_json_encode()` so the HTTP endpoint returns JSON instead of raw exception output.
- Use explicit fallback branches for protocol and request-state checks before routing: protocol disabled handling in `server.php`, request validation in `classes/local/request.php`, and method dispatch checks in `classes/local/server.php`.
- Include extra diagnostics only when Moodle debugging is enabled, matching `server.php` and `classes/local/server.php`.

## Logging

**Framework:** Moodle `debugging()` helpers in `server.php` and `classes/local/server.php`.

**Patterns:**
- Do not add happy-path application logs. The current code logs only around protocol disablement and exception debugging in `server.php` and `classes/local/server.php`.
- Gate rich exception details behind `debugging('', DEBUG_MINIMAL)` or `debugging()` before writing traces, as in `classes/local/server.php`.
- Use `get_exception_info()` and `format_backtrace()` when a stack trace is needed, rather than hand-building output, matching `classes/local/server.php`.

## Comments

**When to Comment:**
- Keep file headers and method/class PHPDoc blocks. Every implementation and test file follows this pattern: `lib.php`, `classes/local/request.php`, `tests/tool_provider_test.php`.
- Reserve inline comments for protocol branches, fallbacks, or non-obvious behavior such as token extraction and JSON schema generation in `classes/local/server.php` and `classes/local/tool_provider.php`.
- Do not explain trivial assignments. The existing files keep inline comments sparse and focused.

**JSDoc/TSDoc:**
- Use PHPDoc, not JSDoc or TSDoc.
- Include `@package` on file or class blocks and `@param`, `@return`, and `@throws` on public or non-obvious methods, as in `lib.php`, `classes/local/server.php`, and `classes/local/request.php`.
- Add `@covers` annotations on PHPUnit classes under `tests/`, matching `tests/client_test.php`, `tests/request_test.php`, `tests/server_test.php`, and `tests/tool_provider_test.php`.

## Function Design

**Size:** Keep helper methods narrow and single-purpose, like `request::validate()` in `classes/local/request.php` and `tool_provider::get_schema_type()` in `classes/local/tool_provider.php`. Let coordinator methods such as `server::run()` and `server::handle_mcp_method()` in `classes/local/server.php` orchestrate smaller helpers rather than inlining all branches.

**Parameters:**
- Use scalar and array type declarations in namespaced implementation classes: `__construct(array $data)` in `classes/local/request.php`, `get_tools(string $token): array` in `classes/local/tool_provider.php`.
- Use nullable and union-style boundaries only where the protocol demands them: `public mixed $id`, `public ?array $params` in `classes/local/request.php`, and `call(string $method, array $params = [], $id = 1)` in `lib.php`.
- Keep legacy Moodle interface methods untyped when the interface signature requires it, as in `locallib.php`.

**Return Values:**
- Prefer explicit return types in autoloaded classes: `: void`, `: bool`, `: string`, `: array`, and `: self` in `classes/local/*.php` and `classes/privacy/provider.php`.
- Return associative arrays from JSON helpers and clients using `json_decode(..., true)` when interoperating with JSON-RPC payloads, as in `lib.php` and `locallib.php`.

## Module Design

**Exports:**
- Keep one primary class per file under `classes/`, with the namespace matching the directory: `classes/local/request.php`, `classes/local/server.php`, `classes/local/tool_provider.php`, `classes/privacy/provider.php`.
- Keep Moodle-required integration surfaces in root hook files instead of adding wrapper barrels: `server.php` for the HTTP endpoint, `lib.php` for the client helper, `locallib.php` for the Moodle test client, and `db/access.php` for capabilities.

**Barrel Files:**
- Not used. No `index.php` or re-export modules were detected under `classes/`.
- Reference classes by full namespace or by an explicit `use` statement, and load legacy globals through Moodle bootstrap files only.

---

*Convention analysis: 2026-04-21*
