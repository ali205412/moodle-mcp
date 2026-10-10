# Custom local plugins: Moodle 4.5.6 to 5.1.8 compatibility audit

Scope: `local_aspireparent`, `local_parentmanager`, `local_privacypolicy` and `local_appdownload` (copied from production to `/tmp/upgrade-audit/custom/local`).
Checked against Moodle 5.1.8 (`/tmp/upgrade-audit/moodle51`, version 2025100608.00), Moodle 4.5.6 (`git show v4.5.6:`) and `origin/MOODLE_500_STABLE`.
This was a read-only audit. No plugin files were changed.

## Method

1. **Global functions.** I extracted every global function called (82 distinct core functions) and checked each one against the function definitions in 5.1 and in `public/lib/deprecatedlib.php`. None is missing and none is deprecated.
2. **Classes.** I checked every class reference against:
   - the 4.5 `db/renamedclasses.php` files in all components (56 keys),
   - 5.1 `public/lib/db/legacyclasses.php`,
   - 5.1 `public/lib/externallib.php`.
3. **Release notes.** I searched every identifier (methods, functions, classes) in the 5.0 and 5.1 `UPGRADING.md` files.
4. **Signatures and schemas.** I compared the signatures and return structures of the core APIs the plugins call (`user_create_user`, `profile_save_data`, `role_assign`, `core_message\api::*`, `core_course_external::get_course_contents_returns`, `external_update_descriptions/services`) between 4.5.6 and 5.1. I also diffed the install.xml definitions of every core table the plugins query.
5. **Lint and PHP 8.2.** All 72 PHP files pass `php -l`. No dynamic-property creation was found (dynamic properties are deprecated in PHP 8.2, and 5.1 requires PHP 8.2+).
6. **Front end.** No plugin has AMD JS, mustache templates, `db/hooks.php` or `db/events.php`. Front-end risk is limited to Bootstrap 4 class names inside `html_writer` calls.

## Summary

| Plugin | Size | Verdict on 5.1 | Upgrade-caused breakage | Effort |
|---|---|---|---|---|
| local_aspireparent | ~8.2k lines, 28 WS functions | **(c) Partially breaks**: one WS function hits a fatal error. The rest works. | `\quiz_attempt` class alias removed in 5.0 | **S** for the upgrade fix. **M** for the pre-existing bugs and security fixes listed below. |
| local_parentmanager | ~2.6k lines | **(b) Works with warnings.** It also has a **HIGH overwrite risk** from a component-name collision. | None in code. Bootstrap 4 classes are deprecated (shimmed until 6.0). `.form-row` is not shimmed. | **S** for the upgrade. **L** if the component is renamed. |
| local_privacypolicy | ~500 lines | **(b) Works with warnings** | `.font-italic` is deprecated (shimmed) | S |
| local_appdownload | ~260 lines | **(b) Works with warnings** | `.font-weight-bold` is deprecated (shimmed) | S |

### Deployment prerequisites that affect all four plugins

These are not code changes. They must be in the upgrade runbook.

- **The plugins must move into `public/`.** In 5.1 the web-served code lives under `public/`:
  - `lib/components.json:44` maps `"local": "public/local"`.
  - `public/lib/setup.php:62-63` sets `$CFG->dirroot = dirname(__DIR__)`, which is `<root>/public`, and sets `$CFG->root` to the parent directory.
  - `public/config.php` is a shim that loads `../config.php`.

  Deploy the plugins to `public/local/<name>/` and point the web server document root at `public/`.
  - Every `$CFG->dirroot . '/lib/...'`, `'/course/...'`, `'/mod/...'` and `'/local/...'` include in the plugins still resolves, because dirroot is now `public`.
  - `$CFG->libdir` is still `dirroot/lib`.
  - Relative includes keep working through the shim: `require_once('../../config.php')` in `aspireparent/test_parent_role.php:24` and `applinks_file.php:32`, and `__DIR__ . '/../../../config.php'` in the parentmanager CLI scripts.
  - No absolute filesystem paths are hard-coded. `wallet.php:160` uses `$CFG->dataroot`.
