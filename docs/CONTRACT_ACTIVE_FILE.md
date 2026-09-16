# Cross-module contract: which asset file is "current" for public serving

Status: **integrated and verified.** Operations shipped this exact contract:
`asset_files.is_primary` and `asset_versions.is_current`
(migration `2026_09_17_230000_add_asset_file_versioning_support.php`), and
the repair + enforcement migration
`2026_09_17_240000_enforce_single_primary_asset_file.php`, which backfills
`is_primary`/`is_current` on pre-existing rows and adds the partial unique
index `asset_files_single_primary_per_asset` (`WHERE is_primary = true`) so
the database itself refuses a second primary file per asset. Both migrations
are present unmodified in this worktree's `database/migrations/`. Portal
verified them directly against
[`tests/Feature/PublicationActiveFileConsistencyTest.php`](../tests/Feature/PublicationActiveFileConsistencyTest.php)
(sqlite) and
[`tests/Feature/PublicationActiveFilePostgresTest.php`](../tests/Feature/PublicationActiveFilePostgresTest.php)
(real PostgreSQL) and confirmed every public route agrees on exactly one
current file with **no further Portal-side schema change** — only the model
casts below and the pre-existing `Schema::hasColumn(...)` guards, which now
resolve `true` unconditionally against this schema.

## The problem this closed

Every public route (search/discovery predicate, the permalink viewer, its
`/media/{size}` stream, and the IIIF Presentation 3 manifest) must agree on
exactly one thing: which `asset_files` row is *the* current, publishable
original for an asset. Before this fix, "eligible" only meant
`ingest_status = 'ready_private' AND scanner_status = 'clean'`, checked with
`whereHas()`/`firstWhere()` — i.e. *existence*, not *uniqueness*. Once
Operations' file-versioning/replace feature could leave more than one file on
an asset, the public predicate could say "this asset is public" while the
viewer, media stream and IIIF manifest each independently picked an unordered
`first()` row — possibly a different, superseded file, entirely by database
ordering luck.

## The fix (implemented in this worktree, against the real schema)

1. [`Asset::currentPublicFile()`](../app/Modules/Catalogue/Models/Asset.php) is
   the single resolver every public controller
   ([`PublicPhotoController`](../app/Http/Controllers/Publication/PublicPhotoController.php),
   [`IiifManifestController`](../app/Http/Controllers/Publication/IiifManifestController.php))
   calls. It never issues its own query — it filters the caller's
   already-eager-loaded `$asset->files` collection for a row that is both
   eligible (`ready_private`/`clean`) and, since the column now exists,
   `is_primary = true`. It returns `null` (fail closed) unless exactly one
   such row exists.
2. [`Publication::scopePubliclyVisible()`](../app/Modules/Publication/Models/Publication.php),
   the one predicate every public route shares, requires its file-eligibility
   check to match **exactly one** row (`ready_private`, `clean`, and
   `is_primary = true`), not merely "at least one". This mirrors
   `Asset::currentPublicFile()`'s condition exactly, so the predicate and the
   controllers can never disagree about which file is currently,
   unambiguously publishable. As of this contract's integration, the check is
   a plain `whereHas('asset.files', ...)` (default `EXISTS` semantics), not
   `whereHas(..., '=', 1)` — see "Performance: EXISTS, not COUNT" below for
   why that is still exactly as fail-closed.
3. Because Operations' partial unique index already guarantees *at most one*
   `is_primary = true` row per asset at the database level, the predicate and
   resolver above can only ever disagree with "the obvious answer" by finding
   **zero** eligible rows (e.g. the primary file is mid-processing, or has
   been demoted without a replacement having been created yet) — never by
   having to arbitrate between two rows claiming to be primary. That
   database-level guarantee is what makes the exact-count check a provable
   invariant rather than a convention every writer has to remember.
4. **Switching which file is primary already invalidates the current
   publication.** Operations' `ImageProcessor`/`FileVersionService` bump
   `assets.lock_version` in the same transaction that demotes the old primary
   and activates the new one (see `ImageProcessor::process()`'s
   `Asset::query()->where('id', ...)->increment('lock_version')` and
   `FileVersionService::invalidateReview()`). Portal's predicate already
   requires `assets.lock_version = publications.published_lock_version`, so
   this alone forces re-review before a newly-primary file can go public
   again — no separate Portal-side gate was needed; the existing
   edit-invalidation mechanism (`docs/PHOTO_WORKFLOW.md`) already covers a
   primary-file switch as just another kind of edit.
