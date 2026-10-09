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

The OAuth issuer is `https://your-moodle-site.example/webservice/mcp`. MCP clients look for its metadata at,
in order, `/.well-known/oauth-authorization-server/webservice/mcp`,
`/.well-known/openid-configuration/webservice/mcp`, and
`/webservice/mcp/.well-known/openid-configuration`. Only the last one is served by the plugin without web
server changes; it is a minimal OpenID document (this server issues no ID tokens, `jwks_uri` is an empty key
set). For clients that only probe the root-level RFC 8414 / RFC 9728 locations, add rewrites, for example
in nginx:

```nginx
location = /.well-known/oauth-authorization-server/webservice/mcp {
    rewrite ^ /webservice/mcp/.well-known/oauth-authorization-server/index.php last;
}
location = /.well-known/openid-configuration/webservice/mcp {
    rewrite ^ /webservice/mcp/.well-known/openid-configuration/index.php last;
}
location = /.well-known/oauth-protected-resource/webservice/mcp/server.php {
    rewrite ^ /webservice/mcp/.well-known/oauth-protected-resource/index.php last;
}
```

### OAuth token revocation (RFC 7009)

```text
https://your-moodle-site.example/webservice/mcp/oauth/revoke.php
```

### Bulk access for users (admin keys and OAuth pre-approval)

Users with `webservice/mcp:issueforothers` (site admins implicitly) can provision access in bulk at
`/webservice/mcp/admin/keys.php` (Site administration > Server > Web services > Model Context Protocol >
MCP access for users) or from the CLI:

```bash
# Issue read/write keys valid 90 days to a cohort; tokens are written once to a 0600 CSV.
php webservice/mcp/cli/keys.php --issue --cohort=12 --label="Spring pilot" --scope=write \
    --expires-days=90 --output=/root/mcp-keys.csv
php webservice/mcp/cli/keys.php --list --label="Spring pilot"
php webservice/mcp/cli/keys.php --revoke --label="Spring pilot"
# Let users connect claude.ai / Claude Desktop without the consent screen.
php webservice/mcp/cli/keys.php --preapprove --all-mcp-users --scope=write
php webservice/mcp/cli/keys.php --unpreapprove --usernames=alice,bob
```

Selectors: `--userids`, `--usernames`, `--emails`, `--idnumbers`, `--file=users.csv`, `--cohort`, `--course` (+ `--role`), `--all-mcp-users`. The page also accepts an uploaded CSV and users chosen in Site administration > Users > Bulk user actions ("Generate MCP keys or pre-approve MCP access").
The CSV holds `claude mcp add` commands and `.mcp.json` snippets per user. Keys are stored hashed and shown
only once. Users see and can revoke admin-issued keys on their Connected apps page.

### Enterprise Managed Authorization (preview)

With `emaenabled` on, the token endpoint accepts `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer`
identity assertions signed by an issuer listed in `ematrustedissuers` (keys from the issuer's OpenID
configuration). Only metadata-document or pre-registered clients may use it; users are matched by
`emausermatchfield` with the auth_oidc token table as a fallback.

### Connected apps

Users can see and revoke the applications connected to their account at
`/webservice/mcp/connections.php`; users with `webservice/mcp:manageconnectors` can review every user's
connections at `/webservice/mcp/connections.php?all=1`.

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

Open `https://your-moodle-site.example/webservice/mcp/launch.php?format=json` in a signed-in browser and
confirm. Issuing a credential requires a POST with the session key, so it cannot be triggered by a link or
from another site.

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

- a PHPUnit matrix for `MOODLE_405_STABLE`
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

## Files

Connector clients get file, export, backup and restore tools (`file_*`, `export_*`, `backup_*`, `restore_*`). Every
call runs as the signed-in user, inside the token's context restriction, and is checked by Moodle itself:
listing and inline reads go through `file_browser`, downloads through `file_pluginfile()`, writes through the
user's draft area and `file_browser` write checks, backups and restores through the backup controllers.

