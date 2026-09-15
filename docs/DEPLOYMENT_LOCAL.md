# Local deployment

This local deployment foundation follows the approved Laravel modular monolith
architecture in `docs/ARCHITECTURE.md`. It uses portable Docker Compose,
PostgreSQL, Valkey as the Redis-compatible queue/cache service, private
local or S3-compatible object storage, one Laravel web container, one worker
container and one scheduler container.

The included `Dockerfile` installs PHP 8.5.10, Apache and production Composer
dependencies from `composer.lock`. Apache serves only `public/` on port 8080.
The container enables Apache `mod_rewrite` and uses explicit front-controller
rules in `deploy/apache.conf`; it does not depend on `public/.htaccess`.
Directory indexes are disabled. The Compose files have passed parser
validation, but the image build, Apache runtime, and PostgreSQL runtime smoke
tests still require a Docker engine or CI runner.
The image starts the first-start onboarding flow without an environment
`APP_KEY`, application database credentials, Redis/Valkey, or S3 credentials.
Those settings are collected by the wizard and stored privately in the shared
`app-storage` volume under `storage/app/installation`.

## Operator changes

1. Copy `.env.example` to `.env`.
2. Set `DB_PASSWORD` for the PostgreSQL container. Keep `POSTGRES_DB` and
   `POSTGRES_USER` stable unless you intentionally want different values in the
   wizard.
3. Use the included `composer.lock`; installation is not a dependency update.
4. Confirm `APP_MAX_UPLOAD_MB=100` and `APP_MAX_UPLOAD_BYTES=104857600` are
   acceptable for the host. If raised, every reverse proxy, CDN, load balancer,
   PHP runtime and worker host in front of the app must allow at least the same
   byte limit. The bundled PHP settings allow a 100 MiB file and 110 MiB
   (115343360 bytes) per HTTP request, with a 300-second PHP execution limit.
   Configure upstream request limits accordingly. Raising the app's variable
   alone does not raise these image settings; change `deploy/php.ini` and
   rebuild as well.
5. Choose storage in the wizard. For a constrained local install, select the
   private local disk. For production-like testing, configure a private
   S3-compatible bucket in the wizard. Hetzner Object Storage is a suitable
   example provider, not a requirement; any provider with S3 protocol
   compatibility may be used.

No real secrets belong in `.env.example`, documentation or source control.

For an existing source-build deployment:

- Remove `APP_START_COMMAND`; the image now starts Apache automatically.
  Remove any Compose command override using `artisan serve` as well.
- Remove application `APP_KEY`, `DB_*`, `FILESYSTEM_DISK` and `AWS_*` values
  from the app runtime unless you have a documented post-install reason to keep
  them. The wizard-owned private installation state has precedence for the app
  key, PostgreSQL connection and storage disk.
- Keep `CACHE_STORE=file`, `SESSION_DRIVER=file` and
  `QUEUE_CONNECTION=database` unless you intentionally switch those runtime
  services after installation. If using Redis-compatible Valkey, keep
  `REDIS_QUEUE_RETRY_AFTER=180` greater than the worker's 120-second timeout.
- The web port mapping is
  `"${APP_BIND_ADDRESS:-127.0.0.1}:${APP_HTTP_PORT:-8080}:8080"`.
  Loopback is the safe default. For access from another computer, explicitly
  choose the test server's LAN address and firewall it appropriately.
- Rebuild/recreate web, worker and scheduler from the same image. The
  entrypoint repairs `storage/` and `bootstrap/cache` ownership when it starts
  as root, runs installation preparation as `www-data`, then starts workers and
  scheduler as `www-data`. Ensure an existing app-storage volume is writable by
  UID 33. Never delete that volume to fix permissions because it contains the
  generated app key and installation state.

## Run locally

After setting `DB_PASSWORD`, start the web and supporting database containers.
The web container prepares the private installation state quietly and serves the
wizard. Valkey is started so it is available for operators who switch runtime
queue/cache/session settings after setup, but it is not required for the
default onboarding path:

```powershell
docker compose up -d --build app postgres valkey
```

Retrieve the one-time setup code through a private terminal. Treat this output
as confidential and do not paste it into tickets, logs or chat. Startup uses
`--quiet-code`, so it does not print the code, and completed installations no
longer reveal it:

