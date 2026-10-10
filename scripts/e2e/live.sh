#!/usr/bin/env bash
# Spin up a throwaway Moodle with this plugin and run real MCP SDK clients against it.
# Usage: scripts/e2e/live.sh [moodle-source-dir]   (default: tmp/moodle). Leaves containers running; `live.sh down` removes them.
set -euo pipefail

# MCP Apps ship inline JavaScript that no PHP test executes: a syntax error leaves the app stuck on "Loading".
for app in "$(dirname "$0")"/../../apps/*.html; do
    python3 -c 'import re,sys; print(re.search(r"<script>(.*)</script>", open(sys.argv[1]).read(), re.S).group(1))' "$app" \
        > /tmp/mcp-app-check.js
    if ! node --check /tmp/mcp-app-check.js; then echo "JS syntax error in $app" >&2; exit 1; fi
done

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK="${WORK:-/tmp/mcp-e2e}"
PORT="${PORT:-8099}"
DBPORT="${DBPORT:-3399}"
IMAGE="${IMAGE:-moodle-mcp-test-plugin-ci}"

down() { docker rm -f mcp-e2e-db mcp-e2e-web >/dev/null 2>&1 || true; }
if [[ "${1:-}" == "down" ]]; then down; exit 0; fi
MOODLE_SRC="${1:-${ROOT}/tmp/moodle}"

down
# Host networking on dedicated ports, like the PHPUnit harness (container bridge traffic is unreliable here).
docker run -d --name mcp-e2e-db --network host -e MYSQL_ALLOW_EMPTY_PASSWORD=true -e MYSQL_ROOT_HOST=% \
    mariadb:10.11 --port="$DBPORT" --bind-address=127.0.0.1 \
    --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci >/dev/null

mkdir -p "$WORK"
rm -rf "$WORK/data" && mkdir -p "$WORK/data"
rsync -a --delete --exclude .git "$MOODLE_SRC/" "$WORK/moodle/"
rsync -a --delete --exclude .git --exclude tmp --exclude node_modules --exclude .planning --exclude dist \
    "$ROOT/" "$WORK/moodle/webservice/mcp/"

docker run -d --name mcp-e2e-web --network host \
    -v "$WORK/moodle:/moodle" -v "$WORK/data:/data" "$IMAGE" sleep infinity >/dev/null

# First boot restarts the server after init, so wait until the web container can really query it.
for _ in $(seq 1 90); do
    timeout 10 docker exec -e P="$DBPORT" mcp-e2e-web php -r '$m = mysqli_init(); $m->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
        exit(@$m->real_connect("127.0.0.1", "root", "", "", (int)getenv("P")) ? 0 : 1);' >/dev/null 2>&1 && break
    sleep 1
done

docker exec mcp-e2e-web php /moodle/admin/cli/install.php --non-interactive --agree-license --lang=en \
    --wwwroot="http://localhost:${PORT}" --dataroot=/data --dbtype=mariadb --dbhost=127.0.0.1 --dbport="$DBPORT" --dbname=moodle \
    --dbuser=root --dbpass= --fullname="MCP E2E" --shortname=MCPE2E --adminuser=admin --adminpass='Admin-pass1!' \
    --adminemail=admin@example.com >/dev/null
docker exec mcp-e2e-web sed -i 's#^require_once#$CFG->mcpe2e = true;\n$CFG->noemailever = true;\nrequire_once#' /moodle/config.php

SEED="$(docker exec mcp-e2e-web php /moodle/webservice/mcp/scripts/e2e/setup.php | tail -1)"
for who in admin teacher student; do
    docker exec mcp-e2e-web php /moodle/webservice/mcp/cli/keys.php --issue --usernames="$who" --label=e2e \
        --scope=write --output="/data/key-$who.csv" >/dev/null
done

docker exec -d -e PHP_CLI_SERVER_WORKERS=6 mcp-e2e-web sh -c \
    "php -d log_errors=1 -d error_log=/data/php.log -S 127.0.0.1:${PORT} -t /moodle > /data/server.log 2>&1"
sleep 2

key() { docker exec mcp-e2e-web php -r '$r = array_map("str_getcsv", file($argv[1])); $h = $r[0]; echo array_combine($h, $r[1])["token"];' "/data/key-$1.csv"; }
export MCP_URL="http://localhost:${PORT}/webservice/mcp/server.php"
MCP_ADMIN_KEY="$(key admin)"
MCP_TEACHER_KEY="$(key teacher)"
MCP_STUDENT_KEY="$(key student)"
export MCP_SEED="$SEED" MCP_ADMIN_KEY MCP_TEACHER_KEY MCP_STUDENT_KEY

CLIENT_DIR="${CLIENT_DIR:-$WORK/client}"
if [[ ! -d "$CLIENT_DIR/node_modules/@modelcontextprotocol/client" ]]; then
    mkdir -p "$CLIENT_DIR" && (cd "$CLIENT_DIR" && npm init -y >/dev/null \
        && npm i @modelcontextprotocol/client@^2 @modelcontextprotocol/sdk@^1 >/dev/null)
fi
cp "$ROOT/scripts/e2e/clients.mjs" "$CLIENT_DIR/clients.mjs"
node "$CLIENT_DIR/clients.mjs"
