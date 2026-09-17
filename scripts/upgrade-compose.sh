#!/bin/sh
set -eu

usage() {
    echo "Usage: sh scripts/upgrade-compose.sh /private/existing-backup-directory" >&2
    echo "Update APP_IMAGE in the stack environment before running this helper." >&2
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

if [ -z "${APP_IMAGE:-}" ]; then
    echo "Refusing upgrade: APP_IMAGE must point to the exact tested image tag or digest." >&2
    exit 1
fi

docker compose -f "$compose_file" config --quiet

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
    printf '%s\n' "Skipping image pull because FOTOARCHIEF_SKIP_IMAGE_PULL=1; APP_IMAGE must already exist on this Docker host."
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
printf '%s\n' "Upgrade complete for APP_IMAGE=${APP_IMAGE}."
