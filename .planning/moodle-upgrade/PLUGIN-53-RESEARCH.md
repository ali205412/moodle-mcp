# Third-party plugins vs Moodle 5.2 / 5.3 LTS: research (2026-10-10)

Scope: the third-party plugins on learn.aspireschool.org (Moodle 4.5.15) and whether each can follow the site to
Moodle 5.3 LTS (released 2026-10-05) or 5.2. Read-only research. Nothing was changed on the production server or in
the repo, apart from writing this file.

**Legend.** **[V]** means verified here, by a command against the plugins directory API, `git ls-remote`, a cloned
repo, the GitHub API, or the local Moodle tree `tmp/moodle` (`origin/MOODLE_50x_STABLE` refs read with
`git show` / `git grep` / `ls-tree`; the checked-out branch was not changed). **[I]** means inferred: a judgement
from the evidence, not tested on a running 5.3 site.

Working clones are in `/tmp/plugin-research/` (format_tiles, lightboxgallery master + `MOODLE_405_STABLE` worktree,
academi, o365-moodle, atto_teamsmeeting, format_grid, editor_atto, and the alternative plugins).

---

## 1. Summary table

| Plugin | Directory max (latest release) | Repo status for 5.3 | Technical blockers found on 5.2/5.3 | Alternative | Migration effort |
|---|---|---|---|---|---|
| **format_tiles** (484 courses) | **5.1**: 5.1.0.2, 2026-01-25 [V] | No 5.2 or 5.3 branch. Last upstream commit 2026-01-25. Maintainer's site `evolutioncode.uk` does not resolve. Open PRs unmerged since 2025 [V]. A community fork, `leonstr/moodle-format_tiles`, carries the two 5.2 fixes [V] | **Two hard blockers from 5.2 on [V]:** (1) `new \Mustache_Engine()` in `classes/local/dynamic_styles.php` gives a fatal "class not found" because 5.2 moved Mustache to the `Mustache\` namespace. (2) AMD `core/modal_factory`, removed in 5.2, is used by `course_mod_modal.js` (activities opened in a modal) and `edit_icon_picker.js`. Other hits are deprecation notices only | Patch Tiles (2 small fixes, already written in the fork). Nearest replacement on 5.3 is `format_softcourse` (sections as image cards). Nothing else replicates Tiles | **Patch: about 1 day** plus a staging test. **Switch format: 2–4 days** of scripting and QA, plus teacher re-styling |
| **mod_lightboxgallery** (832 galleries, ~32k images) | **5.1**: 4.5.4, 2026-10-01 [V] | Active: Open LMS and Luca Bösch, last commit 2026-10-01. `master` is "5.1.0-dev" and its CI tests only `MOODLE_501_STABLE`. No 5.2/5.3 work yet [V] | **None found [V].** The 4.5.4 code uses no API removed in 5.2 or 5.3. It ships its own YUI module, and YUI 3.18.1 is still in 5.3. `version.php` has no `supported`/`incompatible` lines, so 5.3 will install it without a warning [V] | Keep it. Nothing on 5.3 matches it (see §3.2) | **Keep: about 0.5 day** of staging QA. **Convert to another plugin: 5–10 days** of custom migration code. No migration tool exists |
| **theme_academi** | **5.2**: v5.2.1, 2026-09-18 (v5.2 in the directory 2026-04-29) [V] | Active, last commit 2026-09-18. Branches v5.0/v5.1/v5.2. No v5.3 yet. Past lag after a Moodle release: 9 days to 2.5 months [V] | **No fatal blockers found [V].** Cosmetic risk on 5.3 [I]: it still renders `core/moremenu`, while 5.3 Boost renders navigation with React; its `drawerheadercontent` block override is now a no-op; 5.3 brings Noto Sans and dark mode. Its `columns2.mustache` uses the removed `theme_boost/nav-drawer`, but no layout uses that template [V]. **Open security issue #113**: an unauthenticated login request can change the global preset [V] | Wait for v5.3, or run v5.2.1 on 5.3 after visual QA. On 5.3 now: `theme_moove` 5.3.0, Boost Union (5.2 max) | **0.5–1 day** QA. Switching theme means 483 course theme overrides plus re-branding (days) |
| **auth_oidc** (2,137 SSO users) | **5.2**: 5.2.1, 2026-09-02 [V] | Monorepo `microsoft/o365-moodle` has `MOODLE_502_STABLE` and `wip-*-m502` branches. **No `MOODLE_503_STABLE` or m503 branch, and no issue or PR mentioning 5.3** [V]. Very active, with PRs on 2026-10-09 [V] | **None fatal found [V].** It uses `user_update_user()` (deprecated in 5.3, still works). No `supported`/`incompatible` lines [V] | Core `auth_oauth2` with the Microsoft issuer (§4.2), but only if the whole M365 suite goes | Keep: QA only. Replace: 2–3 days, plus losing every plugin that depends on it |
| local_o365 | 5.2: 5.2.1 [V] | as above | **One 5.3 failure [V]:** `local/o365/classes/webservices/read_assignments.php:281` calls `external_format_text()`, which 5.3 turns into a throwing final deprecation. That breaks only the `local_o365` assignments web service (Teams/Microsoft app). Also deprecated `moveto_module`, `course_delete_module`, `user_*` (notices only) | none in core | one-line patch (`\core_external\util::format_text`) |
| local_office365, block_microsoft, repository_office365, local_onenote, assignsubmission_onenote, assignfeedback_onenote, theme_boost_o365teams | 5.2 (5.2.0/5.2.1) [V] | as above | None fatal [V]. `assignfeedback_onenote/externallib.php` uses the global `external_api` class names; on 5.3 those still resolve through `lib/db/renamedclasses.php`, with debug notices [V]. Teams theme JS targets the removed `#nav-drawer` (harmless) [V] | core `repository_onedrive` covers OneDrive | QA |
| atto_teamsmeeting | **4.5 only**: 4.5.8 [V] | `master` = `MOODLE_405_STABLE` [V] | Atto left core in 5.0. Core `lib/db/upgrade.php` uninstalls Atto and **all** `atto_*` subplugins automatically when Atto's files are absent [V] | `tiny_teamsmeeting` (directory max 5.2, `MOODLE_502_STABLE`) [V] | none: it goes away on upgrade (already planned as unused) |
| local_cnw_smartcohort (unused) | 4.2: 2.0.5, 2023 [V] | abandoned | n/a | none needed | uninstall |

