# Technology Stack

**Analysis Date:** 2026-04-21

## Languages

**Primary:**
- PHP 8.0+ - All runtime logic lives in `server.php`, `lib.php`, `locallib.php`, `classes/local/*.php`, `classes/privacy/provider.php`, and `tests/*.php`. `README.md` requires PHP 8.0+, and `.github/workflows/ci.yml` validates PHP `8.0`, `8.1`, and `8.2`.

**Secondary:**
- YAML - CI/CD automation is defined in `.github/workflows/ci.yml` and `.github/workflows/release.yml`.
- Markdown - Operator and release documentation lives in `README.md` and `CHANGELOG.md`.

## Runtime

**Environment:**
- Moodle plugin runtime on PHP - `server.php` loads the host Moodle bootstrap from `../../config.php`.
- Moodle 4.2+ - `version.php` sets `$plugin->requires = 2023041800`, and `README.md` states Moodle 4.2 or higher.

**Package Manager:**
- Not detected as a repo-local manifest - no `composer.json`, `composer.lock`, or `package.json` were found at the plugin root during this mapper scan.
- Composer (CI bootstrap only) - `.github/workflows/ci.yml` uses `composer create-project ... moodlehq/moodle-plugin-ci ci ^4`.
- Lockfile: missing

## Frameworks

**Core:**
- Moodle web service plugin API - `server.php` boots the plugin as component `webservice_mcp`, while `classes/local/server.php` extends `webservice_base_server`.
- Moodle external API layer - `classes/local/server.php` and `classes/local/tool_provider.php` use `core_external\external_api` and Moodle external description classes to validate calls and derive tool schemas.
- Model Context Protocol over JSON-RPC 2.0 - `classes/local/request.php` validates JSON-RPC requests, and `classes/local/server.php` implements `initialize`, `tools/list`, and `tools/call` for MCP clients.

**Testing:**
- PHPUnit via Moodle test harness - `tests/client_test.php`, `tests/request_test.php`, and `tests/server_test.php` extend `advanced_testcase`; `tests/tool_provider_test.php` extends `externallib_advanced_testcase`.
- Behat is wired in CI - `.github/workflows/ci.yml` runs `moodle-plugin-ci behat`, although no plugin-local Behat feature files are present in this repository.

**Build/Dev:**
- `moodle-plugin-ci` `^4` - `.github/workflows/ci.yml` installs and runs lint, validation, PHPUnit, Behat, and Grunt tasks through the Moodle plugin CI wrapper.
- GitHub Actions - `.github/workflows/ci.yml` handles test automation and `.github/workflows/release.yml` handles tagging and GitHub Release publication.

## Key Dependencies

**Critical:**
- Moodle core `webservice_base_server` - `classes/local/server.php` depends on Moodle's web service server base class for authentication and execution flow.
- Moodle core `core_external\external_api` - `classes/local/server.php` validates return payloads and `classes/local/tool_provider.php` resolves external function metadata.
- Moodle DML `$DB` - `classes/local/tool_provider.php` reads `external_tokens` and `external_services_functions` to determine which tools a token may expose.
- Moodle `moodle_url` and `curl` helpers - `lib.php` uses them to provide a bundled MCP client for tests and integrations.
- PHP cURL extension - `locallib.php` uses `curl_init()` and `curl_setopt_array()` for Moodle's test client interface.

**Infrastructure:**
- `actions/checkout@v4` - source checkout in `.github/workflows/ci.yml` and `.github/workflows/release.yml`.
- `shivammathur/setup-php@v2` - PHP runtime provisioning in `.github/workflows/ci.yml`.
- `softprops/action-gh-release@v2` - GitHub release publishing in `.github/workflows/release.yml`.
- PostgreSQL 15 and MariaDB 10 containers - `.github/workflows/ci.yml` validates the plugin against both database backends.

## Configuration

**Environment:**
- Runtime configuration is inherited from the host Moodle install via `server.php` requiring `../../config.php`; the plugin is not a standalone service.
- Plugin metadata is declared in `version.php` as component `webservice_mcp`, release `0.4.1`, and maturity `MATURITY_BETA`.
- Access control is defined in `db/access.php` through capability `webservice/mcp:use`.
- Operational setup is documented in `README.md`: enable Moodle web services, enable the MCP protocol, create an external service, issue a token, and assign capability.
- No plugin-local `.env`, `.env.*`, or other env-file configuration mechanism was detected at the repository root during this mapper scan.

**Build:**
- Test and quality automation lives in `.github/workflows/ci.yml`.
- Release automation lives in `.github/workflows/release.yml`.
- No plugin-local build config files such as `phpunit.xml`, `phpcs.xml`, `composer.json`, or `package.json` were detected at the repository root.

## Platform Requirements

**Development:**
- A Moodle checkout with this plugin installed under `webservice/mcp`, as shown in `README.md`.
- PHP 8.x, with CI explicitly validating `8.0`, `8.1`, and `8.2` in `.github/workflows/ci.yml`.
- A Moodle-supported database; CI covers `pgsql` and `mariadb` in `.github/workflows/ci.yml`.
- Composer is needed in CI to bootstrap `moodle-plugin-ci`, but Composer is not managed from a repo-local manifest in this plugin.

**Production:**
- Deployment target is a host Moodle instance exposing `webservice/mcp/server.php`.
- Authentication is permanent-token based, and the plugin depends on Moodle's enabled web service subsystem rather than any standalone app server or external package runtime.

---

*Stack analysis: 2026-04-21*
