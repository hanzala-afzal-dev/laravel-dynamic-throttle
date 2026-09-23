#!/usr/bin/env bash
#
# Claude Code Stop hook (ADR-0003): before Claude ends a turn, run the test
# suite and static analysis, and block the turn from ending while they fail.
#
# Contract:
#   exit 0 + no output        checks passed, or nothing relevant changed since the last green run
#   exit 0 + {"systemMessage"} a warning for the user; the turn still ends
#   exit 2 + stderr            checks failed; the turn continues and Claude sees stderr
#
# Loop guard: when Claude is already continuing because of this hook
# (stop_hook_active is true) and the checks still fail, the hook warns instead
# of blocking again, so a problem Claude cannot fix never loops forever.
#
# Runner: PHP 8.3+ on the host when available, otherwise the Docker dev
# container (docker compose run --rm php). If neither works, it only warns.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 0
export MSYS_NO_PATHCONV=1 NO_COLOR=1

input="$(cat || true)"
already_blocked=false
if printf '%s' "$input" | grep -Eq '"stop_hook_active"[[:space:]]*:[[:space:]]*true'; then
    already_blocked=true
fi

warn() {
    # Static messages only, so the JSON needs no escaping.
    printf '{"systemMessage": "%s"}\n' "$1"
    exit 0
}

# Skip when no code, test or tool config changed since the last green run.
state_file=".claude/verify.state"
relevant=(src config database routes resources tests workbench composer.json phpunit.xml.dist phpstan.neon.dist)
fingerprint="$(
    {
        git rev-parse HEAD 2>/dev/null
        git diff HEAD -- "${relevant[@]}" 2>/dev/null
        git ls-files --others --exclude-standard -- "${relevant[@]}" 2>/dev/null \
            | while IFS= read -r file; do printf '%s\n' "$file"; cat -- "$file"; done
    } | sha1sum | cut -d' ' -f1
)"
if [[ -f "$state_file" && "$(cat "$state_file")" == "$fingerprint" ]]; then
    exit 0
fi

checks='composer test --no-ansi && composer analyse --no-ansi -- --error-format=raw'

if php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' >/dev/null 2>&1 && [[ -f vendor/autoload.php ]]; then
    output="$(bash -c "$checks" 2>&1)"
    status=$?
elif docker info >/dev/null 2>&1; then
    output="$(docker compose run --rm -T php sh -c "$checks" 2>&1)"
    status=$?
    if [[ $status -ne 0 ]] && printf '%s' "$output" | grep -Eq 'no such service|Cannot connect to the Docker daemon|pull access denied|failed to solve'; then
        warn "Stop hook: could not start the Docker dev container, so tests and analysis did not run. Run: docker compose build php"
    fi
else
    warn "Stop hook: no PHP 8.3+ on this machine and Docker is not running, so tests and analysis did not run (ADR-0003)."
fi

if [[ $status -eq 0 ]]; then
    printf '%s\n' "$fingerprint" > "$state_file"
    exit 0
fi

if [[ "$already_blocked" == true ]]; then
    warn "Stop hook: composer test or composer analyse is still failing after one retry. Please look at the failures before continuing."
fi

{
    echo "Stop hook (ADR-0003): composer test or composer analyse failed. Fix the failures before ending the turn."
    echo "Never edit files under tests/ to make them pass; if a test looks wrong, stop and explain why."
    echo
    printf '%s\n' "$output" | sed 's/\x1b\[[0-9;]*[A-Za-z]//g' | grep -v -E '^\s*(Network|Container|Volume) ' | tail -n 80
} >&2
exit 2
