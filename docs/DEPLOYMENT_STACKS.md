# Komodo and Dockhand deployment

## Status

[compose.yaml](../deploy/compose.yaml) and its
[environment template](../deploy/.env.example) define the image-only stack.
Linux Quality run `35051355300` built and accepted the PHP 8.5.10 / Apache
image, including real Dockhand v1.0.48 API import/start and onboarding.
It also verified queued photo processing, restart persistence and a separate
backup/restore stack. Komodo UI import has not been executed. Use the versioned
test-release artifacts rather than inferring a registry tag from an example;
see the [acceptance ledger](RELEASE_ACCEPTANCE.md). The template supports the
implemented first-start onboarding contract: the web container boots without an
environment `APP_KEY`, application database credentials, Redis/Valkey, or S3
credentials. It quietly prepares private installation state on the shared
storage volume and the wizard owns the app key, PostgreSQL settings and storage
settings after completion. Both Compose templates also pass the Compose parser.

## Import contract

### Manager-specific procedure

**Komodo:** select the target server/Periphery and create a Compose Stack.
Use a file/repository stack with `deploy/compose.yaml` as its Compose path, or
paste the YAML into the UI-managed Compose definition. Set the interpolation
variables from `deploy/.env.example` in that stack's private environment.
Review the resolved Compose, then deploy the stack. Do not create three separate
application deployments: app, worker and scheduler must share the same named
`app-storage` volume and image.
The placeholder-only `deploy/komodo-stack.example.toml` mirrors Komodo's
documented Stack fields (`server`, `run_directory`, `file_paths`,
`project_name`, `environment`, `poll_for_updates`, `auto_update` and
`send_alerts`). Copy it into your private Komodo resource configuration, replace
the server name, public `APP_URL`, proxy settings, image tag/digest and
PostgreSQL password, then import/deploy it from Komodo. Keep
`auto_update=false` for FotoArchief release tags; use `poll_for_updates=true`
only as a visible manager update indicator unless you deliberately operate a
rolling tag with a tested rollback path.

**Dockhand:** first enable authentication under Settings > Authentication and
configure the Docker environment. Keep this administrative interface on a LAN
or VPN; Docker management access is effectively host administrative access.
Create a stack with the image-only Compose definition and supply its private
environment variables before deployment. No relative bind-mount paths are used,
so the application does not depend on Dockhand's own data-directory mapping.
If a Git-backed stack is used, keep its private environment outside Git.

For both managers, validate the resolved `APP_IMAGE` and port binding before
deploying. A loaded CI image can be used on that same Docker daemon without
registry credentials (`fotoarchief-ci:<source-commit>`). For deployment to other
servers, distribute/load the saved image or publish it to your own registry;
do not configure automatic pull of a local-only CI tag.

For HTTPS behind a reverse proxy, set `APP_URL` to the exact public origin
including scheme and non-standard port. Set `TRUSTED_PROXIES` to only the
proxy IP address or CIDR that injects `X-Forwarded-*` headers, and set
`SESSION_SECURE_COOKIE=true`. Passkeys use the configured `APP_URL` as their
Relying Party origin and reject a browser ceremony from any other origin.
`http://localhost` is accepted only for local tests; production passkeys require
HTTPS. Physical or phone passkey acceptance still requires a real authenticator
on the final origin and must be recorded separately from automated tests.

After deployment, open the web service, retrieve the private setup code through
the app's terminal, connect to `postgres:5432` and complete onboarding. Upload a
photo, wait for the worker and verify its private preview. Redeploy the same
stack without deleting volumes and verify that setup stays closed and the photo
remains. Use the empty-target restore drill before updating an existing archive.

