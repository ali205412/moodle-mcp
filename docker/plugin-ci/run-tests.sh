#!/usr/bin/env bash
set -euo pipefail

CI_DIR="${CI_DIR:-/opt/moodle-plugin-ci/ci}"
PLUGIN_DIR="${PLUGIN_DIR:-/plugin}"
DB="${DB:-mariadb}"
DB_HOST="${DB_HOST:-mariadb}"
MOODLE_BRANCH="${MOODLE_BRANCH:-MOODLE_402_STABLE}"
MOODLE_PLUGIN_CI_VERSION="${MOODLE_PLUGIN_CI_VERSION:-^4}"
TEST_STEPS="${TEST_STEPS:-phplint validate savepoints phpunit}"
WORK_DIR="${WORK_DIR:-/work/run}"
RUN_ID="${RUN_ID:-$(date +%s)}"
DB_NAME="${DB_NAME:-moodle_mcp_${RUN_ID}}"
MOODLE_DIR="${MOODLE_DIR:-${WORK_DIR}/moodle}"
DATA_DIR="${DATA_DIR:-${WORK_DIR}/moodledata}"
PLUGIN_COPY_DIR="${PLUGIN_COPY_DIR:-${WORK_DIR}/plugin}"

if [[ ! -x "${CI_DIR}/bin/moodle-plugin-ci" ]]; then
    rm -rf "${CI_DIR}"
    composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci "${CI_DIR}" "${MOODLE_PLUGIN_CI_VERSION}"
fi

select_node_prefix() {
    case "${MOODLE_BRANCH}" in
        MOODLE_402_STABLE)
            echo "/opt/node20"
            ;;
        *)
            echo "/opt/node22"
            ;;
    esac
}

NODE_PREFIX="$(select_node_prefix)"
export PATH="${CI_DIR}/bin:${CI_DIR}/vendor/bin:${NODE_PREFIX}/bin:${PATH}"
export DB
export MOODLE_BRANCH

echo "Using Moodle branch: ${MOODLE_BRANCH}"
echo "Using database: ${DB}"
echo "Using database name: ${DB_NAME}"
echo "Using node: $(node --version)"
echo "Running steps: ${TEST_STEPS}"

git config --global --add safe.directory "${PLUGIN_DIR}"

rm -rf "${WORK_DIR}"
mkdir -p "${WORK_DIR}"
rsync -a \
    --delete \
    --exclude '.git' \
    --exclude '.github' \
    --exclude '.planning' \
    --exclude 'dist' \
    --exclude 'moodle' \
    --exclude 'moodledata' \
    --exclude 'node_modules' \
    --exclude 'tmp' \
    "${PLUGIN_DIR}/" "${PLUGIN_COPY_DIR}/"

moodle-plugin-ci install \
    --plugin "${PLUGIN_COPY_DIR}" \
    --db-host="${DB_HOST}" \
    --db-name="${DB_NAME}" \
    --moodle="${MOODLE_DIR}" \
    --data="${DATA_DIR}"

for step in ${TEST_STEPS}; do
    case "${step}" in
        phplint)
            moodle-plugin-ci phplint
            ;;
        validate)
            moodle-plugin-ci validate
            ;;
        savepoints)
            moodle-plugin-ci savepoints
            ;;
        phpmd)
            moodle-plugin-ci phpmd || true
            ;;
        phpcs)
            moodle-plugin-ci phpcs --max-warnings 1 || true
            ;;
        phpdoc)
            moodle-plugin-ci phpdoc --max-warnings 0
            ;;
        mustache)
            moodle-plugin-ci mustache
            ;;
        grunt)
            moodle-plugin-ci grunt --max-lint-warnings 0
            ;;
        phpunit)
            moodle-plugin-ci phpunit --fail-on-warning
            ;;
        behat)
            moodle-plugin-ci behat --profile chrome --scss-deprecations
            ;;
        *)
            echo "Unknown test step: ${step}" >&2
            exit 1
            ;;
    esac
done
