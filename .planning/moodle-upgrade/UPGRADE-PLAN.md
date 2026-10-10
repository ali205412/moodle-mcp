# learn.aspireschool.org: Moodle upgrade audit and plan (2026-10-10)

Read-only audit; nothing on production was changed. Evidence: production inventory over SSH, Moodle plugins
directory API (`pluglist.php`, fetched 2026-10-10), Moodle source branches 4.5/5.0/5.1/5.2/5.3 (`tmp/moodle`),
our plugin's test suite on 5.1.8, and a code audit of the custom plugins (`CUSTOM-PLUGINS-AUDIT.md`).

## 0. Urgent, independent of the upgrade

`local_aspireparent` lets **any logged-in user read any user's grades** (`get_mentee_grades.php:138`,
`get_mentee_course_grades.php:99`: "BYPASSING ALL PERMISSION CHECKS AS REQUESTED"), also through the mobile app
service. It also has predictable token generation (`get_mentee_token.php:122`), a token check that skips core's
validation (`applinks_file.php:50-66`), and an open diagnostic page (`test_parent_role.php`). Fix these now.
Also set `$CFG->disableupdateautodeploy = true;` (see section 4, parentmanager).

## 1. Where the site is

| | Now | Notes |
|---|---|---|
| Moodle | 4.5.6 (git `MOODLE_405_STABLE`) | latest 4.5 is **4.5.15**: 9 security releases behind |
| PHP | 8.1.34 (FPM 8.1) | ondrej PPA present; 8.2/8.3 installable |
| DB | MySQL 8.0.46 (Ubuntu), Moodle-only instance, 2.8 GB data, ~10 GB binlogs | MySQL 8.0 is end-of-life |
| OS | Ubuntu 22.04 | |
| Data | 1,075 courses, ~3,400 users (2,137 OIDC), 148 GB moodledata | |

## 2. Moodle releases (as of 2026-10-10)

| Release | Latest | Security support ends | PHP | MySQL | Upgrade from |
|---|---|---|---|---|---|
| 4.5 LTS (now) | 4.5.15 | **2027-10-04** | 8.1–8.3 | 8.0+ | |
| 5.0 | 5.0.11 | ended 2026-10-05 | 8.2–8.4 | **8.4** | 4.2.3 |
| 5.1 | 5.1.8 | **2027-04-19** | 8.2–8.4 | 8.4 | 4.2.3 |
| 5.2 | 5.2.4 | 2027-10-04 | **8.3**–8.4 | 8.4 | 4.4 |
| 5.3 LTS | 5.3.0 | **2029-10-01** | 8.3–8.4 | 8.4 | 4.4 |

4.5.6 can go straight to any of them.

## 3. Add-on plugins: newest Moodle each supports (plugins directory + their repos)

