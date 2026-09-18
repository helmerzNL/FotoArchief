#!/bin/sh
set -eu

tag="${1:-}"
if ! printf '%s\n' "$tag" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "Release notes require an exact vMAJOR.MINOR.PATCH tag." >&2
    exit 1
fi

notes="docs/releases/$tag.md"
if [ ! -s "$notes" ]; then
    echo "Missing or empty version-specific release notes: $notes" >&2
    exit 1
fi

if ! awk -v tag="$tag" '
    BEGIN {
        expected[1] = "# FotoArchief " tag
        expected[2] = "## Nederlands"
        expected[3] = "### Wijzigingen in deze versie"
        expected[4] = "### Operatoracties"
        expected[5] = "### Bewijs en grenzen"
        expected[6] = "## English"
        expected[7] = "### Changes in this version"
        expected[8] = "### Operator actions"
        expected[9] = "### Evidence and limitations"
    }
    {
        sub(/\r$/, "")
        if (step < 9 && $0 == expected[step + 1]) {
            step++
            next
        }
        if ($0 ~ /^# / || $0 ~ /^## / || $0 ~ /^### /) {
            invalid = 1
        }
        if ($0 !~ /^[[:space:]]*$/ && $0 !~ /^#/ && $0 !~ /^---$/) {
            content[step] = 1
        }
    }
    END {
        if (step != 9 || invalid || !content[3] || !content[4] || !content[5] ||
            !content[7] || !content[8] || !content[9]) {
            exit 1
        }
    }
' "$notes"; then
    echo "Invalid release notes: require the matching title, Dutch then English, and populated changes/operator/evidence sections in each language ($notes)." >&2
    exit 1
fi

echo "Validated bilingual release notes: $notes"
