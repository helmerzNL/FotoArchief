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