| Plugin | Used? | Max Moodle | Action |
|---|---|---|---|
| format_tiles | **484 courses** | **5.1** (repo `supported = [501, 501]`) | the binding constraint |
| mod_lightboxgallery | **832 galleries, 32k images** | **5.1** (dir 4.5.4); repo master 5.1 beta | binding constraint; `local_aspireparent` depends on it |
| Microsoft 365 suite (auth_oidc, local_o365, local_office365, local_onenote, block_microsoft, repository_office365, theme_boost_o365teams, assignsubmission/feedback_onenote) | SSO for 2,137 users | 5.2 (no 5.3 branch yet) | update to their matching branch |
| theme_academi | site theme + 483 course overrides | 5.2 | update to v5.x |
| atto_teamsmeeting | **unused** (not on toolbar) | 4.5 | uninstall; `tiny_teamsmeeting` exists (≤5.2) if wanted |
| local_cnw_smartcohort | **unused** (0 rules); cron every minute | 4.2 (unmaintained since 2023) | uninstall |
| local_aspireparent, local_parentmanager, local_privacypolicy, local_appdownload | custom | n/a | see section 4 |
| webservice_mcp (ours) | | | 346/350 tests pass on 5.1.8; 4 fail on the 5.0 question-bank change (fix: resolve the course's default `mod_qbank`) |

**Newest version every used plugin supports today: Moodle 5.1 (5.1.8).**

## 4. Custom plugins on 5.1 (`CUSTOM-PLUGINS-AUDIT.md`)

- **local_aspireparent:** one real break: `get_mentee_quiz_attempts.php:114` uses `\quiz_attempt` (alias removed in
  5.0); fix `\mod_quiz\quiz_attempt`. Plus the security issues in section 0 and several functions that already fail
  on 4.5 (wrong grade report class, `realuserid` vs `realuser`, a non-existent `parent` archetype).
- **local_parentmanager:** works (Bootstrap 4 class warnings; `form-row` layout breaks). **Name collides with the
  public "Parent/Child Manager" (E-learningTouch, version 2026100600 > ours 2026090500): Moodle's update checker will
  offer it as an "update" and installing it would wipe this plugin.** Set `$CFG->disableupdateautodeploy = true;`;
  consider renaming the component. `export.php` and reset-password call undefined functions.
- **local_privacypolicy, local_appdownload:** work; one Bootstrap 4 class each.

## 5. What the upgrade itself changes (5.0 + 5.1)

- **/public restructure (5.1):** web code moves to `public/`; nginx `root …/moodle/public;`; add-ons go in
  `public/<type>/<name>`; `config.php` stays at the repo root; CLI paths unchanged.
- **Router:** nginx `try_files $uri /r.php;` + `$CFG->routerconfigured = true;` (check alongside the existing
  slash-arguments rule on staging).
- **Composer:** 5.1's git tree has no `vendor/`; install Composer and run `composer install --no-dev
  --classmap-authoritative` on each upgrade.
- **Removed core plugins (5.0):** Atto editor (Tiny is already the default; content is unaffected), **mod_chat (18
  activities, last message Feb 2025, 275 messages)**, mod_survey (0 in use), MNet, auth_cas. If a removed plugin's
  code is absent at upgrade time, **Moodle uninstalls it and deletes its data**: export or accept losing the chat
  logs first.
- **Question banks (5.0, mod_qbank):** 1,438 categories (418 course-level, 222 category-level, 796 quiz-level, 2
  system). After the upgrade, cron moves course banks into "shared question bank" activities and category banks
  into new "Shared teaching resources for category: X" courses; until it finishes, questions can't be managed. Run
  the transfer task by hand right after upgrading.
- **Bootstrap 5 (5.0):** third-party themes need their 5.x builds (Academi has one); custom plugins' BS4 classes
  work through a compatibility layer until 6.0.
- **Infrastructure:** PHP 8.2+ (use 8.3) with the same extensions, MySQL 8.0 → **8.4** (Oracle MySQL APT repo; or
  move to MariaDB 10.11+/PostgreSQL 15+), keep `max_input_vars = 5000` (CLI too).

## 6. Recommendation

Moodle 5.1 is the newest version all used plugins support, **but its security support ends 2027-04-19, six months
before the 4.5 LTS you already run (2027-10-04).** Going to 5.1 now buys nothing in support time and forces a second
upgrade within six months. The right destination is **5.3 LTS (supported to Oct 2029)**, reachable from 4.5 in one
jump once Tiles, Lightbox Gallery and the Microsoft 365 plugins support it.

**Phase A: now (safe, on 4.5 LTS)**
1. Fix the `local_aspireparent` security issues; set `$CFG->disableupdateautodeploy = true;`.
2. Update core 4.5.6 → **4.5.15** (git pull on `MOODLE_405_STABLE`, CLI upgrade; minutes of maintenance).
3. Update add-ons to their newest 4.5-compatible releases (Microsoft suite 4.5.x, Lightbox 4.5.4, Academi, Tiles).
4. Uninstall `local_cnw_smartcohort` and `atto_teamsmeeting`; delete `lib.php.bak` and `test_parent_role.php`.
5. Upgrade **PHP 8.1 → 8.3** (4.5 supports 8.3) and **MySQL 8.0 → 8.4** (4.5 supports 8.4), purge old binlogs.
6. Install Composer; fix the custom plugins' 5.x issues (quiz_attempt, BS4 classes) and our plugin's qbank fix.
7. Decide on chat logs (export or drop) and on renaming `local_parentmanager`.

**Phase B: the jump (when Tiles, Lightbox and Microsoft publish 5.3 support; until then 5.1 is possible but not
recommended)**
1. Clone production to a staging server (DB + moodledata; same PHP 8.3 / MySQL 8.4) and rehearse end to end:
   `git checkout MOODLE_503_STABLE`, move add-ons to `public/`, `composer install`, nginx `public/` root + router,
   `admin/cli/upgrade.php`, run the qbank transfer tasks, purge caches, then test SSO, Tiles courses, galleries,
   parent features, quizzes and the MCP connector.
2. Time the rehearsal (especially the question-bank transfer) to size the production maintenance window.
3. Production: backup (code, DB, moodledata snapshot), maintenance mode, same steps, smoke tests, keep the old code
   and DB dump for rollback.

If you prefer to go to 5.1 now anyway: Phase A steps 1–7 first, then Phase B with `MOODLE_501_STABLE`, Tiles 5.1.0.2
and Lightbox 4.5.4, and plan the next upgrade before 2027-04-19.

## Done log

- 2026-10-10 10:39–10:44 UTC: core 4.5.6 → 4.5.15 (`git reset --hard v4.5.15` on MOODLE_405_STABLE, then `admin/cli/upgrade.php`).
  Backup: `/var/backups/moodle-upgrade-4515-20261010-1039/` (full DB dump 246 MB gz, previous git HEAD fb02f4fa9f2).
  Maintenance ~4 min (a root-owned `.git` log file blocked the first git step; fixed with `chown -R nginx:nginx .git`).
  Checks: pages/login OK, cron running, 0 PHP fatals, MCP production smoke test all passed.
  Rollback: maintenance on → `git reset --hard fb02f4fa9f2` → restore `moodledb.sql.gz` → maintenance off.
