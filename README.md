# Moodle MCP

`webservice_mcp` is a Moodle web service plugin that turns Moodle into a plugin-first MCP connector.

It does four core things:

- boots users into MCP through Moodle's own login and SSO flow, with a plugin-owned OAuth server for Claude-compatible connectors
- serves remote MCP traffic over Streamable HTTP, with optional legacy SSE compatibility
- harvests Moodle's registered external functions into a permission-gated tool catalog
- fills selected UI-only gaps with plugin-owned wrappers across course authoring, question bank, gradebook, and badges

This repository targets Moodle `4.2` through `4.5` and treats the Moodle source tree as the authority for compatibility and behavior.

## What you get

- Moodle-native browser bootstrap at `/webservice/mcp/launch.php`
- primary MCP transport at `/webservice/mcp/server.php`
- OAuth authorization, token, registration, and discovery endpoints under `/webservice/mcp/oauth/`
- optional SSE compatibility transport at `/webservice/mcp/sse.php`
- site-wide harvested catalog of `external_functions`
- grouped, paginated discovery with coverage metadata
- `x-moodle` metadata on tools for provenance, mutability, risk, eligibility, surface, workflow, and execution hints
- per-user discovery filtered by service scope, connector policy, and Moodle capability checks
- call-time authorization rechecks during execution
- audit ids on discovery and tool execution responses
- typed parity wrappers for high-value UI-only actions in:
  - course authoring
  - question bank
  - gradebook setup
  - badge administration

## Current surface

The connector is harvest-first.

Anything registered in Moodle's external service system can be surfaced automatically once it belongs to the connector service. On top of that, discovery adds curated grouping and workflow metadata for:

- learning surfaces such as courses, completion, files, messaging, notes, and profile data
- activity workflows such as assignment, forum, quiz, workshop, feedback, chat, glossary, wiki, data, choice, survey, SCORM, H5P activity, BigBlueButton, and LTI
- operator surfaces such as users, enrolments, groups, cohorts, roles, courses, categories, competencies, privacy, badges, question bank, and gradebook

Current plugin-owned wrappers now cover four parity domains:

- course editing:
  - `wrapper_course_add_section_after`
  - `wrapper_course_set_section_visibility`
  - `wrapper_course_delete_sections`
  - `wrapper_course_create_missing_sections`
  - `wrapper_course_move_module`
  - `wrapper_course_move_section_after`
  - `wrapper_course_set_module_visibility`
  - `wrapper_course_duplicate_modules`
  - `wrapper_course_delete_modules`
- question bank:
  - category create/update/delete
  - question create/update for supported qtypes
  - question move/delete
  - native preview URLs
  - GIFT/XML import
- gradebook:
  - manual item create/update/move/delete
  - category update/move/delete
- badges:
  - badge create/update/message/delete/duplicate
  - related badges
  - alignments
  - manual award/revoke

## Installation

Install the plugin into Moodle as:

```bash
cd /path/to/moodle/webservice
git clone https://github.com/ali205412/moodle-mcp.git mcp
```

Then visit `Site administration -> Notifications` to complete the upgrade.

The Moodle component name is `webservice_mcp`.

## Required Moodle setup

### 1. Enable web services

In Moodle:

1. go to `Site administration -> Advanced features`
2. enable `Enable web services`

### 2. Enable the MCP protocol

In Moodle:

1. go to `Site administration -> Plugins -> Web services -> Manage protocols`
2. enable `Model Context Protocol (MCP)`

### 3. Grant connector capabilities

The connector bootstrap requires:

- `webservice/mcp:use`

Optional management capability:

- `webservice/mcp:manageconnectors`

By default this is a site policy decision. The plugin does not assume every authenticated user should receive connector access automatically.

### 4. Configure plugin settings

Available settings:

- `connectorserviceidentifier`
  This is the shortname used for the plugin-owned Moodle external service.
- `allowdurablegrants`
  Allows explicit longer-lived connector grants in addition to the default short-lived bootstrap credentials.
- `allowedorigins`
  Browser-facing origin allowlist for remote transport endpoints.