- **PHP and database minimums go up.** For 5.1, `public/admin/environment.xml` requires PHP 8.2.0, MariaDB 10.11, MySQL 8.4 and PostgreSQL 15. For 4.5 the minimums were PHP 8.1, MariaDB 10.6.7, MySQL 8.0 and PostgreSQL 13. The upgrade path requires 4.2.3 or later, so 4.5.6 is fine.
- **PHPUnit moves from 9.6 to 11** (`composer.json`). This only matters for the parentmanager tests.
- **mod_lightboxgallery must be upgraded first.** `aspireparent` depends on it (`get_lightboxgallery_images.php:77,113,117,168`). Install version 2026093000 (4.5.4), which `pluglist.json` lists as supporting 4.5, 5.0 and 5.1. Then re-test the `gallery_images` file area and the `lightboxgallery_image_meta` table.

### Areas checked with no 5.0/5.1 issues found

- **Hooks and legacy callbacks.** The only `lib.php` callbacks are:
  - `local_aspireparent_pluginfile`
  - `local_aspireparent_override_webservice_execution`

  Both are still invoked in 5.1:
  - `override_webservice_execution` is called from `public/lib/external/classes/external_api.php:239` and `public/webservice/lib.php:1493`. It has not been migrated to a hook.
  - `pluginfile` is still dispatched by `pluginfile.php` as `<component>_pluginfile`.

  None of these plugins define navigation, `before_footer`, `before_http_headers`, `before_standard_html_head` or `after_config` callbacks. No other plugin function name collides with a core callback name (I cross-checked every `get_plugins_with_function` and `component_callback` name in 5.1).
- **db/services.php.**
  - The shapes are valid.
  - `MOODLE_OFFICIAL_MOBILE_SERVICE` and `EXTERNAL_TOKEN_PERMANENT` are still defined (`public/lib/moodlelib.php:519,568`).
  - `external_update_descriptions()` and `external_update_services()` are identical in 4.5.6 and 5.1.
- **External API base classes.**
  - `local_aspireparent` uses the global aliases (`external_api`, `external_value`, `external_util`, and so on) after `require_once($CFG->libdir.'/externallib.php')`. 5.1 still defines those aliases at `public/lib/externallib.php:37-48`, with no debugging output. Caveat: `require_phpunit_isolation()` at line 35 throws when the file is included from a non-isolated PHPUnit test.
  - `local_parentmanager` already uses `core_external\*`.
  - `external_util::format_text/format_string/get_area_files` exist with compatible signatures (`public/lib/external/classes/util.php:132,470,528`).
- **Return structures.** `get_mentee_course_contents` delegates to `core_course_external::get_course_contents_returns()`. The only 5.1 difference there is a new optional key, `candisplay`. Other return structures are plugin-owned and unchanged.
- **Core tables used.** I diffed `user_devices`, `external_tokens`, `external_services`, `user_info_data`, `user_info_field`, `role_assignments`, `role_context_levels`, `quiz_attempts`, `forum_posts`, `forum_discussions`, `assign_submission`, `user`, `message` and `grade_grades`/`course_modules` between 4.5 and 5.1. The only changes are new fields: `grade_grades.deductedmark` (NOT NULL DEFAULT 0) and `course_modules.enableaitools/enabledaiactions`. The plugins only read these two tables, so nothing breaks.
- **Session, login-as and token APIs are unchanged.** `\core\session\manager::loginas/set_user/is_loggedinas` are the same as in 4.5. The `\core\event\webservice_token_created` validation still requires `relateduserid` and `other['auto']`, which `get_mentee_token.php:156` provides.
- **XMLDB.** `local_parentmanager/db/install.xml` and `db/upgrade.php` (2026090500) match field-for-field. The `foreign-unique` key type is still supported.
- **version.php.** All four declare `requires = 2022041900` and no `supported` or `incompatible` values, so nothing blocks installation on 5.1. `$plugin->cron = 0` (aspireparent) is harmless.

---

## 1. local_aspireparent

### 1.1 Removed, renamed or deprecated APIs (5.0/5.1)

