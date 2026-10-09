# Phase 11 final pre-production review

Scope: the working-tree change set against HEAD (plugin 0.8.1 / 2026050101 -> 0.9.0 / 2026101004). Target: production Moodle 4.5.6 on mysqli, PHP 8.1, nginx, 5 FPM workers.
This was a read-only review. Moodle behaviour was checked against `tmp/moodle` (4.5) and `git -C tmp/moodle show v4.2.0:...`.
I traced every CONFIRMED item from its entry point to the sink.

Owner policy is respected throughout. Nothing below is reported because a tool is "high risk", because a declared capability is merely informational, or because confirmation is off by default.

---

## CONFIRMED findings (ranked)

### 1. HIGH: stored XSS on the Moodle origin through draft download tickets
- **Where:** `classes/local/files/download_handler.php:111` calls `send_stored_file($file, 0, 0, $forcedownload, ...)` for drafts. `$forcedownload` comes from the ticket claim `fd`. The client sets it through `file_get_download_url{forcedownload:false}` (`classes/local/files/tools.php:173-176`).
- **What core does:** core never serves a draft inline. `tmp/moodle/draftfile.php:88` uses `send_stored_file($file, 0, false, true, ...) // force download - security first!`. `send_file()` only forces SVG to download (`lib/filelib.php:2576`), so `text/html` goes out inline.
- **Exploit:**
  1. A student uses `file_upload` to put `x.html` into their own draft area.
  2. They call `file_get_download_url{uri, forcedownload:false}`.
  3. They send `https://learn.../webservice/mcp/pluginfile.php?ticket=...` to a teacher or admin.
  4. The page runs JS on the Moodle origin. The victim's browser sends cookies on same-origin `fetch()`; `NO_MOODLE_COOKIES` only applies to the ticket request itself. The script reads a sesskey and acts as the victim, which means admin takeover.
- **Fix:** in the draft branch, always force download: `send_stored_file($file, 0, 0, true, ['preview' => $preview])`. Defence in depth: send `X-Content-Type-Options: nosniff` and `Content-Security-Policy: sandbox` at the top of `serve()`.

### 2. HIGH: stored XSS through the badge wrappers (teacher to admin)
- **Where:** `classes/local/wrapper/badge_record_builder.php:45-58` (`badge_payload`) copies `name`, `version`, `description`, `imageauthor*`, `imagecaption`, `issuer*` raw. `classes/local/wrapper/badge_service.php:230-236` (`save_alignment`) does the same for `targetname`, `targeturl`, etc. The tool schema declares `payload` as a free `object` (`builtin_definitions.php:365,373`), so nothing cleans these values.
- **Why it matters:** core cleans these fields only in the forms. `badges/classes/form/badge.php:59-121` applies PARAM_TEXT, PARAM_NOTAGS or PARAM_URL, and `badges/alignment_form.php:49-63` does the same. `badge::create_badge()` and `update()` store whatever they are given. Several outputs render the values unescaped:
  - `badges/renderer.php:191` (`$badge->description` into a `dd`);
  - `badges/templates/issued_badge.mustache:220` (`{{{badgedescription}}}`);
  - the alignment link text and href.
- **Exploit:** an editingteacher (holding `moodle/badges:createbadge`) calls `wrapper_badge_create_badge{courseid, payload:{description:"<script>...</script>"}}`, or saves an alignment with `targeturl:"javascript:..."`. The script runs for an admin viewing the badge and for anyone opening the issued-badge page.
- **Fix:** `clean_param` each field with the type the form uses:
  - PARAM_TEXT: name, version, imageauthorname, imagecaption, targetname, targetframework, targetcode;
  - PARAM_NOTAGS: description, issuername;
  - PARAM_URL: imageauthorurl, issuerurl, targeturl;
  - `validate_email` for the email fields.

