# Phase 12: UI bridge + targeted tools (0.10.0)

User decision (2026-10-10): **bridge + targeted tools**, admin pages included.

Goal: Claude can do anything the signed-in user can do in Moodle's web UI, never more. Today 805/805 external
functions are exposed, but 395 of 467 installed plugins (blocks, reports, admin tools, course formats, local plugins)
and many core screens have no API.

Production facts (checked 2026-10-10): tool_mfa off; file sessions; `sessiontimeout` 28800; auth `oidc`; loopback
`--resolve learn.aspireschool.org:443:127.0.0.1` returns 200 in 63 ms; PHP-FPM `pm.max_children = 5`.

## 1. Session bridge (owner: auth-fixer) `classes/local/ui/session_bridge.php`, `ui/login.php`

Pattern: core `admin/tool/mobile/autologin.php` (one-time IP-bound key turned into a session with
`complete_user_login()`), but admins allowed and keys minted server-side for the loopback request only.

`session_bridge::fetch(call_context $ctx, string $method, string $url, array $fields = [], bool $multipart = false): array`
returns `['status' => int, 'url' => final URL, 'contenttype' => string, 'body' => string (cap 20 MB), 'sesskey' => string|null,
'filename' => from Content-Disposition|null]`.

- **Who may use it:** connector credentials only (`$ctx->connector`), plugin setting `uibridge` (default 1), capability
  `webservice/mcp:uibridge` (system; archetypes user, student, teacher, editingteacher, manager allow). Tokens with a
  restricted context below system are refused (context restriction cannot be enforced on arbitrary pages).
- **Scope:** GET requires read scope; POST, or any GET whose query contains `sesskey`, requires write scope.
- **URL policy:**
  - same origin as `$CFG->wwwroot` only (scheme, host, port; path under wwwroot path);
  - no userinfo, no fragments sent;
  - deny `/login/` (except none), `/webservice/` (all, incl. this plugin), `/admin/tool/mobile/`, `/user/managetoken.php`,
    `/lib/ajax/`, and `/pluginfile.php`/`/draftfile.php` (point to the file tools instead).
  - Clear errors (transfer_exception style, `uibridgeurldenied`) naming the rule.
- **Loopback:** raw curl with `CURLOPT_RESOLVE host:port:<setting uibridgeloopbackip, default 127.0.0.1>`, TLS verified,
  timeout 60 s, no proxy, redirects followed manually (max 5, same-origin only, re-checked against the URL policy;
  a redirect to `/login/index.php` means the session died: re-login once, then fail).
- **Sessions:**
  - per credential family, cookie jar kept in a MUC application cache `uibridge_session` (TTL 900 s, key sha1(familyid));
  - login: `create_user_key('webservice_mcp_ui', $userid, null, <loopback ip as seen by Moodle: getremoteaddr() in
    the loopback request; record what Moodle sees, likely 127.0.0.1>, time() + 30)`, then GET
    `/webservice/mcp/ui/login.php?userid=&key=`;
  - `ui/login.php`: validate + delete the key, `core_user::require_active_user`, the same eligibility as
    `bootstrap_service::require_user_eligible` (minus site-admin policy? keep it: if the site policy forbids admins
    for MCP, the bridge refuses too), `complete_user_login($user)`, set `$SESSION->webservice_mcp_ui = <familyid>`,
    respond 204. Must not count toward concurrent-login limits in a way that logs the user out of their browser:
    do NOT call `apply_concurrent_login_limit`.
  - before every fetch: `credential_manager::assert_service_access($userid, $serviceid, $familyid, false)`;
  - revocation of the family kills its cached bridge session (`\core\session\manager::kill_session`).
- **Concurrency:** at most 2 bridge fetches site-wide (two `\core\lock` slots, 20 s wait, then a clear "busy, retry"
  error), because each fetch occupies a second FPM worker.
- **Sesskey:** extract from the HTML (`"sesskey":"…"` in M.cfg). Never returned to the client (see section 3).
- **Audit:** the tool call is already audited; add the URL path (no query) to the audit detail.

## 2. Page parser + submission (owner: files-builder) `classes/local/ui/page_parser.php`, `form_submitter.php`, `download_saver.php`

`page_parser::parse(string $html, string $url): array`:

- `title`, `url`, `breadcrumb` [text, linkid], `heading` (page header h1), `alerts` [{type: error|warning|info|success, text}]
  from `.alert`, notifications, `.error`, `.invalid-feedback`, `#id_error_*`;
