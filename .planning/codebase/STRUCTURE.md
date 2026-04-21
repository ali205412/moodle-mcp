# Codebase Structure

**Analysis Date:** 2026-04-21

## Directory Layout

```text
[project-root]/
├── classes/              # Moodle-autoloaded PHP classes
│   ├── local/            # MCP runtime server, request parser, tool discovery
│   └── privacy/          # Moodle privacy API provider
├── db/                   # Capability declarations
├── lang/
│   └── en/               # English language strings
├── pix/                  # Plugin icon assets
├── tests/                # PHPUnit coverage for runtime classes and client
├── lib.php               # PHP client for MCP calls
├── locallib.php          # Moodle web service test client adapter
├── server.php            # Public MCP HTTP endpoint
├── version.php           # Plugin metadata for Moodle
├── README.md             # Installation, usage, and protocol overview
└── CHANGELOG.md          # Release history
```

## Directory Purposes

**`classes/`:**
- Purpose: Hold Moodle-autoloaded PHP classes for the plugin.
- Contains: Namespaced runtime and API integration classes under `webservice_mcp\...`.
- Key files: `classes/local/server.php`, `classes/local/request.php`, `classes/local/tool_provider.php`, `classes/privacy/provider.php`

**`classes/local/`:**
- Purpose: Keep the plugin’s core request-handling and schema-generation logic.
- Contains: The MCP server adapter, JSON-RPC request validator, and tool discovery translator.
- Key files: `classes/local/server.php`, `classes/local/request.php`, `classes/local/tool_provider.php`

**`classes/privacy/`:**
- Purpose: Isolate Moodle privacy API implementations from runtime transport code.
- Contains: Null-provider declaration only.
- Key files: `classes/privacy/provider.php`

**`db/`:**
- Purpose: Store Moodle plugin database and capability contract files.
- Contains: Capability definitions for protocol access.
- Key files: `db/access.php`

**`lang/en/`:**
- Purpose: Store English strings used by exceptions, capability labels, and plugin metadata.
- Contains: One string file keyed by the component name.
- Key files: `lang/en/webservice_mcp.php`

**`tests/`:**
- Purpose: Keep PHPUnit coverage close to the plugin code it verifies.
- Contains: Runtime, client, request, and schema translation tests.
- Key files: `tests/server_test.php`, `tests/request_test.php`, `tests/tool_provider_test.php`, `tests/client_test.php`

**`pix/`:**
- Purpose: Store plugin artwork used by Moodle.
- Contains: The plugin icon.
- Key files: `pix/icon.png`

## Key File Locations

**Entry Points:**
- `server.php`: Public HTTP endpoint for MCP requests inside the Moodle `webservice` plugin slot.
- `lib.php`: Reusable PHP client for `initialize`, `tools/list`, and `tools/call`.
- `locallib.php`: Adapter for Moodle’s web service test interface.

**Configuration:**
- `version.php`: Declares component name, version, release, Moodle requirement, and maturity.
- `db/access.php`: Declares the `webservice/mcp:use` capability.
- `lang/en/webservice_mcp.php`: Declares language strings consumed by exceptions and UI labels.

**Core Logic:**
- `classes/local/server.php`: Main protocol adapter built on `webservice_base_server`.
- `classes/local/request.php`: JSON-RPC request validation and parsing.
- `classes/local/tool_provider.php`: Service-scoped tool discovery and JSON Schema generation.
- `classes/privacy/provider.php`: Privacy API declaration that the plugin stores no plugin-owned data.

**Testing:**
- `tests/server_test.php`: Reflection-based coverage of server helpers and constants.
- `tests/request_test.php`: Envelope validation coverage for JSON-RPC requests.
- `tests/tool_provider_test.php`: Schema conversion and service discovery coverage.
- `tests/client_test.php`: Client surface and method signature coverage.

## Naming Conventions

**Files:**
- Use lowercase snake_case PHP filenames for classes and tests: `tool_provider.php`, `request_test.php`.
- Keep Moodle root contract filenames exactly as Moodle expects: `server.php`, `lib.php`, `locallib.php`, `version.php`.
- Match the plugin component name in the language file: `lang/en/webservice_mcp.php`.

**Directories:**
- Use lowercase Moodle-standard directory names: `classes`, `db`, `lang`, `pix`, `tests`.
- Mirror namespaces beneath `classes/`: `classes/local/server.php` maps to `webservice_mcp\local\server`, and `classes/privacy/provider.php` maps to `webservice_mcp\privacy\provider`.

## Where to Add New Code

**New Feature:**
- Primary code: Add new runtime classes under `classes/local/` and keep `server.php` as a thin bootstrap.
- Tests: Add PHPUnit coverage under `tests/` with a matching `_test.php` suffix.
- Supporting strings/config: Extend `lang/en/webservice_mcp.php` for new messages and `db/access.php` only when the feature changes capabilities.

**New Component/Module:**
- Implementation: Put new Moodle-autoloaded classes under `classes/local/` unless the feature belongs to a Moodle-defined API namespace such as `classes/privacy/`.

**Utilities:**
- Shared helpers: Prefer a focused class under `classes/local/` for runtime helpers; reserve `lib.php` and `locallib.php` for Moodle-facing client and test-adapter surfaces.

## Special Directories

**`classes/`:**
- Purpose: Moodle autoload root for namespaced plugin classes.
- Generated: No
- Committed: Yes

**`tests/`:**
- Purpose: PHPUnit suite for plugin runtime behavior.
- Generated: No
- Committed: Yes

**`pix/`:**
- Purpose: Moodle plugin asset directory.
- Generated: No
- Committed: Yes

---

*Structure analysis: 2026-04-21*