### 3. HIGH (correctness): tool errors leak open DB transactions
- **Where:** `classes/local/mcp/dispatcher.php` `call_tool` (~:305) and `run_tool` (~:334) catch `\Throwable` and return `error_result` without `abort_all_db_transactions()`. The legacy path does abort (`transport/server.php:176`, `sse_controller.php:105`).
- **How it happens:** many core externals throw inside a delegated transaction, for example `core_user_create_users` (`user/externallib.php:150` then `:165`). `moodle_transaction` has no destructor, so the transaction stays open.
- **Failure in cron:** the task's final `update_record` (`tasks.php:~282`) runs inside the leaked transaction, and cron then rolls it back (`lib/classes/cron.php` "Task left transaction open"). The task stays `working` forever: the retry fails to claim it, and `tasks/get` says "Running" until the TTL purge.
- **Failure over HTTP:** every later write in the request is silently rolled back at `dispose()`. That includes the inline `tasks/result` status write and any audit or log rows.
- **Fix:** call `abort_all_db_transactions()` before `error_result()` in each catch. Alternatively, put it once in a catch in `tool_runner::call_function` and `wrapper\manager::execute`.

### 4. MEDIUM (deploy risk): the in-place token hashing in the upgrade is not idempotent
- **Where:** `db/upgrade.php:276-282` hashes every `credential.token` and `oauth_code.code` with SHA-256. The savepoint comes much later, at `:304`.
- **Why it matters:** plaintext tokens are 64 hex characters, the same shape as a SHA-256 hex digest, so a re-run cannot tell them apart. If anything between the loop and the savepoint throws, the step re-runs and double-hashes. That would lock out all 844 credentials until every user reconnects. Candidates include the `external_services` update, `set_config`, `sync_service()` (wrapped, but a fatal error is not caught) or a killed CLI.
- **Second window:** web requests are not blocked between the file swap and the upgrade run. Lib/setup only blocks while `upgraderunning` is set. The new code then hits the old schema (no `familyid` and so on) and may store hashed tokens that the migration hashes again.
- **Fix:**
  - Move the hash loop into its own version step, with its savepoint immediately after it.
  - Guard it with a config flag (`if (!get_config('webservice_mcp','tokenshashed')) {...; set_config(...,1);}`).
  - Deploy under `admin/cli/maintenance.php --enable`.

### 5. MEDIUM: anonymous requests to token and revoke trigger client-metadata fetches and permanent client rows
- **Where:** `classes/local/oauth/service.php:540-541` (`authenticate_client`) falls back to `resolve_client_for_authorization()`, and from there to `client_registry::load_metadata_document_client()`. That does a curl GET plus an insert or update. Both `oauth/token.php` and `oauth/revoke.php` are unauthenticated.
- **Conflict with stated intent:** `oauth/authorize.php:52` says anonymous requests must not trigger metadata fetches.
- **Exploit:** each `POST /oauth/token client_id=https://attacker/<random>` causes an outbound fetch, a blind SSRF; the curl security helper still blocks private ranges. With a valid document it also inserts a permanent `isdynamic=0` client row, which `classes/task/cleanup.php` never purges. Nothing rate-limits this, so the table can grow without bound.
- **Fix:** remove lines 540-541 so the token and revoke endpoints use `get_client()` only. Metadata clients are persisted when the user goes through authorize.

### 6. MEDIUM: raw (non-connector) external tokens can run file tools through the task branch
- **Where:** `classes/local/mcp/dispatcher.php:279-283` creates the task before the `file_tools::handles && !$this->ctx->connector` rejection at :289. `dispatcher::run_tool` (:311-313) then executes file tools with no connector check.
- **Exploit:** a user holding any core WS token (mobile app or another service) plus `webservice/mcp:use` sends legacy-era `tools/call{name:"file_delete"|"backup_create"|"restore_from_draft"|"file_get_download_url", task:{}}`. The file tool runs even though the token's service does not offer it; Moodle file permissions still apply. Three side effects:
  - Tickets minted this way carry `c=null`, so `tickets::redeem` skips `family_active` (`tickets.php:~151`). Deleting the token does not kill the links.
  - The task row gets `familyid=''`. `assert_still_authorised` then skips revocation.
  - All raw tokens of that user share the `''` family, so they can see each other's tasks.