- `enablelegacysse`
  Enables the SSE compatibility endpoint.
- `oauthenabled`
  Enables the plugin-owned OAuth authorization server for Claude-compatible connectors.
- `transportsessionttl`
  TTL for MCP transport sessions.
- `replayttl`
  TTL for replay/event buffers.
- `showhighrisktools`
  Controls whether high-risk tools are shown in discovery.

## Authentication model

### Claude / remote MCP

For Claude web connectors and Claude Code remote HTTP servers, use the MCP transport URL directly:

```text
/webservice/mcp/server.php
```

The transport returns an OAuth Bearer challenge that points clients at the plugin-owned resource metadata and authorization server endpoints. The user then completes:

1. normal Moodle login
   Existing sessions still work, and Moodle SSO/OAuth2 login flows remain the authority.
2. an OAuth consent screen hosted by this plugin
3. token exchange and refresh handled by the MCP client

This is the recommended connector path for Claude-compatible clients.

### Manual bootstrap / compatibility mode

`launch.php` still works for manual testing, non-OAuth clients, and debugging bootstrap credentials. The transport also still accepts raw Moodle web service tokens for compatibility and controlled service-account integrations.

## Endpoints

### Primary MCP transport

```text
https://your-moodle-site.example/webservice/mcp/server.php
```

### OAuth authorize endpoint

```text
https://your-moodle-site.example/webservice/mcp/oauth/authorize.php
```

### OAuth token endpoint

```text
https://your-moodle-site.example/webservice/mcp/oauth/token.php
```

### OAuth dynamic client registration endpoint

```text
https://your-moodle-site.example/webservice/mcp/oauth/register.php
```

### Protected resource metadata

```text
https://your-moodle-site.example/webservice/mcp/.well-known/oauth-protected-resource
```

### Authorization server metadata

```text
https://your-moodle-site.example/webservice/mcp/.well-known/oauth-authorization-server
```

### OpenID discovery

```text
https://your-moodle-site.example/webservice/mcp/.well-known/openid-configuration
```

### Browser bootstrap

```text
https://your-moodle-site.example/webservice/mcp/launch.php?format=json
```

### Legacy SSE compatibility transport

```text
https://your-moodle-site.example/webservice/mcp/sse.php
```

### Supported auth styles

Bearer header:

```text
Authorization: Bearer YOUR_TOKEN
```

Query parameter:

```text
?wstoken=YOUR_TOKEN
```

`YOUR_TOKEN` can be either:

- an OAuth access token issued by `/webservice/mcp/oauth/token.php`
- a connector credential returned by `launch.php`
- a raw Moodle web service token

## Minimal examples

### Claude connector URL

```text
https://your-moodle-site.example/webservice/mcp/server.php
```

### Manual bootstrap

```bash
curl "https://your-moodle-site.example/webservice/mcp/launch.php?format=json" \
  -H "Accept: application/json"
```

### List tools

```bash
curl "https://your-moodle-site.example/webservice/mcp/server.php" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Mcp-Method: tools/list" \
  -H "Mcp-Protocol-Version: 2025-03-26" \
  -d '{
    "jsonrpc": "2.0",
    "method": "tools/list",
    "params": {
      "limit": 50
    },
    "id": 1
  }'
```

### Call a tool

```bash
curl "https://your-moodle-site.example/webservice/mcp/server.php" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Mcp-Method: tools/call" \
  -H "Mcp-Protocol-Version: 2025-03-26" \
  -d '{
    "jsonrpc": "2.0",
    "method": "tools/call",
    "params": {
      "name": "core_webservice_get_site_info",
      "arguments": {}
    },
    "id": 2
  }'
```

## Discovery model

Discovery is not a flat dump of Moodle functions.

The plugin builds a cached harvested catalog from Moodle's external service registry and projects it into MCP tool definitions. Discovery responses include:

- `tools`
- `nextCursor`
- `groups`
- `coverage`
- `catalogVersion`

Each tool can include `x-moodle` metadata such as:

