# External Integrations

**Analysis Date:** 2026-04-21

## APIs & External Services

**Moodle Core Web Service APIs:**
- Moodle web service runtime - The plugin delegates execution to Moodle's native web service stack in `server.php` and `classes/local/server.php`.
  - SDK/Client: `webservice_base_server`, `core_external\external_api`, and Moodle external description classes in `classes/local/server.php` and `classes/local/tool_provider.php`
  - Auth: Permanent Moodle web service tokens via `WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN` in `server.php`

**MCP / JSON-RPC Clients:**
- MCP-compatible clients and AI assistants - The plugin exposes a JSON-RPC 2.0 MCP endpoint from `server.php`, with request validation in `classes/local/request.php` and protocol handling in `classes/local/server.php`.
  - SDK/Client: Bundled helper client `webservice_mcp_client` in `lib.php`
  - Auth: `Authorization: Bearer <token>` header or `wstoken` request parameter, handled in `classes/local/server.php` and documented in `README.md`

**Developer Automation Services:**
- GitHub Actions - The repository integrates with GitHub-hosted CI and release workflows in `.github/workflows/ci.yml` and `.github/workflows/release.yml`.
  - SDK/Client: `actions/checkout@v4`, `shivammathur/setup-php@v2`, `actions/upload-artifact@v4`, and `softprops/action-gh-release@v2`
  - Auth: GitHub-managed workflow permissions and `secrets.GITHUB_TOKEN` in `.github/workflows/release.yml`

## Data Storage

**Databases:**
- Host Moodle database - Plugin runtime reads Moodle's existing web service metadata tables rather than defining plugin-specific tables.
  - Connection: Inherited from the host Moodle bootstrap in `server.php` via `../../config.php`
  - Client: Moodle DML `$DB` in `classes/local/tool_provider.php`
  - Tables touched directly: `external_tokens` and `external_services_functions` in `classes/local/tool_provider.php`; `tests/tool_provider_test.php` also inserts into `external_services` for test setup
- CI validation databases - `.github/workflows/ci.yml` runs the plugin against PostgreSQL 15 and MariaDB 10 service containers.
  - Connection: Provisioned by `moodle-plugin-ci install --db-host=127.0.0.1` in `.github/workflows/ci.yml`
  - Client: Moodle test installation created by `moodle-plugin-ci`

**File Storage:**
- Local filesystem only - The repository contains static assets such as `pix/icon.png`, but the plugin does not implement a dedicated file-storage integration.

**Caching:**
- None - No cache backend integration or cache configuration files were detected in `classes/`, `db/`, or the repository root during this mapper scan.

## Authentication & Identity

**Auth Provider:**
- Moodle external service tokens
  - Implementation: `server.php` instantiates the server with permanent-token auth, `classes/local/server.php` extracts Bearer or `wstoken` credentials, `classes/local/tool_provider.php` resolves the token to an external service, and `db/access.php` gates usage behind capability `webservice/mcp:use`

## Monitoring & Observability

**Error Tracking:**
- None - No dedicated error tracking SaaS integration is configured. Runtime debugging uses Moodle's `debugging()` and exception handling in `server.php` and `classes/local/server.php`.

**Logs:**
- Standard Moodle logs - `README.md` directs operators to Moodle's built-in logs under Site administration reports.
- Debug traces - `classes/local/server.php` formats exception backtraces with `get_exception_info()` and `format_backtrace()` when debugging is enabled.

## CI/CD & Deployment

**Hosting:**
- Moodle plugin deployment - `README.md` installs the code into the host Moodle path `webservice/mcp`, and `server.php` serves the runtime endpoint from that location.
- Release distribution - `.github/workflows/release.yml` creates Git tags and GitHub Releases intended for Moodle plugin distribution.

**CI Pipeline:**
- GitHub Actions - `.github/workflows/ci.yml` runs matrix builds for PHP `8.0`, `8.1`, and `8.2`, against Moodle branch `MOODLE_402_STABLE`, with `pgsql` and `mariadb` databases.
- GitHub Release automation - `.github/workflows/release.yml` watches `version.php`, derives a `vX.Y.Z` tag, pushes the tag, and publishes a GitHub Release.

## Environment Configuration

**Required env vars:**
- Runtime: No plugin-local environment variables were detected in the repository. Runtime authentication is request-based through `Authorization` or `wstoken`, as implemented in `classes/local/server.php`.
- CI: `DB`, `MOODLE_BRANCH`, and `NVM_DIR` are set in `.github/workflows/ci.yml`.
- Release: `secrets.GITHUB_TOKEN` is consumed by `.github/workflows/release.yml`.

**Secrets location:**
- Runtime tokens are issued and stored by Moodle's web service administration flow described in `README.md`; the plugin repo does not store token values.
- Release credentials are sourced from GitHub Actions secrets in `.github/workflows/release.yml`.

## Webhooks & Callbacks

**Incoming:**
- `server.php` exposes the Moodle endpoint `/webservice/mcp/server.php` described in `README.md`.
- `classes/local/server.php` accepts `GET` for server info, `POST` for JSON-RPC MCP methods (`initialize`, `tools/list`, `tools/call`), and `OPTIONS` for CORS preflight.

**Outgoing:**
- None automatically from plugin runtime - no webhook dispatchers or background callback integrations were detected in `server.php`, `classes/`, `lib.php`, or `locallib.php`.
- Optional client-side POSTs - `lib.php` and `locallib.php` can send HTTP POST requests to an MCP endpoint when their helper clients are explicitly used by tests or external consumers.

---

*Integration audit: 2026-04-21*
