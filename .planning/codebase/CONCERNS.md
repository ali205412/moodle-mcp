# Codebase Concerns

**Analysis Date:** 2026-04-21

## Tech Debt

**Protocol and Error Handling Coupling:**
- Issue: JSON-RPC parsing, authentication, CORS handling, MCP method dispatch, and envelope generation are all hand-managed inside `classes/local/server.php`. The same class builds success payloads, error payloads, and browser headers, and those paths diverge: `generate_error()` writes the request `id` into the `jsonrpc` field while success paths use the actual protocol version.
- Files: `classes/local/server.php`, `server.php`, `tests/server_test.php`
- Impact: Small changes in one branch of the request lifecycle can break unrelated protocol behavior. The implementation produces malformed error envelopes under real requests and hides that defect behind narrow unit tests.
- Fix approach: Centralize JSON-RPC envelope creation in one helper, split auth/preflight handling from method dispatch, and add end-to-end tests that hit `server.php` instead of only reflecting into internals.

**Lossy Schema Translation:**
- Issue: `classes/local/tool_provider.php` converts Moodle external descriptions into a very small JSON Schema subset. `PARAM_INT` becomes `number`, most scalar types collapse to `string`, and the generated schema omits defaults, nullable state, enums, and richer constraints.
- Files: `classes/local/tool_provider.php`, `tests/tool_provider_test.php`
- Impact: MCP clients receive incomplete tool contracts, which increases invalid tool calls and makes model guidance weaker for real Moodle APIs.
- Fix approach: Expand schema mapping for real Moodle parameter types, add fixtures for representative external functions, and snapshot complex generated schemas in tests.

**Client Transport Robustness:**
- Issue: The bundled clients favor convenience over robustness. `webservice_mcp_client` always appends `wstoken` to the URL, and both client implementations decode responses without checking transport errors or HTTP status. `webservice_mcp_test_client` also uses an unlimited timeout.
- Files: `locallib.php`, `lib.php`, `tests/client_test.php`
- Impact: Integrations can fail with silent `null` responses, hang indefinitely in tests, and default to the least private token transport even though the server supports Bearer auth.
- Fix approach: Add explicit transport/status error handling, bound timeouts, Authorization-header support in the client, and integration tests for both auth modes.

## Known Bugs

**CORS Preflight Is Authenticated Before It Can Short-Circuit:**
- Symptoms: Browser clients using `Authorization: Bearer ...` will hit an `OPTIONS` preflight, but `run()` authenticates the request before `handle_mcp_method()` can return the preflight response.
- Files: `classes/local/server.php`, `README.md`
- Trigger: Any cross-origin POST that relies on the Authorization header instead of a `wstoken` query string.
- Workaround: Use same-origin requests or place `wstoken` in the URL so preflight still carries a token, which reintroduces token-leakage risk.

**JSON-RPC Error Envelope Uses the Request ID as the Protocol Version:**
- Symptoms: Exception responses can contain the request `id` in the `jsonrpc` field instead of `"2.0"`.
- Files: `classes/local/server.php`, `tests/server_test.php`
- Trigger: Any exception raised after a valid MCP request has been parsed and assigned an `id`.
- Workaround: No server-side workaround exists; tolerant clients must ignore the malformed envelope.

**Release Workflow Does Not Actually Skip Existing Tags:**
- Symptoms: `.github/workflows/release.yml` claims to skip when the tag already exists, but the job continues after the check step and reaches tag creation. The release body also references `steps.Changelog.outputs.changelog` even though no `Changelog` step exists.
- Files: `.github/workflows/release.yml`
- Trigger: Re-running the release workflow for an existing version or expecting generated changelog text in the release body.
- Workaround: Manually edit or rerun the workflow with the tag removed, and write release notes manually.

## Security Considerations

**URL Tokens Are Still a First-Class Auth Path:**
- Risk: The server accepts `wstoken` in the URL, the README documents that flow first, and `webservice_mcp_client` hardcodes it. Query-string tokens are easy to leak through browser history, reverse-proxy logs, copied links, and shell history.
- Files: `classes/local/server.php`, `locallib.php`, `README.md`, `server.php`
- Current mitigation: `classes/local/server.php` also accepts Authorization Bearer tokens.
- Recommendations: Make Bearer auth the default in docs and client code, add a setting to disable URL tokens, and avoid printing tokenized example URLs in operator-facing guidance.

**Cross-Origin Access and Error Detail Are Too Open by Default:**
- Risk: `set_headers()` allows every origin, and `generate_error()` returns raw exception class names and messages to callers. That broadens the attack surface for browser-based callers and exposes internal implementation details on failures.
- Files: `classes/local/server.php`
- Current mitigation: `NO_DEBUG_DISPLAY` suppresses Moodle HTML/debug output, and `debuginfo` is only added when debugging is enabled.
- Recommendations: Add a configurable origin allowlist, let unauthenticated preflight complete safely, and return generic production error messages while logging detailed exceptions server-side.

