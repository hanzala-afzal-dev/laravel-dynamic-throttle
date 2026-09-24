#!/usr/bin/env bash
#
# Phase 0 exit criterion (docs/roadmap.md): the package installs through a path
# repository into a fresh Laravel application, and the application boots.
#
#   scripts/verify-install.sh <laravel-major> [work-dir]
#   docker compose run --rm php scripts/verify-install.sh 13
#
# Needs PHP 8.3+ and Composer. Used by the "install" CI job.
set -euo pipefail

LARAVEL_MAJOR="${1:?usage: scripts/verify-install.sh <laravel-major> [work-dir]}"
PACKAGE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK_DIR="${2:-$(mktemp -d)}"
APP_DIR="${WORK_DIR}/laravel-${LARAVEL_MAJOR}"
PACKAGE="hanzala-afzal/laravel-dynamic-throttle"

step() { printf '\n==> %s\n' "$*"; }

step "Creating a fresh Laravel ${LARAVEL_MAJOR} application in ${APP_DIR}"
rm -rf "${APP_DIR}"
composer create-project "laravel/laravel:^${LARAVEL_MAJOR}.0" "${APP_DIR}" \
    --prefer-dist --no-interaction --no-progress --quiet
cd "${APP_DIR}"
php artisan --version

step "Installing ${PACKAGE} from ${PACKAGE_DIR}"
composer config repositories.dynamic-throttle \
    "{\"type\": \"path\", \"url\": \"${PACKAGE_DIR}\", \"options\": {\"symlink\": true}}"
composer require "${PACKAGE}:*@dev" --no-interaction --no-progress

step "Checking that the service provider is discovered and the app boots"
php artisan package:discover --ansi | tee /tmp/discover.log
grep -q "${PACKAGE}" /tmp/discover.log
php artisan about --only=dynamic_throttle
php artisan about --json | php -r '
    $about = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (($about["dynamic_throttle"]["enabled"] ?? null) !== true) {
        fwrite(STDERR, "The about command has no enabled Dynamic Throttle section.\n");
        exit(1);
    }
'

step "Publishing the config file"
php artisan vendor:publish --tag=dynamic-throttle-config --ansi
test -f config/dynamic-throttle.php
php artisan config:show dynamic-throttle

step "Publishing and running the migrations (SPEC-005)"
php artisan vendor:publish --tag=dynamic-throttle-migrations --ansi
ls database/migrations/*_create_dynamic_throttle_rules_table.php
php artisan migrate --force --no-interaction
php artisan migrate:status | grep -q 'create_dynamic_throttle_rules_table.*Ran'
php artisan db:table dynamic_throttle_rules

step "OK: ${PACKAGE} installs into Laravel ${LARAVEL_MAJOR}, boots and migrates"
