#!/bin/sh
set -eu
umask 077

if [ "$#" != 3 ] || [ ! -d "$1" ] || [ -e "$2" ] || [ ! -f "$3" ]; then
    echo "Usage: sh scripts/backup-copy-encrypted.sh /private/backup /private/new-encrypted-copy.tar.gz.enc /private/keyfile" >&2
    exit 2
fi

source_dir=$(cd "$1" && pwd)
target=$2
key_file=$3

if [ ! -f "$source_dir/SHA256SUMS" ]; then
    echo "Encrypted copy refused: source backup has no SHA256SUMS." >&2
    exit 1
fi

(
    cd "$source_dir"
    sha256sum --check SHA256SUMS >/dev/null
)

tmp_manifest="${target}.manifest.tmp"
tmp_archive="${target}.tmp"
cleanup() {
    rm -f "$tmp_manifest" "$tmp_archive"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

tar -C "$source_dir" -czf - . \
    | openssl enc -aes-256-cbc -salt -pbkdf2 -iter 600000 -md sha256 -pass "file:$key_file" -out "$tmp_archive"

sha256sum "$tmp_archive" | awk '{print $1}' > "$tmp_manifest"
{
    printf 'format=fotoarchief-encrypted-backup-copy-v1\n'
    printf 'cipher=aes-256-cbc\n'
    printf 'kdf=pbkdf2-sha256\n'
    printf 'iterations=600000\n'
    printf 'source_format=%s\n' "$(cat "$source_dir/FORMAT")"
    printf 'source_version=%s\n' "$(cat "$source_dir/VERSION")"
    printf 'encrypted_sha256=%s\n' "$(cat "$tmp_manifest")"
    printf 'created_at=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
} > "${target}.manifest"

mv "$tmp_archive" "$target"
rm -f "$tmp_manifest"
printf '%s\n' "Encrypted backup copy complete: $target"
