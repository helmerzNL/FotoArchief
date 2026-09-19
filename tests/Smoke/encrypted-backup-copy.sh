#!/bin/sh
set -eu
umask 077

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
tmp=$(mktemp -d "${TMPDIR:-/tmp}/fotoarchief-encrypted-backup.XXXXXX")
cleanup() {
    rm -rf "$tmp"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

backup=$tmp/source-backup
mkdir "$backup"
printf '%s\n' 'fotoarchief-local-backup-v2' > "$backup/FORMAT"
printf '%s\n' '0.9.51' > "$backup/VERSION"
printf '%s\n' 'database bytes' > "$backup/database.dump"
mkdir "$tmp/storage"
printf '%s\n' 'private installation state' > "$tmp/storage/state.txt"
tar -C "$tmp/storage" -cf "$backup/storage-app.tar" .
cat > "$backup/BACKUP-MANIFEST.json" <<EOF
{
  "schema_version": 2,
  "format": "fotoarchief-local-backup-v2",
  "app_version": "0.9.51",
  "created_at": "2026-10-12T12:00:00Z",
  "components": {
    "database.dump": {"sha256": "$(sha256sum "$backup/database.dump" | awk '{print $1}')", "bytes": $(wc -c < "$backup/database.dump" | tr -d ' ')},
    "storage-app.tar": {"sha256": "$(sha256sum "$backup/storage-app.tar" | awk '{print $1}')", "bytes": $(wc -c < "$backup/storage-app.tar" | tr -d ' ')}
  }
}
EOF
(
    cd "$backup"
    sha256sum database.dump storage-app.tar VERSION FORMAT BACKUP-MANIFEST.json > SHA256SUMS
)

printf '%s\n' 'disposable encrypted-copy test key' > "$tmp/key"
printf '%s\n' 'wrong disposable encrypted-copy test key' > "$tmp/wrong-key"

copy=$tmp/fotoarchief-copy.tar.gz.enc
sh "$root/scripts/backup-copy-encrypted.sh" "$backup" "$copy" "$tmp/key"

restored=$tmp/restored
sh "$root/scripts/backup-restore-encrypted-copy.sh" "$copy" "$restored" "$tmp/key"
diff "$backup/FORMAT" "$restored/FORMAT"
diff "$backup/VERSION" "$restored/VERSION"
diff "$backup/SHA256SUMS" "$restored/SHA256SUMS"
diff "$backup/BACKUP-MANIFEST.json" "$restored/BACKUP-MANIFEST.json"

tampered=$tmp/tampered.tar.gz.enc
cp "$copy" "$tampered"
cp "$copy.manifest" "$tampered.manifest"
printf 'x' >> "$tampered"
if sh "$root/scripts/backup-restore-encrypted-copy.sh" "$tampered" "$tmp/tampered-restore" "$tmp/key" 2>"$tmp/tampered.err"; then
    echo "Tampered encrypted copy unexpectedly restored." >&2
    exit 1
fi
grep -E 'checksum mismatch|bad decrypt|not in gzip format' "$tmp/tampered.err" >/dev/null

if sh "$root/scripts/backup-restore-encrypted-copy.sh" "$copy" "$tmp/wrong-key-restore" "$tmp/wrong-key" 2>"$tmp/wrong-key.err"; then
    echo "Wrong key unexpectedly restored the encrypted copy." >&2
    exit 1
fi

mkdir "$tmp/nonempty-target"
if sh "$root/scripts/backup-restore-encrypted-copy.sh" "$copy" "$tmp/nonempty-target" "$tmp/key" 2>"$tmp/nonempty.err"; then
    echo "Encrypted restore unexpectedly accepted a non-empty target." >&2
    exit 1
fi
grep -F 'Usage:' "$tmp/nonempty.err" >/dev/null

echo "Encrypted backup copy smoke passed."
