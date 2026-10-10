# Production deploy: webservice_mcp 0.8.1 (2026050101) -> 0.9.0 (2026101006)

Target: learn.aspireschool.org (`ssh ali@server.aspireschool.org`, fish shell: wrap in `bash -s`),
Moodle 4.5.6 at `/var/www/html/moodle`, web user `nginx`, DB mysqli `moodledb` (prefix `mdl_`).
Nothing below runs without explicit approval. Expected downtime: about 1-2 minutes of maintenance mode.

## 0. Pre-flight (read-only, already done 2026-10-09)

- Plugin version 2026050101, no other pending upgrades.
- 1682 credential rows (844 active), 14 OAuth clients (13 claude.ai, 1 mofeed.info), 2827 audit rows.
- Connector service id 11, `component=webservice_mcp` (the upgrade detaches it so core stops deleting it).
- Cron runs every minute. 557 GB free.
- `$CFG->maxbytes=0`, PHP `upload_max_filesize=2M` (MCP uploads stream, plugin default cap 2 GB).

## 1. Package (local)

Commit the release on a branch, then build the tarball from that commit, so production gets exactly
what was tested (no scratch files, no `dist/`, no `tmp/`):

```
git switch -c release/0.9.0 && git add <plugin files> && git commit
git archive --format=tar.gz --prefix=mcp/ HEAD -o /tmp/webservice_mcp-0.9.0.tgz
scp /tmp/webservice_mcp-0.9.0.tgz ali@server.aspireschool.org:/tmp/
```

## 2. Backup (production)

```
TS=$(date +%Y%m%d-%H%M); B=/var/backups/webservice_mcp-$TS; sudo mkdir -p $B
sudo tar czf $B/plugin-0.8.1.tgz -C /var/www/html/moodle/webservice mcp
D="sudo mysqldump --single-transaction --skip-add-drop-table --no-create-info --replace moodledb"
# Plugin-owned tables: full, with structure (rollback recreates them as they were).
sudo mysqldump --single-transaction moodledb \
  $(sudo mysql -N moodledb -e "SHOW TABLES LIKE 'mdl\_webservice\_mcp\_%'") | sudo tee $B/plugin-tables.sql > /dev/null
# Shared core tables: only the rows this plugin owns or the upgrade changes.
$D mdl_config_plugins --where="plugin='webservice_mcp'" | sudo tee $B/config.sql > /dev/null
$D mdl_external_services --where="id=11" | sudo tee $B/service.sql > /dev/null
$D mdl_external_services_functions --where="externalserviceid=11" | sudo tee -a $B/service.sql > /dev/null
$D mdl_external_services_users --where="externalserviceid=11" | sudo tee -a $B/service.sql > /dev/null
```

## 3. Deploy

```
cd /var/www/html/moodle
sudo -u nginx php admin/cli/maintenance.php --enable
sudo rm -rf webservice/mcp && sudo tar xzf /tmp/webservice_mcp-0.9.0.tgz -C webservice
sudo chown -R nginx:nginx webservice/mcp
sudo find webservice/mcp -type d -exec chmod 755 {} + ; sudo find webservice/mcp -type f -exec chmod 644 {} +
sudo -u nginx php admin/cli/upgrade.php --non-interactive
sudo -u nginx php admin/cli/maintenance.php --disable
```

The upgrade (steps 2026100900 to 2026101006) hashes stored tokens in place (once, transactional, flag-guarded),
detaches the connector service from the component, adds the task/jti tables, creates the signing secret and
seeds the redirect-host allowlist with existing clients' hosts (adds mofeed.info). New settings take defaults.

## 4. Verify (production)

- `sudo -u nginx php -r '...'`: plugin version 2026101006; service 11 still enabled, `component` NULL;
  active credential count unchanged (844); allowlist contains mofeed.info.
- `curl https://learn.aspireschool.org/webservice/mcp/server.php` without a token: 401 with `resource_metadata`.
- Protected resource metadata and server card return 200 JSON.
- Mint a short-lived admin key for one test account (`cli/keys.php --issue --usernames=<you> --days=1`), then over
  HTTP: `server/discover`, `tools/list`, `resources/read moodle://site`, upload 50 MB to private files and download
  it back (sha256 match). Revoke the key afterwards.
- An existing Claude connection keeps working without re-authorising.
- Watch `/var/log/nginx/error.log` and the PHP-FPM log for 10 minutes.

## 5. Rollback

```
sudo -u nginx php admin/cli/maintenance.php --enable
sudo rm -rf webservice/mcp && sudo tar xzf $B/plugin-0.8.1.tgz -C webservice
sudo mysql moodledb < $B/plugin-tables.sql   # plugin tables as they were (credentials keep their 0.8.1 form)
sudo mysql moodledb -e "DELETE FROM mdl_config_plugins WHERE plugin='webservice_mcp'"
sudo mysql moodledb < $B/config.sql          # plugin settings incl. version 2026050101
sudo mysql moodledb < $B/service.sql         # service 11 rows (REPLACE: restores component=webservice_mcp)
sudo -u nginx php admin/cli/purge_caches.php
sudo -u nginx php admin/cli/maintenance.php --disable
```

New tables created by the upgrade (`mdl_webservice_mcp_task`, `mdl_webservice_mcp_jti`, ...) are left behind
harmlessly; drop them if wanted.

## 6. Optional, separate approval

- **Fast large downloads:** nginx `X-Accel-Redirect` for moodledata plus `$CFG->xsendfile`/`xsendfilealiases` in
  `config.php`, so downloads don't hold one of the 5 PHP-FPM workers. Needs an nginx reload.
- **Root-level discovery:** nginx rewrites for `/.well-known/oauth-protected-resource/webservice/mcp/server.php`
  and `/.well-known/oauth-authorization-server/webservice/mcp` (today 404; clients use the 401 challenge first).
