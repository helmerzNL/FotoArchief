# Cross-module contract: recoverable deletion (`assets.deleted_at`)

Status: **integrated and verified.** Operations shipped this exact contract:
`deleted_at`, `deleted_by_user_id` and `deletion_reason` on `assets`, using
the standard `SoftDeletes` trait on `Asset` (migration
`2026_09_17_270000_add_trash_and_purge_to_assets.php`, model change from
operations commit `a17969f`, integrated on parent as `dbb27a2`). Portal
verified that column/migration/model change directly against
[`tests/Feature/SoftDeleteGuardTest.php`](../tests/Feature/SoftDeleteGuardTest.php)
and confirmed every public predicate denies a trashed asset with **no
further Portal-side code change**, exactly as designed below. Only the
`Asset` model change and its migration were brought into this worktree for
that verification (Operations' own `TrashController`/`TrashService`/routes/
views/tests are not duplicated here and are not re-submitted — parent
already has them via `dbb27a2`/`d8d959c`).

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
2. Portal added an explicit, defense-in-depth guard in
   [`Publication::scopePubliclyVisible()`](../app/Modules/Publication/Models/Publication.php)
   that checks `Schema::hasColumn('assets', 'deleted_at')` at query time and,
   if present, adds `whereNull('assets.deleted_at')`. Now that the column is
   real, this guard is active in every environment, and is redundant with
   (not a replacement for) the `SoftDeletes` global scope — both independently
   deny the same trashed asset, which is intentional defense-in-depth.
   `tests/Feature/SoftDeleteGuardTest.php` verifies, against the real column
   and trait: discovery/permalink/media/IIIF-manifest denial for a trashed
   asset, and that `Asset::findOrFail()`/`Asset::find()` also fail closed
   (no accidental republish path through a stale reference), while
   `Asset::restore()` correctly reverses it (leaving re-review to staff, not
   auto-republishing).
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

- No PostgreSQL migration count is added or altered here — the coordinator
  confirmed Parent owns adjusting `PostgresInstallationTest`/`PhotoUpgradeTest`
  totals for the now-integrated Operations migration.
- No trash/restore UI, `TrashController`/`TrashService`, routes, views, or
  backup script changes are made or duplicated in this worktree — those stay
  owned by Operations (already on parent as `dbb27a2`) and Parent.
- The migration file and `Asset` model change were brought into this
  worktree only to verify the contract end-to-end; that commit is not a
  resubmission of Operations' work, since parent already has it.
