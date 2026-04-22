#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
STAGE_DIR="${DIST_DIR}/package"
PLUGIN_DIR_NAME="mcp"
VERSION="$(
    sed -n "s/^\$plugin->release = '\(.*\)';/\1/p" "${ROOT_DIR}/version.php" | head -n1
)"
if [[ -z "${VERSION}" ]]; then
    VERSION="$(
        sed -n "s/^\$plugin->version = \([0-9][0-9]*\);/\1/p" "${ROOT_DIR}/version.php" | head -n1
    )"
fi
if [[ -z "${VERSION}" ]]; then
    echo "Could not determine plugin version from version.php" >&2
    exit 1
fi
ARCHIVE_NAME="webservice_mcp-${VERSION}.zip"

rm -rf "${STAGE_DIR}"
mkdir -p "${STAGE_DIR}/${PLUGIN_DIR_NAME}"

rsync -a \
    --exclude '.git' \
    --exclude '.github' \
    --exclude '.gitignore' \
    --exclude '.planning' \
    --exclude 'AGENTS.md' \
    --exclude 'dist' \
    --exclude 'docker' \
    --exclude 'docker-compose.test.yml' \
    --exclude 'scripts' \
    --exclude 'tmp' \
    "${ROOT_DIR}/" "${STAGE_DIR}/${PLUGIN_DIR_NAME}/"

(
    cd "${STAGE_DIR}"
    rm -f "../${ARCHIVE_NAME}"
    zip -r "../${ARCHIVE_NAME}" "${PLUGIN_DIR_NAME}" >/dev/null
)

echo "${DIST_DIR}/${ARCHIVE_NAME}"
