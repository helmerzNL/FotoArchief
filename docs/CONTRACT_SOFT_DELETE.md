# Cross-module contract: recoverable deletion (`assets.deleted_at`)

Status: **agreed by Portal, awaiting Operations' implementation.** This file
is Portal's (`feature/expansion-portal`) half of the contract requested by the
coordinator. Operations, Exchange and Parent each own the pieces below that
are *not* implemented in this worktree; this document exists so those
implementations do not diverge from what Portal already relies on.

## Ownership split (unchanged from `docs/REPOSITORY.md`)

- **Operations** owns recoverable deletion itself: the `assets.deleted_at`
  column, the trash/restore UI, and any admin-facing "deleted" state.
- **Portal** (this worktree) owns the public authorization predicate and must
  deny a deleted asset in every public route the instant that column exists.
- **Exchange** owns bundle delivery and must not deliver a bundle for an
  asset that has been deleted or had access revoked after the bundle was
  created/signed.
- **Parent** owns packaging/CI/backup only, including rejecting a backup
  configuration that mixes local and remote `asset_files.storage_disk`
  values.

## What Portal requires from Operations' migration

1. Use Laravel's standard `SoftDeletes` trait on `Asset` (a nullable
   `deleted_at timestamptz` column), **not** a custom boolean/status flag.
   Reason: `SoftDeletes` registers a *global scope* on `Asset`. Every
   `whereHas('asset', ...)` / `whereHas('asset.rights', ...)` /
   `whereHas('asset.files', ...)` call already used throughout Portal builds
   its subquery against a fresh `Asset` query, so Eloquent's global scope is
   applied automatically inside those closures. Once the trait lands, nearly
   all of Portal's existing predicate code excludes deleted assets with
   **zero further Portal-side changes**.
2. Portal has already added an explicit, defense-in-depth guard in
   [`Publication::scopePubliclyVisible()`](../app/Modules/Publication/Models/Publication.php)
   that checks `Schema::hasColumn('assets', 'deleted_at')` at query time and,
   if present, adds `whereNull('assets.deleted_at')`. This is a genuine no-op
   against the current schema (the column does not exist yet), so it cannot
   break anything today, and it self-activates the moment the column is
   migrated in — no second coordinated deploy is required from Portal.
   Covered by
   [`tests/Feature/SoftDeleteGuardTest.php`](../tests/Feature/SoftDeleteGuardTest.php),
   which adds the column at runtime to prove both states (absent today,
   enforced once present) without depending on Operations' migration existing
   in this worktree.
3. Every public route (`/foto/{slug}`, its `/media/{derivative}` route,
   `/iiif/{slug}/manifest.json`, `/ontdek`, search/collections, sitemaps) is
   already required to resolve visibility only through
   `Publication::publiclyVisible()` (route-model binding, listings and the
   manifest all call it). Nothing else needs to independently learn about
   `deleted_at` — the single predicate is the enforcement point, as already
   established for rights/privacy/embargo/scanner status in steps 16-29.

## What Exchange must do (not implemented here)

Bundle creation/signing happens at one point in time; deletion or access
revocation can happen afterward, before the bundle is actually delivered or
downloaded. Exchange must **re-check eligibility live, immediately before
streaming/signing a delivery**, not only when the bundle was created — e.g.
by calling the same `Publication::query()->publiclyVisible()->where('asset_id', $id)->exists()`
predicate (or the underlying asset's `deleted_at`/access-revocation state)
at delivery time. Because this is one shared Laravel monolith, Exchange can
call Portal's existing scope directly instead of re-implementing it.

## What any queued/batch job must do

Any job that acts on an asset asynchronously must re-authorize at execution
time, not only at enqueue time, for the same reason as Exchange above.
Portal itself does not currently dispatch any queued jobs for
publication/suggestion workflows — all of steps 16-19/29 are synchronous DB
transactions — so there is no queue-naming conflict to resolve on Portal's
side. If Portal ever needs an async job in the future, it must use a queue
name distinct from Operations' existing dedicated `ingest` queue (see
`docs/OPERATIONS.md`), e.g. a separate `public`/`default` queue, so the two
do not contend on the same worker.

## What Parent's backup scripts must do (acknowledged, not implemented here)

Parent's backup scripts must support a consistent local-volume-only setup and
must reject a mixed configuration where some `asset_files.storage_disk`
values are local and others are a remote disk (e.g. `s3`) at the same time.
Portal does not implement or alter backup scripts; this is acknowledgment for
the contract only.

## Non-goals for this document

- No PostgreSQL migration counts or migration file itself is added here —
  the coordinator confirmed Operations owns that.
- No schema is broken today: `Schema::hasColumn` returns `false` until the
  column is added, so the guard is inert until Operations ships it.
- No trash/restore UI, bundle-delivery code, or backup script changes are
  made in this worktree.
