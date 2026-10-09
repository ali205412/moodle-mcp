# Files layer report (files-builder)

Nothing committed. All files pass `php -l`; my files pass phpcs (moodle standard) with 0 errors.

## Files

- `classes/local/files/`
  - `tools.php`: `describe()`, `handles()`, `is_mutating()`, `execute()` (dispatcher contract); argument validation against the schemas.
  - `tool_definitions.php`: titles, descriptions, input schemas.
  - `file_service.php`: `read_resource()` (resources contract), `store_draft_file()` (shared by every upload path), inline/URL uploads, save draft, delete, course image, user limits.
  - `file_reader.php`: resolve uri/url, file_read content blocks, resource contents, in-process activity-file reads, document conversion.
  - `lister.php`: file_list (file_browser walk plus activity files via `*_export_contents`).
  - `locator.php`: `moodle://file` URIs, on-site URL parsing, file_browser lookups, context-restriction check.
  - `tickets.php`: sign/redeem `dl`/`ul` tickets; service flag checks.
  - `endpoint.php`: CORS (transport origin allowlist), login, JSON errors and status mapping.
  - `download_handler.php`, `upload_handler.php`: endpoint logic, unit-testable.
  - `backup_service.php`, `limits.php`, `transfer_exception.php`.
  - `export_service.php`, `export_task.php` (adhoc task), `assign_export_downloader.php` (exposes `\mod_assign\downloader`'s file list): asynchronous exports.
- `pluginfile.php`, `upload.php`: endpoint scripts (`AJAX_SCRIPT`, `NO_MOODLE_COOKIES`, `NO_DEBUG_DISPLAY`).
- `tests/files_access_test.php`, `files_upload_test.php`, `files_tickets_test.php`, `files_backup_test.php`, `files_locator_test.php`, and the fixture `tests/fixtures/files_antivirus_scanner.php` (offline antivirus double).
- Appended only: a Files block at the end of `settings.php`, strings at the end of `lang/en/webservice_mcp.php`, and a "Files" section in `README.md` (including the nginx X-Accel-Redirect recipe).

## Exact tool names (14)

`file_list`, `file_read`, `file_get_download_url`, `file_create_upload_url`, `file_upload`, `file_upload_from_url`, `file_save_draft`, `file_delete`, `file_set_course_image`, `export_course_content`, `export_assignment_submissions`, `backup_create`, `backup_status`, `restore_from_draft`

- Mutating (write scope): `file_create_upload_url`, `file_upload`, `file_upload_from_url`, `file_save_draft`, `file_delete`, `file_set_course_image`, `backup_create`, `restore_from_draft`.
- Hidden when the service has `downloadfiles=0`: `file_get_download_url`, `export_course_content`, `export_assignment_submissions`.
- Hidden when the service has `uploadfiles=0`: `file_create_upload_url`, `file_upload`, `file_upload_from_url`.
- Guests get no tools.
- `_meta anthropic/alwaysLoad`: `file_list`, `file_read`, `file_get_download_url`, `file_create_upload_url`, `file_upload`. `file_read` also has `anthropic/maxResultSizeChars` 500000.
- destructiveHint: `file_delete`, `file_save_draft`, `restore_from_draft`.
- openWorldHint is true only for `file_upload_from_url`.
- No outputSchema is declared. Structured tools return `{content:[text JSON], structuredContent}`; `file_read` returns content blocks only.

## Tools

| Tool | Behaviour |
|---|---|
| file_list | `contextid`/`courseid`/`cmid`/`userid`/`draftitemid`, plus optional `component`, `filearea`, `itemid`, `filepath`. Also `recursive` (depth ≤ 10), `limit` (≤ 1000), `offset`. Entries have `type` context, area, folder or file, and carry `uri`, size, mimetype, author, license, writable. With `courseid` and `recursive`, each visible activity's files come from `{mod}_export_contents`, the same view as core_course_get_contents, so students see resource files; those entries carry `url`, `uri`, `cmid` and `activity`. Your own user context adds `limits` (max upload, quota, used). |
| file_read | Takes `uri` or `url` (pluginfile, webservice/pluginfile, tokenpluginfile, draftfile). Text is returned inline, paged by `offset`/`length` on UTF-8 boundaries. Images (png, jpeg, gif, webp) come back as image blocks; too-large images get a bigthumb preview. Audio comes back as audio blocks, other files as a base64 resource ≤ 5 MB. Anything larger gets a resource_link plus a download link. Requires the service's `downloadfiles` flag (so do `moodle://file` resources). Files `file_browser` hides are read in-process only if the activity's `*_export_contents` lists them for the user; other areas return a download link (size unknown); there is no HTTP self-request. `convert_to` pdf/txt uses `\core_files\converter`. |
| file_get_download_url | Signed link plus `curl` command. `forcedownload`, `preview` (thumb, bigthumb, tinyicon), and `ttl` (can only shorten). |
| file_create_upload_url | Allocates a draftitemid if none is given. Optional `filepath`, `filename`, `overwrite`, and `courseid`/`cmid`/`contextid` (sets the size limit). Returns url, maxbytes and curl examples (raw, multipart, chunk). |
| file_upload | `content_base64` or `content_text`, ≤ uploadinlinemaxbytes. |
| file_upload_from_url | Uses `\curl` with Moodle's security helper on, emulated redirects (max 5), a progress-abort size cap, and a 300 s timeout. |
| file_save_draft | Target must be `file_browser` is_writable. Default target is user/private. `merge` uses `file_merge_files_from_draft_area_into_filearea`; `replace` uses `file_save_draft_area_files`. Private files require `moodle/user:manageownfiles` and check the userquota like `core_user_add_user_private_files`. |
| file_delete | Needs `file_browser` is_writable. Refuses area roots and non-empty folders. |
| file_set_course_image | Requires `moodle/course:update`. Checks `course_overviewfiles_options` types and count, then purges the `course_image` cache. |
| export_course_content | Checks course access and `\core\content::can_export_context`, then queues an `export_task` (adhoc, as the user). Returns `{backupid: "export<id>", state: "queued"}`. Cron re-checks the same permissions and the token's context restriction, builds the zip with `zipwriter::get_file_writer` and stores it in the user's context (`webservice_mcp/exports`, itemid = export id). |
| export_assignment_submissions | Checks cm visibility, course access and `require_view_grades` (plus separate-groups rules for `groupid`), then queues an `export_task`. Cron re-checks all of these and writes the same files `\mod_assign\downloader` would select into a zip (`core_files\archive_writer` file mode), triggering `all_submissions_downloaded`. |
| backup_create | Takes one of `courseid`, `sectionid`, `cmid`, plus `include_users` and `anonymize` (each needs its capability; settings locked by config are refused). Builds a backup_controller with INTERACTIVE_YES and MODE_ASYNC, then finish_ui, then queues `asynchronous_backup_task` as the user. The backupid is embedded in the filename so the file can be found later. |
| backup_status | Owner only. Reports state queued, running, finished or failed, plus progress. A finished backup returns the .mbz `uri` and a download link; a finished restore returns `courseid` and `courseurl`. |
| restore_from_draft | Source is `draftitemid` (with `filename` if needed) or `uri`. `target` is new_course (`categoryid`, which needs `moodle/course:create`), existing_add or existing_delete (`courseid`). Needs `moodle/restore:restorecourse`, plus `moodle/restore:uploadfile` for draft sources and `moodle/restore:userinfo` if `include_users`. Extract, restore_controller (ASYNC, convert if needed), finish_ui, execute_precheck, queue `asynchronous_restore_task`. On precheck errors the skeleton course and temp files are cleaned up. |

## Asynchronous exports

- **Queue:** the export tools check permissions, write `state.json` (`webservice_mcp/exportstate`, itemid = export id, in the user's context) with state `queued`, and queue `\webservice_mcp\local\files\export_task` with `set_userid` = the user. They return `{backupid: "export<id>", state: "queued"}`.
- **Status:** `backup_status` with `backupid="export<id>"` reports `queued`, `running`, `finished` (plus `file{filename,size,uri,download{url,expires},curl}`) or `failed` (plus `error`). The state is read from the caller's own user context, so other users get "No export with that id belongs to you".
- **Build (cron):**
  - re-checks the token context restriction captured at queue time;
  - re-checks course access and `can_export_context`, or cm visibility, `require_view_grades` and group rules;
  - builds the zip into a request directory and stores it;
  - records `finished`, or `failed` with the message; the task never throws, so it never retries.
  - A purged export whose task runs later does nothing.
- **Purge:** `export_service::purge(?int $now = null): int` deletes exports whose `state.json` was created more than 24 hours ago (the state keeps its original `timecreated`).
- **MCP Tasks:** the export tools are not in `tasks.php` LONG_RUNNING. A legacy client that opts in with `params.task` gets the (fast) queueing call run by `run_tool` in cron, which queues exactly one export, so nothing is queued twice.
- **Reading:** `file_read` and `file_get_download_url` accept the export uri for its owner (read straight from storage; links use the `export` ticket kind). The context-restriction check exempts the owner's own export area, as it does drafts.
- `file_read` and download links still require the service's `downloadfiles` flag; the export tools check it at queue time.

## Endpoints

### `/webservice/mcp/pluginfile.php?ticket=…`
- Methods: GET and HEAD; OPTIONS for preflight.
- The ticket kind is `file`, `course_content` or `assign_all`.
- Files are served by `file_pluginfile()`, which gives Range, ETag/304 and X-Sendfile. The user's own drafts go through `send_stored_file()` after a `file_browser` check and are **always** sent as attachments (as core `draftfile.php`); `forcedownload=false` is only honoured for areas whose `file_pluginfile()` callbacks decide.
- Every response from `serve()` carries `X-Content-Type-Options: nosniff` and `Content-Security-Policy: sandbox`.
- Ticket kind `export` (claim `fid`) serves a finished export zip with `send_stored_file(..., forcedownload)`, only if the stored file is in the ticket user's own `webservice_mcp/exports` area; otherwise 404. Nothing is generated while streaming, so X-Accel-Redirect applies to exports too.

### `/webservice/mcp/upload.php?ticket=…[&filename=][&overwrite=1]`
- PUT or POST with a raw body, streamed to disk in 1 MB chunks; 413 over the limit.
- POST multipart with one or many files.
- Chunked uploads use `Content-Range: bytes s-e/total`. A chunk that doesn't start at the received size gets 416.
- Partial data is keyed by user, draft, folder, file name and total, so a new link for the same draft file resumes the upload. Stale partials in `$CFG->tempdir/webservice_mcp_uploads/<userid>/` are purged after a day.
- Per-user caps on unfinished chunked uploads: at most `uploadmaxpartials` open (429 `toomanypartials`) holding at most `uploadmaxpartialbytes` declared bytes (default twice the upload limit; 413 `partialquota`). A declared total above the upload limit is refused (413) before any data is read.
- Same-named files are auto-renamed unless overwrite is set.
- Antivirus scanning, the draft-area rate limit and the `draft_file_added` event apply as for core uploads.
- Response: `201 {complete:true, draftitemid, files:[{filename,filepath,size,mimetype,contenthash,uri,renamedfrom?}]}`, or `202 {complete:false, received, total}` for intermediate chunks.

### Shared rules
- Errors are JSON `{error, errorcode}` with status 400, 401, 403, 404, 405, 413, 416, 422, 429, 503 or 507.
- Tickets are only minted when `$ctx->credentialid` (the family key) is set (otherwise 403 `nocredential`), and redeem rejects tickets without `c`.
- Ticket claims: `u` (user), `c` (family key from `$ctx->credentialid`), `s` (service), `rc` (restricted context id), `exp`, plus kind data.
- Redeeming re-checks:
  - the signature, purpose and expiry;
  - that the user exists, is confirmed, and is not deleted, suspended, guest, nologin or password-expired;
  - maintenance mode (unless `moodle/site:maintenanceaccess`);
  - `credential_manager::family_active(c)`;
  - when the ticket names a service: `service_access::problem(user, service, family)`, the same service checks as transport authentication: enabled; restricted users with their validuntil and IP; requiredcapability; for OAuth families, that OAuth is enabled and the client is not revoked. Revocation problems give 401, others 403 with the problem code;
  - the service's `downloadfiles`/`uploadfiles` flag;
  - `webservice/mcp:use` in the rc context.
- After redeeming: `enrol_check_plugins`, `session_manager::set_user`, `external_api::set_context_restriction(rc)`.
- The target context must be inside rc; the user's own draft area is exempt.

### Error responses (both endpoints)

Body: `{"error": "<message>", "errorcode": "<code>"}`. `debuginfo` is added only at developer debugging level.

| Status | When (errorcode) |
|---|---|
| 400 | Malformed request: `invalidrange`, `incompletechunk`, `filenamerequired`, `uploaderror`, `nofile`, `invalidparameter`, other `moodle_exception`s |
| 401 | Ticket missing its credential family claim, tampered, expired or for the other purpose; user deleted; credential family revoked or expired; restricted context gone (`invalidticket`) |
| 403 | Ticket requested without a connector credential family (`nocredential`, at mint time); origin not allowed (`originnotallowed`); user suspended, unconfirmed, nologin or guest (`useraccessdenied`); password expired; missing `webservice/mcp:use`; service files flag off (`servicefilesdownloadfiles`, `servicefilesuploadfiles`); `required_capability_exception`, `require_login_exception` or `restricted_context_exception` from `file_pluginfile()` or the restriction check (`nopermissions`) |
| 404 | `filenotfound`, `invalidrecord`, missing record, `nosubmission` (assignment zip with no files) |
| 405 | Upload endpoint called with a method other than PUT, POST or OPTIONS (`methodnotallowed`, sends an `Allow` header) |
| 413 | Over the user's limit (`maxbytes`, `maxbytesfile`), quota (`userquotalimit`, `maxareabytes`), unfinished-upload space (`partialquota`), or PHP multipart ini limit |
| 416 | A chunk does not start at the received size (`rangemismatch`; the message gives the expected offset) |
| 422 | Antivirus found an infection (`virusfound`) |
| 429 | Draft upload rate limit (`maxdraftitemids`); too many unfinished chunked uploads (`toomanypartials`) |
| 500 | Unexpected `Throwable` (body says "Internal error."; details go to developer debugging), or the upload buffer cannot be opened |
| 503 | Site in maintenance mode and the user lacks `maintenanceaccess` |
| 507 | Disk write failed while streaming an upload |

Success on the download endpoint is the file itself: 200, 206 for Range requests, or 304 on a matching ETag (handled by core `readfile_accel`).

### Chunked (resumable) upload protocol

1. Call `file_create_upload_url` (optionally with `draftitemid`, `filepath`, `filename`). It returns `url`, `draftitemid` and `maxbytes` (−1 means unlimited).
2. Send each chunk in order:
   `curl -T chunk -H "Content-Range: bytes <start>-<end>/<total>" "<url>&filename=<name>"`
   - `total` must not exceed `maxbytes`, otherwise 413.
   - The header must match `bytes \d+-\d+/\d+`, with end ≥ start and end < total, otherwise 400 `invalidrange`.
3. Each intermediate chunk returns `202 {"complete": false, "received": end+1, "total": total, "draftitemid": N}`.
4. A chunk whose start ≠ bytes already received gets 416 `rangemismatch`, with the expected offset in the message. Resume from there.
5. A short body (fewer bytes than the range declares) is truncated back and gets 400 `incompletechunk`.
6. When end+1 == total, the assembled file goes through the normal checks (size, antivirus, rate limit), is stored in the draft area, and the endpoint returns `201 {"complete": true, "draftitemid": N, "files": [...]}`.
7. Where partial data lives:
   - The buffer is `$CFG->tempdir/webservice_mcp_uploads/<sha1(user|draft|path|name|total)>.part`, appended under `flock`.
   - Because it is keyed by user and target rather than by the link, a fresh upload link for the same draftitemid, folder and file name continues an interrupted upload, even after the first link expires.
   - Partials untouched for 24 hours are deleted the next time anyone sends a chunk.

## Settings (Files heading)

`downloadticketttl` 900 s, `uploadticketttl` 3600 s (both max 1 day; clients can only shorten), `inlinetextmaxbytes` 256 KB (max 4 MB), `inlinebinarymaxbytes` 5 MB (max 20 MB), `uploadinlinemaxbytes` 15 MB (max 50 MB), `uploadfromurlmaxbytes` 100 MB, `uploadmaxbytes` 2 GB (used only when site and course limits are 0; 0 = default), `uploadmaxpartials` 5, `uploadmaxpartialbytes` 0 (= twice the upload limit).

## Tests

- New: 5 test files with 37 test methods (I didn't count assertions separately).
- Moodle 4.2.11 / MariaDB 10.11, with asynchronous exports: `OK (293 tests, 1823 assertions)`.
- Moodle 4.5.15 / PostgreSQL (isolated compose project `filesx`), with asynchronous exports: `OK (293 tests, 1826 assertions)`.
- Export regression tests (`tests/files_export_test.php`): builds in cron (assignment and course content), permission re-check at build time (failed status), owner isolation (status, read, download), purge (and a purged queued export builds nothing), up-front permission checks.
- No test does outbound HTTP:
  - The antivirus test uses the offline scanner double `antivirus_mcpfilestest`, whose incident report skips the geoplugin lookup.
  - The loopback fallback is gone, so no file test mocks or makes HTTP requests.
  - `file_upload_from_url` has no test that fetches.

## Contract notes and decisions

- **Family binding:** tickets bind `$ctx->credentialid`, which is now the family key, and are checked with `family_active()`. They survive OAuth refresh rotation and die when the family is revoked or expired. Test: `test_ticket_survives_token_rotation_but_not_family_revocation`.
- **Upload limit:** `limits::max_upload_bytes()` applies a nonzero site or course `maxbytes` as core does (the smaller wins). When both are 0 it uses the plugin's `uploadmaxbytes`, which defaults to 2 GB in code with no upgrade step, instead of PHP's limit: production's PHP limit is 2 MB, which would cap streamed uploads, and PHP ini limits only affect multipart anyway. It is never unlimited except for `moodle/course:ignorefilesizelimits`. Test: `test_upload_limit_defaults_to_plugin_setting_not_php_limit`.
- **Restriction:** file tools call `external_api::set_context_restriction($ctx->restrictedcontext)` when it is set, and explicitly check the context is inside it via `locator::check_restriction`. A course-restricted token cannot touch user private files, but can always use its own draft area.
- **Mimetype:** client-supplied mimetypes are ignored; Moodle detects them.
- **No DB or cache changes.**

## Limitations

- **No inline read for some areas:** areas neither `file_browser` nor an activity's export listing covers (blocks, question, grading areas) return a download link from `file_read` rather than content, because `file_pluginfile()` ends the request and cannot run in-process.
- **Exports wait for cron.** The export tools return immediately and cron builds the zip; status and download come from `backup_status`. Exports (zips and state) are deleted after 24 hours by `export_service::purge()`; I asked auth-fixer to call it from `classes/task/cleanup.php`.
- **Multipart uploads are capped by php.ini**; use PUT for big files.
- **Backups and restores need cron** to run their adhoc tasks.
- **Endpoints not tested over real HTTP:** there is no local web server. The endpoint logic is unit-tested directly; the PHP scripts are thin wrappers.

## Recommended nginx X-Accel-Redirect setup (do not edit servers; this is for the operator)

PHP-FPM has 5 workers in production, so file bodies should be sent by nginx. In `config.php`:

```php
$CFG->xsendfile = 'X-Accel-Redirect';
$CFG->xsendfilealiases = ['/dataroot/' => $CFG->dataroot];
```

In the nginx server block:

```nginx
location /dataroot/ {
    internal;
    alias /path/to/moodledata/;   # must equal $CFG->dataroot, trailing slash required
}
```

With this in place, `file_pluginfile()` / `send_stored_file()` emit `X-Accel-Redirect`, and the worker is released immediately.

- **Uploads:** nginx buffers request bodies to disk before PHP runs (default `fastcgi_request_buffering on`), so slow uploads don't hold workers. Keep `client_max_body_size` (16G now) at least as large as the biggest upload allowed. For chunked uploads, each chunk must fit under it.
- **Exports:** course content and assignment zips are built by cron into stored files, so their downloads use X-Accel-Redirect like any other file and hold no worker.

