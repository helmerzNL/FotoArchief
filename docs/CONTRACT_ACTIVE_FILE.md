# Cross-module contract: which asset file is "current" for public serving

Status: **proposed by Portal, not yet implemented by any other module.**
Nothing today creates more than one eligible file per asset (see below), so
this is a forward-compatible hardening contract for a future retained-file,
replace-photo, or reprocess feature (likely Catalogue's or Operations'), not
a description of an existing feature. Portal has already made its own code
self-activate the moment the column below exists, using the same
`Schema::hasColumn(...)` technique as
[`docs/CONTRACT_SOFT_DELETE.md`](CONTRACT_SOFT_DELETE.md).

## The problem this closes

Every public route (search/discovery predicate, the permalink viewer, its
`/media/{size}` stream, and the IIIF Presentation 3 manifest) must agree on
exactly one thing: which `asset_files` row is *the* current, publishable
original for an asset. Before this fix, "eligible" only meant
`ingest_status = 'ready_private' AND scanner_status = 'clean'`, checked with
`whereHas()`/`firstWhere()` — i.e. *existence*, not *uniqueness*. If a future
feature ever left two such rows on one asset at once (e.g. a replaced or
reprocessed original where the previous file is retained rather than
deleted), the public predicate could say "this asset is public" while the
viewer, media stream and IIIF manifest each independently picked an
unordered `first()` row — possibly three different files, one of which could
be the superseded/incorrect one, entirely by database ordering luck.

## The fix (implemented in this worktree)

1. [`Asset::currentPublicFile()`](../app/Modules/Catalogue/Models/Asset.php) is
   now the single resolver every public controller
   ([`PublicPhotoController`](../app/Http/Controllers/Publication/PublicPhotoController.php),
   [`IiifManifestController`](../app/Http/Controllers/Publication/IiifManifestController.php))
   calls. It never issues its own query — it filters the caller's
   already-eager-loaded `$asset->files` collection — so it can never diverge
   from what the SQL-level predicate already validated. It returns `null`
   (fail closed) when zero or more than one file is eligible.
2. [`Publication::scopePubliclyVisible()`](../app/Modules/Publication/Models/Publication.php),
   the one predicate every public route shares, now requires its
   `whereHas('asset.files', ..., '=', 1)` file-eligibility check to match
   **exactly one** row, not merely "at least one" (Laravel's exact-count
   `whereHas` form). This mirrors `Asset::currentPublicFile()`'s condition
   exactly, so the predicate and the controllers can never disagree about
   whether an asset is currently, unambiguously publishable.
3. Both of the above already check `Schema::hasColumn('asset_files',
   'is_primary')` at query/resolve time and, if present, additionally
   require `is_primary = true`. Until that column exists this is a no-op
   against today's schema — it cannot break anything today, exactly like the
   `deleted_at` guard in `CONTRACT_SOFT_DELETE.md`.
4. Today's ingest pipeline (`AdminAssetController::store()`,
   `ImageProcessor::process()`) only ever produces one `AssetFile` per
   `Asset` — every upload creates a brand-new asset, `ImageProcessor` is
   idempotent per `storage_key`, and duplicate content is rejected
   archive-wide by `sha256`. So an ordinary single-file ingest resolves
   unambiguously today and remains publishable completely unchanged; this
   contract only starts constraining behavior the day a second eligible file
   can exist on one asset.

## What the future `is_primary`/`is_current` column must guarantee

Whichever module implements a replace/reprocess/retained-file feature:

1. **Exactly one `asset_files` row may have `is_primary = true` per asset at
   any moment.** Enforce this at write time (a single transaction that
   flips the old primary off and the new one on), not only by convention —
   Portal's predicate fails closed (denies the asset publicly) for the
   instant that invariant is violated, rather than guessing, so a bug here
   silently un-publishes the asset instead of ever leaking the wrong file.
2. **Switching which file is primary must go through the same
   edit-invalidation gate as any other metadata change.** Either bump
   `assets.lock_version` (Portal's predicate already requires
   `assets.lock_version = publications.published_lock_version`, so this
   alone forces re-review before the new primary can go public again — the
   existing mechanism, no new gate needed), or use another explicit privacy
   gate with equivalent effect. A silent primary-flip that does **not**
   invalidate the current publication would let a newly-primary,
   not-yet-reviewed file become public without review, defeating the
   purpose.
3. **The original single-file ingest path must keep resolving unambiguously
   with zero further Portal-side changes** once the column lands — Portal's
   `is_primary` guard only narrows an already-eligible set; it never expands
   or replaces the `ready_private`/`clean` eligibility check.
4. Coordinate the exact migration (column name, nullability/default,
   whether it lives on `asset_files` or a join table) with Portal before
   shipping it, so this document and the code it describes can be updated in
   the same change — the `Schema::hasColumn(...)` guards mean Portal does not
   need a code change to *start* enforcing the invariant, but a name or
   semantics different from `asset_files.is_primary` (boolean) would need one.

## Verification

- [`tests/Feature/PublicationActiveFileConsistencyTest.php`](../tests/Feature/PublicationActiveFileConsistencyTest.php) —
  sqlite: baseline single-file case unchanged; two-eligible-files fails
  closed across predicate/viewer/media/IIIF; simulates the future
  `is_primary` column landing (zero/one/multiple primaries) entirely via
  `Schema::table()` at runtime, without needing Operations' migration to
  exist yet.
- [`tests/Feature/PublicationActiveFilePostgresTest.php`](../tests/Feature/PublicationActiveFilePostgresTest.php) —
  the same exact-count `whereHas()` and `is_primary` guard, proven against a
  real PostgreSQL connection (skips without an explicitly supplied, empty
  `..._portal_test` database — no PostgreSQL engine was available to execute
  this locally; Parent/CI must run it with `FOTOARCHIEF_TEST_PG_PORTAL_DATABASE`
  set, mirroring `PostgresInstallationTest`/`PhotoUpgradeTest`).

## Non-goals for this document

- No `is_primary`/`is_current` column is added to `asset_files` here — that
  remains a future, coordinated change by whichever module implements
  replace/reprocess. This document only defines the contract it must satisfy
  and proves Portal's side already honors it once it exists.
- No replace-photo/reprocess UI or workflow is implemented here.
