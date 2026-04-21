# Architecture

**Analysis Date:** 2026-04-21

## Pattern Overview

**Overall:** Thin Moodle protocol adapter around `webservice_base_server`

**Key Characteristics:**
- Keep the public endpoint in `server.php` minimal; actual MCP handling lives in `classes/local/server.php`.
- Treat MCP as a transport and schema adaptation layer over Moodle external functions instead of a separate business domain.
- Rely on Moodle core for authentication, external function execution, capability checks, and return-value cleaning.

## Layers

**Plugin bootstrap and Moodle contract layer:**
- Purpose: Register the plugin with Moodle and expose the MCP endpoint and capability metadata.
- Location: `server.php`, `version.php`, `db/access.php`, `lang/en/webservice_mcp.php`
- Contains: The HTTP entry script, plugin metadata, capability declaration, and user-facing strings.
- Depends on: Moodle core bootstrap in `../../config.php` and Moodle plugin conventions.
- Used by: Moodle’s plugin loader, protocol enablement UI, and incoming HTTP requests to `/webservice/mcp/server.php`.

**Transport and protocol layer:**
- Purpose: Parse HTTP requests, extract tokens, route MCP methods, and adapt standard Moodle web service calls to MCP JSON-RPC responses.
- Location: `classes/local/server.php`
- Contains: The `webservice_mcp\local\server` class extending `webservice_base_server`.
- Depends on: `webservice_base_server`, `core_external\external_api`, `webservice_mcp\local\request`, and HTTP globals.
- Used by: `server.php`.

**Request validation layer:**
- Purpose: Validate JSON-RPC 2.0 payloads before the server routes or executes them.
- Location: `classes/local/request.php`
- Contains: The `webservice_mcp\local\request` class with `from_raw_input()` and structural validation.
- Depends on: `moodle_exception` and `php://input`.
- Used by: `classes/local/server.php`.

**Tool discovery and schema translation layer:**
- Purpose: Discover service-scoped Moodle external functions and publish them as MCP tools with JSON Schema.
- Location: `classes/local/tool_provider.php`
- Contains: Database lookup of token/service relationships and recursive conversion from Moodle external descriptions to MCP schemas.
- Depends on: `$DB`, `core_external\external_api`, `core_external\external_description`, `core_external\external_multiple_structure`, `core_external\external_single_structure`, and `core_external\external_value`.
- Used by: `classes/local/server.php` when serving `tools/list`.

**Client and test integration layer:**
- Purpose: Provide callers and tests with small wrappers for invoking the MCP endpoint.
- Location: `lib.php`, `locallib.php`, `tests/client_test.php`, `tests/server_test.php`, `tests/request_test.php`, `tests/tool_provider_test.php`
- Contains: `webservice_mcp_client`, `webservice_mcp_test_client`, and PHPUnit coverage.
- Depends on: Moodle `curl`, `moodle_url`, `webservice_test_client_interface`, and PHPUnit base classes.
- Used by: Automated tests and external integrations that want a lightweight PHP client.

**Privacy declaration layer:**
- Purpose: Satisfy Moodle’s privacy API and declare that the plugin stores no plugin-owned personal data.
- Location: `classes/privacy/provider.php`
- Contains: `webservice_mcp\privacy\provider` implementing `null_provider`.
- Depends on: `core_privacy\local\metadata\null_provider`.
- Used by: Moodle privacy tooling.

## Data Flow

**MCP handshake and tool discovery:**

1. `server.php` bootstraps Moodle via `../../config.php`, checks `webservice_protocol_is_enabled('mcp')`, instantiates `webservice_mcp\local\server`, and calls `run()`.
2. `classes/local/server.php` sets JSON and CORS headers, raises the memory limit, extends the timeout, and parses the incoming request.
3. `classes/local/request.php` validates POST JSON as JSON-RPC 2.0 when a request body is present.
4. `classes/local/server.php` extracts the token from `Authorization: Bearer ...` or `wstoken`, authenticates with the inherited Moodle server flow, and routes `initialize` or `tools/list`.
5. For `tools/list`, `classes/local/tool_provider.php` resolves the token’s `externalserviceid`, enumerates service functions from `external_services_functions`, loads each definition through `external_api::external_function_info()`, and returns MCP tool metadata.

**Tool execution:**

1. A POST `tools/call` request reaches `server.php` and is parsed into `webservice_mcp\local\request`.
2. `classes/local/server.php` maps `params.name` to `$this->functionname` and `params.arguments` to `$this->parameters`.
3. Control falls through to `parent::run()` on `webservice_base_server`, so Moodle core handles authentication, parameter cleaning, and the actual external function invocation.
4. `classes/local/server.php::send_response()` wraps Moodle’s return value into MCP `content` and `structuredContent` fields after `external_api::clean_returnvalue()`.

