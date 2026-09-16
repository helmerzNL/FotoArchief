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
- S3-compatible object storage: recommended for production originals and
  derivatives. Hetzner Object Storage is supported through the standard S3
  settings collected by onboarding; provider acceptance requires the opt-in
  real S3 test described in [ONBOARDING.md](ONBOARDING.md#real-s3hetzner-acceptance).

The first private pipeline is implemented. A running worker alone does not
prove job completion. Upload a test image and inspect its preview/status.
See [PHOTO_WORKFLOW.md](PHOTO_WORKFLOW.md) for statuses, host requirements,
limits, scanning and exact 0.3.0 operator/upgrade instructions.

System diagnostics measure background activity with persisted heartbeats. The
scheduler writes one every minute from the Laravel schedule. The worker writes a
startup heartbeat from the container entrypoint and updates the heartbeat again
when ingest jobs start, finish or fail. A missing heartbeat means activity has
not been observed by the application; it is not treated as proof that the
process is healthy.

## Routine commands

```powershell
docker compose ps
docker compose logs --tail=100 app
docker compose logs --tail=100 worker
docker compose logs --tail=100 scheduler
docker compose exec app php artisan about --only=environment
docker compose exec app php artisan queue:failed
docker compose exec scheduler php artisan schedule:list
docker compose exec scheduler php artisan operations:heartbeat scheduler
docker compose exec scheduler php artisan operations:check-alerts --dry-run
sh scripts/backup-copy-encrypted.sh /private/backups/latest /offsite/fotoarchief-latest.tar.gz.enc /private/fotoarchief-backup.key
```

## Operational alerts

FotoArchief evaluates the same diagnostics used by the operations page through
`php artisan operations:check-alerts`. The scheduler runs this hourly. Alerts
are disabled by default; when incidents are found while disabled, the command
writes a structured warning to the application log instead of calling any
external service.

Configure a webhook only with an operator-owned endpoint:

```text
OPERATIONS_ALERTS_ENABLED=true
OPERATIONS_ALERT_WEBHOOK_URL=https://ops.example.invalid/fotoarchief
OPERATIONS_ALERT_MINIMUM_SEVERITY=warning
OPERATIONS_ALERT_FAILED_INGEST_THRESHOLD=5
OPERATIONS_ALERT_PENDING_INGEST_THRESHOLD=100
```

The payload contains the application name, environment, timestamp,
overall status and incident summaries. It does not include credentials, `.env`
contents, installation state or image metadata. Use `--dry-run` after changing
thresholds or routing so the payload can be inspected without sending a
notification.

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
## Reliability of background operations

The timing values below are a set, not independent knobs. Changing one without
the others reintroduces a failure that is silent in production:

- Worker timeout **120s** (`--timeout`), and each job declares the same value.
  A per-job timeout overrides the worker's, so it must never be higher.
- Stale-claim window **150s**. A run whose claim is older than this may be taken
  over, because no worker can still be running the chunk.
- Ingest `retry_after` **180s**. The queue redelivers a reserved message after
  this long.

The window must sit strictly between the other two. If it equalled `retry_after`,
a redelivery could arrive while the row was not yet stale, fail to claim, and be
dropped -- leaving the run "running" with nothing left to retry it.

A delivery that cannot take the claim is **released back to the queue**, not
deleted, while the run is unfinished. Deleting it is what strands an operation:
if the holder was killed between claiming and finishing, its own redelivery is
the only thing that can resume the run.

### Size limits

One job handles 25 items and then re-dispatches the remainder, so any run is made
of jobs that each finish well inside the timeout. The chain is bounded at 2500
jobs, which covers **62 500 items** -- a 50 000-file maintenance run completes in
one go.

A copy chunk is bounded by bytes as well as by items (256 MB), because twenty-five
100 MB originals do not copy inside 120s on a slow target disk. Copies are
idempotent: a file already copied and checksum-verified for that migration is
skipped rather than copied and counted twice.

Reaching a bound is **never reported as success**. The run is marked failed, with
`result.resume_cursor` recording the last processed file, and starting it again
continues from there. A run that stops making progress is stopped the same way
rather than chaining forever.

## Checking that OCR really works

`php artisan operations:ocr-smoke` runs the configured Tesseract binary against a
generated image and fails with a non-zero exit code if the binary is missing, the
language data is absent, or no text comes back. Configuration alone proves
nothing: a container can carry the right environment variables and have no binary
at all. Run it in the image build or in CI, where a missing binary stops the
pipeline instead of reaching an operator.

Use `--language=nld` to verify the Dutch language data specifically.

The command ships inside the image (it lives in `app/`, which the Dockerfile
copies; only `tests/` is excluded), so nothing has to be copied into a running
container:

```
docker compose exec --user www-data worker php artisan operations:ocr-smoke --language=nld
```

Run it against the **worker** container, because that is where the binary is
installed. Running it against the web container proves nothing about the
container that will actually do the work.

### Proving the whole path, not just the binary

```
docker compose exec --user www-data app php artisan operations:ocr-smoke --queued --wait=90
```

`--queued` skips the local binary probe entirely and instead creates a synthetic
dossier, pushes a real `ProcessAssetOcrJob` onto the ingest queue, and waits for
the worker to write the text back. It therefore proves the deployment an operator
depends on: the queue connection, the running worker, its Tesseract binary and its
language data. It is the mode to run against the **app** container, which may have
no binary of its own.

It runs against the live onboarded database on purpose -- a separate test database
would prove the schema and not the deployment. Consequently:

- It **never** resets, migrates or re-onboards anything. It only inserts its own
  synthetic rows.
- Everything it creates carries an `OCR-SMOKE-` accession number and is removed
  again in a `finally` block, including the stored image, so a failure or a
  timeout still leaves no dossier behind.
- Do **not** set `APP_ENV=testing` for it. It must run in the application's own
  environment, against the real configuration; forcing a testing environment would
  point it at other credentials and prove nothing about the deployment.

### What the test suite can and cannot prove

The suite covers the smoke command's own success path: a stub executable stands in
for Tesseract, answers `--version` and `--list-langs`, and returns known text. That
proves the parts this repository owns -- argument construction, the language check,
output parsing, the queued job writing machine text, and the diagnostics report --
so a failure in the real pipeline points at the image rather than at this code.

What it cannot prove is recognition itself. Whether the packaged binary reads an
actual scan, and whether the Dutch language data is present, is a property of the
image and is only established by running the command above against a real container.
### Which account runs it

The queued smoke test must run as the **runtime account**, not as root:

```
docker compose exec --user www-data app php artisan operations:ocr-smoke --queued --wait=90
docker compose exec --user www-data worker php artisan operations:ocr-smoke --language=nld
```

Stored files are private: Laravel creates directories `0700` and files `0600`, owned
by whoever wrote them. `deploy/entrypoint.sh` runs the worker and artisan under
`gosu www-data`, but `docker compose exec` bypasses the entrypoint and uses the image
default, **root**. A fixture written by root lands in a directory the worker cannot
enter, so the job reports the file as missing although it is plainly there — a
failure that reads like a broken engine and is not one.

The command refuses to run as the wrong account and names the right one. It does not
take ownership of the fixture to make itself work: that would let the acceptance pass
under an account no real request ever uses, proving nothing about the runtime that
actually serves files. Matching the runtime is the property being tested.
### Environment the worker needs

OCR is off unless it is switched on, and the setting has to reach the **worker**
container, not only the web one. Both read the same names:

| Variable | Default | Meaning |
|---|---|---|
| `OCR_ENABLED` | `false` | Master switch. While false, OCR records are written with status `disabled` and no binary is ever invoked. |
| `OCR_BINARY` | `tesseract` | Path or name of the executable. |
| `OCR_LANGUAGES` | `nld+eng` | Languages passed to `-l`. The image must carry the matching language data. |

A worker started without `OCR_ENABLED=true` will accept the job and record it as
disabled, which the queued smoke test reports as a failure rather than a pass.
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
