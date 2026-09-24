#!/bin/bash
#
# Setup script for the Claude Code cloud environment (claude.ai/code).
#
# Paste this file's contents into the environment's "Setup script" field. It runs
# as root on Ubuntu 24.04 before Claude starts, and the result is cached, so later
# sessions start with everything installed. The SessionStart hook
# (scripts/cloud-session-start.sh) runs it too when something is missing, so a
# session also works without it, just more slowly.
#
# Rules for setup scripts: exit 0 and finish within about five minutes.
export DEBIAN_FRONTEND=noninteractive

# PHP 8.3 and Composer are pre-installed. Add the extensions the test suite uses.
required=(bcmath curl intl mbstring memcached pcov pdo_mysql pdo_pgsql pdo_sqlite redis xml zip)
missing=()
for extension in "${required[@]}"; do
    php -m 2>/dev/null | grep -qix "$extension" || missing+=("$extension")
done

if [ ${#missing[@]} -gt 0 ]; then
    echo "Installing PHP extensions: ${missing[*]}"
    apt-get update -qq || true
    for extension in "${missing[@]}"; do
        case "$extension" in
            pdo_mysql) package=mysql ;;
            pdo_pgsql) package=pgsql ;;
            pdo_sqlite) package=sqlite3 ;;
            *) package=$extension ;;
        esac
        # PECL extensions are packaged as php-<name> on some Ubuntu releases.
        apt-get install -y -qq --no-install-recommends "php8.3-$package" \
            || apt-get install -y -qq --no-install-recommends "php-$package" \
            || echo "Could not install the $extension extension."
    done
fi

# Pull the service images once, so the cached environment has them on disk.
if docker info >/dev/null 2>&1; then
    for image in redis:7.4-alpine valkey/valkey:8-alpine memcached:1.6-alpine mysql:8.4 postgres:16-alpine; do
        docker pull -q "$image" >/dev/null 2>&1 &
    done
    wait
fi

exit 0
