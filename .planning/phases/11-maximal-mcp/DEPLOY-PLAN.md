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