**State Management:**
- Request state is per-request and held on the `webservice_mcp\local\server` instance in `$httpmethod`, `$mcprequest`, `$functionname`, `$parameters`, and inherited `webservice_base_server` fields.
- Tool inventory is not cached inside the plugin; `classes/local/tool_provider.php` queries Moodle tables on each `tools/list` call.
- The plugin does not create its own persistent storage; it reads Moodle tables such as `external_tokens` and `external_services_functions`.

## Key Abstractions

**`webservice_mcp\local\server`:**
- Purpose: Adapt Moodle’s `webservice_base_server` lifecycle to MCP semantics.
- Examples: `classes/local/server.php`, `server.php`
- Pattern: Inheritance-based adapter with a thin root entrypoint and overridden request and response hooks.

**`webservice_mcp\local\request`:**
- Purpose: Represent a validated JSON-RPC 2.0 envelope before routing.
- Examples: `classes/local/request.php`, `tests/request_test.php`
- Pattern: Small validation object built from raw input.

**`webservice_mcp\local\tool_provider`:**
- Purpose: Translate Moodle external function metadata into MCP tool definitions.
- Examples: `classes/local/tool_provider.php`, `tests/tool_provider_test.php`
- Pattern: Static translator that recursively maps `external_description` trees to JSON Schema.

**`webservice_mcp_client`:**
- Purpose: Give PHP callers a minimal client for `initialize`, `tools/list`, and `tools/call`.
- Examples: `lib.php`, `tests/client_test.php`
- Pattern: Thin wrapper around `curl` and JSON-RPC payload construction.

**`webservice_mcp_test_client`:**
- Purpose: Plug the protocol into Moodle’s web service test harness.
- Examples: `locallib.php`
- Pattern: Framework adapter implementing `webservice_test_client_interface`.

## Entry Points

**HTTP MCP endpoint:**
- Location: `server.php`
- Triggers: Incoming requests to Moodle’s `/webservice/mcp/server.php`
- Responsibilities: Load Moodle, enforce protocol enablement, instantiate the local server, and hand over execution.

**Autoloaded runtime server:**
- Location: `classes/local/server.php`
- Triggers: Instantiated by `server.php`
- Responsibilities: Parse requests, extract tokens, dispatch `initialize` and `tools/list`, bridge `tools/call` to Moodle external functions, and shape JSON-RPC responses.

**PHP client surface:**
- Location: `lib.php`
- Triggers: Required by other PHP code or tests
- Responsibilities: Build JSON-RPC requests and send them to the endpoint with the service token.

**Moodle test harness adapter:**
- Location: `locallib.php`
- Triggers: Moodle web service testing infrastructure
- Responsibilities: Provide a protocol-specific test client that wraps calls as `tools/call`.

**Plugin metadata and capability entrypoints:**
- Location: `version.php`, `db/access.php`, `classes/privacy/provider.php`
- Triggers: Moodle plugin discovery, capability checks, and privacy scans
- Responsibilities: Register plugin metadata, expose `webservice/mcp:use`, and declare privacy behavior.

## Error Handling

**Strategy:** Validate early, throw `moodle_exception` for malformed MCP input, and normalize runtime failures into JSON-RPC error payloads inside `classes/local/server.php`.

**Patterns:**
- Use `webservice_mcp\local\request` in `classes/local/request.php` to reject invalid JSON, missing methods, and wrong JSON-RPC versions before routing.
- Use `webservice_mcp\local\server::generate_error()` and `webservice_mcp\local\server::safe_json_encode()` in `classes/local/server.php` to produce JSON-RPC-friendly failures and a fallback encoding path.
- Let Moodle core exceptions surface from inherited web service execution, then adapt them in `send_error()` or `send_response()` when wrapping standard tool calls.
- Expose extra debug context only when Moodle debugging is enabled via `debugging()` and `log_exception_for_debug()`.

## Cross-Cutting Concerns

**Logging:** Use Moodle debugging hooks in `classes/local/server.php`; there is no dedicated application logger in the plugin.
**Validation:** Use `classes/local/request.php` for envelope validation, `optional_param()` in `classes/local/server.php` for `wstoken`, and `external_api::clean_returnvalue()` for response validation.
**Authentication:** Accept tokens from the `Authorization` header or `wstoken` parameter in `classes/local/server.php`, then rely on `webservice_base_server` authentication and the capability declared in `db/access.php`.

---

*Architecture analysis: 2026-04-21*
