#!/bin/bash
#
# SessionStart hook: prepares a Claude Code cloud session (claude.ai/code).
# Does nothing on a local machine, where Docker handles everything (CLAUDE.md).
#
# 1. Starts Docker. In the background, pulls the service images and starts Redis,
#    Valkey, Memcached, MySQL and PostgreSQL for `composer test:drivers` and
#    `composer test:mutate`.
# 2. Installs missing PHP extensions (scripts/cloud-setup.sh).
# 3. Installs the Composer dependencies. A library commits no composer.lock.
#
# Its output is added to Claude's context, so it prints one short status line.
[ "${CLAUDE_CODE_REMOTE:-}" = "true" ] || exit 0
cd "${CLAUDE_PROJECT_DIR:-$(dirname "$0")/..}" || exit 0

status=()

if ! docker info >/dev/null 2>&1; then
    (service docker start || (dockerd >/tmp/dockerd.log 2>&1 &)) >/dev/null 2>&1
    for _ in $(seq 1 20); do docker info >/dev/null 2>&1 && break; sleep 1; done
fi

if docker info >/dev/null 2>&1; then
    nohup bash -c 'bash scripts/cloud-setup.sh images; docker compose up -d redis valkey memcached mysql postgres' \
        >/tmp/dynamic-throttle-services.log 2>&1 &
    status+=("services starting in the background (docker compose ps)")
else
    status+=("Docker unavailable: driver tests will fail, see /tmp/dockerd.log")
fi

bash scripts/cloud-setup.sh extensions >/tmp/dynamic-throttle-setup.log 2>&1
status+=("PHP $(php -r 'echo PHP_VERSION;' 2>/dev/null)")
if grep -q '^Could not install' /tmp/dynamic-throttle-setup.log; then
    status+=("PHP extensions missing, see /tmp/dynamic-throttle-setup.log")
fi

if [ ! -f vendor/autoload.php ]; then
    # The cloud runs as root, and Composer skips plugins for root unless allowed.
    # Pest's plugin writes the manifest that loads --parallel, --mutate and the rest.
    export COMPOSER_ALLOW_SUPERUSER=1
    log=/tmp/dynamic-throttle-composer.log

    # The proxy sometimes drops a connection to Packagist, so try up to three times.
    for attempt in 1 2 3; do
        composer update --prefer-dist --no-interaction --no-progress >"$log" 2>&1 && break
        # Not a network hiccup: see below.
        grep -q 'Could not authenticate against github.com' "$log" && break
        sleep $((attempt * 5))
    done

    if [ -f vendor/autoload.php ]; then
        status+=("dependencies installed")
    elif grep -q 'Could not authenticate against github.com' "$log"; then
        # GitHub archive downloads are blocked for repositories not attached to the
        # session. Composer falls back to git clones, except for packages without a
        # git source, such as phpstan/phpstan.
        status+=("composer update FAILED: this environment blocks GitHub archive downloads, and phpstan/phpstan has no git source to fall back to (see $log)")
    else
        status+=("composer update FAILED, see $log")
    fi
else
    status+=("dependencies present")
fi

printf -v summary '%s, ' "${status[@]}"
echo "Cloud session: ${summary%, }. Run composer commands directly, without the docker prefix."
exit 0