- **Fix:**
  - Move the connector rejection above the task branch in `call_tool`.
  - Add the same guard in `run_tool`.
  - Have `tickets::redeem` reject tickets without `c`, or never mint tickets when `credentialid` is null.

### 7. MEDIUM (availability): unlimited-size and unlimited-count chunked uploads
- **Where:** `classes/local/files/limits.php:78-90` returns -1 (unlimited) when `$CFG->maxbytes` and course `maxbytes` are both 0. 0 is Moodle's default. Core instead falls back to PHP's limits (`get_max_upload_file_size`, `moodlelib.php:~6389`).
- **Why the disk fills:** `classes/local/files/upload_handler.php:142-176` accepts any declared `total`. Partials are keyed per user, draft, path, filename and total, are kept for 24h, and have no per-user count or byte cap. nginx `client_max_body_size` only limits each chunk.
- **Exploit:** any student script fills moodledata.
- **Fix:**
  - When the site and course limits are 0, fall back to `get_max_upload_file_size()` or a plugin `uploadmaxbytes` setting.
  - Cap open partials per user (count and bytes).
  - Production check: what is `$CFG->maxbytes` on learn.aspireschool.org?

### 8. MEDIUM (production UX): logging in can silently revoke all of a user's MCP connections
- **Where:** `classes/observer.php:38-40` revokes every credential on `\core\event\user_password_updated`.
- **Why it misfires:** in 4.5 that event also fires when login re-hashes a legacy bcrypt hash or rotates a pepper (`lib/moodlelib.php:4307` then `update_internal_user_password`, `:4431-4447`). The first web login after such a re-hash disconnects the user's Claude. This affects manual-auth accounts; OIDC accounts are `AUTH_PASSWORD_NOT_CACHED` and are not affected.
- **Fix:** mirror core. Revoke only when `!empty($CFG->passwordchangetokendeletion)`, or only when it is an admin reset (`$event->userid != $event->relateduserid`).

### 9. MEDIUM-LOW: resources, prompts, the app tool and completions bypass the token's service function list
- **Where:** `classes/local/mcp/resources.php:249` calls `tool_runner::call_function()` with no `function_in_service` check. `apps.php` and `prompts.php` reuse the same path.
- **Impact:** a narrow-service raw token can read `moodle://course/{id}/participants`, grades, modules and users through functions outside its service. So can a connector service where an admin deliberately removed a function. Moodle's permission checks still apply, so this is a service-restriction bypass, not a permission bypass.
- **Fix:** route `resources::call` through `tool_runner::run()`, which checks service membership, or check `function_in_service($fn, $ctx->serviceid)` first. Optionally offer resources, prompts and apps only when `ctx->connector`, as memories already are.

### 10. MEDIUM-LOW: context restriction is not applied to the app tool in cron and leaks between cron tasks
- **Where:** `classes/local/mcp/tasks.php:~291-296` calls `dispatcher::run_tool`, which reaches `apps::execute` before anything calls `external_api::set_context_restriction`. In a fresh cron process the restriction is null, which means system scope (`lib/external/classes/external_api.php:501-503`). Separately, `tool_runner::run` sets the static restriction and never resets it.
- **Exploit:** a credential restricted to course A queues a task `moodle_explorer{view:"course",courseid:B}` and receives course B's contents. The data is still limited by the user's own permissions. In the other direction, later adhoc tasks in the same cron run inherit course A's restriction and fail with `restricted_context_exception`.
- **Fix:** in `tasks::execute`, call `external_api::set_context_restriction($ctx->restrictedcontext)` before `run_tool` and reset it to null in `finally`.

### 11. MEDIUM-LOW: inline file reads ignore the service's `downloadfiles=0`
- **Where:** `file_read` (`files/tools.php:136` to `file_reader::read_tool`) and `resources/read moodle://file/...` (`mcp/resources.php:145,170` to `file_service::read_resource`) return file bytes without `tickets::require_service_flag(..., 'downloadfiles')`. Download URLs do check it. Core `webservice/pluginfile.php` refuses all bytes when the flag is off.
- **Fix:**
  - Call `require_service_flag` in both paths.
  - Add `file_read` to `NEEDS_DOWNLOAD`.
  - Gate `moodle://file` resources on `ctx->connector`; otherwise tickets are minted with `c=null`, the same issue as #6.

