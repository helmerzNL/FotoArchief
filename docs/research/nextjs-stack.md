# Next.js stack assessment

**Decision date:** 2026-09-15
**Status:** viable alternative, not the MVP recommendation

## Decision

Next.js with React and TypeScript scores **7.5/10 as the web layer** but only
**5/10 as a complete asset-processing platform**. It is a strong option for a
self-hosted public and administrative experience when deployed as standard
Node/Docker services, not as a Vercel-dependent solution.

It is not recommended as a single all-in-one MVP runtime. Large upload
processing, scanning, metadata extraction and image transformations require a
durable queue and independently scalable worker process.

## Assessment

| Criterion | Score | Assessment |
|---|---:|---|
| Self-hosted VPS/container deployment | 9/10 | Standard Node server and Docker deployment are supported without Vercel. |
| Public and administrative UX | 9/10 | React works well for interactive grids, filters and editorial workflows. |
| Background processing | 5/10 | Route handlers are the wrong durability, timeout and resource boundary for 50,000+ archival images. |
| PostgreSQL metadata/search | 9/10 | A strong relational source of truth with standard full-text/indexing growth paths. |
| S3-compatible storage portability | 8/10 | A protocol-based adapter avoids provider coupling. |
| Image processing | 8/10 | Sharp/libvips is performant but belongs in constrained workers. |
| Security posture | 7/10 | Secure when upload quarantine and server-side authorization are enforced. |
| Decade maintainability | 7/10 | Broad adoption, but faster framework/runtime cadence adds upgrade discipline. |
| Infrastructure lock-in | 7/10 | Low when deployed as Docker/Node/Postgres/S3; moderate UI/server framework coupling remains. |

## Required architecture if selected

1. A stateless Next.js web container serves public pages, authenticated admin
   pages and upload initiation behind Nginx or Caddy.
2. PostgreSQL owns asset metadata, rights, workflow transitions, audit records
   and idempotency keys.
3. Private S3-compatible storage owns originals and derivative objects. Browser
   access uses short-lived presigned upload/download URLs or an authorised
   application endpoint.
4. A separate worker service consumes durable jobs for scanning, checksums,
   EXIF/XMP/IPTC extraction, derivative generation, OCR and indexing.

## Scaling guidance

At 50,000 assets, run application, worker, PostgreSQL, queue and storage as
separate processes with health checks and resource limits. At 100,000 to
1,000,000 assets, horizontally scale stateless web containers and workers
independently. Do not list storage to populate user interfaces; page from
PostgreSQL by cursor and resolve known storage keys only.

## Why it is not chosen for the MVP

The decisive problems are archival ingest, rights, metadata integrity,
backup/restore and volunteer-manageable operations. Next.js adds a Node runtime
and worker architecture but does not eliminate the durable backend concerns.
Laravel has a more conservative integrated operational path for the stated
target audience. A future Next.js portal remains a valid API consumer.

## Sources

- [Next.js self-hosting guide](https://nextjs.org/docs/app/guides/self-hosting),
  accessed 2026-09-15.
- [Next.js Docker deployment guide](https://nextjs.org/docs/app/getting-started/deploying),
  accessed 2026-09-15.
- [Sharp documentation](https://sharp.pixelplumbing.com/), accessed 2026-09-15.
- [OWASP File Upload Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html),
  accessed 2026-09-15.
