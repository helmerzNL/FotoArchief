#!/bin/sh
set -eu
umask 077

if [ "$#" != 1 ] || [ -e "$1" ]; then
    echo "Usage: sh scripts/backup-compose.sh /private/new-backup-directory" >&2
    exit 2
fi

backup=$1
mkdir -m 700 "$backup"
backup=$(cd "$backup" && pwd)
compose_file=${COMPOSE_FILE:-deploy/compose.yaml}
running=$(docker compose -f "$compose_file" ps --status running --services | grep -E '^(app|worker|scheduler)$' || true)

resume() {
    if [ -n "$running" ]; then
        # Only services running before the backup are resumed.
        docker compose -f "$compose_file" start $running
    fi
}
trap resume EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

docker compose -f "$compose_file" stop app worker scheduler
docker compose -f "$compose_file" run --rm --no-deps --entrypoint php app -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config("filesystems.default") !== "local") {
    fwrite(STDERR, "This consistent volume backup supports local storage only. Use the documented S3 snapshot procedure.\n");
    exit(1);
}
if (! app(App\Modules\Installation\InstallationStore::class)->completed()) {
    fwrite(STDERR, "Complete installation before creating a backup.\n");
    exit(1);
}
if (Illuminate\Support\Facades\DB::table("asset_files")->whereNull("storage_disk")->orWhere("storage_disk", "!=", "local")->exists()) {
    fwrite(STDERR, "Mixed or unknown legacy object locations require the documented full object-storage backup procedure.\n");
    exit(1);
}
'
docker compose -f "$compose_file" exec -T postgres sh -ec \
    'pg_dump --format=custom --no-owner --no-acl -U "$POSTGRES_USER" -d "$POSTGRES_DB"' > "$backup/database.dump"
docker compose -f "$compose_file" run --rm --no-deps --entrypoint tar app \
    --exclude=app/.gitignore -cf - -C storage app > "$backup/storage-app.tar"
docker compose -f "$compose_file" run --rm --no-deps --entrypoint cat app VERSION > "$backup/VERSION"
printf '%s\n' 'fotoarchief-local-backup-v1' > "$backup/FORMAT"
(
    cd "$backup"
    sha256sum database.dump storage-app.tar VERSION FORMAT > SHA256SUMS
)
printf '%s\n' "Backup complete: $backup"
