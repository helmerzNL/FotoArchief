# Hybrid architecture and hosting assessment

**Decision date:** 2026-09-15
**Status:** Laravel modular monolith recommended for MVP

## Decision

Start with a provider-independent **Laravel modular monolith**, not a hybrid
deployment. Keep stable API and domain seams so a separate modern public
frontend can later consume the API without reading the database directly.

A hybrid Laravel admin/API plus independently deployed frontend should be
approved only when public traffic must scale independently, a specialised
rendering/caching model is required, a separate frontend team owns delivery, or
multiple API clients have justified versioned-contract costs.

## Weighted comparison

| Criterion | Weight | Laravel modular monolith | Separate frontend plus Laravel API |
|---|---:|---:|---:|
| MVP delivery simplicity | 20% | 5/5 | 2/5 |
| Volunteer operational burden | 20% | 5/5 | 2/5 |
| Single auth and authorization model | 15% | 5/5 | 3/5 |
| Public UI flexibility | 10% | 3/5 | 5/5 |
| Independent public scaling | 10% | 3/5 | 5/5 |
| Backup and incident recovery | 10% | 5/5 | 3/5 |
| Future multi-client integration | 10% | 4/5 | 5/5 |
| Early infrastructure/vendor complexity | 5% | 5/5 | 2/5 |

At the stated MVP scale, a separate frontend creates an extra deployment,
security-update stream, CORS/session policy, monitoring surface, compatibility
contract and cache-invalidation problem before it resolves a measured need.

## Recommended minimum production topology

```text
Internet
  -> Nginx or Caddy reverse proxy with TLS
  -> Laravel application process
       -> PostgreSQL
       -> private S3-compatible object storage
       -> Redis/Valkey queue
  -> queue worker process from the same release
  -> scheduler process or one-minute cron/systemd timer
  -> independent backup/restore destination
```

- A managed VPS is the recommended minimum for 50,000+ assets.
- Hetzner Object Storage is the preferred active storage implementation through
  an S3 abstraction. MinIO/local/NAS-capable alternatives remain supported.
- Put originals, derivatives, temporary imports/exports and database backups in
  distinct private prefixes or buckets with lifecycle policies.
- Back up PostgreSQL and immutable originals to a separate location. Schedule
  restore drills; an untested backup is not a recovery control.
- Shared hosting is suitable only for a restricted catalogue/MVP where cron,
  upload limits, storage, image worker capacity and restore procedures are
  explicitly proven.

## Migration trigger to a hybrid portal

Approve a separate public frontend only if at least one measurable condition
holds:

1. Public traffic/rendering needs isolated capacity or availability.
2. The required portal interaction or caching model cannot reasonably be met by
   the monolith.
3. A separate team needs independently releasable ownership.
4. Multiple clients (mobile, kiosk, partners) need an already exercised public
   API.

Before splitting, publish OpenAPI/API contracts, contract tests, versioning,
authorisation policy tests, correlation IDs and cache invalidation behaviour.

## Sources

- [Laravel filesystem 13.x](https://laravel.com/docs/13.x/filesystem), accessed
  2026-09-15.
- [Laravel queues 13.x](https://laravel.com/docs/13.x/queues), accessed
  2026-09-15.
- [Docker Compose documentation](https://docs.docker.com/compose/), accessed
  2026-09-15.
- [Hetzner Object Storage documentation](https://docs.hetzner.com/storage/object-storage/),
  accessed 2026-09-15.