### 12. MEDIUM-LOW: badge wrappers ignore the site badge switches
- **Where:** `classes/local/wrapper/badge_service.php`. Only create goes through `creation_context()` (:388), which checks `enablebadges` and `badges_allowcoursebadges`. Update, message, delete, duplicate, related, alignment, award and revoke do not.
- **What core does:** `badges/award.php`, `edit.php`, `related.php` and `alignment.php` all refuse when badges are disabled.
- **Impact:** an admin disables badges, but teachers can still award and edit them through MCP.
- **Fix:** add one `require_badges_enabled($badge)` guard, used by every method that loads a badge.

### 13. LOW-MEDIUM: `completion/complete` leaks activity names in courses the user cannot access
- **Where:** `classes/local/mcp/completions.php:94-112` calls `get_fast_modinfo($anycourseid, $userid)` and filters on `$cm->uservisible`. `uservisible` does not check course access or visibility, and `mod/*:view` is granted to the `user` archetype.
- **Exploit:** iterate `context.arguments.courseid` to use it as a substring oracle over activity names in hidden or unenrolled courses. The `userid` branch (:121-135) also lacks `can_access_course` and group mode checks, and both branches ignore the restricted context.
- **Fix:** skip a course unless `can_access_course()` passes and it is inside `ctx->restrictedcontext`, or limit completion to `enrol_get_my_courses()`.

### 14. LOW (4.2 compatibility): `wrapper_badge_update_badge` is fatal on Moodle 4.2
- **Where:** `badge_record_builder.php:62` calls `$existing->get_badge_tags()`, which is absent in v4.2.0 `badges/classes/badge.php` (added in 4.3). Production 4.5 is not affected.
- **Fix:** guard with `method_exists`, falling back to `core_tag_tag::get_item_tags_array('core_badges','badge',$id)`.

### 15. LOW (info leak with debug off): raw `Throwable::getMessage()` returned to clients
- **Where:** `dispatcher::error_result` (~:445), `tasks::execute` (~:301-303) and `wrapper/discovery_service.php:201` (`strip_tags($exception->getMessage())` for non-moodle exceptions).
- **Impact:** PHP `TypeError`/`Error` messages contain absolute server paths and line numbers. This bypasses the "Internal error" masking in `transport/server.php` `generate_error`.
- **Fix:** for non-`moodle_exception` errors, use "Internal error" unless `debugging('', DEBUG_DEVELOPER)`.

### 16. LOW (availability on 5 workers): `tasks/result` sleeps for up to 20s
- **Where:** `classes/local/mcp/tasks.php:166-171` (`sleep(1)` up to 20 times while cron runs the task).
- **Impact:** five parallel polls occupy the entire FPM pool.
- **Fix:** return "still running" immediately, or cap the wait at about 2s.

### 17. LOW: a failed eligibility check at code exchange does not burn the authorization code
- **Where:** `classes/local/oauth/token_issuer.php:178-200`. `used=1` is written inside the transaction that the `eligible_service` failure rolls back. The code stays redeemable for up to 600s.
- **Fix:** mark the code used before opening the transaction.

---

## PLAUSIBLE (path confirmed, exploitability depends on factors I could not verify)

- **Auto pre-approval accepts a cross-site GET for any client.** See `oauth/authorize.php:96-118` and `preapproval_service::find()`.
  - The match uses redirect host, scope and context only. Client identity is ignored, so dynamic and CIMD clients are included, and there is no user interaction.
  - An attacker starts a flow in their own claude.ai account and sends the authorize URL to a pre-approved teacher. The code lands at claude.ai's callback carrying the attacker's `state`. Whether the victim's Moodle access ends up in the attacker's Claude depends on claude.ai binding `state` to the browser.
  - Fix: auto-approve only admin-allowlisted client ids or non-dynamic clients, and/or require `Sec-Fetch-Site: none|same-origin`.