**Bottom line.** A 5.3 jump is technically possible today if Tiles gets two small patches. Lightbox and the Microsoft
suite look able to run on 5.3 unmodified (one Microsoft web service excepted). Academi needs visual QA. None of the
five vendors has published 5.3 support, so the site would run "not declared supported" code. That is acceptable only
with a staging rehearsal.

---

## 2. Moodle-side facts used (from `tmp/moodle`)

- **Versions [V].** `MOODLE_501_STABLE` = 5.1.8 (2025100608). `MOODLE_502_STABLE` = 5.2.4 (2026042004).
  `MOODLE_503_STABLE` = 5.3 (2026100500). `main` = 6.0dev. 5.3 requires **PHP 8.3**, PostgreSQL 17 / MariaDB 11.4 /
  MySQL 8.4 (`public/admin/environment.xml`).
- **`$plugin->supported` is advisory only [V].** `core\plugininfo\base::is_core_compatible_satisfied()` checks only
  `$plugin->incompatible`. `plugin_manager::check_explicitly_supported()` feeds the "not supported" label in the admin
  UI (`admin/renderer.php`). So Tiles' `supported = [501, 501]` gives a warning, not a block, on 5.3.
- **Removed in 5.2** (root `UPGRADING.md`, 5.2 "Removed") [V]:
  - AMD `core/modal_factory` and `core/modal_registry` (the file is absent from `MOODLE_502_STABLE` and `MOODLE_503_STABLE`)
  - YUI `moodle-core-notification-confirm`, plus `keyDelegation` / `lightbox` in `moodle-core-notification`
  - `M.util.set_user_preference` and other `javascript-static.js` helpers
  - `get_context_instance()`, `get_system_context()`, `print_arrow()`
  - flat navigation, and the `theme_boost/nav-drawer` and `flat_navigation` templates
  - `core_courseformat\base::get_section_number()` and `stateactions::section_move()`
  - action_menu `set_alignment`, `set_constraint`, `do_not_enhance`
  - PHP-4 style constructors
  - `qtype_random`, `block_activity_modules`, `tool_moodlenet`
