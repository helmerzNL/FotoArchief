#!/bin/sh
set -eu
umask 077

if [ "$#" != 1 ] || [ ! -f "$1" ]; then
    echo "Usage: sh scripts/backup-second-copy-scheduled.sh /private/fotoarchief-second-backup.conf" >&2
    exit 2
fi

config=$1
# shellcheck disable=SC1090
. "$config"

: "${FOTOARCHIEF_SECOND_BACKUP_ENABLED:=false}"
: "${COMPOSE_FILE:=}"
: "${BACKUP_PARENT_DIR:=}"
: "${ENCRYPTED_COPY_DIR:=}"
: "${KEY_FILE:=}"
: "${LOCK_DIR:=}"
: "${RETENTION_POLICY:=}"
: "${FAILURE_REPORT_COMMAND:=}"

if [ "$FOTOARCHIEF_SECOND_BACKUP_ENABLED" != "true" ]; then
    echo "Second encrypted backup schedule is disabled. Set FOTOARCHIEF_SECOND_BACKUP_ENABLED=true after choosing the location, key custody, retention policy and reporting channel." >&2
    exit 2
fi

require_path() {
    name=$1
    value=$2
    if [ -z "$value" ]; then
        echo "Second encrypted backup refused: $name is not configured." >&2
        exit 2
    fi
}

require_path BACKUP_PARENT_DIR "$BACKUP_PARENT_DIR"
require_path ENCRYPTED_COPY_DIR "$ENCRYPTED_COPY_DIR"
require_path KEY_FILE "$KEY_FILE"
require_path LOCK_DIR "$LOCK_DIR"
require_path RETENTION_POLICY "$RETENTION_POLICY"

if [ ! -d "$BACKUP_PARENT_DIR" ] || [ ! -d "$ENCRYPTED_COPY_DIR" ] || [ ! -f "$KEY_FILE" ]; then
    echo "Second encrypted backup refused: backup parent, encrypted destination directory and key file must already exist." >&2
    exit 2
fi

if ! mkdir "$LOCK_DIR" 2>/dev/null; then
    echo "Second encrypted backup refused: lock is already held at $LOCK_DIR." >&2
    exit 1
fi

label=$(date -u '+fotoarchief-%Y%m%dT%H%M%SZ')
backup_dir=$BACKUP_PARENT_DIR/$label
encrypted_copy=$ENCRYPTED_COPY_DIR/$label.tar.gz.enc
failure_message="Second encrypted backup failed for $label."

finish() {
    status=$?
    rmdir "$LOCK_DIR" 2>/dev/null || true
    if [ "$status" -ne 0 ]; then
        echo "$failure_message" >&2
        if [ -n "$FAILURE_REPORT_COMMAND" ]; then
            FOTOARCHIEF_BACKUP_FAILURE_MESSAGE=$failure_message sh -c "$FAILURE_REPORT_COMMAND" || \
                echo "Second encrypted backup failure reporter also failed." >&2
        fi
    fi
    exit "$status"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if [ -e "$backup_dir" ] || [ -e "$encrypted_copy" ] || [ -e "$encrypted_copy.manifest" ]; then
    echo "Second encrypted backup refused: generated target already exists for $label." >&2
    exit 1
fi

if [ -n "$COMPOSE_FILE" ]; then
    COMPOSE_FILE=$COMPOSE_FILE sh scripts/backup-compose.sh "$backup_dir"
else
    sh scripts/backup-compose.sh "$backup_dir"
fi

sh scripts/backup-copy-encrypted.sh "$backup_dir" "$encrypted_copy" "$KEY_FILE"

printf '%s\n' "Second encrypted backup complete: $encrypted_copy"
printf '%s\n' "Retention policy recorded, not enforced by this script: $RETENTION_POLICY"
