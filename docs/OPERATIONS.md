# Operations

FotoArchief is operated as a self-hosted Laravel application on a managed VPS or
equivalent server, with an additional shared-hosting deployment path. The
initial topology uses PostgreSQL, private local or S3-compatible storage,
file cache/sessions and database queues. Valkey/Redis is optional for larger
deployments, not required before the onboarding wizard.

## Service responsibilities

- `app`: serves the Laravel web/API runtime behind a TLS reverse proxy such as
  Caddy or Nginx.
- `worker`: waits for completed installation, then processes the dedicated
  database `ingest` queue: validation, optional scanning, checksums,
  technical metadata, private derivatives and retry recovery.
  OCR, indexing and import/export jobs are still planned.
- `scheduler`: waits for completed installation and runs scheduled Laravel tasks. Prefer the scheduler container;
  use a host cron/systemd timer running `php artisan schedule:run` every minute
  only as a fallback.
- `postgres`: stores authoritative metadata and workflow state.
- Redis/Valkey: optional external service when selecting Redis-compatible
  queue/cache/session drivers.

The first private pipeline is implemented. A running worker alone does not
prove job completion. Upload a test image and inspect its preview/status.
See [PHOTO_WORKFLOW.md](PHOTO_WORKFLOW.md) for statuses, host requirements,
limits, scanning and exact 0.3.0 operator/upgrade instructions.

## Routine commands

```powershell
docker compose ps
docker compose logs --tail=100 app
docker compose logs --tail=100 worker
docker compose logs --tail=100 scheduler
docker compose exec app php artisan about --only=environment
docker compose exec app php artisan queue:failed
docker compose exec scheduler php artisan schedule:list
```

## Upload limits

The default contractual upload ceiling is:

- `APP_MAX_UPLOAD_MB=100`
- `APP_MAX_UPLOAD_BYTES=104857600`
- `APP_UPLOAD_TIMEOUT_SECONDS=300`
- Ingest job timeout: 120 seconds; queue visibility: 180 seconds.
  The older `APP_WORKER_JOB_TIMEOUT_SECONDS` example does not alter ingest.

If an operator raises any size, rate or timeout limit, the same concrete value
must be allowed at every layer in front of or inside the app: reverse proxy,
CDN if present, PHP/web server, Laravel validation, temporary storage and worker
runtime. Do not describe the requirement as one proxy-specific directive; state
the numeric limit the deployment must accept.

## Background archive operations

Every expensive archive operation runs on the dedicated database `ingest` queue,
never inside an HTTP request. Re-hashing originals, rebuilding derivatives,
copying between storage disks, purging trashed assets and cleaning orphan
quarantine uploads are started from `/admin/operations/...`, which only records
an `operation_runs` row and pushes a job; the worker performs the work.

- Status, progress, error message and retry are visible at
  `/admin/operations/runs` and in the panel on each operations page.
- A run is processed in bounded chunks of 25 items. Each chunk is a separate
  job that re-dispatches the remainder, so no single job approaches the worker
  timeout regardless of archive size.
- Job timeout is 120 seconds, equal to the worker timeout and strictly below the
  `ingest` queue visibility of 180 seconds. A per-job timeout overrides the
  worker `--timeout`, so it must never be raised above 120 without raising the
  worker timeout and the visibility window together.
- A failed run keeps its cursor. Retrying from the UI resumes where it stopped
  instead of restarting; retry requires `users.manage`.
- A crashed worker does not strand a run: a `running` row older than the job
  timeout plus 60 seconds is reclaimed by the next delivery.
- Storage cutover is the one operation that stays in the request: it only flips
  already-verified database references and moves no bytes.

Without a running worker these operations stay queued and nothing happens. Check
`docker compose logs --tail=100 worker` and `/admin/operations/runs` together.
## Reaching the operations pages

The global header carries one `Operaties` link, into system diagnostics, shown to
users holding `users.manage`. Every operations page then renders the same
`Archiefbewerkingen` menu, so duplicates, processing, integrity, storage
migration, trash, OCR, background runs and the photo overview are all one tap
apart. Menu entries are rendered only when the signed-in user may open them.

Two operations belong to a single photo and live on its detail page under
`Archiefbewerkingen`: starting text recognition (OCR) and moving the photo to the
trash. File versions and the processing log for each upload are linked from the
same page. File-version pages follow the asset ownership policy, exactly like the
photo detail page they are reached from.

