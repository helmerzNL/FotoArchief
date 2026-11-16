#!/bin/sh
# Moves an installation that still keeps its data in Docker volumes into DATA_PATH
# (App-Guidance baseline §21): storage, the PostgreSQL database, Valkey and the ClamAV
# signatures each become a plain folder under DATA_PATH on the host.
#
#   sh scripts/migrate-data.sh           move, when there is something to move
#   sh scripts/migrate-data.sh --check   only report; changes nothing
#
# Safe to run on every update: it is a no-op when the data is already in DATA_PATH. It
# stops the stack before copying, checks every copy, never deletes the old volumes and
# refuses when DATA_PATH already holds a database (it does not choose between two).
set -eu
umask 022

check=0
case "${1:-}" in
    "") ;;
    --check) check=1 ;;
    *)
        echo "Usage: sh scripts/migrate-data.sh [--check]" >&2
        exit 2
        ;;
esac

compose_file=${COMPOSE_FILE:-deploy/compose.yaml}
project=${COMPOSE_PROJECT_NAME:-fotoarchief}
helper_image=${FOTOARCHIEF_MIGRATE_IMAGE:-alpine:3.20}

env_value() {
    # Reads NAME=value from an env file without sourcing it.
    sed -n "s/^$1=//p" "$2" 2>/dev/null | tail -n 1
}

data_path=${DATA_PATH:-}
for env_file in "$(dirname "$compose_file")/.env" .env; do
    if [ -z "$data_path" ] && [ -f "$env_file" ]; then
        data_path=$(env_value DATA_PATH "$env_file")
    fi
done
case "$data_path" in
    /*) ;;
    "")
        echo "Refused: DATA_PATH is not set. Set it in .env to an absolute folder of the host, for example /mnt/user/appdata/fotoarchief." >&2
        exit 2
        ;;
    *)
        echo "Refused: DATA_PATH '$data_path' is relative. Use an absolute folder of the host, for example /mnt/user/appdata/fotoarchief." >&2
        exit 2
        ;;
esac
data_path=${data_path%/}
export DATA_PATH="$data_path"
marker="$data_path/.migrated-from-docker-volumes"

# volume suffix:folder under DATA_PATH
pairs="app-storage:storage postgres-data:postgres valkey-data:valkey clamav-data:clamav"

if [ -f "$marker" ]; then
    echo "Already moved into $data_path (see $marker). Nothing to do."
    exit 0
fi

# Where does the database container really keep its data right now?
container=$(docker compose -f "$compose_file" ps -a -q postgres 2>/dev/null | head -n 1 || true)
if [ -n "$container" ]; then
    source=$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/var/lib/postgresql/data"}}{{.Type}} {{if .Name}}{{.Name}}{{else}}{{.Source}}{{end}}{{end}}{{end}}' "$container" 2>/dev/null || true)
    if [ -n "$source" ]; then
        echo "The database container keeps its data in: $source"
    fi
fi

found=""
for pair in $pairs; do
    volume="${project}_${pair%%:*}"
    if docker volume inspect "$volume" > /dev/null 2>&1; then
        found="$found $pair"
    fi
done

if [ -z "$found" ]; then
    echo "Nothing to move: project '$project' has no FotoArchief Docker volumes; the data is in $data_path."
    exit 0
fi

refused=0
for pair in $found; do
    target="$data_path/${pair#*:}"
    if [ "${pair#*:}" = "postgres" ] && [ -f "$target/PG_VERSION" ]; then
        echo "Refused: both the volume ${project}_${pair%%:*} and $target hold a database. Nothing was copied. Decide which one to keep: if $target is an empty database from a first start with DATA_PATH, move that folder aside and run this again." >&2
        refused=1
    elif [ -d "$target" ] && [ -n "$(ls -A "$target" 2>/dev/null)" ]; then
        echo "Refused: $target is not empty, so ${project}_${pair%%:*} cannot be copied into it. Nothing was copied. Move $target aside and run this again." >&2
        refused=1
    fi
done
[ "$refused" = 0 ] || exit 1

if [ "$check" = 1 ]; then
    echo "Would move into $data_path:"
    for pair in $found; do
        echo "  volume ${project}_${pair%%:*} -> $data_path/${pair#*:}"
    done
    echo "Run without --check to move. The stack is stopped while copying; the volumes are kept."
    exit 0
fi

echo "Stopping the stack before copying."
docker compose -f "$compose_file" stop

for pair in $found; do
    volume="${project}_${pair%%:*}"
    target="$data_path/${pair#*:}"
    mkdir -p "$target"
    echo "Copying volume $volume to $target."
    docker run --rm -v "$volume":/from:ro -v "$target":/to "$helper_image" sh -ec 'cp -a /from/. /to/'
    if ! docker run --rm -v "$volume":/from:ro -v "$target":/to:ro "$helper_image" diff -r /from /to > /dev/null; then
        echo "Stopped: the copy in $target differs from volume $volume. The volume is untouched; check free space and permissions, empty $target and run this again." >&2
        exit 1
    fi
done

{
    echo "Moved from Docker volumes on $(date -u +%Y-%m-%dT%H:%M:%SZ). The old volumes were kept:"
    for pair in $found; do
        echo "${project}_${pair%%:*}"
    done
} > "$marker"

echo "Done. The data is now in $data_path."
echo "The old volumes were kept. After checking the app, remove them with:"
for pair in $found; do
    echo "  docker volume rm ${project}_${pair%%:*}"
done
echo "Start the stack again: docker compose -f $compose_file up -d"
