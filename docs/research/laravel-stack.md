# Laravel stack assessment

**Decision date:** 2026-09-15
**Status:** recommended candidate

## Decision

Laravel 13 with PostgreSQL, private S3-compatible object storage, Redis-backed
workers in production, and a cron-compatible scheduler is the strongest MVP
candidate for the historical image bank. It scores **8.4/10** against the
project's local-hosting, long-term maintenance, ingestion, and 50,000-asset
requirements.

Use one modular Laravel application for the first public portal and staff
environment. Keep a versioned API boundary available, but do not require a
separate JavaScript portal during the MVP.

## Assessment

| Criterion | Weight | Score | Rationale |
|---|---:|---:|---|
| Self-hosting and provider independence | 20% | 9/10 | Conventional PHP/Linux deployment, PostgreSQL, queues and S3 protocol do not require a managed cloud runtime. |
| MVP delivery at 50,000 assets | 20% | 9/10 | Auth, policies, migrations, queues, storage abstractions and admin-oriented patterns reduce integration work. |
| Growth to 1,000,000 assets | 15% | 8/10 | Achievable when binaries remain in object storage, metadata is indexed in PostgreSQL, and derivatives/search stay asynchronous and rebuildable. |
| Reliable background processing | 15% | 8/10 | Queue workers handle ingest; a minute-based scheduler command supports an operational cron fallback. |
| Security and rights management | 15% | 8/10 | Policies, validation, CSRF protection, rate limiting and signed temporary URLs are available, but deployment hardening remains essential. |
| Dutch/EU regional operation | 5% | 8/10 | Runs on standard VPS/dedicated infrastructure; residency and processor commitments remain procurement concerns. |
| Ten-year maintainability | 5% | 7/10 | Mature ecosystem, but annual upgrades need an explicit maintenance budget. |
| Lock-in | 5% | 8/10 | Application code uses Laravel, but durable data remains in PostgreSQL and S3-compatible objects. |

## Required architecture boundaries

- PostgreSQL owns structured metadata, relationships, rights, audit data and
  workflow state. Image binaries never enter the database.
- Store immutable originals and rebuildable derivatives under stable,
  application-owned S3 keys. The database records keys, checksums and media
  attributes, not vendor URLs.
- Every image-heavy step runs as an idempotent queue job: validation, checksum,
  malware scan, technical metadata extraction, derivatives, OCR and indexing.
- Production uses Redis/Valkey-backed workers; development or smaller
  installations may use a database queue. The scheduler must also support
  `schedule:run` from cron or a system timer.
- Use resource-limited image workers and private storage. Image decoders,
  SVG/PDF and embedded metadata are untrusted input.

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Large ingestion work delays web requests | Never process in HTTP handlers; use retryable queue jobs with a dead-letter/recovery path. |
| Image decoder attacks or resource exhaustion | Limit size, dimensions, formats, execution time, memory, disk and permitted ImageMagick coders; scan before publication. |
| Portal UX eventually needs more specialised rendering | Publish tested, versioned API contracts and extract a separate portal only after measured need. |
| Search degrades as catalogues grow | Start with indexed PostgreSQL full-text/trigram queries and keyset pagination; introduce a dedicated index only based on measurements. |

## Sources

- [Laravel filesystem 13.x](https://laravel.com/docs/13.x/filesystem), accessed
  2026-09-15.
- [Laravel queues 13.x](https://laravel.com/docs/13.x/queues), accessed
  2026-09-15.
- [Laravel task scheduling 13.x](https://laravel.com/docs/13.x/scheduling),
  accessed 2026-09-15.
- [Laravel authorization 13.x](https://laravel.com/docs/13.x/authorization),
  accessed 2026-09-15.
- [PostgreSQL GIN indexes](https://www.postgresql.org/docs/current/gin.html),
  accessed 2026-09-15.
- [OWASP File Upload Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html),
  accessed 2026-09-15.
