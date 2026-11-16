#!/bin/sh
set -eu

APP_RUNTIME_USER="${APP_RUNTIME_USER:-www-data}"
INSTALLATION_WAIT_SECONDS="${INSTALLATION_WAIT_SECONDS:-10}"

run_as_app_user() {
    if [ "$(id -u)" = "0" ]; then
        gosu "$APP_RUNTIME_USER" "$@"
    else
        "$@"
    fi
}

ensure_writable_paths() {
    mkdir -p \
        bootstrap/cache \
        storage/app \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs

    if [ "$(id -u)" = "0" ]; then
        chown -R "$APP_RUNTIME_USER:$APP_RUNTIME_USER" bootstrap/cache storage
    fi
}

# A deploy tool that injects its own variables bypasses .env.example and Compose's
# required-variable check, so the container itself says when DATA_PATH is unsafe
# (App-Guidance baseline §21). It warns and continues: refusing would leave an operator
# without a running app and without the message.
check_data_path() {
    case "${DATA_PATH:-}" in
        "")
            echo "WARNING: DATA_PATH is not set. FotoArchief keeps its data in DATA_PATH on the host; without it the database and photos may live where a redeploy or reboot loses them. Set DATA_PATH to an absolute folder, for example /mnt/user/appdata/fotoarchief." >&2
            ;;
        /*)
            ;;
        *)
            echo "WARNING: DATA_PATH '$DATA_PATH' is relative. The tool that starts the stack decides what it points to, which can be an empty or temporary folder. Use an absolute folder of the host, for example /mnt/user/appdata/fotoarchief, and see docs/DEPLOYMENT_STACKS.md to move existing data." >&2
            ;;
    esac
}

# The setup code is printed while the first-start wizard is pending, so the owner finds it
# in the container log. FOTOARCHIEF_LOG_SETUP_CODE=false keeps it only in the private file.
log_setup_code_enabled() {
    case "$(printf '%s' "${FOTOARCHIEF_LOG_SETUP_CODE:-true}" | tr '[:upper:]' '[:lower:]')" in
        false|0|no|off) return 1 ;;
        *) return 0 ;;
    esac
}

prepare_installation() {
    echo "Preparing private installation state."
    if log_setup_code_enabled; then
        run_as_app_user php artisan installation:prepare
    else
        run_as_app_user php artisan installation:prepare --quiet-code
    fi
}

wait_for_installation() {
    echo "Waiting for first-start onboarding to complete before starting $*."
    while true; do
        set +e
        output="$(run_as_app_user php artisan installation:ready 2>&1)"
        status="$?"
        set -e

        if [ "$status" = "0" ]; then
            echo "First-start onboarding is complete; starting $*."
            return 0
        fi

        if [ -n "$output" ]; then
            echo "$output" >&2
            echo "Readiness check failed with output; refusing to start $*." >&2
            return 2
        fi

        sleep "$INSTALLATION_WAIT_SECONDS"
    done
}

installation_is_ready() {
    set +e
    output="$(run_as_app_user php artisan installation:ready 2>&1)"
    status="$?"
    set -e

    if [ "$status" = "0" ]; then
        return 0
    fi

    if [ -n "$output" ]; then
        echo "$output" >&2
        echo "Readiness check failed with output; refusing to continue." >&2
        return 2
    fi

    return 1
}

coordinate_migrations() {
    echo "Coordinating automatic database migrations before starting $*."
    run_as_app_user php artisan installation:migrate-ready
}

exec_as_app_user() {
    if [ "$(id -u)" = "0" ]; then
        exec gosu "$APP_RUNTIME_USER" "$@"
    fi

    exec "$@"
}

ensure_writable_paths
check_data_path

if [ "${1:-}" = "apache2-foreground" ]; then
    prepare_installation
    if installation_is_ready; then
        coordinate_migrations "$@"
    else
        ready_status="$?"
        if [ "$ready_status" != "1" ]; then
            exit "$ready_status"
        fi
        echo "First-start onboarding is not complete; starting web setup without database migrations."
    fi
    exec "$@"
fi

if [ "${1:-}" = "php" ] && [ "${2:-}" = "artisan" ]; then
    case "${3:-}" in
        queue:work|queue:listen|schedule:work|schedule:run)
            wait_for_installation "$@"
            coordinate_migrations "$@"
            if [ "${3:-}" = "queue:work" ] || [ "${3:-}" = "queue:listen" ]; then
                run_as_app_user php artisan operations:heartbeat worker --state=starting || true
            fi
            exec_as_app_user "$@"
            ;;
        *)
            exec_as_app_user "$@"
            ;;
    esac
fi

exec "$@"