- `tabs`: secondary navigation / tabs as links;
- `text`: the main region (`#region-main`, else `[role=main]`, else body) as readable markdown-like text:
  - headings with #;
  - lists with -;
  - tables as `| a | b |`;
  - links inline as `[text][L12]`;
  - images as `[image: alt]`;
  - strip scripts, styles, hidden elements (`hidden`, `aria-hidden`, `.sr-only` kept as text? no: skip
    `.sr-only`/`.visually-hidden` duplicates), drawers, footer, navbar.
- `links` [{id: "L12", text, url}] (deduped by url+text, absolute, only http(s) same-origin or external marked);
- `forms` [{id: "F1", name/id attr, action (absolute), method, enctype, buttons [{name, value, label}],
  fields [{name, type, label (from <label for>, aria-label, or fieldset legend), value, required, options [{value, label,
  selected}], multiple, checked, hidden, help (from help icons/descriptions), editor (true for Atto/TinyMCE textareas),
  filemanager {draftitemid, accepted types, maxfiles} for Moodle file pickers}]}];
- skip forms that are pure navigation (single select jump menus are fine to list).

`form_submitter::build(array $form, array $values, ?string $button): array` returns `['method', 'action', 'fields' =>
[name => string|array], 'multipart' => bool]`:

- starts from defaults (hidden fields, current values, checked boxes/radios, selected options);
- applies `$values` (by field name; also accept label match for selects/radios);
- validates unknown names (error lists the available ones) and select/radio values;
- Moodle editors: `name[text]`, `name[format]`, `name[itemid]`;
- date selectors: `name[day]` etc.;
- file pickers: value is a draftitemid the user prepared with the file tools;
- the submit button (by name/value/label) defaults to the form's primary submit;
- never fills password fields unless explicitly given.

`download_saver::save(array $response, call_context $ctx): array`: non-HTML responses (exports, CSV, PDF reports) are
stored in the user's draft area (`user/draft`, new draftitemid) and returned as `{uri moodle://file/…, filename,
mimetype, size}` so `file_read`/`file_get_download_url` work.

## 3. Tools (owner: team lead) `classes/local/ui/tools.php`

- `moodle_page_view {url, offset?, length?, sections?}`: read-only, idempotent, closed-world. GET via the bridge, parse,
  return structured content + a text rendering (text paged at 40k chars with offset/length; links and forms always
  included). Refuses URLs needing write (sesskey) with a pointer to `moodle_page_action`.
- `moodle_page_action {url}`: follow a state-changing link (contains sesskey, e.g. delete/hide/enrol): write scope,
  destructive.
- `moodle_page_submit {url, form, fields, button?}`: fetch fresh page, find form (by id F1, form id/name, or index),
  build submission, POST, return the resulting page parsed (with alerts so the model sees validation errors).
  Write scope, destructive.
- Sesskey handling: the real sesskey is replaced by `{sesskey}` in every URL/field value returned, and substituted back
  on input; links/forms are re-resolved against a fresh fetch so stale sesskeys never matter.
- Non-HTML results go through `download_saver`.

## 4. Targeted tools (owner: wrapper-fixer), each mirroring the UI page's own checks

- `wrapper_course_get_module_settings {cmid}` / `wrapper_course_update_module {cmid, settings}`: modedit.php update
  path (`get_moduleinfo_data`, the module's mod_form validation and `data_postprocessing`, `update_moduleinfo`).
- `wrapper_course_update_section {sectionid|courseid+section, name?, summary?, visible?, availability?}`.
- `wrapper_admin_search_settings {query}` / `wrapper_admin_get_settings {names|section}` /
  `wrapper_admin_set_settings {settings}`: admin tree, `moodle/site:config` (or the page's required capability),
  each setting's own `write_setting()` validation, config log entries like the UI.
- `wrapper_role_get_overrides {contextid, roleid}` / `wrapper_role_set_override {contextid, roleid, capability,
  permission}`: `role_change_permission` with `moodle/role:override` / `safeoverride` checks as in admin/roles/permissions.php.
- `wrapper_enrol_list_instances` exists in core (`core_enrol_get_course_enrolment_methods`); add
  `wrapper_enrol_add_instance {courseid, plugin, settings}` / `update_instance` / `delete_instance` / `set_status`,
  using the enrol plugin's `can_add_instance`, `edit_instance_form` validation, `add_instance`/`update_instance`.
- `wrapper_report_logs {courseid?, userid?, from?, to?, component?, limit, offset}` (report/log:view, logstore reader,
  same fields as the log report), `wrapper_report_participation {courseid, roleid?, action?, timefrom?}`.
- `wrapper_course_reset {courseid, options}` (moodle/course:reset, `reset_course_userdata`).

Each with tests, `title` + annotations, explicit input explanations on invalid input.
