# Backup and restore

FotoArchief must back up PostgreSQL metadata, S3-compatible object storage,
deployment configuration and required secrets separately. A backup is not a
recovery control until a restore has been tested.

## Consistent local-volume backup scripts

On a Linux Docker host, from the application checkout:

```sh
export COMPOSE_FILE=deploy/compose.yaml
sh scripts/backup-compose.sh /private/backups/fotoarchief-2026-09-16
```

The destination must not exist. The script stops the web, worker and scheduler
services while collecting a custom PostgreSQL dump and the private `storage/app`
tree. It resumes only services which were previously running, including on an
error. Stop any additional replicas or external writers yourself first.
`SHA256SUMS`, `FORMAT` and `VERSION` identify the matching recovery set.
Protect the backup as a secret: installation state includes the application key
and database/storage credentials. Copy it encrypted to a second location.
Checksums detect accidental damage; they do not authenticate untrusted backups.

The automatic volume procedure refuses S3, mixed disks and unassigned legacy
file locations rather than claiming to have backed up absent originals. Those
installations need the object-storage snapshot procedure below together with a
database dump and installation-state backup during the same write-free window.
Webhosting operators can use their provider's PostgreSQL dump/file backup tools
with that same consistency requirement; these scripts require Docker Compose.

Restore into a **separate empty stack** using the same image version, database
name/user/password and private installation hostname (normally `postgres`):

```sh
export COMPOSE_PROJECT_NAME=fotoarchief-restore
export APP_HTTP_PORT=8081
sh scripts/restore-compose.sh /private/backups/fotoarchief-2026-09-16 --confirm-empty-target
```

The restore verifies checksums, refuses a non-empty database or private storage,
rejects unsafe archive entries and uses a single-transaction `pg_restore`.
The container's `scripts/restore-storage.php` reads the archive through standard
input into a private temporary `.tar` file, validates every entry before
extracting, and removes its temporary copy on success or failure. Keep that
helper with the matching image; do not substitute an unchecked `tar -xf`.
It does not run `--clean`, erase volumes, regenerate keys or reopen onboarding.
On failure it leaves application services stopped; investigate before retrying
in a new empty target. Verify account login, original checksums and previews
before serving traffic. An existing installation must never be used as a test
restore destination.

## Backup contents

- PostgreSQL database: authoritative metadata, workflow state, audit events and
  object keys.
- S3-compatible object storage: immutable originals, derivatives that should be
  retained, quarantine when operationally required, and exports.
- Deployment configuration: `docker-compose.yml`, `.env.example`, operator
  `.env` values from a secure secret store, reverse-proxy configuration and
  release/version identifiers.
- Private installation state: the complete `storage/app/installation`
  directory from the shared application storage volume. It contains the
  generated Laravel app key, installation lock/status, setup code material while
  pending, and the submitted database/storage settings after completion.
- Required secrets: database password, S3 access keys when S3 is selected,
  the generated app key inside installation state, and any future scanner or
  mail credentials. Store operator-managed secrets in a password manager or
  secret manager, not in git.

Keep at least one backup copy outside the primary VPS and outside the active
object-storage location.

## Database backup

Example local PostgreSQL dump into the persistent `postgres-backups` volume:

```powershell
docker compose exec postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc -f "/backups/fotoarchief.dump"'
docker compose cp postgres:/backups/fotoarchief.dump .\fotoarchief.dump
```

Move the copied dump to the chosen off-host backup location.

## Object backup

Use an S3-compatible tool such as `rclone`, `aws s3 sync` with a custom endpoint,
or the provider's S3-compatible client configuration. Back up from the active
private bucket or prefixes to a second location.

Required behavior:

- preserve object keys and metadata needed for verification;
- include immutable originals;
- include derivatives only when retention policy says they are not disposable;
- never make quarantine or originals public;
- record the source endpoint, bucket, prefixes and backup timestamp.

## Restore test steps

Run a restore drill into an empty test environment:

1. Create a fresh `.env` with test-only secrets and S3-compatible restore
   credentials.
2. Restore the application storage volume, including
   `storage/app/installation`, from the matching backup. Do not run
   `key:generate`, delete the installation directory or start a fresh wizard
   against a restored database.
3. Start PostgreSQL and Valkey:
   `docker compose up -d postgres valkey`.
4. Copy the dump into the database container:
   `docker compose cp .\fotoarchief.dump postgres:/backups/fotoarchief.dump`.
5. Restore into an empty database:
   `docker compose exec postgres sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists "/backups/fotoarchief.dump"'`.
6. Restore or sync object storage into the configured private bucket/prefixes
   when the installation uses S3-compatible storage. For private local storage,
   restore the relevant `storage/app/private` contents with the same storage
   backup.
7. Start app, worker and scheduler from the same release:
   `docker compose up -d app worker scheduler`.
8. Run migrations only if the release procedure requires them for the restored
   version.
9. Verify app health, queue health, scheduler listing, representative asset
   detail pages, signed downloads and derivative rebuild behavior.
10. Document restore duration, missing objects, failed jobs and corrective
   actions.

## Configuration precedence and cache

After onboarding, the wizard-owned state overrides deployment environment values
for:

- `app.key`;
- `database.default` and the PostgreSQL connection used by the application;
- `filesystems.default`, private local disk settings and S3 settings.

Deployment environment still owns queue, cache and session settings. Defaults
are `QUEUE_CONNECTION=database`, `CACHE_STORE=file` and `SESSION_DRIVER=file`.
Redis-compatible Valkey is optional operator configuration after setup.

Do not restore or create a configuration cache that predates the installation
state. `php artisan config:cache` is disallowed while onboarding is pending, and
after completion the application must reload installation settings after cached
configuration loads. A stale cache must never supersede the restored app key,
database or storage configuration.

## Recovery checks

After restore, confirm:

- PostgreSQL row counts and migration state match expectations.
- A sample of originals has matching SHA-256 checksums.
- Private objects are not publicly accessible without a signed URL.
- Workers can retry failed ingest or derivative jobs.
- Search indexes, thumbnails, previews and other disposable outputs can be
  rebuilt from PostgreSQL plus object storage.
- The restored `storage/app/installation` state remains complete, and
  `/setup` does not reopen after redeploy.
