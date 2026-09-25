#!/usr/bin/env bash
#
# Exits 0 when the changes between two commits can change the mutation score of
# src/Engine (ADR-0016), so CI runs mutation testing: the engine itself, a test
# that covers an engine class, or what every test runs on. Exits 1 otherwise.
# When in doubt, such as when git cannot diff, it says the engine is touched.
#
# Usage: scripts/touches-engine.sh <base> <head>
set -uo pipefail

base="${1:?Usage: scripts/touches-engine.sh <base> <head>}"
head="${2:?Usage: scripts/touches-engine.sh <base> <head>}"

# --no-renames lists a renamed file under its old and its new path.
if ! files="$(git diff --name-only --no-renames "$base" "$head")"; then
    echo "Could not compare $base and $head, so mutation testing runs."
    exit 0
fi

while IFS= read -r file; do
    case "$file" in
        '')
            ;;
        src/Engine/* | tests/Support/* | tests/Pest.php | tests/TestCase.php | phpunit.xml.dist | composer.json \
            | .github/workflows/ci.yml | scripts/touches-engine.sh)
            echo "Touches the engine: $file"
            exit 0
            ;;
        tests/*)
            # A test only counts for the classes its covers() names. A deleted test is read from the base.
            content="$(git show "$head:$file" 2>/dev/null || git show "$base:$file" 2>/dev/null || true)"
            if grep -q 'covers(' <<< "$content" && grep -qF 'DynamicThrottle\Engine\' <<< "$content"; then
                echo "Touches the engine: $file"
                exit 0
            fi
            ;;
    esac
done <<< "$files"

echo "Does not touch the engine, so mutation testing is skipped."
exit 1
