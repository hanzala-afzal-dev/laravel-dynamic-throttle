#!/usr/bin/env bash
#
# The browser smoke test's server (SPEC-036): the Workbench app, built afresh with
# the demo's data, counting in Redis, so the live feed works. Playwright starts it
# when no server answers at the test's address yet (e2e/playwright.config.ts).
#
#   bash e2e/serve.sh [port]
#
# Needs PHP 8.3+ with the redis extension, and Redis (docker compose up -d redis).
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

PORT="${1:-8000}"
export REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
export REDIS_PORT="${REDIS_PORT:-6379}"

if ! (exec 3<>"/dev/tcp/${REDIS_HOST}/${REDIS_PORT}") 2>/dev/null; then
    echo "The browser smoke test needs Redis on ${REDIS_HOST}:${REDIS_PORT}: docker compose up -d redis" >&2
    exit 1
fi

export CACHE_STORE=redis
# Keys of their own on each start: a run never sees an earlier run's counters or
# live feed, whose rules had the same ids in the database built before.
export DYNAMIC_THROTTLE_PREFIX="smoke-$(date +%s):"
# The dashboard's own limit, 120 requests a minute, is off (WorkbenchServiceProvider),
# so the test can run again within the minute.
export WORKBENCH_SMOKE=true
# PHP's built-in server answers the dashboard's requests side by side.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
# The Docker demo's php.ini: the served app sees the variables above through $_ENV.
export PHP_INI_SCAN_DIR=":${PWD}/docker/workbench"

vendor/bin/testbench workbench:build --ansi
exec vendor/bin/testbench serve --host=127.0.0.1 --port="${PORT}" --no-reload
