#!/bin/sh
set -eu

repo="$(CDPATH='' cd -- "$(dirname "$0")/../.." && pwd)"
fixture="$(mktemp -d)"
trap 'rm -f "$fixture/docs/releases/v1.2.3.md" "$fixture/valid.md" "$fixture/output"; rmdir "$fixture/docs/releases" "$fixture/docs" "$fixture"' EXIT
mkdir -p "$fixture/docs/releases"
cd "$fixture"
notes="docs/releases/v1.2.3.md"
guard="$repo/scripts/check-release-notes.sh"

reject() {
    if sh "$guard" "$1" > output 2>&1; then
        echo "Expected rejection: $2" >&2
        exit 1
    fi
    if [ ! -s output ]; then
        echo "Expected explicit diagnostic: $2" >&2
        exit 1
    fi
}

reject v1.2.3 "missing notes"
touch "$notes"
reject v1.2.3 "empty notes"
reject '../../escape' "invalid tag"

cat > valid.md <<'NOTES'
# FotoArchief v1.2.3

## Nederlands

### Wijzigingen in deze versie
- Beschrijving van de wijziging.
### Operatoracties
Geen configuratiewijzigingen.
### Bewijs en grenzen
Gerichte tests geslaagd; geen productiecertificering.

## English

### Changes in this version
- Description of the change.
### Operator actions
No configuration changes.
### Evidence and limitations
Targeted tests passed; no production certification.
NOTES
cp valid.md "$notes"
sh "$guard" v1.2.3
sed 's/v1.2.3/v1.2.4/' valid.md > "$notes"
reject v1.2.3 "wrong version"
sed '/^## English/,$d' valid.md > "$notes"
reject v1.2.3 "missing English"
sed 's/## Nederlands/## SWAP/; s/## English/## Nederlands/; s/## SWAP/## English/' valid.md > "$notes"
reject v1.2.3 "incorrect language sequence"
sed '/^- Beschrijving van de wijziging\./d' valid.md > "$notes"
reject v1.2.3 "empty Dutch changes"
sed '/^- Description of the change\./d' valid.md > "$notes"
reject v1.2.3 "empty English changes"
sed '/^Geen configuratiewijzigingen\./d' valid.md > "$notes"
reject v1.2.3 "empty Dutch operator actions"
sed '/^No configuration changes\./d' valid.md > "$notes"
reject v1.2.3 "empty English operator actions"
sed '/^Gerichte tests geslaagd/d' valid.md > "$notes"
reject v1.2.3 "empty Dutch evidence"
sed '/^Targeted tests passed/d' valid.md > "$notes"
reject v1.2.3 "empty English evidence"
awk '{ printf "%s\r\n", $0 }' valid.md > "$notes"
sh "$guard" v1.2.3

cp valid.md "$notes"
printf '\nAdditional evidence paragraph.\n\n' >> "$notes"
sh "$guard" v1.2.3
printf '### Unexpected section\nNot part of the contract.\n' >> "$notes"
reject v1.2.3 "unexpected section after English evidence"
sed '/^Targeted tests passed/d' valid.md > "$notes"
printf '\n\n' >> "$notes"
reject v1.2.3 "empty English evidence with trailing blank lines"

workflow="$repo/.github/workflows/release.yml"
grep -Fq 'run: sh scripts/check-release-notes.sh "$GITHUB_REF_NAME"' "$workflow"
grep -Fq -- '--notes-file "docs/releases/$GITHUB_REF_NAME.md" dist/*' "$workflow"
guard_line="$(grep -n 'name: Validate version-specific bilingual release notes' "$workflow" | cut -d: -f1)"
publish_line="$(grep -n 'name: Publish the image that passed acceptance' "$workflow" | cut -d: -f1)"
test "$guard_line" -lt "$publish_line"
echo 'Release-note guard and publication wiring smoke passed.'
