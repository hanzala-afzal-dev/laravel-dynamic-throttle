#!/usr/bin/env bash
#
# Decides what a pull request's mutation run mutates (ADR-0016), from the changes
# between two commits. Prints one line:
# - the src/Engine files to mutate, comma-separated: those the changes touch, and the
#   engine classes that the changed tests name in covers();
# - "full" when they change what every test runs on, or when in doubt;
# - "none" when they can move no engine file's score.
# A change to tests/Support adds no file: the weekly run of the whole engine covers it.
# The reasons go to stderr.
#
# Usage: scripts/mutation-paths.sh <base> <head>
set -uo pipefail

base="${1:?Usage: scripts/mutation-paths.sh <base> <head>}"
head="${2:?Usage: scripts/mutation-paths.sh <base> <head>}"

# --no-renames lists a renamed file under its old and its new path.
if ! files="$(git diff --name-only --no-renames "$base" "$head")"; then
    echo "Could not compare $base and $head, so the whole engine is mutated." >&2
    echo full
    exit 0
fi

paths=()
engine="Afzal\\DynamicThrottle\\Engine\\"

# An engine file, when it still exists after the changes.
add() {
    if git cat-file -e "$head:$1" 2>/dev/null; then
        paths+=("$1")
    fi
}

while IFS= read -r file; do
    case "$file" in
        '' | tests/Support/*)
            ;;
        composer.json | phpunit.xml.dist | tests/Pest.php | tests/TestCase.php \
            | .github/workflows/ci.yml | scripts/mutation-paths.sh)
            echo "Changes what every test runs on: $file" >&2
            echo full
            exit 0
            ;;
        src/Engine/*.php)
            echo "Changes the engine: $file" >&2
            add "$file"
            ;;
        tests/*.php)
            # A test counts for the engine classes its covers() names, which its use
            # statements import. A deleted test is read from the base.
            content="$(git show "$head:$file" 2>/dev/null || git show "$base:$file" 2>/dev/null || true)"
            covered="$(grep -oE 'covers\([^)]*\)' <<< "$content" | grep -oE '[A-Za-z0-9_]+::class' | sed 's/::class$//' | sort -u)"

            for class in $covered; do
                # "use A\B\C;" imports C, and "use A\B\C as D;" imports D.
                fqcn="$(awk -v class="$class" '
                    $1 == "use" {
                        name = $2; alias = ($3 == "as") ? $4 : $2
                        sub(/;$/, "", name); sub(/;$/, "", alias); sub(/.*\\/, "", alias)
                        if (alias == class) print name
                    }' <<< "$content")"

                case "$fqcn" in
                    "$engine"*)
                        relative="${fqcn#"$engine"}"
                        echo "Changes a test of the engine: $file covers $fqcn" >&2
                        add "src/Engine/${relative//\\//}.php"
                        ;;
                esac
            done
            ;;
    esac
done <<< "$files"

if [ "${#paths[@]}" -eq 0 ]; then
    echo "Changes no engine file and no test of one, so nothing is mutated." >&2
    echo none
    exit 0
fi

printf '%s\n' "${paths[@]}" | sort -u | paste -sd, -