| # | Location | 4.5 behaviour | 5.1 behaviour | Fix |
|---|---|---|---|---|
| A1 | `classes/external/get_mentee_quiz_attempts.php:114` (`\quiz_attempt::FINISHED`) | Works through the alias in `v4.5.6:mod/quiz/db/renamedclasses.php:65` (`'quiz_attempt' => 'mod_quiz\quiz_attempt'`), with developer debugging output | **Fatal error: `Class "quiz_attempt" not found`.** The alias file was deleted in 5.0 by MDL-76566 (commit `ded7f2e995e`, "final removal of pre-404 renamed class definitions"). The constant now lives only at `public/mod/quiz/classes/quiz_attempt.php:54,65`. The error is a PHP `\Error`, so `local_aspireparent_get_mentee_quiz_attempts` fails whenever any attempt is returned. | Replace with `\mod_quiz\quiz_attempt::FINISHED`, or use the literal `'finished'`. |
| A2 | `classes/external/get_mentee_assignment_submissions.php:164` (`ASSIGN_ATTEMPT_REOPEN_METHOD_NONE`) | Deprecated since 4.5 (MDL-80741, recorded in the 4.5 UPGRADING notes). The 4.5 upgrade already converted every `'none'` value. | Still defined (`public/mod/assign/locallib.php:55`), with no runtime warning. The condition is now always true. Final removal is expected in a later release. | Remove the condition and always load previous attempts, or test `$assign->get_instance()->maxattempts != 1`. |

**No other deprecated function, class, method or constant usages were found.**

### 1.2 Legacy lib.php callbacks

None need conversion (see the section above). `local_aspireparent_override_webservice_execution` (`lib.php:309-333`) runs on every web service call. It is cheap and still supported.

### 1.3 Web services

- **Shapes:** OK.
- **Classes:** the legacy global aliases still work. Optional modernisation: `use core_external\external_api;` and the other `core_external\*` classes, and drop `require_once(.../lib/externallib.php)`. This is required only if you add PHPUnit tests.
- **Return structures:** OK. One concern is the `$USER`-swapping pattern in `get_mentee_course.php:179-205`, `get_mentee_course_contents.php:132-170` and `lib.php:53-68`. It reassigns the global `$USER` without `\core\session\manager::set_user()`. The behaviour is unchanged in 5.1, but this is fragile code, and caches keyed on the real session user can leak data. Regression-test these paths in particular.

### 1.4 Front end

- No AMD modules, templates or Bootstrap classes.
- `youtube-proxy.html` is a static file and is still served from `public/local/aspireparent/`.
- `wallet.php:574` hard-codes a `pluginfile.php/1/core_admin/logo/0x200/1778925950/...` URL. It keeps working only as long as the logo file is unchanged.

### 1.5 Database

- No `install.xml`.
- Queries against core tables are compatible. Portability notes:
  - `get_mentee_news.php` uses a raw `LIMIT 100` in SQL. It works on MySQL and PostgreSQL only; use the `$limitnum` argument instead.
  - Several `=` comparisons on TEXT columns are fine on MySQL/PG. `wallet.php:353` already wraps them in `sql_compare_text`.

### 1.6 Bugs and risks that will show up in post-upgrade regression testing

The upgrade did not cause these, but they will look like upgrade breakage during testing. Several also violate the project rule "never exceed real Moodle permissions".