Operator note: a user with `catalogue.manage` or `assets.update` but without
`users.manage` sees no `Operaties` link in the header and reaches the module only
through a photo detail page. Grant `users.manage`, or add a header entry, if
archivists should reach the module directly.

The interface is checked at a 390 px viewport. Wide tables scroll inside their own
container so the page itself never scrolls sideways.
## Which file a dossier currently serves

A dossier keeps every original it has ever received: an improved scan is added,
never written over the previous one. Exactly one of those files is the one the
archive currently serves, and any reader outside this module — the public
catalogue, the viewer, media and IIIF endpoints, exports — must select it
explicitly. Taking the first retained file of a dossier is wrong, because the
oldest withdrawn scan is a retained file too.

The contract:

- `asset_files.is_primary = true` marks the file the dossier currently serves.
  A dossier has **at most one**; this is enforced by the partial unique index
  `asset_files_single_primary_per_asset`, not merely by convention, so a reader
  may rely on it without defending against duplicates.
- `asset_versions.is_current = true` marks the matching version row, and always
  points at the same file as the primary flag. The two move together inside one
  transaction.
- A file that is not primary is retained history. It must never be served
  publicly, and it must never be reachable through a viewer, media or IIIF route
  by id alone.
- Files ingested before version tracking existed have been given a version row
  by the repair migration, so joining through `asset_versions` no longer loses
  them. Readers may use either flag; `is_primary` is the cheaper one.

### When a review becomes stale

`assets.lock_version` is the dossier revision the metadata form validates
against. It is incremented whenever the served bytes change, which invalidates
any open review rather than letting it be saved over a file that is no longer
the one being served:

- activating a different version (operations),
- rebuilding derivatives for a file (operations),
- ingesting a replacement scan that takes over as primary (ingest),
- merging a duplicate dossier (operations).

A consumer that caches rendered output should treat `lock_version` as the cache
key for a dossier; a bump means the previous rendition is withdrawn.
## Who may see which dossier

Every archive operation is authorised against the dossier it touches, not against
a global permission, because a global permission would let any account holding
`assets.view` read a private dossier belonging to someone else.

- Reading a dossier requires `assets.view` **and** either owning it or holding
  `assets.publish`. `assets.publish` is a role permission that widens private
  read access; it is not a published status on the dossier.
- Changing a dossier additionally requires `assets.update`; uploading a new file
  version additionally requires `assets.create`.
- OCR listing, searching, viewing, correcting and dispatching are scoped the same
  way. A search never returns text from a dossier the actor may not open, and rows
  whose dossier is trashed or gone are excluded everywhere.
- Dispatching OCR writes machine text onto the dossier, so it requires the update
  permission rather than only the read permission.
## Security and storage posture

- Keep originals, quarantine and private derivatives out of public buckets.
- Use short-lived signed URLs or authorised application endpoints for access.
- Treat image files and metadata as untrusted input until validation and scan
  jobs have completed.
- Keep database passwords, S3 keys and `APP_KEY` outside git.
- Rotate credentials after suspected exposure or before a public release.

## Deployment checklist

1. Pull the intended release on the VPS.
2. Review operator changes listed in `docs/DEPLOYMENT_LOCAL.md`.
3. Ensure `.env` contains safe production values and no placeholder remains.
4. Run `docker compose pull` when using published images, or
   `docker compose build` when building locally.
5. Start the database dependency: `docker compose up -d postgres`.
6. Start application services: `docker compose up -d app worker scheduler`.
7. On first installation, complete `/setup` using the private code from
   `docker compose exec --user www-data app php artisan installation:prepare`.
   The wizard migrates the empty database and creates the first administrator.
   On upgrades, run migrations according to the release notes instead.
8. Verify health, queue and scheduler status.
9. Confirm backups complete after the release.

## Incident response

- Web unavailable: check reverse proxy, `app` health, container logs and
  database connectivity.
- Ingest stalled: inspect `worker` logs, failed jobs and object-storage
  permissions.
- Scheduler stale: confirm `scheduler` is running or that the host cron/systemd
  fallback executes once per minute.
- Storage errors: verify S3 endpoint, region, bucket, credentials, private
  prefixes and network access, or local private-directory ownership and space.
- Installer incomplete: workers waiting for readiness is expected. Follow
  [ONBOARDING.md](ONBOARDING.md); do not delete persistent state to retry.
- Restore required: follow `docs/BACKUP_RESTORE.md` and restore into a test
  target first when time allows.