| Tool | Purpose |
| --- | --- |
| `file_list` | Browse contexts, areas and folders (`courseid` + `recursive` lists course and activity files); includes upload limits and quota for your own areas. |
| `file_read` | Read by `moodle://file/...` URI or any on-site file URL. Text inline (paged), images/audio as content blocks, other files as base64 up to the inline limit, otherwise a download link. Areas `file_browser` does not cover are read in-process when the activity lists the file for the user (as `core_course_get_contents` does); anything else gets a download link (no HTTP self-requests). Optional `convert_to` pdf/txt via document converters. |
| `file_get_download_url` | Signed, short-lived link for any file the user can download (any size, Range/resume). |
| `file_create_upload_url` | Signed upload link into the user's draft area (any size). |
| `file_upload` / `file_upload_from_url` | Small inline uploads / server-side fetch of a public URL (with Moodle's cURL security rules). |
| `file_save_draft` | Merge or replace a draft area into a writable file area (private files obey the user quota). |
| `file_delete` | Delete a file or empty folder the user may manage. |
| `file_set_course_image` | Set the course overview image from a draft. |
| `export_course_content` / `export_assignment_submissions` | Queue "Download course content" / "Download all submissions" zips, built in the background (adhoc task, as the user, permissions re-checked); `backup_status` with the returned `export...` id gives the zip's uri and a download link. Exports are deleted after 24 hours (`export_service::purge()`). |
| `backup_create` / `backup_status` / `restore_from_draft` | Asynchronous backups and restores (adhoc tasks; cron must run). |

Uploaded files land in the draft area and return a `draftitemid`; pass it to any Moodle function that accepts
draft files (assignment submissions, forum attachments, `core_user_add_user_private_files`, `wrapper_course_add_module`, ...).

### Download endpoint: `/webservice/mcp/pluginfile.php?ticket=...`

- `GET`/`HEAD`; `OPTIONS` for CORS preflight (origins from the *Allowed transport origins* setting).
- Single files are served by `file_pluginfile()` (or `send_stored_file()` for the user's own drafts) with ETag/304,
  `Range` byte serving and X-Sendfile when configured. Finished export zips are stored files too, served the same way.
- Every download carries `X-Content-Type-Options: nosniff` and `Content-Security-Policy: sandbox`; draft files are
  always sent as attachments, as core `draftfile.php` does.
- Links are only issued to connector connections. The ticket binds user, connector credential family, external
  service and context restriction; it is rejected (401/403) once it expires, the credential family is
  revoked/expired, the user is suspended/deleted, the service disables
  `downloadfiles`, or the user loses `webservice/mcp:use`.
- Errors are JSON `{"error": "...", "errorcode": "..."}` with status 400, 401, 403, 404, 413, 429 or 503.

### Upload endpoint: `/webservice/mcp/upload.php?ticket=...`

- `PUT` (or `POST`) raw body: `curl -T ./file.pdf "<url>&filename=file.pdf"`. The body is streamed to disk in 1 MB
  chunks and refused with 413 above the user's limit. The name comes from the ticket, `filename=` or `Content-Disposition`.
- `POST multipart/form-data` with one or many files: `curl -F "file=@./a.pdf" -F "file2=@./b.png" "<url>"`
  (subject to PHP `upload_max_filesize`/`post_max_size`; prefer `PUT` for big files).
- Resumable: send chunks with `Content-Range: bytes start-end/total` in order. Partial data is kept under
  `$CFG->tempdir/webservice_mcp_uploads` (removed after completion or a day of inactivity); a chunk that does not
  start at the received size gets 416 with the expected offset. If the link expires mid-upload, get a new one for the
  same `draftitemid`, folder and file name and continue from the received offset.
- Same-named files are renamed (`name (1).ext`) unless the ticket or `&overwrite=1` asks to overwrite.
- Antivirus scanning, draft upload rate limits and the `draft_file_added` event apply as for core uploads.
- Response: `201 {"complete": true, "draftitemid": N, "files": [{"filename", "filepath", "size", "mimetype", "contenthash", "uri"}]}`
  (`202 {"complete": false, "received": N}` for intermediate chunks).
- Upload limit: the site `maxbytes` and course limit as in core (smaller wins); when both are 0, the plugin's
  `uploadmaxbytes` (default 2 GB).
  PHP's `upload_max_filesize`/`post_max_size` don't apply: uploads are streamed bodies, not multipart forms. The web
  server must accept large bodies (nginx `client_max_body_size`).
- Unfinished chunked uploads are capped per user: `uploadmaxpartials` open uploads (default 5, else 429) holding at most
  `uploadmaxpartialbytes` declared bytes (default twice the upload limit, else 413). A declared total above the upload
  limit is refused with 413 before any data is read.

### Settings (Site administration > Plugins > Web services > Model Context Protocol > Files)

`downloadticketttl` (900 s), `uploadticketttl` (3600 s), `inlinetextmaxbytes` (256 KB), `inlinebinarymaxbytes` (5 MB),
`uploadinlinemaxbytes` (15 MB), `uploadfromurlmaxbytes` (100 MB), `uploadmaxbytes` (2 GB),
`uploadmaxpartials` (5), `uploadmaxpartialbytes` (0 = twice the upload limit). Ticket lifetimes are capped at one day; clients can
request shorter links but not longer ones. The connector's external service must have *Can download files* (for
`file_read`, file resources, links and exports) and *Can upload files* enabled for those tools to appear and work.

### Recommended nginx X-Accel-Redirect setup

PHP-FPM workers are scarce, so let nginx send file bodies. In `config.php`:

```php
$CFG->xsendfile = 'X-Accel-Redirect';
$CFG->xsendfilealiases = ['/dataroot/' => $CFG->dataroot];
```

and in the nginx server block:

```nginx
location ^~ /dataroot/ {
    internal;
    alias /path/to/moodledata/;   # must match $CFG->dataroot, with a trailing slash
    # nginx keeps only a few upstream headers on X-Accel-Redirect (Content-Type, Content-Disposition,
    # Cache-Control, ...), so the plugin's nosniff header is lost unless nginx adds it here.
    add_header X-Content-Type-Options nosniff always;
}
```

Enable nginx first, then `config.php`: Moodle sending `X-Accel-Redirect` before nginx knows the internal location
breaks every file download. `^~` keeps regex locations (e.g. the `.php` handler) from matching file paths.

Uploads are buffered to disk by nginx before PHP runs, so slow clients do not hold workers; keep
`client_max_body_size` at least as large as the biggest upload you allow. Exports (course content and
assignment zips) are built by cron into stored files, so their downloads go through X-Accel-Redirect as well.