- **File permissions:** Moodle code is world-writable (`config.php` 777, plugin dir 777/666). Step 3 fixes the
  plugin dir only; tightening the rest of the Moodle tree is the site owner's call.

## Deploy record (2026-10-09)

- Release commit `51ea802` (branch `release/0.9.0`), tarball sha256 `ac8628acb8e0...`. Backup: `/var/backups/webservice_mcp-20261009-1642` (root-only;
  plugin tables row counts verified against live; `my.cnf` there holds the DB credentials, mode 600).
- Maintenance 16:42:49-16:43:55 (66 s). The first upgrade run stopped at Moodle's environment check: CLI
  `max_input_vars` was 1000 (FPM had 5000). Re-run with `php -d max_input_vars=5000` succeeded; CLI php.ini now 5000.
- Post-upgrade: version 2026101006, tokens hashed (flag set), service 11 detached (component NULL, 805 functions),
  unrevoked credentials 844 before and after (5 unexpired; the rest are expired hourly access tokens), allowlist
  includes mofeed.info, signing secret set.
- Smoke (official SDK v1 + v2 through nginx): all checks pass, incl. 50 MB upload/download sha256 match.
- Extras applied: nginx root discovery rewrites + internal `/dataroot/` (X-Accel, with `add_header nosniff`),
  `$CFG->xsendfile` in config.php, Moodle tree chown nginx + `u=rwX,go=rX` (was 9,591 world-writable entries;
  snapshot in `/var/backups/webservice_mcp-20261009-1642/moodle-perms.txt`), config.php 640, plugin `uploadmaxbytes` = 16 GiB (FPM limit is 16G; the
  "2 MB" seen earlier was the CLI php.ini only). Config backups: `moodle.conf.bak`, `config.php.bak`, `php-cli.ini.bak`.
- Open, not changed: Moodle cron runs as **root** (root crontab), 16 overlapping cron processes at the time.

## Deploy record: 0.9.1 to 0.9.3 (2026-10-09)

- 0.9.1 (2026101007, commit `0f52eae`): async exports, DB rate limit, admin key labels. Backup `/var/backups/webservice_mcp-20261009-1731`.
- 0.9.2 (2026101008, commit `2bf32ca`): Office/PDF text extraction, page images, short links, Explorer fix, cold-load
  library loader. Maintenance 11 s. Backup `/var/backups/webservice_mcp-20261009-1847`. Server: poppler-utils installed;
  LibreOffice headless build swapped for regular components (headless crashed on conversion).
- 0.9.3 (2026101009, commit `fc8d983`): retry document conversions core had cached as failed. Package sha256 `6d00e6b74fa17663...`.
  Maintenance 7 s. Backup `/var/backups/webservice_mcp-20261009-1857` (row presence verified per table).
- Verified on production: Y1 English T3 pptx/docx/pdf text, pdf page image (0.4 s), pptx slide image (11.6 s first
  conversion, cached after), short links (95 chars), Explorer renders courses in the MCP Apps reference host.
- Pre-release checks for 0.9.3: 305/305 PHPUnit on 4.2/MariaDB and 4.5/PostgreSQL (all CI steps), 82/82 live HTTP checks.
- Test key `deploy-smoke-091` revoked, local CSV shredded, local proxy/host/Chrome stopped.

## MCP log audit + 0.9.4 deploy (2026-10-09 evening)

- Audit scope: plugin audit table (all time), Moodle event log, nginx access/error logs and PHP-FPM log since the 0.9.0 deploy.
- Production data fixes (Moodle API, before-state in `/var/backups/webservice_mcp-20261009-1857/create_password-prefs-before.tsv`):
  removed `create_password` preference from users 2503/2611 (invalid emails `@Caprioledevelopments`, `@lah`), which made
  core `send_new_user_passwords_task` regenerate their passwords every minute since 2024-08 (~20k events/week);
  revoked orphan OAuth family `92887fd0…` left by mofeed's failed concurrent refresh at 17:28:50.
- 0.9.4 (2026101010, commit `1e85ee1`), package sha256 `371ce5e24e79921c…`, maintenance 8 s, backup
  `/var/backups/webservice_mcp-20261009-2008`. Pre-release: 313/313 PHPUnit on 4.2/MariaDB and 4.5/PostgreSQL (no skips),
  phpcs clean, 82/82 live checks, fingerprint unchanged.
- Verified on production: legacy-token refresh fix in place; explorer `courseid` alone opens the course; drafts listing;
  explained not-found; gateway validation reasons; wrapper input explanations; audit `detail` stored; rejected token
  audited (`invalid_token`); smoke 21/21; file checks all pass. Test keys revoked, CSV shredded.

## 0.9.5 Explorer redesign deploy (2026-10-10)

- Screen-by-screen audit of the Moodle Explorer MCP App in the official MCP Apps reference host (ext-apps basic-host)
  against production data: course list, course view, filter, download, Ask Claude, open in Moodle, error state,
  dark mode, 430 px width, keyboard focus. Found and fixed: `ui/message` content not an array (Ask Claude silently
  dropped by SDK hosts), non-focusable cards/links, no dark theme, panel height never shrinking, unreadable sizes.
- 0.9.5 (2026101011, commit `87d89fb`), maintenance 9 s, backup `/var/backups/webservice_mcp-20261010-0706`.
  Pre-release: 313/313 PHPUnit on 4.2/MariaDB and 4.5/PostgreSQL, phpcs clean, 82/82 live checks.
- Post-deploy, app served from production: activity type names, open-in-Moodle (header + activities), model context
  update all confirmed in the reference host. Test key `ui-audit` revoked; harness stopped.