- **`webservice/mcp:issueforothers` has no dominance check.** In `admin_key_service.php:141-163`, a holder can mint 365-day keys for any non-admin, including managers and other issuers. Only site admins hold the capability by default. Refuse targets holding `issueforothers`, `moodle/site:config` or `moodle/user:loginas` unless the issuer is a site admin.
- **The ID-JAG grant maps users by the mutable `email` claim first.** In `jwt_bearer_grant.php:251-298`, the immutable `oid`/`sub` match via `auth_oidc_token` is only a fallback. This is risky if the tenant has guests or editable mail attributes. Prefer `oid` when the `auth_oidc_token` table exists, or require `email_verified`.
- **The redirect host allowlist is a behaviour change on upgrade.** The new default is claude.ai, claude.com and loopback (`client_registry.php:51`, `settings.php:74`). Any of the 14 production clients with other hosts cannot re-authorize, although existing refresh families keep working. Run `SELECT clientid, redirecturis FROM mdl_webservice_mcp_oauth_client` before deploying.
- **The `file_reader` loopback can starve workers and follows redirects.** `file_reader.php:~388-392` makes a curl call back to our own `pluginfile.php`, using `ignoresecurity=>true` and core's default `FOLLOWLOCATION=1`.
  - Five parallel reads take all five FPM workers.
  - A repository alias whose `send_file` redirects (dropbox/equella) becomes SSRF with the security helper off.
  - Add `CURLOPT_FOLLOWLOCATION=>0`, and prefer in-process `file_pluginfile` with output buffering.
- **Ticket redemption and cron re-checks are weaker than transport auth.** `tickets::redeem` and `tasks::assert_still_authorised` re-check `revoked` and `validuntil` only. They do not re-check `external_services_users` (`restrictedusers`, `validuntil`, `iprestriction`), the service's `requiredcapability`, or `oauthenabled`. The exposure is bounded by the TTLs. Factor the transport's service/user checks into one helper.
- **The MRTR confirmation `requestState` is replayable.** It is bound to user and digest only, with no credential binding and no single use, so it can be reused for 15 minutes. This only matters when `confirmdestructive` is on, which is off by default.
- **`signer::secret()` is created lazily without a lock** (`signer.php:~79-90`). Concurrent first requests can each write a secret, and one batch of tickets or states then fails once. Generate the secret in install and upgrade.
- **Badge and other wrapper hardening.** None of these reach permission escalation:
  - `add_related_badges` accepts badge ids from other courses (`badge_service.php:176`; the UI restricts this in `related_form.php:82-94`).
  - `save_alignment` accepts an `alignmentid` that belongs to another badge (core has the same flaw).
  - `add_module` sends raw `options` to `add_moduleinfo` without mod_form validation, for example the mod_lti `typeid` course check.
  - `gradebook scaleid` is not checked against site or course scales.
  - A question category can be made its own ancestor, creating a cycle (core API has the same gap).
  - `update_question` drops embedded files (correctness).
- **`dispatcher::run_tool` still unwraps `wrapper_moodle_api_execute`** (:314-318). As a result, cron skips `execute_api`'s deprecated-function and visibility checks; the service check still applies through `tool_runner::run`. Now that `execute_api` uses `call_function`, this rewrite can be deleted so cron goes through the wrapper.
- **4.2 only:** `file_upload_from_url` has no `CURLOPT_RESOLVE` pinning, so DNS rebinding is possible. This is core parity and does not affect production.

---

## Areas with nothing serious

