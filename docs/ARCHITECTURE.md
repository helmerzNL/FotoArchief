# Historical Image Bank architecture

**Status:** approved MVP baseline
**Decision date:** 2026-09-15

## Executive decision

The MVP uses a **Laravel modular monolith** with PostgreSQL, private
S3-compatible object storage, Redis/Valkey-backed workers in production, and
Docker Compose on a managed VPS. Laravel renders both the first public portal
and administrative environment. A versioned API seam is designed from the
start; a separately deployed Next.js public portal is deferred until concrete
capacity, interaction or team-ownership evidence justifies it.

This choice prioritises the brief's local/regional hosting, volunteer
manageability, secure archival ingestion, backup/restore and 50,000-asset
baseline. It avoids Vercel, serverless, Kubernetes and cloud-provider
dependencies.

## Component boundaries

```text
Browser
  -> Nginx/Caddy: TLS, security headers, rate limits and cache policy
  -> Laravel modular monolith
       |- public discovery and photo detail pages
       |- staff/admin workflows
       |- versioned API and DTOs
       |- domain modules: assets, metadata, rights, catalogues, people,
       |  locations, collections, search, audit and contributions
       `- job dispatch/scheduler

Laravel worker processes
  -> validate, scan, checksum, extract metadata, create derivatives,
     OCR/reindex/import/export, retry/dead-letter recovery

PostgreSQL (authoritative metadata and workflow state)
S3-compatible object storage (immutable originals and rebuildable derivatives)
Redis/Valkey (production queue/cache)
```

The application never stores photo binaries in PostgreSQL. It never treats the
search index, cache, thumbnails, previews or embeddings as a source of truth.

## Upload and publication workflow

The diagram below is the target architecture, not the shipped publication
contract. Version 0.3.0 ends at **ready_private**, never publishable: it has
ownership-aware private media, metadata/rights revisions and optional ClamAV.
OCR/indexing/publication and the public portal remain future work.
Current ingest uses one idempotent queued job and a dedicated PostgreSQL queue
on the same transaction as upload acceptance. It intentionally does not switch
to Redis with `QUEUE_CONNECTION`; that future transition needs an outbox.
See [the implemented workflow and operator contract](PHOTO_WORKFLOW.md).

```text
upload
  -> quarantine
  -> signature/size/dimension validation
  -> malware scan
  -> SHA-256 and metadata extraction
  -> immutable original storage
  -> derivative generation
  -> search indexing
  -> publishable
```

Each step is an observable, idempotent, retryable background job. Raw uploads
cannot be public. Workers apply strict resource and decoder-format limits.
Originals use immutable storage keys; thumbnails and previews are versioned,
cacheable and disposable derivatives.

## Data, privacy and access

- PostgreSQL has relational entities for assets/files/versions, collections,
  people, locations, tags, rights/licenses, contributors/sources, jobs,
  revisions, public contributions and audit events.
- Date, person and location uncertainty are first-class values rather than text
  conventions. Store exact, circa, range, before/after, decade and confidence
  semantics explicitly.
- Server-side RBAC supports administrator, archivist, editor, volunteer and
  viewer permissions. Rights, embargo and privacy checks guard every endpoint
  and signed download issuance.
- Metadata revisions, publication changes, rights changes, bulk actions,
  export/import and purge actions are auditable.

## Search and portal

Begin with PostgreSQL indexes, full-text/trigram capabilities, constrained
facets, debounced queries and keyset pagination. Do not use deep OFFSET
pagination, unbounded DOM lists or client-side collection filtering. Add a
dedicated search engine only after measurements establish that the relational
baseline no longer satisfies performance/relevance needs.

The public portal renders cacheable thumbnails/previews, permanent canonical
URLs, structured metadata and segmented sitemaps. WCAG 2.2 AA is a release
requirement. IIIF and AI suggestions are designed as later adapters; they do
not block the MVP and do not bypass human review.

## Operations and recovery

Deliver the same application as an uploadable PHP release archive (including
production dependencies and built frontend assets) and a PHP 8.5 image with a
production HTTP server. The image-only Compose and environment templates target
Komodo and Dockhand without requiring a source checkout or local build.
See [DEPLOYMENT_STACKS.md](DEPLOYMENT_STACKS.md) for the template and delivery gates.
First-start onboarding configures database, private storage and the initial
administrator; setup must work before the database exists, lock after completion,
and preserve private configuration across web/worker/scheduler redeployments.
The wizard, connection probes, initial local administrator login/logout and
setup lock are implemented. The PostgreSQL database itself must already exist
and be empty; the wizard creates its schema, not the database server.
Uploadable ZIP packaging and image deployment acceptance remain delivery gates.
See [ONBOARDING.md](ONBOARDING.md) for the implemented scope and recovery design.

The constrained first-start topology also supports private local storage, file
cache/sessions and database queues without Redis. Saved installation settings
override environment database/storage settings and preserve the generated key.
Atomic state writes, installation locking and a transactional database receipt
support resuming a failed installation without replacing the first administrator.

For 50,000+ assets, deploy at least one managed VPS/server, PostgreSQL,
S3-compatible object storage, worker/scheduler, TLS reverse proxy and a second
backup location. Hetzner Object Storage is the preferred active storage, but
the S3 abstraction keeps MinIO/NAS-compatible or other S3 providers viable.

Back up database, objects, deployment configuration and required secrets
separately. Test restore routinely. Support a cron-based scheduler fallback
for basic hosting while making dedicated workers the production recommendation.

## Scale and quality gates

- **50,000 assets:** normal production baseline. Measure public filtered search
  p95 under 700 ms, detail response p95 under 400 ms where realistic, and
  admin listing p95 under 800 ms.
- **250,000 assets:** add worker capacity, dedicated database/storage resources
  and measured indexing improvements.
- **1,000,000+ assets:** retain stateless web scaling, independently scalable
  workers, partition/read-replica analysis and a dedicated search service only
  where evidence warrants it.

No release is production-ready without successful automated security,
accessibility, API, integration, E2E, ingest/retry, bulk/import/export,
backup/restore and performance validation on a representative 50,000-asset
dataset.

## Deferred architecture decisions

1. Exact Laravel UI approach (Blade/Livewire/Inertia) after admin workflow
   prototyping and accessibility review.
2. Final authentication provider/passkey/recovery model and organisation scope;
   onboarding currently creates a hashed local-password administrator.
3. Malware-scanning implementation and allowed archival-format policy.
4. Secondary backup provider, retention, RPO and RTO.
5. IIIF server/viewer implementation after MVP demand and source-format review.
