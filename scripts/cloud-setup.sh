#!/bin/bash
#
# Setup script for the Claude Code cloud environment (claude.ai/code).
#
# Paste this file's contents into the environment's "Setup script" field. It runs
# as root on Ubuntu 24.04 when a session starts. The SessionStart hook
# (scripts/cloud-session-start.sh) runs it too, so a session also works without it.
#
#   bash scripts/cloud-setup.sh              PHP extensions, then service images
#   bash scripts/cloud-setup.sh extensions   only the PHP extensions
#   bash scripts/cloud-setup.sh images       only the service images
#
# What the cloud network allows (checked 2026-09-24), and how this script copes:
# - The image's PHP comes from the ondrej/php PPA, which the network blocks (403),
#   like packages.sury.org and pecl.php.net. The Ubuntu archive and git clones of
#   public GitHub repositories work, so a missing extension that apt cannot install
#   is built from its git tag with phpize.
# - Anonymous pulls from Docker Hub hit its rate limit (429), so images fall back to
#   mirror.gcr.io, Google's Docker Hub mirror, and get their Docker Hub names.
#
# Rules for setup scripts: exit 0 and finish within about five minutes.
export DEBIAN_FRONTEND=noninteractive

php_version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)"

# The extensions CI installs for the driver and mutation jobs (.github/workflows/ci.yml).
required=(bcmath curl intl mbstring memcached pcov pdo_mysql pdo_pgsql pdo_sqlite redis xml zip)

# Same images as docker-compose.yml.
images=(redis:7.4-alpine valkey/valkey:8-alpine memcached:1.6-alpine mysql:8.4 postgres:16-alpine)

loaded() {
    php -m 2>/dev/null | grep -qix "$1"
}

install_with_apt() {
    local package
    case "$1" in
        pdo_mysql) package=mysql ;;
        pdo_pgsql) package=pgsql ;;
        pdo_sqlite) package=sqlite3 ;;
        *) package=$1 ;;
    esac
    apt-get install -y -qq --no-install-recommends "php${php_version}-${package}" >/dev/null 2>&1
}

# clone <tag> <repository> <directory> [git clone options]
clone() {
    git -c advice.detachedHead=false clone -q --depth 1 --branch "$1" "${@:4}" "$2" "$3"
}

# Builds a PECL extension from its git tag, or a bundled one from the php-src tag of
# the running PHP. Needs php-dev (phpize), which the image has.
build_from_source() {
    local extension=$1 work source configure=()
    work="$(mktemp -d)"
    source="$work/$extension"

    case "$extension" in
        pcov)
            clone v1.0.12 https://github.com/krakjoe/pcov.git "$source" ;;
        redis)
            clone 6.3.0 https://github.com/phpredis/phpredis.git "$source" ;;
        memcached)
            apt-get install -y -qq --no-install-recommends libmemcached-dev zlib1g-dev pkg-config >/dev/null 2>&1
            configure=(--disable-memcached-sasl)
            clone v3.4.0 https://github.com/php-memcached-dev/php-memcached.git "$source" ;;
        bcmath)
            clone "php-$(php -r 'echo PHP_VERSION;')" https://github.com/php/php-src.git "$work/php-src" --filter=blob:none --sparse \
                && git -C "$work/php-src" sparse-checkout set "ext/$extension" \
                && source="$work/php-src/ext/$extension" ;;
        *)
            rm -rf "$work"
            return 1 ;;
    esac

    (cd "$source" && "phpize$php_version" && ./configure "${configure[@]}" && make -j"$(nproc)" && make install) >/dev/null 2>&1 \
        && printf 'extension=%s.so\n' "$extension" > "/etc/php/$php_version/mods-available/$extension.ini" \
        && phpenmod -v "$php_version" "$extension"
    rm -rf "$work"
}

install_extensions() {
    local missing=() extension
    for extension in "${required[@]}"; do
        loaded "$extension" || missing+=("$extension")
    done
    [ ${#missing[@]} -eq 0 ] && return

    echo "Installing PHP $php_version extensions: ${missing[*]}"
    # Fails for the blocked PPA, but still refreshes the Ubuntu archive.
    apt-get update -qq >/dev/null 2>&1

    for extension in "${missing[@]}"; do
        install_with_apt "$extension" || build_from_source "$extension"
        loaded "$extension" || echo "Could not install the $extension extension."
    done
}

pull_images() {
    if ! docker info >/dev/null 2>&1; then
        echo "Docker is not running, so the service images were not pulled."
        return
    fi

    local image mirror
    for image in "${images[@]}"; do
        docker image inspect "$image" >/dev/null 2>&1 && continue
        case "$image" in
            */*) mirror="mirror.gcr.io/$image" ;;
            *) mirror="mirror.gcr.io/library/$image" ;;
        esac
        (
            docker pull -q "$image" >/dev/null 2>&1 \
                || { docker pull -q "$mirror" >/dev/null 2>&1 && docker tag "$mirror" "$image"; } \
                || echo "Could not pull $image."
        ) &
    done
    wait
}

case "${1:-all}" in
    extensions) install_extensions ;;
    images) pull_images ;;
    *) install_extensions; pull_images ;;
esac

exit 0