- **Transport identity:** the service, user state, IP, `validuntil`, `resourceuri` and `mcp:use` checks mirror `webservice/lib.php:1005-1125`. The restricted context is applied. Read/write scope is enforced on every dispatcher method. Legacy sessions are bound to principal, user, context and service.
- **Native tool path:** `load_function_info` enforces service membership. Mutability fails closed (anything not typed `read` needs write scope).
- **Cron user switching:** core does it (`\core\cron` plus `set_userid`) in both 4.2 and 4.5. `tasks::execute` re-asserts the owner, and `load()` blocks cross-user task access.
- **CORS and Origin:** an exact-match allowlist with opaque origins rejected. There is no wildcard and no `Allow-Credentials` on authenticated endpoints. The only `*` is on the public server card and the cookie-less OAuth token, register and revoke endpoints.
- **Tokens:** read from the `Authorization` header only, stored as SHA-256, never logged or echoed. Lookups hash consistently.
- **Tickets:** HMAC-SHA256 with `hash_equals`, bound to purpose and expiry. Base claims cannot be overridden. Family revocation works for OAuth credentials.
- **Path traversal:** prevented by per-segment `PARAM_COMPONENT`/`AREA`/`FILE`, `PARAM_PATH` plus `file_correct_filepath`, and a server-signed `rp`.
- **Draft ownership:** writes always target `context_user($USER)`. Reads go through `file_browser`'s owner checks. Chunk keys include the user id.
- **Write paths and backup/restore:** `file_info::is_writable` is used. Private-files quota mirrors core. Backup and restore capabilities mirror `backup.php`/`restore.php`, and the controllers re-check security.
- **Export zips:** the download re-checks `can_export_context` and `require_view_grades`, plus `accessallgroups` or group membership under separate groups.
- **SSRF in `file_upload_from_url`:** the security helper is on, every redirect hop is re-checked, and 4.5 pins addresses with `CURLOPT_RESOLVE`.
- **OAuth basics:**
  - PKCE is S256-only at both ends.
  - Codes are single-use under a lock and bound to client, redirect and resource; replay revokes the family.
  - authorize requires sesskey, refuses framing and refuses login-as sessions.
  - Refresh tokens rotate with reuse detection and re-check eligibility.
  - Client secrets are checked with `password_verify`.
  - The JWT grant uses only asymmetric algorithms with pinned keys and checks iss, aud, exp (max 24h) and jti with durable replay protection.
  - CIMD and JWKS fetches use the default `\curl` with no redirects.
- **Wrappers (other than the badge items):** course authoring, gradebook, questions, memory and `read_module_data` all validate context, apply the matching capability and scope ids to the course or context, citing the core equivalents. Catalog visibility is only a hint; call paths re-check through `can_discover`, service membership and Moodle's own checks.
- **Upgrade (apart from #4):**
  - Savepoints 2026101000, 101001, 101003 and 101004 are in order.
  - Every DDL step is guarded by an exists check.
  - install.xml matches the end state.
  - The connector service's `component` is nulled before `external_update_descriptions()` (`lib/upgradelib.php:758` then `:770`), so the service and its tokens and users survive.
- **PHP 8.0 and compatibility:** no 8.1-only syntax was found, and `php -l` is clean. Apart from #14, every Moodle API used exists in v4.2.0.

### Areas still being edited, reviewed last and re-read fresh

- **`wrapper/discovery_service.php::execute_api`, now using `tool_runner::call_function`:** OK. It:
  - asserts the session user;
  - rejects missing and deprecated functions;
  - enforces service membership (`serviceid` comes from the transport, and a null value is coerced to 0, which fails closed);
  - enforces discovery visibility;
  - inherits the restricted context from `wrapper\manager::execute` (:182).

  `call_function` mirrors `webservice_base_server::execute()` (validate, override hooks, call, clean) as `$USER`, with a fresh `$PAGE`/`$COURSE`. Remaining nits are already listed: the error-message leak in #15, the missing transaction abort in #3, and the cron unwrap in "plausible".
- **OAuth client-secret rotation** (`client_admin_service::rotate_secret`, `service::authenticate_client`, `admin/clients.php`): OK.
  - Rotation requires the capability and sesskey, uses `password_hash` for the new secret, and shows it once through `s()`.
  - The previous hash is honoured only while `previoussecretexpires > time()`. The overlap defaults to 0.
  - Public and metadata clients are refused.
  - The install.xml and upgrade step 2026101004 fields match.

  Note for operators: rotation does not revoke tokens already issued. After a leaked secret, rotate and also revoke the client's credentials.
