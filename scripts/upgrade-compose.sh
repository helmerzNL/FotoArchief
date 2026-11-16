#!/bin/sh
set -eu

usage() {
    echo "Usage: sh scripts/upgrade-compose.sh /private/existing-backup-directory" >&2
    echo "Set FOTOARCHIEF_TAG (latest, beta or a version such as v0.9.79) in the stack environment first." >&2
}

if [ "$#" != 1 ]; then
    usage
    exit 2
fi

backup=$1
compose_file=${COMPOSE_FILE:-deploy/compose.yaml}

if [ ! -d "$backup" ] || [ ! -f "$backup/SHA256SUMS" ]; then
    echo "Refusing upgrade: create and verify a backup first with scripts/backup-compose.sh." >&2
    exit 1
fi

(
    cd "$backup"
    sha256sum --check SHA256SUMS >/dev/null
)

if [ -n "${APP_IMAGE:-}" ]; then
    echo "Refusing upgrade: APP_IMAGE is no longer read. Set FOTOARCHIEF_TAG (latest, beta or a version) instead and remove APP_IMAGE." >&2
    exit 1
fi

docker compose -f "$compose_file" config --quiet
image=$(docker compose -f "$compose_file" config --images | grep -m 1 '/fotoarchief:' || true)
if [ -z "$image" ]; then
    echo "Refusing upgrade: the Compose file does not resolve a FotoArchief image." >&2
    exit 1
fi
printf '%s\n' "Upgrading to $image (FOTOARCHIEF_TAG=${FOTOARCHIEF_TAG:-latest, from the Compose default or the stack .env})."

# An installation from before DATA_PATH keeps its data in Docker volumes; move it into
# DATA_PATH first (a no-op once it is there), then make sure the database runs on it.
sh "$(dirname "$0")/migrate-data.sh"
docker compose -f "$compose_file" up -d --wait postgres

running=$(docker compose -f "$compose_file" ps --status running --services | grep -E '^(worker|scheduler)$' || true)

resume() {
    if [ -n "$running" ]; then
        docker compose -f "$compose_file" start $running >/dev/null
    fi
}
trap resume EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if [ -n "$running" ]; then
    docker compose -f "$compose_file" stop $running
fi

if [ "${FOTOARCHIEF_SKIP_IMAGE_PULL:-0}" = "1" ]; then
    printf '%s\n' "Skipping image pull because FOTOARCHIEF_SKIP_IMAGE_PULL=1; $image must already exist on this Docker host."
else
    docker compose -f "$compose_file" pull app worker scheduler
fi
docker compose -f "$compose_file" up -d --wait --no-deps app
docker compose -f "$compose_file" exec -T app php artisan installation:ready
docker compose -f "$compose_file" exec -T app php artisan installation:migrate-ready
docker compose -f "$compose_file" exec -T app php artisan about --only=environment
docker compose -f "$compose_file" up -d --wait worker scheduler
docker compose -f "$compose_file" exec -T app php artisan installation:ready

trap - EXIT
printf '%s\n' "Upgrade complete for $image."
