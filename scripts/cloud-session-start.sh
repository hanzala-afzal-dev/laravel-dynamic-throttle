#!/bin/bash
#
# SessionStart hook: prepares a Claude Code cloud session (claude.ai/code).
# Does nothing on a local machine, where Docker handles everything (CLAUDE.md).
#
# 1. Makes sure the PHP extensions are there (scripts/cloud-setup.sh).
# 2. Installs the Composer dependencies. A library commits no composer.lock.
# 3. Starts Redis, Valkey, Memcached, MySQL and PostgreSQL in the background,
#    for `composer test:drivers` and `composer test:mutate`.
#
# Its output is added to Claude's context, so it prints one short status line.
[ "${CLAUDE_CODE_REMOTE:-}" = "true" ] || exit 0
cd "${CLAUDE_PROJECT_DIR:-$(dirname "$0")/..}" || exit 0

status=()

if ! php -m 2>/dev/null | grep -qix pcov || ! php -m 2>/dev/null | grep -qix redis; then
    bash scripts/cloud-setup.sh >/tmp/dynamic-throttle-setup.log 2>&1 || true
fi
status+=("PHP $(php -r 'echo PHP_VERSION;' 2>/dev/null)")

if [ ! -f vendor/autoload.php ]; then
    if composer update --prefer-dist --no-interaction --no-progress >/tmp/dynamic-throttle-composer.log 2>&1; then
        status+=("dependencies installed")
    else
        status+=("composer update FAILED, see /tmp/dynamic-throttle-composer.log")
    fi
else
    status+=("dependencies present")
fi

if ! docker info >/dev/null 2>&1; then
    (service docker start || (dockerd >/tmp/dockerd.log 2>&1 &)) >/dev/null 2>&1
    for _ in $(seq 1 20); do docker info >/dev/null 2>&1 && break; sleep 1; done
fi

if docker info >/dev/null 2>&1; then
    nohup docker compose up -d redis valkey memcached mysql postgres >/tmp/dynamic-throttle-services.log 2>&1 &
    status+=("services starting in the background (docker compose ps)")
else
    status+=("Docker unavailable: driver tests will fail, see /tmp/dockerd.log")
fi

echo "Cloud session: $(IFS=', '; echo "${status[*]}"). Run composer commands directly, without the docker prefix."
exit 0