- `component`
- `domain`
- `mutability`
- `capabilities`
- `provenance`
- `transport`
- `eligibility`
- `risk`
- `surface`
- `workflow`
- `execution`
- `services`

That metadata exists so clients can do better than naive tool prompting. It enables better routing, safer confirmations, and clearer UX for high-risk or long-running operations.

## Security model

The connector is designed so that Moodle remains the authority.

- the plugin-owned connector service is synced from Moodle's `external_functions` table
- the connector service is restricted to explicitly allowed users
- bootstrap only works for users with `webservice/mcp:use`
- discovery is filtered before tools are shown
- execution re-checks context and capability at call time
- high-risk and destructive operations carry structured risk metadata
- transport sessions are isolated per user, context, and service
- session locks are released after auth so long-lived connector traffic does not block normal Moodle browsing

## Testing

### Local Docker runner

The repository includes a Docker-based runner that mirrors the `moodle-plugin-ci` path used in GitHub Actions.

MariaDB:

```bash
bash scripts/run-local-tests.sh
```

PostgreSQL:

```bash
bash scripts/run-local-tests.sh pgsql
```

Branch override:

```bash
MOODLE_BRANCH=MOODLE_405_STABLE bash scripts/run-local-tests.sh
```

Custom steps:

```bash
TEST_STEPS="phplint validate savepoints phpunit phpcs" bash scripts/run-local-tests.sh
```

Image override:

```bash
MARIADB_IMAGE=mariadb:12.3-ubi10-rc bash scripts/run-local-tests.sh
POSTGRES_IMAGE=postgres:16-alpine bash scripts/run-local-tests.sh pgsql
```

### Installed Moodle test site

If the plugin is mounted inside an installed Moodle test site, use that site's normal PHPUnit and plugin test workflow instead of the Docker runner.

## GitHub automation

### CI

GitHub Actions runs:

- a PHPUnit matrix across `MOODLE_402_STABLE` to `MOODLE_405_STABLE`
- both `mariadb` and `pgsql`
- a `Quality (MOODLE_405_STABLE)` lane for static quality checks
- a final `Branch Gate` job used by branch protection

### Delivery

Pushes to `dev` and `main` build a packaged plugin ZIP and upload it as a workflow artifact.

### Release

Successful CI on `main` auto-creates the matching `v<release>` tag when it does not already exist. That tag triggers the release workflow, which publishes the packaged ZIP as a GitHub Release asset.

Local packaging command:

```bash
bash scripts/package-release.sh
```

The release ZIP is packaged with the Moodle plugin directory name `mcp/`.

## Repository workflow

Current branch model:

- `dev` for active integration
- `main` as the protected release branch

Current protections:

- `dev` requires `Branch Gate`
- `main` requires `Branch Gate`
- `main` also requires PR review
- merged branches are auto-deleted

## Repository layout

```text
webservice/mcp/
├── classes/local/auth/         browser bootstrap, credentials, identity
├── classes/local/catalog/      harvest, schemas, workflow descriptors, coverage
├── classes/local/discovery/    eligibility and risk analysis
├── classes/local/stream/       replay and transport session persistence
├── classes/local/transport/    Streamable HTTP and SSE compatibility
├── classes/local/wrapper/      plugin-owned wrapper framework
├── db/                         capabilities, caches, install/upgrade
├── docker/                     local CI runner image and scripts
├── oauth/                      OAuth authorize/token/metadata endpoints
├── scripts/                    local test and release packaging helpers
├── tests/                      PHPUnit coverage
├── launch.php                  browser bootstrap endpoint
├── server.php                  primary transport entrypoint
└── sse.php                     SSE compatibility entrypoint
```

## Practical notes

- the connector is strongest when the target action already exists as a Moodle external function
- the wrapper framework exists so UI-only gaps can be added without abandoning the plugin-first model
- current wrapper implementation is intentionally concentrated on course authoring, where the upstream external surface is weakest for MCP-style operator workflows
- if you change supported Moodle behavior, verify it against the relevant Moodle source branch, not memory
