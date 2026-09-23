#!/usr/bin/env bash
#
# Claude Code Stop hook (ADR-0003): before Claude ends a turn, run the test
# suite and static analysis, and block the turn from ending while they fail.
#
# Contract:
#   exit 0 + no output         checks passed, or nothing relevant changed since the last green run
#   exit 0 + {"systemMessage"} a warning for the user; the turn still ends
#   exit 2 + stderr            checks failed; the turn continues and Claude sees stderr
#
# It does not block when:
#   - only tests/ changed since the last green run: that is ADR-0003's Red
#     step, where failing tests are the goal (it warns instead)
#   - Claude is already continuing because of this hook (stop_hook_active),
#     so a problem Claude cannot fix never loops forever (it warns instead)
#   - neither PHP 8.3+ nor Docker is available (it warns instead)
#
# Runner: PHP 8.3+ on the host when available, otherwise the Docker dev
# container (docker compose run --rm php).
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

# State of the last green run: line 1 is a fingerprint of everything the checks
# depend on, line 2 is the commit it was taken at.
state_file=".claude/verify.state"
implementation=(src config database routes resources workbench composer.json phpunit.xml.dist phpstan.neon.dist)
relevant=("${implementation[@]}" tests)

fingerprint="$(
    {
        git rev-parse HEAD 2>/dev/null
        git diff HEAD -- "${relevant[@]}" 2>/dev/null
        git ls-files --others --exclude-standard -- "${relevant[@]}" 2>/dev/null \
            | while IFS= read -r file; do printf '%s\n' "$file"; cat -- "$file"; done
    } | git hash-object --stdin 2>/dev/null
)"
green_fingerprint="$(sed -n 1p "$state_file" 2>/dev/null)"
green_head="$(sed -n 2p "$state_file" 2>/dev/null)"

if [[ -n "$fingerprint" && "$fingerprint" == "$green_fingerprint" ]]; then
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
    printf '%s\n%s\n' "$fingerprint" "$(git rev-parse HEAD 2>/dev/null)" > "$state_file"
    exit 0
fi

# Red step: the implementation is unchanged since the last green run, so only tests changed.
if [[ -n "$green_head" ]] && git cat-file -e "${green_head}^{commit}" 2>/dev/null \
    && git diff --quiet "$green_head" -- "${implementation[@]}" 2>/dev/null \
    && [[ -z "$(git ls-files --others --exclude-standard -- "${implementation[@]}" 2>/dev/null)" ]]; then
    warn "Stop hook: tests fail, but only tests changed since the last green run. This looks like the ADR-0003 Red step: make sure they fail for the right reason, commit them (test: ...), then implement."
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