| Sev | Location | Issue | Fix |
|---|---|---|---|
| **CRITICAL (security)** | `classes/external/get_mentee_grades.php:138-140` and `get_mentee_course_grades.php:99-100` ("BYPASSING ALL PERMISSION CHECKS AS REQUESTED") | Any authenticated user can read **any** user's grades by passing `userid` or `menteeid`. These functions are in the official mobile service and have `ajax => true`. | Add `local_aspireparent_is_parent_of($USER->id, $id) \|\| $id == $USER->id`, and call `validate_context`. |
| HIGH (security) | `applinks_file.php:50-66` | Looks up `external_tokens` directly. It ignores service, token type, IP restriction and suspended or deleted users, then sets the session user. | Use `(new webservice())->authenticate_user($token)`, as `wallet.php:56-57` already does. |
| HIGH (security) | `classes/external/get_mentee_token.php:122` | Generates the token with `md5(uniqid(rand(),1))` and inserts it by hand. The token is predictable, and the code bypasses core checks. | Use `\core_external\util::generate_token(EXTERNAL_TOKEN_PERMANENT, $servicerecord, $menteeid, context_system::instance(), $validuntil, '', $name)` (`public/lib/external/classes/util.php:193`). |
| HIGH | `test_parent_role.php` (whole file) | A diagnostic page open to any logged-in user. It discloses role assignments and names for any `userid`. | Delete it before the upgrade. |
| MEDIUM | `classes/external/get_mentee_user_report_grades.php:35,149` | `\grade_report_user` does not exist in 4.5.6 **or** 5.1. The class is `\gradereport_user\report\user` (`public/grade/report/user/classes/report/user.php:228`). The `catch (\Exception)` at line ~217 does not catch the resulting `\Error`, so the function always fails. | `new \gradereport_user\report\user($course->id, $gpr, $coursecontext, $params['menteeid'], true)`, and remove `use grade_report_user;`. |
| MEDIUM | `classes/external/get_mentee_assignment_submissions.php:119,150` | Calls `get_submission_plugin_data()` and `get_feedback_plugin_data()`, which do not exist in core 4.5 or 5.1. This is a fatal error whenever a submission or grade exists. | Use the plugins' real APIs (`$plugin->get_editor_text()`, `get_files()`), or wrap `mod_assign_get_submission_status`. |
| MEDIUM | `login_as_mentee.php:93`, `restore_original_user.php:56,60,63`, `get_mentee_courses.php:129,134-135` | Read `$USER->realuserid`, but core sets `$USER->realuser` (`public/lib/classes/session/manager.php:1143`). Return validation fails, and `set_user(get_complete_user_data('id', null))` throws a TypeError because `set_user(\stdClass $user)` is typed. | Use `$USER->realuser`. |
| MEDIUM | `get_mentee_quiz_attempts.php:72`, `get_mentee_assignment_submissions.php:66`, `check_parent_permission.php:58` | Filter roles by `archetype = 'parent'`, which is not a Moodle archetype. The parent check never matches, so parents are denied. | Use `local_aspireparent_is_parent_of()`. |
| LOW | `get_mentee_courses.php:148,268` | `$params` is overwritten with SQL parameters at line 148, so `$params['returnusercount']` at line 268 triggers an undefined-key warning. | Rename the SQL parameters variable. |
| LOW | `get_mentee_courses.php:286` | `get_progress_all($userid)` passes a user id where `get_progress_all` expects a WHERE SQL string (`public/lib/completionlib.php:1496`). PostgreSQL throws a SQL error and MySQL loads every tracked user. | Use `$completion->get_data($cm, false, $userid)` per activity, or `\core_completion\progress::get_course_progress_percentage($course, $userid)`. |
| LOW | 49 `error_log()` calls (for example `get_mentee_courses.php`, `get_mentee_token.php`) | Log PII and noise on every call. | Remove them, or use `debugging()`. |
| LOW | `classes/privacy/provider.php` | Declares `null_provider`, but the plugin writes user preferences (`payment_reminders.php:121`), `user_devices` rows (`register_mentee_devices.php:147`) and tokens. | Implement a metadata or request provider. |
| LOW | `db/services.php` | `login_as_mentee`, `get_mentee_token` and `restore_original_user` are `ajax => true` with `capabilities => ''`. | Set `ajax => false` for the token and login-as functions. |

### 1.7 Fix list (ranked)

1. **[Upgrade blocker, S]** `get_mentee_quiz_attempts.php:114`: change `\quiz_attempt::FINISHED` to `\mod_quiz\quiz_attempt::FINISHED`.
2. **[Deploy, S]** Place the plugin in `public/local/aspireparent`, and upgrade mod_lightboxgallery to 2026093000.
3. **[Security, S/M]** Restore the parent checks in `get_mentee_grades.php` and `get_mentee_course_grades.php`. Switch token authentication in `applinks_file.php` to `webservice::authenticate_user`. Use `util::generate_token` in `get_mentee_token.php`. Delete `test_parent_role.php`.
4. **[Pre-existing fatals, M]** `grade_report_user` (user_report_grades:149), the assign plugin data methods (submissions:119,150), and `realuserid` → `realuser`.
5. **[Deprecation, S]** Remove the `ASSIGN_ATTEMPT_REOPEN_METHOD_NONE` check (submissions:164).
6. **[Hygiene, S]** Fix the `archetype='parent'` checks, the `$params` clobber and `get_progress_all`. Remove the `error_log` calls. Bump `version.php` to `requires = 2024100700` and `supported = [405, 501]`.

---

## 2. local_parentmanager

### 2.1 Confirming this is the school's own code, and the overwrite risk

- **The code is the school's own.** The evidence:
  - Every file is marked `@copyright 2025 Aspire School`.
  - It integrates with Odoo (`https://odoo.aspireschool.org`, `X-Aspire-Sync-Key` header in `classes/local/api_client.php:16`).
  - It links to the Aspire app (`lib.php:54`, id633359593).
  - It has its own table `local_parentmanager_map`, its own release history (1.4.0 / 2026090500) and its own `pluginname` "Parent Manager".
