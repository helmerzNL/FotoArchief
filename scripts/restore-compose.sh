#!/bin/sh
set -eu
umask 077

if [ "$#" != 2 ] || [ "$2" != "--confirm-empty-target" ] || [ ! -d "$1" ]; then
    echo "Usage: sh scripts/restore-compose.sh /private/backup --confirm-empty-target" >&2
    echo "Configure a separate, empty Compose project and matching DB credentials first." >&2
    exit 2
fi
backup=$(cd "$1" && pwd)
compose_file=${COMPOSE_FILE:-deploy/compose.yaml}
test "$(cat "$backup/FORMAT")" = fotoarchief-local-backup-v1
(
    cd "$backup"
    sha256sum database.dump storage-app.tar VERSION FORMAT | diff - SHA256SUMS
)
docker compose -f "$compose_file" stop app worker scheduler
docker compose -f "$compose_file" up -d --wait postgres
count=$(docker compose -f "$compose_file" exec -T postgres sh -ec \
    'psql -XAt -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ('\''pg_catalog'\'', '\''information_schema'\'');"')
if [ "$count" != 0 ]; then
    echo "Restore refused: target database is not empty. No data was overwritten." >&2
    exit 1
fi
docker compose -f "$compose_file" run --rm --no-deps --entrypoint sh app -ec \
    'test -z "$(find storage/app -mindepth 1 ! -name .gitignore -print -quit)"'
version=$(docker compose -f "$compose_file" run --rm --no-deps --entrypoint cat app VERSION)
if [ "$version" != "$(cat "$backup/VERSION")" ]; then
    echo "Restore refused: use the same application version as the backup, then upgrade." >&2
    exit 1
fi
docker compose -f "$compose_file" run --rm --no-deps -T --entrypoint php app \
    scripts/restore-storage.php < "$backup/storage-app.tar"
docker compose -f "$compose_file" exec -T postgres sh -ec \
    'pg_restore --single-transaction --exit-on-error --no-owner --no-acl -U "$POSTGRES_USER" -d "$POSTGRES_DB"' < "$backup/database.dump"
docker compose -f "$compose_file" up -d --wait app worker scheduler
docker compose -f "$compose_file" exec -T app php artisan installation:ready
printf '%s\n' "Restore complete. Verify representative photos and account access before allowing traffic."
