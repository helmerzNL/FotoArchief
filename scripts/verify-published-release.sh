#!/bin/sh
set -eu

tag="${1:?Release tag required}"
repo="${GITHUB_REPOSITORY:-helmerzNL/FotoArchief}"
root="$(CDPATH='' cd -- "$(dirname "$0")/.." && pwd)"
directory="$(mktemp -d)"
trap 'rm -rf "$directory"' EXIT

gh release view "$tag" --repo "$repo" --json isDraft,isPrerelease --jq \
  'select(.isDraft == false and .isPrerelease == true) | "published-prerelease"' |
  grep -Fxq 'published-prerelease'
gh release download "$tag" --repo "$repo" --dir "$directory"
php "$root/scripts/release-asset-manifest.php" verify "$directory"

gh release view "$tag" --repo "$repo" --json body --jq .body > "$directory/published-notes.md"
php -r '
$published = rtrim(str_replace("\r\n", "\n", file_get_contents($argv[1])));
$tracked = rtrim(str_replace("\r\n", "\n", file_get_contents($argv[2])));
if (!hash_equals($tracked, $published)) {
    fwrite(STDERR, "Published release notes differ from tracked notes.\n");
    exit(1);
}
' "$directory/published-notes.md" "$root/docs/releases/$tag.md"

echo "Verified published release $tag."