## Performance Bottlenecks

**Uncached Tool Discovery:**
- Problem: Every `tools/list` request fetches the token record, loads service-function mappings, and calls `external_api::external_function_info()` for each exposed function before building schemas.
- Files: `classes/local/tool_provider.php`, `classes/local/server.php`
- Cause: No cache keyed by service or plugin version; schema generation is repeated for every request.
- Improvement path: Cache tool lists per external service and invalidate on service-function changes or plugin upgrades.

**Tool Results Are Fully Duplicated in Each Response:**
- Problem: `send_response()` serializes the same Moodle return payload twice: once as `structuredContent` and again as a full JSON string inside `content[0].text`.
- Files: `classes/local/server.php`
- Cause: The implementation always duplicates the full result body instead of emitting a smaller textual summary.
- Improvement path: Keep `structuredContent` authoritative, reduce the text payload to a summary when possible, or make the duplicate text optional for large responses.

## Fragile Areas

**Request Lifecycle State Machine:**
- Files: `classes/local/server.php`, `classes/local/request.php`, `server.php`
- Why fragile: The request lifecycle relies on mutable state (`token`, `httpmethod`, `mcprequest`, `functionname`, `parameters`) spread across `run()`, `parse_request()`, `extract_tool_call()`, and the response helpers. Auth order, request parsing, and protocol formatting are tightly coupled.
- Safe modification: Change request parsing, auth, and response generation together. Add regression tests before touching `run()` or `handle_mcp_method()`.
- Test coverage: No end-to-end tests hit the real `server.php` entry point, and no test covers browser-style `OPTIONS` handling.

**Automation and Documentation Drift:**
- Files: `README.md`, `.github/workflows/release.yml`, `version.php`, `classes/local/server.php`
- Why fragile: Human-facing docs and release automation drift from the runtime. `README.md` shows `protocolVersion` `1.0` and server version `0.1.0`, while `classes/local/server.php` advertises `2025-03-26` and `1.0.0`. Release automation similarly assumes shell parsing will stay aligned with `version.php`.
- Safe modification: Update docs, release scripts, and runtime constants in the same change whenever protocol/version behavior changes.
- Test coverage: No automated check verifies README examples or release workflow behavior against `classes/local/server.php` and `version.php`.

**Packaged Clients Are Easy to Misuse:**
- Files: `locallib.php`, `lib.php`, `tests/client_test.php`
- Why fragile: Client behavior around auth, timeouts, and error handling is implicit rather than contractual. The tests mainly assert method existence and signatures instead of network behavior.
- Safe modification: Add transport-level tests before changing request format or auth headers, and treat the client surface as a public API with explicit failure semantics.
- Test coverage: `tests/client_test.php` does not exercise HTTP failures, response validation, or Bearer-token flows.

## Scaling Limits

**Large Service Catalogs and Large Moodle Result Sets:**
- Current capacity: Not quantified in the repository. The current implementation is synchronous and uncached in `classes/local/tool_provider.php` and `classes/local/server.php`.
- Limit: `tools/list` cost grows with the number of service functions, and `tools/call` payload size grows quickly because responses are duplicated in text and structured form.
- Scaling path: Add per-service caching, introduce payload size guidance for exposed Moodle functions, and trim or stream large response bodies where the MCP client can handle it.

## Dependencies at Risk

**Not detected:**
- Risk: No package-manifest dependency risk is directly visible in this repository snapshot.
- Impact: Not applicable.
- Migration plan: Not applicable.

## Missing Critical Features

**No Configurable Hardening Controls:**
- Problem: The plugin exposes no admin setting to restrict CORS origins, disable query-string tokens, or tune/collapse expensive schema generation behavior.
- Blocks: Safe browser-based deployment patterns and environment-specific hardening without patching code in `classes/local/server.php` and `locallib.php`.

## Test Coverage Gaps

**End-to-End MCP Server Behavior:**
- What's not tested: Real requests through `server.php`, authenticated `initialize` and `tools/list`, browser preflight behavior, and exception responses after a populated request object.
- Files: `server.php`, `classes/local/server.php`, `tests/server_test.php`
- Risk: Protocol regressions can ship even when PHPUnit passes. The current suite does not cover the malformed `jsonrpc` error field or the auth-before-OPTIONS flow.
- Priority: High

**Client Failure Modes and Delivery Tooling:**
- What's not tested: HTTP failures and timeout behavior in `locallib.php` and `lib.php`, README example accuracy, and the release workflow in `.github/workflows/release.yml`.
- Files: `locallib.php`, `lib.php`, `README.md`, `.github/workflows/release.yml`, `tests/client_test.php`
- Risk: Integrations can fail silently, operators can follow stale protocol examples, and release automation can break on reruns without any pre-merge signal.
- Priority: Medium

---

*Concerns audit: 2026-04-21*
