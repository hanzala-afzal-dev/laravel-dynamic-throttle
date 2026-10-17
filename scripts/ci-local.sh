#!/usr/bin/env bash
# The CI workflow's checks, run locally while GitHub Actions has no minutes left.
# Usage: scripts/ci-local.sh [--drivers] [--mutate PATHS]
cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1
fail=0
step() { local name="$1"; shift; if out="$("$@" 2>&1)"; then echo "ok    $name"; else echo "FAIL  $name"; echo "$out" | tail -25; fail=1; fi; }

step "composer test" composer test
php -m | grep -qi apcu && step "composer test:apcu" composer test:apcu
step "composer analyse" composer analyse
step "composer format:check" composer format:check
step "composer refactor:check" composer refactor:check
step "download holds only package files" bash -c '
  allowed="^(src|config|database|resources|routes|lang|dist)/|^(composer\.json|LICENSE|README\.md|CHANGELOG\.md|SECURITY\.md)$"
  unexpected="$(git archive HEAD | tar -t | grep -v "/$" | grep -v -E "$allowed" || true)"
  [ -z "$unexpected" ] || { echo "$unexpected"; exit 1; }
  git archive HEAD | tar -t | grep -q "^dist/\.vite/manifest\.json$"'
step "npm run typecheck" npm run typecheck
step "npm run lint" npm run lint
step "npm run test" npm run test
step "committed build is what the sources build" bash -c 'npm run build >/dev/null && changed="$(git status --porcelain -- dist)" && [ -z "$changed" ] || { echo "$changed"; exit 1; }'
step "npm run budget" npm run budget
step "npm audit (what ships)" npm audit --omit=dev --audit-level=high
step "composer audit (no dev)" composer audit --no-dev --abandoned=fail
step "licenses" bash -c 'composer licenses --no-dev --format=json | php scripts/check-licenses.php'
if [ "$1" = "--drivers" ]; then step "composer test:drivers" composer test:drivers; fi
if [ "$1" = "--mutate" ]; then step "mutation $2" env DYNAMIC_THROTTLE_DRIVERS=1 vendor/bin/pest --exclude-group=concurrency --mutate --everything --path="$2" --min=85 --ignore-min-score-on-zero-mutations --parallel; fi
exit $fail