- **The component name collides with an unrelated public plugin.** `pluglist.json` lists `local_parentmanager` as "Parent/Child Manager" (plugin id 3611, source `github.com/E-learningTouch/moodle-local_parentmanager`). Its latest version is **2026100600 (1.3)** and supports 4.1–5.3. That is **higher than the school's 2026090500**.
- **Mechanism (verified in 5.1):** `\core\update\checker::load_current_environment()` (`public/lib/classes/update/checker.php:440-452`) collects every non-standard plugin. `prepare_request_params()` (lines 459-489) then sends `local_parentmanager@2026090500` to download.moodle.org. For non-core components, `get_update_info()` (lines 164-184) returns whatever versions the server lists. The admin Notifications page and the plugins page will therefore show "Update available: Parent/Child Manager 1.3". If `$CFG->disableupdateautodeploy` is not set (checked at `public/lib/classes/plugin_manager.php:1030,1089,1165`), the page offers **"Install this update"**.

  Installing it would replace the school's code with E-learningTouch's code:
  - It has a different schema and upgrade steps, so the school's `local_parentmanager_map` and its settings would be orphaned or broken.
  - It has different capabilities and web service function names, so the Aspire app's `local_parentmanager_sync_student_parents` would disappear.
  - The family-sync scheduled task would be lost.
- **Mitigation:**
  1. **Now:** set `$CFG->disableupdateautodeploy = true;` in config.php. Tell admins never to accept updates for this component.
  2. **Long term (L):** rename the component, for example to `local_aspireparentsync`. That means migrating config (`local_parentmanager/*`), the table, the capability `local/parentmanager:manageparents`, the external function name and the task. Coordinate any app changes.

### 2.2 Removed or deprecated APIs

**None found.** The plugin already uses `core_external\*`, `\core\lock\lock_config`, `\curl`, `\core_text`, `\core_user` and `\core\notification`, and all of them exist unchanged in 5.1. `profile_load_data`, `profile_save_data`, `user_create_user` and `role_assign` are byte-identical between 4.5.6 and 5.1.

### 2.3 Callbacks and hooks

`lib.php` contains only helper functions. There are no callbacks.

### 2.4 Web services

- `db/services.php` is valid.
- Risk: `local_parentmanager_sync_student_parents` (a write function that creates users) is added to `MOODLE_OFFICIAL_MOBILE_SERVICE` and gated only by `moodle/user:create`. If the app does not call it, remove the `services` entry.
- The return structure matches `parent_sync_service::result()`.

### 2.5 Front end (Bootstrap 4 to 5)

5.1 still compiles `public/theme/boost/scss/moodle/bs4-compat.scss`. It is marked "Final deprecation in 6.0, MDL-84465". It shims `.mr-*`, `.ml-*`, `.badge-<color>`, `.form-group`, `.font-weight-*` and `.font-italic`, and in theme designer mode it outlines them in red. It does **not** shim `.form-row`. Themes that do not include Boost's SCSS lose all of these.

| Location | BS4 | BS5 replacement |
|---|---|---|
| `manage.php:293`, `:298`, `:355` | `mr-2` | `me-2` |
| `manage.php:494` | `badge badge-success` | `badge text-bg-success` |
| `manage.php:495` | `badge badge-warning` | `badge text-bg-warning` |
| `manage.php:426` | `form-row align-items-center` (**not shimmed**: the gutters collapse) | `row g-2 align-items-center` |
| `manage.php:534` | `form-group` | `mb-3` |

### 2.6 Database

`install.xml` and `upgrade.php` are consistent. No core tables or fields this plugin uses were removed.

### 2.7 Bugs and risks

| Sev | Location | Issue |
|---|---|---|
| **HIGH** | component name | Overwrite risk (see 2.1). |
| MEDIUM | `export.php:29,40,93`, `manage.php:86` | Call `local_parentmanager_get_all_parents()` and `local_parentmanager_reset_parent_password()`. **Neither is defined anywhere**, not even in `lib.php.bak`. This is a fatal error on 4.5 and 5.1 alike. `manage.php:351-356` and `:385-394` still render the buttons. Remove the buttons and `export.php`, or reimplement the functions. Also drop the plaintext password kept in `$_SESSION` (`manage.php:89,114-125`). |
| LOW | `lib.php.bak` | Served as plain text from `public/local/parentmanager/lib.php.bak`, which discloses source code. Delete it. |
| LOW | `manage.php:612` | Links to `API_SPEC.md`, which does not exist. |
| LOW | `tests/*.php` (`@covers` docblocks) | PHPUnit 11 (5.x) deprecates docblock metadata in favour of `#[\PHPUnit\Framework\Attributes\CoversClass(...)]`. The tests still run. |