```powershell
docker compose exec --user www-data app php artisan installation:prepare
```

Open `http://localhost:8080/setup` and unlock the wizard with the code. When
prompted for database settings, use:

```text
Host: postgres
Port: 5432
Database: fotoarchief
Username: fotoarchief
Password: the DB_PASSWORD value from your private .env
```

The PostgreSQL database must be empty. The wizard creates the schema and first
administrator; do not replace it with manual `migrate` or `db:seed` commands.
Choose either private local disk storage or S3-compatible storage in the wizard.

After the wizard completes, start workers and the scheduler:

```powershell
docker compose up -d --build worker scheduler
```

Worker and scheduler containers poll `php artisan installation:ready` and do not
execute queue or schedule commands while onboarding is pending. Readiness check
errors are written to container logs and prevent startup rather than falling
through to background processing.

To use Redis-compatible Valkey after onboarding, set `CACHE_STORE`,
`SESSION_DRIVER` or `QUEUE_CONNECTION` to `redis` in the private `.env` and
recreate the containers. Valkey is optional; the default deployment uses
database queues and file cache/sessions.

Do not run `php artisan key:generate`. The generated key under
`storage/app/installation` is authoritative and must remain unchanged across
redeployments.

## Config cache

Do not run `php artisan config:cache` while first-start onboarding is pending;
the application blocks this because cached environment settings must not
supersede installation state. After completion, the application reloads the
wizard-owned app key, PostgreSQL and storage settings after configuration cache
loads. Queue, cache and session remain deployment environment settings with
defaults `database`, `file` and `file`.

## Stop locally

Stop containers while keeping persistent volumes:

```powershell
docker compose down
```

Remove local persistent data only when intentionally resetting the environment:

```powershell
docker compose down --volumes
```

## Health verification

Use these checks after each deployment:

```powershell
docker compose ps
docker compose exec postgres pg_isready -U fotoarchief -d fotoarchief
docker compose exec valkey valkey-cli ping
docker compose exec app php artisan about --only=environment
docker compose exec scheduler php artisan schedule:list
```

Verify the HTTP liveness endpoint through the published port. `/up` confirms
framework boot, not database, S3, queue or full ingest readiness:

```powershell
curl http://localhost:8080/up
```

The parent browser onboarding evidence used an isolated PHP CLI-server router,
not Apache. Treat it as application onboarding proof, not as proof that this
Docker image or Apache configuration has run successfully.

## Worker and scheduler behavior

- `worker` waits for completed onboarding, then runs
  `php artisan queue:work ingest --sleep=3 --tries=3 --timeout=120`.
- Ingest uses its own database queue with a fixed 180-second retry-after, on
  the same database as the upload transaction. It does not follow
  `QUEUE_CONNECTION` or `APP_WORKER_JOB_TIMEOUT_SECONDS`.
- For the new extensions, fifth migration, scanner mappings and existing-stack
  edits, follow [0.3.0 operator instructions](PHOTO_WORKFLOW.md#upgraden-en-exacte-operatorwijzigingen).
- Check worker/scheduler logs and actual job completion. Merely listing a
  schedule or queue does not prove those processes are healthy.
- `WORKER_REPLICAS` documents desired worker count. Portable Compose keeps this
  as configuration; operators can also scale explicitly with
  `docker compose up -d --scale worker=2 worker`.
- `scheduler` runs `php artisan schedule:work` for local/container operation.
- If a host cannot keep the scheduler container running, configure a system cron
  or systemd timer to execute `php artisan schedule:run` once per minute from
  the same release.

## Storage setup

The wizard stores selected storage settings privately outside `public/`.
Deployment environment `FILESYSTEM_DISK` and `AWS_*` values are intentionally
not required for first start and should not be used to bypass onboarding.

Create private prefixes or buckets for:

- immutable originals: `ARCHIVE_ORIGINALS_PREFIX`
- rebuildable derivatives: `ARCHIVE_DERIVATIVES_PREFIX`
- non-public quarantine uploads: `ARCHIVE_QUARANTINE_PREFIX`
- temporary exports: `ARCHIVE_EXPORTS_PREFIX`
- backup copies: `ARCHIVE_BACKUPS_PREFIX`

The app stores object keys and checksums in PostgreSQL. It must not depend on
provider-specific object URLs or store photo binaries in PostgreSQL.
