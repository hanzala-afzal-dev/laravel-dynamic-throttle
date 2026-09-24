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

wait_for_docker() {
    for _ in $(seq 1 20); do docker info >/dev/null 2>&1 && return 0; sleep 1; done
    return 1
}

if ! docker info >/dev/null 2>&1; then
    (service docker start || (dockerd >/tmp/dockerd.log 2>&1 &)) >/dev/null 2>&1
    # On a fresh container, dockerd sometimes gives up waiting for containerd.
    # A second start, without the stale processes, comes up in seconds.
    if ! wait_for_docker; then
        pkill dockerd; pkill containerd; sleep 1
        (dockerd >>/tmp/dockerd.log 2>&1 &) >/dev/null 2>&1
        wait_for_docker
    fi
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

# GitHub archive downloads are blocked for repositories not attached to the session,
# so Composer falls back to git clones. A package without a git source, such as
# phpstan/phpstan, has no fallback. This fetches the commit composer.lock names with
# git, and zips it where Composer's download cache expects the archive (approved by
# the maintainer on 2026-09-24). git archive honors export-ignore like GitHub does.
seed_dist_only_packages() {
    local cache name repository reference url work
    cache="$(composer config --global cache-files-dir 2>/dev/null)" || return 1

    php -r '
        $lock = json_decode((string) file_get_contents("composer.lock"), true);
        foreach ([...$lock["packages"] ?? [], ...$lock["packages-dev"] ?? []] as $package) {
            $url = $package["dist"]["url"] ?? "";
            if (empty($package["source"]) && preg_match("#^https://api\.github\.com/repos/([\w.-]+/[\w.-]+)/zipball/([0-9a-f]{40})$#", $url, $match)) {
                echo "{$package["name"]} {$match[1]} {$match[2]} {$url}\n";
            }
        }' | while read -r name repository reference url; do
        work="$(mktemp -d)"
        echo "Seeding $name from git at $reference"
        git -C "$work" init -q \
            && git -C "$work" fetch -q --depth 1 "https://github.com/$repository.git" "$reference" \
            && mkdir -p "$cache/$name" \
            && git -C "$work" archive --format=zip -o "$cache/$name/$(printf '%s' "$url" | sha1sum | cut -d' ' -f1).zip" FETCH_HEAD
        rm -rf "$work"
    done
}

if [ ! -f vendor/autoload.php ]; then
    # The cloud runs as root, and Composer skips plugins for root unless allowed.
    # Pest's plugin writes the manifest that loads --parallel, --mutate and the rest.
    export COMPOSER_ALLOW_SUPERUSER=1
    log=/tmp/dynamic-throttle-composer.log

    # The proxy sometimes drops a connection to Packagist, so try up to three times.
    for attempt in 1 2 3; do
        composer update --prefer-dist --no-interaction --no-progress >"$log" 2>&1 && break
        if grep -q 'Could not authenticate against github.com' "$log"; then
            # composer.lock is written before the downloads, so it names the exact commits.
            seed_dist_only_packages >>"$log" 2>&1
            composer install --no-interaction --no-progress >>"$log" 2>&1
            break
        fi
        sleep $((attempt * 5))
    done

    if [ -f vendor/autoload.php ]; then
        status+=("dependencies installed")
    else
        status+=("composer update FAILED, see $log")
    fi
else
    status+=("dependencies present")
fi

printf -v summary '%s, ' "${status[@]}"
echo "Cloud session: ${summary%, }. Run composer commands directly, without the docker prefix."
exit 0
