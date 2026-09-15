# Komodo and Dockhand deployment

## Status

[compose.yaml](../deploy/compose.yaml) and its
[environment template](../deploy/.env.example) define the image-only stack.
They are not yet a runnable release: no verified application image is published,
the PHP 8.5.10 / Apache image build is not runtime-verified, and import/start
has not yet been tested in either manager. The template is wired for the
implemented first-start onboarding contract: the web container boots without an
environment `APP_KEY`, application database credentials, Redis/Valkey, or S3
credentials. It quietly prepares private installation state on the shared
storage volume and the wizard owns the app key, PostgreSQL settings and storage
settings after completion. Parent browser onboarding validation used an
isolated PHP CLI-server router; it is not Apache, image-build, or container
runtime proof. Both Compose templates pass the Compose parser.

## Import contract

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

## Operator changes

This is a separate image-only template. For source-build deployments and the
change from the PHP development server to Apache, also see
[DEPLOYMENT_LOCAL.md](DEPLOYMENT_LOCAL.md).

- Set `APP_IMAGE` (no default) to an available image.
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

The deployment template now expects the application onboarding contract to be
present. CI smokes only the pending-onboarding start and restart persistence of
the generated private state; it does not fake success with manual migrations or
seeding. Acceptance testing still must cover fresh import in both managers,
wizard completion against PostgreSQL and chosen storage, worker activation,
restart/redeploy persistence after completion, and an upgrade without reopening
installation. Until those manager runs pass, do not claim a verified Komodo or
Dockhand release.
