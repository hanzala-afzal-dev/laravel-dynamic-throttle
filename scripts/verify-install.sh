#!/usr/bin/env bash
#
# Phase 0 exit criterion (docs/roadmap.md): the package installs through a path
# repository into a fresh Laravel application, and the application boots. Then a
# new user's first steps: the README's install commands, after which
# dynamic-throttle:health must report no error.
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

step "Running the php artisan commands of the README's installation steps, in order"
# A new user runs exactly these, so the check reads them from the README rather
# than keeping its own copy that could drift from it.
readme_commands="$(awk '/^## Installation/ { inside = 1; next } /^## / { inside = 0 } inside && /^php artisan /' "${PACKAGE_DIR}/README.md")"
test -n "${readme_commands}"
while IFS= read -r line; do
    read -ra command <<< "${line}"
    printf '$ %s\n' "${line}"
    "${command[@]}" --no-interaction
done <<< "${readme_commands}"

step "Checking the migrations ran (SPEC-005)"
ls database/migrations/*_create_dynamic_throttle_rules_table.php
php artisan migrate:status | grep -q 'create_dynamic_throttle_rules_table.*Ran'
php artisan db:table dynamic_throttle_rules

step "Checking the setup as the README says: no health check may err (SPEC-016)"
# Warnings are expected in a fresh app, such as no route running the middleware
# yet. An error, which makes the command exit 1, is not.
php artisan dynamic-throttle:health

step "Compiling the rules manifest (SPEC-006)"
php artisan dynamic-throttle:compile
php artisan schedule:list | grep -q 'dynamic-throttle:compile'

step "OK: ${PACKAGE} installs into Laravel ${LARAVEL_MAJOR}, boots, migrates and compiles"
