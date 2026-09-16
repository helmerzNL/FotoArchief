#!/bin/sh
set -eu
umask 077

if [ "$#" != 3 ] || [ ! -f "$1" ] || [ -e "$2" ] || [ ! -f "$3" ]; then
    echo "Usage: sh scripts/backup-restore-encrypted-copy.sh /private/copy.tar.gz.enc /private/new-restored-backup-directory /private/keyfile" >&2
    exit 2
fi

encrypted=$1
target_dir=$2
key_file=$3
manifest="${encrypted}.manifest"

if [ ! -f "$manifest" ]; then
    echo "Encrypted restore refused: manifest is missing." >&2
    exit 1
fi

expected=$(awk -F= '$1 == "encrypted_sha256" {print $2}' "$manifest")
if [ -z "$expected" ]; then
    echo "Encrypted restore refused: manifest has no encrypted checksum." >&2
    exit 1
fi
actual=$(sha256sum "$encrypted" | awk '{print $1}')
if [ "$actual" != "$expected" ]; then
    echo "Encrypted restore refused: encrypted checksum mismatch." >&2
    exit 1
fi

mkdir -m 700 "$target_dir"
tmp_tar="${target_dir}.tar.tmp"
cleanup() {
    rm -f "$tmp_tar"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -md sha256 -pass "file:$key_file" -in "$encrypted" -out "$tmp_tar"
tar -tzf "$tmp_tar" | while IFS= read -r entry; do
    case "$entry" in
        .|./*) ;;
        *)
            echo "Encrypted restore refused unsafe archive entry: $entry" >&2
            exit 1
            ;;
    esac
done
tar -xzf "$tmp_tar" -C "$target_dir"
(
    cd "$target_dir"
    sha256sum --check SHA256SUMS >/dev/null
)

printf '%s\n' "Encrypted backup copy restored: $target_dir"