### 2.8 Fix list (ranked)

1. **[Ops, S]** Set `$CFG->disableupdateautodeploy = true;` and deploy to `public/local/parentmanager`.
2. **[Pre-existing fatal, S]** Remove or replace the reset and export feature (export.php, manage.php:83-96, 351-356, 383-394).
3. **[BS5, S]** Apply the class replacements in `manage.php:293,298,355,426,494,495,534`.
4. **[Cleanup, S]** Delete `lib.php.bak` and the dead API_SPEC link. Convert `@covers` to attributes. Bump `requires`/`supported`.
5. **[Optional, L]** Rename the component to remove the collision permanently.

---

## 3. local_privacypolicy

- **APIs and callbacks:** none affected. It has no `lib.php`, `db/` or privacy provider. Only `privacy.php` exists.
- **Bootstrap:** `privacy.php:44` uses `font-italic`, which is shimmed and deprecated. Use `fst-italic`.
- **Risks:**
  - `privacy.php:28` defines `NO_LOGIN`, which is not a Moodle constant. It is harmless because the page never calls `require_login`.
  - `privacy.php:102` prints "last updated" as `userdate(time())`, which is always today. That is misleading on a store-compliance page. Use a fixed date string.
  - `APP_CODE_ANALYSIS.md`, `DATA_SAFETY_GUIDE.md` and `README.md` are web-served from `public/`. They contain no secrets.
  - There is no `classes/privacy/provider.php`, so the privacy registry flags the plugin. Add a `null_provider`.
- **Verdict:** (b). **Effort:** S.

## 4. local_appdownload

- **APIs and callbacks:** none affected. It has no `lib.php` or `db/`.
- **Bootstrap:** `download.php:136` uses `font-weight-bold`, which is shimmed and deprecated. Use `fw-bold`.
- **Risks:**
  - `download.php:40` calls `$PAGE->requires->css('/local/appdownload/styles.css')` even though a plugin's `styles.css` is already bundled into the theme CSS on every page. Remove the call.
  - The `styles.css` selectors are prefixed (`.app-*`, `.download-*`), so global bleed is low.
  - There is no privacy provider. Add a `null_provider`.
- **Verdict:** (b). **Effort:** S.

---

## Consolidated severity list

| # | Severity | Plugin | Item | Effort |
|---|---|---|---|---|
| 1 | CRITICAL (upgrade) | aspireparent | `get_mentee_quiz_attempts.php:114`: `\quiz_attempt` removed in 5.0 | S |
| 2 | CRITICAL (security, pre-existing) | aspireparent | `get_mentee_grades.php:138`, `get_mentee_course_grades.php:99`: no permission check | S |
| 3 | HIGH (ops) | parentmanager | Component collision with E-learningTouch 2026100600: set `disableupdateautodeploy` | S (rename: L) |
| 4 | HIGH (deploy) | all | Move to `public/local/*`, docroot to `public/`, PHP 8.2+, DB minimums | S |
| 5 | HIGH (security) | aspireparent | `applinks_file.php` token auth, `get_mentee_token.php:122` token generation, `test_parent_role.php` | S |
| 6 | MEDIUM (pre-existing fatal) | aspireparent | `grade_report_user` (:149), assign plugin data methods (:119,150), `realuserid` (several files) | M |
| 7 | MEDIUM (pre-existing fatal) | parentmanager | undefined reset/export functions (`export.php`, `manage.php:86`) | S |
| 8 | MEDIUM (dependency) | aspireparent | Upgrade mod_lightboxgallery to 2026093000 before upgrading core | S |
| 9 | LOW (deprecated) | aspireparent | `ASSIGN_ATTEMPT_REOPEN_METHOD_NONE` (`get_mentee_assignment_submissions.php:164`) | S |
| 10 | LOW (BS5) | parentmanager, privacypolicy, appdownload | Classes listed above; `.form-row` is not shimmed | S |
| 11 | LOW | all | Bump version.php `requires`/`supported`; add privacy providers; delete `.bak`/`.md` from the web root | S |