References: [Komodo introduction](https://komo.do/docs/intro) and
[Dockhand manual](https://dockhand.pro/manual/). UI details vary by manager
version. These are documented import procedures, **not evidence of a completed
manager UI acceptance run**; record the exact version and result when run.

Create a Compose stack in Komodo or Dockhand using the contents of
`deploy/compose.yaml`, or select that path from a repository checkout if the
manager supports it. Supply the template variables in the manager's Compose
interpolation environment, not only as container runtime variables. Alternatively
keep a private `.env` beside `compose.yaml` in the stack working directory.

The stack deliberately has no build context, local source mounts, fixed
container names, external network requirement or manager-specific labels.
The same versioned image runs the web service, worker and scheduler. PostgreSQL
and Valkey run separately without published host ports. Object storage is chosen
in onboarding: use private local storage for constrained installs or external
S3-compatible private storage for production scale.
The app image must serve HTTP on port 8080 by default and allow overriding its
command with `php artisan` for the worker and scheduler. The entrypoint prepares
installation state as `www-data`, never as root, and worker/scheduler commands
wait for `php artisan installation:ready` before they exec as `www-data`.
The bundled Apache virtual host enables front-controller routing itself and
disables directory indexes; the container does not require `public/.htaccess`.

Registry credentials belong in the manager's registry configuration if the
chosen image is private. Select a verified release tag or digest for `APP_IMAGE`;
do not infer a working image name from an example.

## Optional local AI service

AI is disabled by default. Operators who run their own AI service can add
`deploy/ai-local-compose.override.example.yaml` as a private Compose override,
replace `AI_LOCAL_IMAGE` with their own tested image and set
`COMPOSE_PROFILES=ai-local`. FotoArchief expects that service to expose:

- `GET /v1/capabilities`;
- `POST /v1/analyze-image`;
- `POST /v1/embed-image`;
- `POST /v1/embed-text`.

The capability response must identify `provider_kind=local`, support image
analysis plus text/image embeddings and prove both embeddings share one model
space. After configuring the admin AI settings, run:

```sh
docker compose exec app php artisan ai:probe-local
```

This probe is not a GPU/runtime endorsement. Record CPU/GPU, latency, model
license and proof-set relevance evidence as required by
[AI_CAPABILITY_DECISION.md](AI_CAPABILITY_DECISION.md). A normal PHP-ZIP host may
point `AI_LOCAL_ENDPOINT` at an organisation-owned HTTPS service instead of
running the model beside PHP.

External AI endpoints use the same `/v1/*` adapter contract but require
separate admin opt-in, public HTTPS, region/retention documentation and a
non-zero budget. Put `AI_EXTERNAL_API_KEY` only in the manager's private
environment or secret store. FotoArchief never falls back from the local
provider to the external provider after a local error.

## Operator changes

### Exchange worker recovery

Existing deployments keep working without adding variables: the new defaults are
`EXCHANGE_JOB_TIMEOUT_SECONDS=120`, `EXCHANGE_STALE_CLAIM_SECONDS=150` and
`EXCHANGE_ABANDONED_CLAIM_SECONDS=1800`. A stopped import/export worker can be
reclaimed after 150 seconds; the job deadline is 120 seconds and the ingest queue
visibility remains 180 seconds. Keep **timeout < reclaim < visibility**.
The scheduler marks abandoned claims failed after 1800 seconds, checked every
15 minutes; that is not a promise of recovery exactly 1800 seconds after a crash.

If customising these values, add them to the private stack interpolation
environment and copy these exact mappings into the shared application environment
of a manually maintained Compose file, then redeploy app, worker and scheduler:

```yaml
EXCHANGE_JOB_TIMEOUT_SECONDS: ${EXCHANGE_JOB_TIMEOUT_SECONDS:-120}
EXCHANGE_STALE_CLAIM_SECONDS: ${EXCHANGE_STALE_CLAIM_SECONDS:-150}
EXCHANGE_ABANDONED_CLAIM_SECONDS: ${EXCHANGE_ABANDONED_CLAIM_SECONDS:-1800}
```

No port or volume mapping changes are required. Preserve the existing
`app-storage` and `postgres-data` volumes and database password.

### Manager update checks

Komodo's update modes apply to the Stack resource. For pinned FotoArchief
release tags, prefer `poll_for_updates=true` so Komodo shows an available digest
change without redeploying unexpectedly. Leave `auto_update=false` unless a
human has accepted the exact backup, migration and restore procedure for a
rolling tag. Dockhand does not replace FotoArchief's own release gate; update
the stack's private `APP_IMAGE` value to the tested tag/digest and redeploy
without deleting volumes.

For either manager, the acceptance evidence is the same as direct Compose:
the stack is created by the manager, onboarding completes, a photo is processed
by the worker, a redeploy preserves volumes, and an upgrade run preserves the
installer lock, administrator, photo files and application key. A Compose parse
alone is only a template syntax check.

### Safe Compose upgrade helper

For Docker/manager deployments where the operator can run Docker Compose
commands, use `scripts/upgrade-compose.sh` after updating the stack's private
`APP_IMAGE` variable to the exact tested release tag or digest:

```bash
sh scripts/backup-compose.sh /private/fotoarchief-backup-YYYYMMDD
APP_IMAGE=ghcr.io/helmerznl/fotoarchief:vX.Y.Z \
  sh scripts/upgrade-compose.sh /private/fotoarchief-backup-YYYYMMDD
```

The helper refuses to run without a backup directory containing valid
`SHA256SUMS`, validates the resolved Compose file, stops only worker and
scheduler services, starts the new web image, checks that installation is still
complete, runs `php artisan migrate --force`, then restarts workers/scheduler.
It does not delete volumes, regenerate keys, reopen setup or run
`docker compose down --volumes`. If any command fails, stopped background
services are started again so the operator can restore from the verified backup.

### OCR and exchange settings

The image includes Tesseract plus Dutch and English trained data. OCR remains
disabled by default. To enable it, add `OCR_ENABLED=true` to the stack's private
interpolation variables and redeploy both app and worker. The optional defaults
are `OCR_BINARY=tesseract`, `OCR_LANGUAGES=nld+eng`, and `OCR_TIMEOUT=60` seconds.
For the PHP ZIP route, the host must install Tesseract and allow subprocesses;
uploading PHP files alone cannot install an operating-system executable.

Both Compose templates forward these exact mappings to app, worker and scheduler:
`OCR_ENABLED: ${OCR_ENABLED:-false}`, `OCR_BINARY: ${OCR_BINARY:-tesseract}`,
`OCR_LANGUAGES: ${OCR_LANGUAGES:-nld+eng}`, `OCR_TIMEOUT: ${OCR_TIMEOUT:-60}`.
Existing deployments keep working without adding these variables.

CSV/export settings are also forwarded, rather than silently ignored by Compose.
The optional defaults are `EXCHANGE_MAX_IMPORT_BYTES=5242880`,
`EXCHANGE_MAX_IMPORT_ROWS=5000`, `EXCHANGE_SYNC_ANALYSIS_BYTES=262144`,
`EXCHANGE_MAX_EXPORT_ASSETS=500`, `EXCHANGE_MAX_EXPORT_BYTES=1073741824`,
`EXCHANGE_EXPORT_TTL_MINUTES=120`, and `EXCHANGE_DOWNLOAD_TTL_MINUTES=10`.
Each uses the mapping `NAME: ${NAME:-default}` in both templates. No manual
addition is required to retain those defaults. Upstream infrastructure must
allow CSV requests of at least **6291456 bytes** and export responses of at least
**1073741824 bytes**, with enough streaming time. Upload limits remain
**104857600 bytes per image**, **115343360 bytes per request**, **300 seconds**.

This is a separate image-only template. For source-build deployments and the
change from the PHP development server to Apache, also see
[DEPLOYMENT_LOCAL.md](DEPLOYMENT_LOCAL.md).

- Set `APP_IMAGE` (no default) to an available image.
- Set `APP_URL`, `TRUSTED_PROXIES` and `SESSION_SECURE_COOKIE` as described
  above before registering passkeys.
- Set `DB_PASSWORD` (no default) once for the PostgreSQL container. Changing
  this variable does not change a password in an existing database; rotation
  requires changing the database role password as well. The app does not receive
  this value from Compose before onboarding. During the wizard, enter host
  `postgres`, port `5432`, database `fotoarchief`, user `fotoarchief` and the
  same `DB_PASSWORD` value unless you changed `POSTGRES_DB` or `POSTGRES_USER`.
- Do not set `APP_KEY` for the container. The first-start wizard generates and
  persists the key under `storage/app/installation`; retain the `app-storage`
  volume across redeployments.
- Do not set `AWS_*` or `FILESYSTEM_DISK` to force storage before onboarding.
  Select private local disk or S3-compatible storage in the wizard. Stored
  installation settings override environment database and storage values after
  configuration loads.
- `CACHE_STORE`, `SESSION_DRIVER` and `QUEUE_CONNECTION` default to `file`,
  `file` and `database`. Redis-compatible Valkey remains available after
  onboarding by setting those variables to `redis`. Keep
  `REDIS_QUEUE_RETRY_AFTER` greater than the worker timeout.
- `APP_URL` defaults to `http://localhost:8080`; replace it with the externally
  reachable URL. Use HTTPS outside local testing.
- The exact port mapping is
  `"${APP_BIND_ADDRESS:-127.0.0.1}:${APP_HTTP_PORT:-8080}:8080"`.
  Loopback is the safe default. For access from another computer, explicitly
  select the test server's LAN address and firewall it appropriately.
  A reverse proxy in another container cannot reach the host's loopback as its
  own loopback; configure its host reachability or a shared network separately.
- Keep `COMPOSE_PROJECT_NAME` stable (default `fotoarchief`) so redeployments
  reuse the named volumes. Use different names and ports for separate instances.
- Retrieve the pending setup code only through a private shell:
  `docker compose -f deploy/compose.yaml exec --user www-data app php artisan installation:prepare`.
  Startup automation uses `--quiet-code`, so the code is not printed on every
  restart. Completed installations no longer reveal the code. Treat the code,
  `storage/app/installation/state.json` and `setup-code.txt` as confidential.
- The file ceiling remains `APP_MAX_UPLOAD_BYTES=104857600` (100 MiB).
  The image uses PHP `upload_max_filesize=100M`, `post_max_size=110M`
  (115343360 bytes per HTTP request) and a 300-second PHP execution limit.
  Every ingress/proxy must allow at least 115343360 bytes and the required
  request duration. Raising the app variable alone does not raise the image's
  PHP limits: change the PHP configuration and rebuild the image as well.
  The JavaScript uploader sends selected files one per request. Without
  JavaScript, the total request size and PHP max_file_uploads also apply.
- Ingest worker timeout is 120 seconds and its database queue visibility is
  180 seconds, independently of the default/Redis queue settings.
- For 0.3.0, take over the explicit ingest workercommand and the five new
  scanner/pixel/batch environment mappings documented in
  [PHOTO_WORKFLOW.md](PHOTO_WORKFLOW.md#upgraden-en-exacte-operatorwijzigingen).
  Existing `.env` files need no additions for the default, explicitly unscanned
  mode, but custom Compose copies do need the updated command and mappings.

Only persist secrets in the private stack environment or the wizard-owned
private installation state, never the Git repository. App storage, PostgreSQL
and Valkey use named volumes. Object storage needs its own backup; named volumes
are persistence, not backups. Follow [BACKUP_RESTORE.md](BACKUP_RESTORE.md)
before upgrading or moving a stack.

## Onboarding delivery gate

The deployment template expects the implemented application onboarding contract.
The Quality workflow now covers pending startup/key persistence, actual HTTP
wizard completion against PostgreSQL/local storage, queued upload/JPEG delivery,
restart after completion, and empty-target backup restoration. Its separate
Dockhand API import creates a second stack and repeats onboarding. These are
executable acceptance gates, not evidence that a run has passed: see the
[acceptance ledger](RELEASE_ACCEPTANCE.md) for actual results. Komodo UI import,
S3 storage and a production HTTPS origin require their own acceptance; neither
Compose parsing nor a Dockhand API run proves those paths.