- **Not in UPGRADING but breaking [V].** From 5.2 the bundled Mustache library is namespaced (`public/lib/mustache/src/Engine.php`). Core autoloads only `\Mustache\` (`public/lib/classes/component.php:147`). The `Mustache_*` aliases live in `src/compat.php`, which Moodle never loads, so `new \Mustache_Engine()` is a fatal error on 5.2 and 5.3.
- **Changed in 5.3 [V]:**
  - **Removed:** `theme_classic` is removed from core (settings migrate to Boost). Boost's `drawerheadercontent` block is replaced by `drawercontrols`. `block_timeline` renderers and external functions are removed.
  - **Moved:** the `core/loginform` template moves from Boost to core.
  - **Now throws:** the `external_*()` functions in `public/lib/externallib.php` (`external_format_text`, `external_format_string`, `external_validate_format`, `external_generate_token`, and others) are now `final: true` deprecations, so calling them throws.
  - **Deprecated, still works:**
    - The global class names `\external_api`, `\external_value` and so on now resolve through `public/lib/db/renamedclasses.php`, with debug notices.
    - `theme_boost/bootstrap/*` AMD imports (the files still exist in 5.3).
    - The `user_*()` functions, which moved to `lib/deprecatedlib.php`.
  - **UI changes:**
    - Primary and secondary navigation are rendered by React (`core/nav/Nav`, `core/primarymoremenu`), and the edit switch uses the design system.
    - Experimental dark mode.
    - The default font is now Noto Sans.
- **Deprecated in 5.2, still work [V]:** `course_set_marker`, `set_section_visible`, `moveto_module`,
  `course_delete_module`, `duplicate_module`, `move_section_to`. All still exist in 5.3 `public/course/lib.php`.
- **Atto [V].** Present in `MOODLE_405_STABLE` and absent from 5.1 onward. The upgrade step in
  `public/lib/db/upgrade.php:719-731` uninstalls `atto_*` subplugins and `editor_atto` when the files are missing.
  The standalone `moodlehq/moodle-editor_atto` was last touched 2025-04-24, and `editor_atto` is not in the plugins
  directory feed.
- **Course format fallback [V].** `core_courseformat\base::get_format_or_default()` renders a course in the site
  default format (with a debug notice) when its format plugin is missing. `core\plugininfo\format::uninstall_cleanup()`
  converts every course to the default format and then deletes that format's `course_format_options`.
  Format options are stored per format (`course_format_options.format`), so switching a course to another format
  leaves the old rows in place, and switching back restores them (`base::update_format_options()`).

---

## 3. Per plugin

### 3.1 format_tiles (Tiles format, David Watson)

**Directory [V].** `pluglist.php` and `pluginfo.php?plugin=format_tiles&branch=5.3` both return 5.1.0.2
(2026012570, released 2026-01-25), supported 5.1 only. The `branch=5.3` query returns the 5.1 build: the API offers the
latest build, not a compatible one. Download now comes from `marketplace.moodle.com`.

**Repo [V].**
- Source: `https://bitbucket.org/dw8/moodle-format_tiles`. Branches run `moodle37`…`moodle45`, `moodle50`,
  `moodle51`; `master` equals `moodle51`. There is no `moodle52` or `moodle53`.
- Last commit: 2026-01-25 (`b22538a`, "Mustache tile num comment").
- Release cadence:
  - 5.0.0.1 shipped 3 days after Moodle 5.0, and 5.1.0.0 3 days after 5.1.
  - Nothing has shipped in the 173 days since 5.2 (2026-04-20).
- Bitbucket issues API: "Functionality has been deprecated". Open PRs date from 2023 to 2026-04-22 and are unmerged.
  One of them is "Moodle 5 issues with course index drawer and subsections".
- `evolutioncode.uk`, the plugin's documentation site, has no DNS record today.
- **[I]** The maintainer looks inactive or slow. A 5.3 release is not visibly imminent.

**Community fork [V].** `github.com/leonstr/moodle-format_tiles` (Leon Stringer, a non-GitHub-fork copy) is upstream
master plus two commits:
- `4d4461c` (2026-04-30), "Fix: Class Mustache_Engine not found". It uses `\Mustache\Engine` when
  `$CFG->branch >= 502` and sets `supported = [501, 502]`.
- `3ef1cfd` (2026-07-08), "Move modal factory instantiation to modal::create". It changes
  `amd/src/course_mod_modal.js` and `amd/src/edit_icon_picker.js` and rebuilds the files.

**Static scan of upstream master against 5.2/5.3 removals [V].**
- `classes/local/dynamic_styles.php:71` `new \Mustache_Engine()` → **fatal on 5.2+**. The dynamic CSS is built for
  Tiles course pages.
- `amd/src/course_mod_modal.js:30` and `amd/src/edit_icon_picker.js:298` require `core/modal_factory` → **JS module
  missing on 5.2+**. Activities in modals (PDF, URL, page) and the teacher's icon/photo picker break.
- `lib.php:680`, `db/upgradelib.php:71`: `set_section_visible()`. `lib.php:830`, `format.php:50`:
  `course_set_marker()`. These are deprecated in 5.2 and still work, with debug notices.
- No use of `theme_boost/bootstrap/*`, `get_section_number`, `section_move` or the removed renderers.
  External functions use the namespaced `core_external\*`.
- **[I]** Possible soft issues on 5.3:
  - Tiles overrides `core_courseformat/local/content*` templates, so the 5.3 collapse-all toggle and the
    course-index ARIA changes won't apply.
  - 5.2 always shows subsections inline (MDL-87276).
  - Test both on staging.

**Options.**
1. **Patch and keep Tiles (recommended).**
   - Take the fork's two commits, or the equivalent ~20-line change, plus a one-line `supported = [501, 503]`.
   - Effort: about 1 day, including Grunt rebuild and staging test of 484 courses' rendering (spot-check).
   - **[I]** The site carries a local patch until upstream catches up. Re-apply it on each Tiles update.
2. **Switch course format.** There is no 5.3 grid/tile format with Tiles' features. `format_grid` stops at 5.0
   (`gjb2048/moodle-format_grid` main = `supported [500, 500]`) and is not in the directory feed [V]. Candidates with 5.3 in the directory [V]:
   - `format_softcourse` 4.7.2 (2026-09-29, supports 5.2/5.3, `DigiDago`): sections as image cards with progress and
     a "Start" button. **Closest look.** No icon tiles, no subtiles, no activity modals.
   - `format_multitopic` v5.3.0: tabs. `format_flexsections` 5.0.5: nested collapsible sections.
     `format_popups` 1.1: activities in pop-ups. `format_simple` 1.1.3/1.2.0: one unit at a time, newer and smaller
     plugin.
   - 5.2 max: `format_remuiformat` (Edwiser cards), `format_buttons`, `format_masonry`, `format_trail`.
     `format_onetopic` stops at 5.1.

   **Migration path [V for core behaviour, I for effort]:**
   - Changing `course.format` (UI or `update_course()`) keeps every section and activity.
   - Tiles-only options stay in `course_format_options` under `format='tiles'` and are ignored. Tiles icons and
     colours are lost visually.
   - Tile photos live in the `format_tiles` file areas. They can be copied by script into
     `format_softcourse`'s section image area.
   - Write a CLI script: for each course with `format='tiles'`, set the new format and copy section photos. About
     2–4 days including QA.
   - Do not uninstall Tiles first. Uninstalling converts courses to the site default format and deletes all Tiles
     options [V].
   - If the upgrade happens with Tiles absent, courses still render in the default format (`get_format_or_default`)
     [V], so nothing is lost. They just look like Topics.

### 3.2 mod_lightboxgallery (Open LMS / Luca Bösch)

**Directory [V].** 4.5.4 (2026093000, released 2026-10-01), supported 4.5, 5.0, 5.1.

**Repo [V].**
- Source: `github.com/open-lms-open-source/moodle-mod_lightboxgallery`.
- `MOODLE_405_STABLE` = tag v4.5.4: `requires 2024100700`, no `supported` or `incompatible`.
- `master` = "5.1.0-dev": requires 5.1, AMD rewrite, YUI removed (107 files changed vs 4.5). Its CI matrix covers
  `MOODLE_501_STABLE` only.
- Active: merged PR #133 (2026-09-22); commits on 2026-10-01.
- No issues or PRs about 5.2/5.3. PR #126 "Moodle 5.1 compatibility" closed as "done already".
- **[I]** A 5.2/5.3 declaration probably comes with the master release. Date unknown, not imminent.

**Static scan [V].**
- 4.5.4 (`/tmp/plugin-research/lightbox405`): no removed API.
- It loads its own YUI module (`yui/lightbox/lightbox.js`, `moodle-mod_lightboxgallery-lightbox`). That module does
  not depend on the removed `moodle-core-notification` lightbox. YUI 3.18.1 is still in 5.3
  (`public/lib/yuilib/3.18.1`).
- Master: only a test calling the deprecated `course_delete_module()`.
- **[I]** 4.5.4 should install and run on 5.3 as-is. Smoke-test galleries, the edit tools (crop/rotate/resize),
  comments, backup/restore and `local_aspireparent`, which depends on Lightbox.

**Alternatives on 5.3 [V directory, I fit]:**
- `mod_folder` (core): stores the images, shows them inline or on a page, but as a file list. No thumbnails, no
  lightbox, no captions.
- `mod_unilabel` v5.3 (`grabs/moodle-mod_unilabel`): carousel/grid content types. Each image becomes a hand-built
  tile, which suits a few images, not 32k.
- `mod_mediagallery` (NetSpot, 4.3.3, max 5.2): the closest real gallery (collections, galleries, items, lightbox).
  No 5.3 yet, and the latest branch in the repo is `MOODLE_403_STABLE` plus master.
- `mod_vimigallery` (5.3) is **not** an image gallery. It is an album of ViMi Pad knowledge maps.
- Core 5.3 has no gallery activity.

**Data to migrate [V].**
- Tables `lightboxgallery` (perpage, perrow, comments, ispublic, captionpos…), `lightboxgallery_comments`, and
  `lightboxgallery_image_meta` (captions and tags per image).
- Files in `mod_lightboxgallery` areas `gallery_images`, `gallery_thumbs` and `gallery_index`.

**Migration [I].** There is no migration tool. A converter to `mod_folder`:
- create a folder module per gallery in the same section and position, with the same visibility and availability;
- copy `gallery_images` files;
- drop thumbnails;
- lose captions or write them into the intro;
- lose comments.

Estimate 3–5 days for the script plus 2–5 days of QA and teacher communication across 832 activities. Users lose the
gallery UX. Not recommended while Lightbox runs on 5.3.

### 3.3 theme_academi (LMSACE)

**Directory [V].** v5.2 (2026042900, supports 5.2) and v5.1 (supports 5.0/5.1). The repo has tag v5.2.1 (2026-09-18).

**Repo [V].**
- `github.com/lmsace/academi`, branches `v5.0`/`v5.1`/`v5.2`. CI tests `MOODLE_502_STABLE`.
- v5.2 `version.php`: `requires 2026042000`, depends on `theme_boost 2026042000`. That requirement is satisfied by 5.3.
- Release lag:
  - 5.0: v5.0 shipped 15 days after Moodle 5.0.
  - 5.1: v5.1 shipped about 2.5 months after Moodle 5.1.
  - 5.2: v5.2 shipped 9 days after Moodle 5.2.
- **[I]** v5.3 is likely within weeks to about 3 months.

**Scan [V].**
- Partials used: `core/moremenu`, `theme_boost/drawer`, `courseindexdrawercontrols`, `primary-drawer-mobile`,
  `head` and others. All exist in 5.3.
- `theme_boost/nav-drawer` is missing in 5.2+, but it is used only by `templates/columns2.mustache`, and no layout
  in `config.php` uses `columns2.php`.
- `drawers.mustache` overrides `{{$drawerheadercontent}}`, a block that 5.3's `theme_boost/drawer` no longer
  has. The override becomes a silent no-op.

**[I]** The theme's own navbar keeps the legacy `core/moremenu`, so primary navigation won't get 5.3's React look.
Mixed visuals are possible: Noto Sans font, dark-mode variables, design-system edit switch. Open issue #100 already
reports hidden elements on 5.1.

**Security [V].** Open issue #113 (2026-09-26): an unauthenticated login request can change the global Academi
preset (present since v4.3.3). Check whether the site's version is affected, independent of the upgrade.

### 3.4 Microsoft 365 suite (Microsoft / Enovation)

**Directory [V].**
- 5.2.1 (2026-09-02): auth_oidc, local_o365, local_office365, block_microsoft, assignfeedback_onenote,
  tiny_teamsmeeting.
- 5.2.0 (2026-07-07): local_onenote, repository_office365, assignsubmission_onenote, theme_boost_o365teams.
- Each build supports exactly one branch.
- None has `supported` or `incompatible` in `version.php`. auth_oidc 5.2.1 `requires 2026042000`.

**Repo [V].**
- `microsoft/o365-moodle` (monorepo) and `Microsoft/moodle-auth_oidc` (split repo) both have `MOODLE_27_STABLE` …
  `MOODLE_502_STABLE`. **No `MOODLE_503_STABLE`.** No `wip-*-m503` branches; the current wip branches stop at
  `-m502`.
- `gh issue list --search "5.3"` / `"Moodle 5.3"` / `MOODLE_503` returns nothing.
- Very active: issues and PRs opened 2026-10-07 to 2026-10-09.
- Lag from Moodle release to the Microsoft x.0 tag:
  - 5.0: about 5.5 months (to 2025-09-29).
  - 5.1: about 4 months (to 2026-02-10).
  - 5.2: about 2.5 months (to 2026-07-07).
- **[I]** Expect a 5.3 branch around December 2026 to February 2027.

**Scan of `MOODLE_502_STABLE` [V]:**
- `local/o365/classes/webservices/read_assignments.php:281` `external_format_text(` → **throws on 5.3**. This is
  the Teams/Moodle-app assignments web service. Fix: `\core_external\util::format_text(...)` (same signature
  semantics).
- `mod/assign/feedback/onenote/externallib.php` uses global `external_api`, `external_value` and so on after
  requiring `lib/externallib.php`. In 5.3 the aliases moved to `lib/db/renamedclasses.php`, so this still works,
  with debug notices.
- `user_update_user`/`user_create_user` (auth_oidc, usersync, SDS): deprecated in 5.3, wrappers in `lib/deprecatedlib.php`.
  `moveto_module`, `course_delete_module`, `user_get_user_details`: deprecated, still present.
- `theme_boost_o365teams` `iframeChecker.js` shows `div#nav-drawer`, which no longer exists, so that call does
  nothing. Open PRs #3775–3777 (2026-10-09) remove dead `core_course_renderer` overrides from this theme.
- Uses `\Firebase\JWT\JWT`/`JWK`, still shipped in `public/lib/php-jwt`.
- **[I]** auth_oidc on 5.3 should work. Sign-in is the critical path, so it is test item #1 on staging.

**Dependencies [V].**
- `local_o365` → `auth_oidc`.
- `block_microsoft`, `repository_office365`, `theme_boost_o365teams` and `local_onenote` → `local_o365`.
- The onenote assign plugins → `local_onenote`.
- `local_office365` → all of the above.
- auth_oidc can only be removed if the whole suite goes.

**atto_teamsmeeting [V].** It supports 4.5 only. `enovation/moodle-atto_teamsmeeting` master = `MOODLE_405_STABLE`
(v4.5.8). The 5.x upgrade removes it automatically with Atto. `tiny_teamsmeeting` has `MOODLE_500/501/502_STABLE`.

---

## 4. auth_oidc → core auth_oauth2 (Microsoft issuer): could it replace it?

### 4.1 How core matches users [V, `public/auth/oauth2/classes/auth.php` and `classes/api.php`, 5.3]

**User data and username.**
- `client::map_userinfo_to_fields()` builds `username` from the issuer's field mappings.
- If no mapping produces `username`, **username = email**.
- Login fails if username or email is empty (`loginerror_userincomplete`).

**Linked logins.**
- `api::match_username_to_user($username, $issuer)` looks up `auth_oauth2_linked_login` by
  `(issuerid, username)` (unique key `userid, issuerid, username`).
- If a confirmed linked login exists, that Moodle user logs in, **whatever its `user.auth` value**. Login ends with
  `complete_user_login()` and never calls `authenticate_user_login()`.
- With no linked login, it looks up an existing user **by email** (`get_user_by_email(..., IGNORE_MULTIPLE)`):
  - If `requireconfirmation` is off, it links automatically and logs the user in.
  - If on, it sends a confirm-link email.
  - If no user has that email, it creates a new `auth='oauth2'` user, unless `authpreventaccountcreation` is set.
    It refuses when the username already exists with a different email.

**Profile sync and Microsoft defaults.**
- Profile fields sync only for users whose `auth == 'oauth2'` (`update_user()`).
- The core Microsoft template (`core\oauth2\service\microsoft`) uses `baseurl https://login.microsoftonline.com/common/v2.0`
  and scopes `openid profile email user.read`.
- Its default field mappings include **`sub → idnumber`**, plus given/family name, email, displayName,
  officeLocation, mobilePhone and locale.

### 4.2 Migration recipe [I, built on the verified behaviour above]

1. Prepare the issuer.
   - In Entra, reuse the existing app registration and add the redirect URI `https://learn.aspireschool.org/admin/oauth2callback.php`.
   - Create a Microsoft OAuth2 service with a **tenant-specific** base URL
     (`https://login.microsoftonline.com/<tenant-id>/v2.0`), not `common`.
   - Set "login domains" to the school's domains.
   - Turn `requireconfirmation` off.
   - **Delete the `sub → idnumber` mapping**, or it will overwrite the school's ID numbers once users are `oauth2`.
   - Add `preferred_username → username` so the linked username equals the UPN, which is presumably today's Moodle
     username (check against `auth_oidc_token.oidcusername` / `mdl_user.username`).
2. Pre-seed `auth_oauth2_linked_login` from `auth_oidc_token`: (`userid`, new `issuerid`, `username` = lower-cased
   UPN, `email`, `confirmtoken=''`, `confirmtokenexpires=0`, timestamps). This avoids relying on email matching:
   duplicate emails are silently resolved by `IGNORE_MULTIPLE`, and some Entra accounts lack a `mail` attribute.
3. `UPDATE mdl_user SET auth='oauth2' WHERE auth='oidc'`. Logins would work without this (step 2). The step is still
   needed because:
   - code paths call `get_auth_plugin($user->auth)`, which fails once auth_oidc is uninstalled;
   - with `auth='oauth2'`, profile sync works.
4. Enable `auth_oauth2` and disable `auth_oidc`. Test with pilot accounts. Then uninstall the M365 suite top-down
   (`local_office365`, block, repository, theme, onenote, `local_o365`, `auth_oidc`).

**What is lost:**
- Entra user sync and provisioning (`local_o365` usersync creates and suspends accounts).
- Teams course sync, SDS sync, OneNote assignments, the Teams theme and the Microsoft block.
- OneDrive browsing can be replaced by core `repository_onedrive`.
- New users would need another provisioning route, such as CSV/cohort upload or core oauth2 auto-create on first login.

**Effort and cost.** About 2–3 days including a pilot. It is reversible while auth_oidc is still installed but
disabled (linked logins and `user.auth` can be switched back).

**Verdict [I].** The Microsoft suite is the most actively maintained plugin set here and has no 5.3 blocker found.
Replacing it gains nothing for 5.3 and loses provisioning and Teams features. Keep it in reserve as an emergency path
if auth_oidc fails on 5.3 staging.

---

## 5. Recommendation

1. **Target 5.3 LTS** (support runs to late 2029 [I, LTS norm]). Do not stop at 5.2: it is a short-life non-LTS
   release, and it hits the same two Tiles blockers.
2. Only one plugin truly blocks the upgrade: **format_tiles**. Fix it with a local patch: the `\Mustache\Engine`
   switch, `core/modal_factory` → `core/modal` `create()`, and `supported` widened.
   - Base it on `leonstr/moodle-format_tiles` (commits `4d4461c`, `3ef1cfd`).
   - Track upstream Tiles. If no 5.3 release by 5.3's first point releases, consider migrating to
     `format_softcourse` for the longer term.
3. **Lightbox 4.5.4**: keep as-is on 5.3 (no removed APIs). The upgrade does not need a gallery migration. Re-check
   when upstream master is released.
4. **Microsoft suite**: run 5.2.1 on 5.3. Patch the one `external_format_text()` call in `local_o365`. Swap to the
   `MOODLE_503_STABLE` builds when Microsoft publishes them (expected around Dec 2026 to Feb 2027 [I]).
   Atto/atto_teamsmeeting disappear automatically; install `tiny_teamsmeeting` only if wanted.
5. **Academi**: run v5.2.1 on 5.3 after visual QA, or wait for v5.3 (likely soon [I]). Separately, assess open
   security issue #113 now.
6. **Mandatory staging rehearsal on a copy of production** before the jump:
   - Test order:
     - SSO via auth_oidc with several user types
     - Tiles courses: modal activities, the icon picker, subsections
     - galleries: view, edit and backup/restore, including `local_aspireparent`
     - Academi front page, login and course pages, light and dark
     - Teams/OneNote features
   - Run with `debug=DEVELOPER` to catalogue the remaining deprecation notices.
7. If any patch is unacceptable, fall back to **5.1.8** (all plugins declare 5.1). Plan the 5.3 jump for when the
   vendors catch up.

### Key sources

- Plugins directory: `https://download.moodle.org/api/1.3/pluglist.php` (datarevision 1791627303 = 2026-10-09);
  `pluginfo.php?plugin=format_tiles&format=json&branch=5.3`
- `https://bitbucket.org/dw8/moodle-format_tiles` ·
  `https://github.com/leonstr/moodle-format_tiles/commit/4d4461c` · `.../commit/3ef1cfd`
- `https://github.com/open-lms-open-source/moodle-mod_lightboxgallery` (PR #126, #133)
- `https://github.com/lmsace/academi` (issues #100, #113)
- `https://github.com/microsoft/o365-moodle` (branches; PRs #3770–3777); `https://github.com/Microsoft/moodle-auth_oidc`
- `https://github.com/enovation/moodle-atto_teamsmeeting`, `https://github.com/enovation/moodle-tiny_teamsmeeting`
- `https://github.com/gjb2048/moodle-format_grid`, `https://github.com/moodlehq/moodle-editor_atto`
- Alternatives:
  - `https://github.com/DigiDago/moodle-course_format_softcourse`
  - `https://github.com/james-cnz/moodle-format_multitopic`
  - `https://github.com/marinaglancy/moodle-format_flexsections`
  - `https://github.com/dthies/moodle-format_popups`
  - `https://github.com/mkpelletier/format_simple`
  - `https://github.com/grabs/moodle-mod_unilabel`
  - `https://github.com/netspotau/moodle-mod_mediagallery`
  - `https://github.com/ralferlebach/moodle-mod_vimigallery`
- Moodle (`tmp/moodle`, `origin/MOODLE_503_STABLE` unless noted):
  - `UPGRADING.md`; `public/course/format/UPGRADING.md`; `public/course/UPGRADING.md`
  - `public/lib/externallib.php`; `public/lib/db/renamedclasses.php`
  - `public/lib/mustache/src/compat.php`; `public/lib/classes/component.php`
  - `public/lib/classes/plugininfo/{base,format}.php`; `public/lib/classes/plugin_manager.php`
  - `public/course/format/classes/base.php`; `public/lib/db/upgrade.php`
  - `public/auth/oauth2/classes/{auth,api}.php`; `public/auth/oauth2/db/install.xml`
  - `public/lib/classes/oauth2/{client.php,service/microsoft.php}`
  - `public/admin/environment.xml`; `public/lib/deprecatedlib.php`; `public/user/lib.php`