5. Today's ordinary single-file ingest (`AdminAssetController::store()`,
   `ImageProcessor::process()`'s first-version path) creates exactly one file
   with `is_primary` defaulting to `true` (the migration's own default), so an
   ordinary photo resolves unambiguously and remains publishable completely
   unchanged.

## Performance: EXISTS, not COUNT

The exact-count form above (`whereHas('asset.files', ..., '=', 1)`) is
logically correct but was never safe to run at scale: Laravel's
`Illuminate\Database\Eloquent\Concerns\QueriesRelationships::has()` compiles
any non-default operator/count (including `'='`, `1`) as a correlated
`(SELECT COUNT(*) FROM asset_files WHERE ...) = 1` subquery
(`getRelationExistenceCountQuery()`), not the semi-join-friendly
`WHERE EXISTS (...)` form used only for the default `'>='`/`1`
(`getRelationExistenceQuery()`). On the real 50k-row/45k-eligible benchmark
this compiled to a nested-loop plan re-scanning `asset_files` once per
outer row (`EXPLAIN ANALYZE` showed 46,500 loops and ~558,000 buffer hits)
and regressed public search p95 from ~438ms to ~1,913ms against a 700ms
budget.

Because Operations' partial unique index
`asset_files_single_primary_per_asset` (`WHERE is_primary = true`) already
guarantees **at most one** `is_primary = true` row per asset at the database
level, "at least one eligible primary file exists" (a plain `whereHas()`,
`EXISTS`) is provably identical to "exactly one eligible primary file
exists" (the `COUNT(*) = 1` form) — the database has already ruled out the
"two or more" case everywhere else, so `EXISTS` only ever has to distinguish
"zero" from "one", exactly as `COUNT(*) = 1` did, but as a semi-join the
planner can index and short-circuit. `scopePubliclyVisible()` therefore uses
the plain `whereHas()` form whenever `asset_files.is_primary` exists (the
real, integrated case), which restored a `Hash Semi Join` plan (~9,956
buffer hits total, no per-row loop) on the same benchmark. A defensive
`whereHas(..., '=', 1)` fallback is kept for the (currently dead) case where
`is_primary` is absent, so the predicate never silently regresses to "at
least one" if that assumption is ever violated. See
`app/Modules/Publication/Models/Publication.php` for the exact branch.

## Verification

- [`tests/Feature/PublicationActiveFileConsistencyTest.php`](../tests/Feature/PublicationActiveFileConsistencyTest.php) —
  sqlite, against the real, integrated schema (no `Schema::table()`
  simulation): baseline single-file case unchanged; the database itself
  refuses a second primary file (`UniqueConstraintViolationException`); a
  superseded non-primary file coexisting with a primary one is correctly
  ignored everywhere; zero-primary (in-flight replace) fails closed across
  predicate/viewer/media/IIIF; a primary-file switch invalidates the
  published review until re-review, exactly mirroring Operations' real
  `ImageProcessor`/`FileVersionService` sequence.
- [`tests/Feature/PublicationActiveFilePostgresTest.php`](../tests/Feature/PublicationActiveFilePostgresTest.php) —
  the same behavior proven against a real PostgreSQL connection (skips
  without an explicitly supplied, empty `..._portal_test` database — no
  PostgreSQL engine was available to execute this locally; Parent/CI must run
  it with `FOTOARCHIEF_TEST_PG_PORTAL_DATABASE` set, mirroring
  `PostgresInstallationTest`/`PhotoUpgradeTest`).
- [`tests/Feature/OperationsPortalActiveFileIntegrationTest.php`](../tests/Feature/OperationsPortalActiveFileIntegrationTest.php) —
  drives the real Operations services (`FileVersionService::setActiveVersion()`,
  `TrashService::moveToTrash()`) against the portal's real predicate and
  resolver, proving the two modules' contracts hold together rather than
  merely each side's own simulation of the other. Three of its four cases
  require Operations' `ArchiveOperations` module, which does not exist on
  this branch in isolation, and are skipped (not faked) here via
  `class_exists()` until Parent merges this branch together with
  Operations'; the fourth (the two-primary-state guard) needs only the
  Catalogue schema and always runs. Confirmed to pass all four, fully
  unskipped, against a scratch tree with both branches merged
  (`scratch/ops-portal-integration@261ee44`) before being included here, so
  Parent does not have to author it a second time after integration.

## Non-goals for this document

- No replace-photo/reprocess UI or workflow is implemented here — that
  remains owned by Operations (`FileVersionService`/`FileVersionController`,
  not duplicated in this worktree).
- The two migrations and the `is_primary`/`is_current` model casts were
  brought into this worktree only to verify the contract end-to-end against
  the real schema; this is not a resubmission of Operations' work — Parent
  already has it via `a392d44`.
