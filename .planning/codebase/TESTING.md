# Testing Patterns

**Analysis Date:** 2026-04-21

## Test Framework

**Runner:**
- PHPUnit through Moodle test base classes `advanced_testcase` and `externallib_advanced_testcase`, as used in `tests/client_test.php`, `tests/request_test.php`, `tests/server_test.php`, and `tests/tool_provider_test.php`.
- Repo-local PHPUnit config was not detected in `/home/yui/Documents/moodle-mcp`; local execution guidance lives in `README.md`, and CI orchestration lives in `.github/workflows/ci.yml`.
- CI runs the suite across PHP `8.0`, `8.1`, and `8.2` against both `pgsql` and `mariadb` in `.github/workflows/ci.yml`.

**Assertion Library:**
- PHPUnit assertions exposed by Moodle's test base classes: `assertEquals`, `assertTrue`, `assertArrayHasKey`, `assertInstanceOf`, `assertNotEmpty`, `expectException`, and `expectExceptionMessage` throughout `tests/`.

**Run Commands:**
```bash
vendor/bin/phpunit --testsuite webservice_mcp_testsuite   # Local suite command from `README.md`
moodle-plugin-ci phpunit --fail-on-warning                # CI-equivalent PHPUnit run from `.github/workflows/ci.yml`
# Watch mode and standalone coverage commands are not configured in this repository
```

## Test File Organization

**Location:**
- Keep PHPUnit files in the root `tests/` directory. There are no co-located source-and-test pairs under `classes/`.

**Naming:**
- Name files `*_test.php` and the test classes `final class ..._test`: `tests/request_test.php`, `tests/server_test.php`, `tests/client_test.php`, `tests/tool_provider_test.php`.
- Name each test method `test_<scenario>` and keep the scenario specific: `test_request_without_params()` in `tests/request_test.php`, `test_generate_schema_nested_structure()` in `tests/tool_provider_test.php`.

**Structure:**
```text
tests/
├── client_test.php
├── request_test.php
├── server_test.php
└── tool_provider_test.php
```

## Test Structure

**Suite Organization:**
```php
final class request_test extends advanced_testcase {
    public function test_invalid_request_missing_jsonrpc(): void {
        $this->resetAfterTest(true);

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('JSON-RPC version must be 2.0');
        new request($data);
    }
}
```
Pattern source: `tests/request_test.php`.

**Patterns:**
- Call `resetAfterTest(true)` at the start of each test method. The current suite uses that pattern in all 40 test methods across `tests/client_test.php`, `tests/request_test.php`, `tests/server_test.php`, and `tests/tool_provider_test.php`.
- Keep one scenario per method and build the input inline inside that method. No `setUp()`, `tearDown()`, or `@dataProvider` usage was detected in `tests/`.
- Use reflection for white-box checks of non-public methods and properties when there is no public seam, as in `tests/server_test.php`, `tests/tool_provider_test.php`, and `tests/client_test.php`.
- Mix pure in-memory assertions with Moodle test-database writes depending on the subject under test: pure request validation in `tests/request_test.php`; DB-backed service and token setup in `tests/tool_provider_test.php`.

## Mocking

**Framework:** Not used. No `createMock()`, `getMockBuilder()`, `expects()`, or `willReturn()` usage was detected under `tests/`.

**Patterns:**
```php
$reflection = new ReflectionClass($server);
$mcprequestprop = $reflection->getProperty('mcprequest');
$mcprequestprop->setAccessible(true);
$mcprequestprop->setValue($server, new request($requestdata));
```
Pattern source: `tests/server_test.php`.

**What to Mock:**
- Nothing by default. Follow the current style and prefer real value objects such as `request`, `external_value`, `external_single_structure`, and `external_multiple_structure` in `tests/request_test.php` and `tests/tool_provider_test.php`.
- If a live endpoint would be required, keep the test narrower rather than inventing HTTP mocks. `tests/client_test.php` only verifies constructor behavior and method signatures for `webservice_mcp_client`.

**What NOT to Mock:**
- Do not mock `core_external` description objects. `tests/tool_provider_test.php` instantiates the real classes directly.
- Do not mock token and service storage when verifying `tool_provider::get_tools()`. `tests/tool_provider_test.php` inserts records into `external_services`, `external_services_functions`, and `external_tokens`.
- Do not replace white-box inspection with fake wrappers. The current repo uses reflection against the real `server` object in `tests/server_test.php`.

## Fixtures and Factories

**Test Data:**
```php
$service = new stdClass();
$service->name = 'Test MCP Service';
$service->enabled = 1;
$service->restrictedusers = 0;
$service->component = null;
$service->timecreated = time();
$service->timemodified = time();
$service->shortname = 'test_mcp_service';
$service->downloadfiles = 0;
$service->uploadfiles = 0;
$serviceid = $DB->insert_record('external_services', $service);
```
Pattern source: `tests/tool_provider_test.php`.

**Location:**
- Keep fixtures inline inside the test method that needs them. No `tests/fixtures/`, factory classes, or reusable builders were detected in `/home/yui/Documents/moodle-mcp`.
- Reuse Moodle globals and context helpers inside the test body when database state is required: `$DB`, `$USER`, and `context_system::instance()` in `tests/tool_provider_test.php`.

## Coverage

**Requirements:** No numeric target is enforced in the repo. `.github/workflows/ci.yml` disables coverage collection with `coverage: none`, and the PHPUnit job fails on warnings with `moodle-plugin-ci phpunit --fail-on-warning`.

**View Coverage:**
```bash
# No coverage command is configured in `/home/yui/Documents/moodle-mcp`.
# Enable a coverage driver in the surrounding Moodle environment before collecting reports.
```

## Test Types

**Unit Tests:**
- `tests/request_test.php` exercises request validation and value mapping entirely in memory.
- `tests/client_test.php` verifies constructor behavior, public method presence, and method signatures for `webservice_mcp_client` without a live server.
- `tests/tool_provider_test.php` unit-tests schema generation with real `core_external` description objects.

**Integration Tests:**
- `tests/tool_provider_test.php::test_get_tools()` writes service, function, and token rows into the Moodle test database before calling `tool_provider::get_tools()`.
- `tests/server_test.php` behaves as a white-box integration test of `classes/local/server.php` by instantiating the real server and manipulating internal request state through reflection instead of issuing HTTP requests.

**E2E Tests:**
- Not used in the plugin repository itself. `.github/workflows/ci.yml` runs `moodle-plugin-ci behat --profile chrome --scss-deprecations`, but no local `.feature` files were detected under `/home/yui/Documents/moodle-mcp`.

## Common Patterns

**Async Testing:**
```php
$json = $method->invoke($server, $data);
$decoded = json_decode($json, true);
$this->assertEquals($data, $decoded);
```
Pattern source: `tests/server_test.php`. The suite is synchronous; there are no async polling or concurrency tests in `tests/`.

**Error Testing:**
```php
$this->expectException(moodle_exception::class);
$this->expectExceptionMessage('Method field is required and must be a string');
new request($data);
```
Pattern source: `tests/request_test.php`.

---

*Testing analysis: 2026-04-21*
