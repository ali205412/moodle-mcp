#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.test.yml"
DATABASE="${1:-mariadb}"

cleanup() {
    docker compose -f "${COMPOSE_FILE}" --profile pgsql down --remove-orphans >/dev/null 2>&1 || true
}

wait_for_service_health() {
    local service="$1"
    local container_id=""
    local status=""

    for _ in $(seq 1 60); do
        container_id="$(docker compose -f "${COMPOSE_FILE}" --profile pgsql ps -q "${service}")"
        if [[ -n "${container_id}" ]]; then
            status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "${container_id}")"
            if [[ "${status}" == "healthy" ]]; then
                return 0
            fi

            if [[ "${status}" == "exited" || "${status}" == "dead" ]]; then
                docker logs "${container_id}" >&2 || true
                return 1
            fi
        fi

        sleep 1
    done

    if [[ -n "${container_id}" ]]; then
        docker logs "${container_id}" >&2 || true
    fi

    echo "Timed out waiting for ${service} to become healthy." >&2
    return 1
}

cleanup
trap cleanup EXIT

case "${DATABASE}" in
    mariadb)
        docker compose -f "${COMPOSE_FILE}" up -d mariadb
        wait_for_service_health mariadb
        docker compose -f "${COMPOSE_FILE}" run --build --rm --no-deps plugin-ci
        ;;
    pgsql|postgres|postgresql)
        docker compose -f "${COMPOSE_FILE}" --profile pgsql up -d pgsql
        wait_for_service_health pgsql
        docker compose -f "${COMPOSE_FILE}" --profile pgsql run --build --rm --no-deps plugin-ci-pgsql
        ;;
    *)
        echo "Usage: bash scripts/run-local-tests.sh [mariadb|pgsql]" >&2
        exit 1
        ;;
esac
