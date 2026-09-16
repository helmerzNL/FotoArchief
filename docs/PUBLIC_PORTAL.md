# Public portal operator handbook

Concise, implementation-truthful reference for staff operating the public
publication workflow, moderation queue and public-facing surfaces added by
`Modules/Publication`. Each section names the exact code it describes so this
document cannot silently drift from behaviour.

## 1. Publication review: action sequence and exact gates

Staff review happens per asset at `/admin/publications` (list) and
`/admin/publications/{asset}` (detail). All actions run through
[`StaffPublicationController`](../app/Http/Controllers/Publication/StaffPublicationController.php).

1. **Submit for review** (`POST /admin/publications/{asset}/submit`,
   `assets.update`). Requires, at submit time:
   - the asset has at least one `asset_rights` row with
     `verification_status = 'verified'`;
   - the asset has at least one `asset_files` row with
     `ingest_status = 'ready_private'` and `scanner_status = 'clean'`
     (an infected or not-yet-scanned file blocks submission);
   - the operator picks a `download_policy` (`none` or `preview_only`, see
     §3) and confirms `privacy_cleared` (checkbox must be accepted);
   - an optional `credit_line` and `embargo_until` date.
   This creates or updates the `publications` row to `status = 'in_review'`
   and records an `publication.submitted` audit event. Submitting again
   after a rejection is allowed; submitting an already-published asset is
   rejected with an HTTP 409.

2. **Publish** (`POST /admin/publications/{asset}/publish`, `assets.publish`
   — a higher permission than submission). Re-verifies, under a row lock, at
   *publish* time — not just at submit time:
   - the publication is currently `in_review` and `privacy_cleared`;
   - rights are still verified and a scan-clean ready-private file still
     exists (either could have changed between submit and publish).
   On success: `status = 'published'`, a permanent `permalink_slug` is
   assigned once (never regenerated on republish), and
   `published_lock_version` is set to the asset's current `lock_version`.
   This lock-version snapshot is what makes edit-invalidation (§2) work.

3. **Reject** (`POST /admin/publications/{asset}/reject`, `assets.publish`).
   Only valid while `in_review`; returns the publication to `draft` with a
   required `reject_reason`, auditable via `publication.rejected`.

4. **Revoke** (`POST /admin/publications/{asset}/revoke`, `assets.publish`).
   Only valid while `published`; requires a `revoked_reason`. See §2 for why
   this takes effect immediately.

Every one of these transitions writes an `AssetAuditEvent`
(`publication.submitted` / `.published` / `.rejected` / `.revoked`).

## 2. Immediate revocation and edit invalidation

Every public route — search, the permalink viewer, its media/derivative
stream, sitemaps, the IIIF manifest and anonymous suggestion submission —
resolves visibility through exactly one predicate:
[`Publication::scopePubliclyVisible()`](../app/Modules/Publication/Models/Publication.php).
There is no separate "public copy" of a photo and no cache to invalidate:

- **Revocation** sets `publications.status = 'revoked'` inside the same
  request that handles the revoke action. The very next request to any
  public route re-evaluates the predicate and 404s immediately — there is no
  propagation delay, background job or CDN purge to wait for. Media
  responses are additionally sent with `Cache-Control: no-store, private` so
  a shared proxy or browser cannot keep serving a stale copy after
  revocation.
- **Metadata edits invalidate publication automatically, without a separate
  takedown step.** The predicate requires
  `assets.lock_version = publications.published_lock_version`. Any staff
  edit to the asset increments `lock_version`
  (see [`docs/PHOTO_WORKFLOW.md`](PHOTO_WORKFLOW.md) for the optimistic-lock
  mechanism), so the moment an edit is saved, the equality fails and the
  photo disappears from every public predicate — before anyone re-reviews
  it. `Publication::needsReReview()` reports this state to staff (shown on
  the publications list) so it is visible that a previously-published photo
  is currently hidden and awaiting re-review, not lost.
- **Deletion (Operations' trash)**: `assets.deleted_at` (via the standard
  `SoftDeletes` trait, integrated per
  [`docs/CONTRACT_SOFT_DELETE.md`](CONTRACT_SOFT_DELETE.md)) is denied by the
  same predicate. A trashed asset is never public, exportable or
  downloadable, and restoring it does not auto-republish it — re-review is
  still required.
- **Malware scanning**: `asset_files.scanner_status` must be exactly
  `'clean'` and `ingest_status = 'ready_private'`. This is checked live, on
  every read of the same shared predicate — exactly like rights/lock-version/
  embargo/status/privacy — via `whereHas('asset.files', ...)` in
  `scopePubliclyVisible()`. If a file's scanner status is ever changed away
  from `'clean'` after publication (e.g. a later re-scan flags it), the
  photo disappears from every public route on the very next request, with no
  separate revoke action required; `revoke` remains available for any other
  reason staff need to pull a photo immediately.
- **Which file is "current"**: the predicate, the viewer, its media stream
  and the IIIF manifest all resolve the same single canonical file for an
  asset (`Asset::currentPublicFile()`, mirrored by the predicate's exact-count
  `whereHas('asset.files', ..., '=', 1)`), requiring exactly one file that is
  both scan-clean/ready and flagged `is_primary` by Operations' file-versioning
  schema. The database itself refuses a second primary file per asset
  (partial unique index `asset_files_single_primary_per_asset`), and
  replacing a photo's primary scan already bumps `assets.lock_version`, so a
  primary-file switch forces the same re-review as any other edit before the
  replacement can go public. If a file is ever demoted without a replacement
  yet in place, every public route fails closed (404) instead of guessing —
  see [`docs/CONTRACT_ACTIVE_FILE.md`](CONTRACT_ACTIVE_FILE.md) for the full
  contract.

## 3. Download policy options

`publications.download_policy` is a database-checked enum with exactly two
values (see the `publications_download_policy_check` constraint):

| value | effect |
|---|---|
| `none` | No derivative can be downloaded (`?download=1` is rejected with 403 on every size). Only inline viewing of the bounded preview derivatives is allowed. |
| `preview_only` | Only the `preview2000` derivative may be downloaded via `?download=1`; `preview300`/`preview1200` remain view-only even with the flag. There is no "original file" download policy — the original is never exposed on any public route. |

Enforced in [`PublicPhotoController::media()`](../app/Http/Controllers/Publication/PublicPhotoController.php).

## 4. Anonymous suggestion moderation

Any visitor viewing a currently-public photo can submit a correction or
identification suggestion (`POST /foto/{slug}/suggesties`,
[`PublicSuggestionController`](../app/Http/Controllers/Publication/PublicSuggestionController.php)):
rate-limited to 5 requests per 60 minutes per client (route `throttle:5,60`),
bounded to 2000 characters, and protected by a hidden honeypot field that
silently rejects bot submissions. The route binding itself already 404s a
non-public photo before a suggestion can be filed against it, so a
suggestion can only ever reference a photo the visitor actually saw.

Staff moderate at `/admin/suggesties`
([`StaffSuggestionController`](../app/Http/Controllers/Publication/StaffSuggestionController.php)),
requiring `assets.view` to list/read and `assets.update` to decide. Access is
also scoped per asset, exactly like publication review: a staff member
without `assets.publish` may only see and moderate suggestions filed against
assets they themselves created, so they can never read another owner's
visitor-submitted name/e-mail or moderate an asset outside their own scope.
Staff with `assets.publish` see and moderate every asset's suggestions. A
suggestion on a since-trashed asset 404s for every role and is excluded from
the list, because it is resolved through the asset's normal (non-trashed)
query.

**Accepting a suggestion does not apply any metadata change.** `accept()`
only records `status = 'accepted'`, the moderator, a timestamp and an
optional note, plus an `AssetSuggestion` audit trail entry
(`suggestion.accepted` / `suggestion.rejected`). A moderator who agrees with
a suggestion must still make the actual metadata edit through the ordinary
staff asset-edit form, which keeps its own independent audit trail and its
own rights/privacy checks. There is intentionally no auto-apply pipeline
from visitor input straight into archive metadata.

## 5. SEO, sitemaps and IIIF: what is and is not implemented

- **Structured data**: the permalink viewer embeds a `schema.org/Photograph`
  JSON-LD block (title, description, canonical URL, credit line, rights
  holder, license URL, content/thumbnail URLs) built in
  [`PublicPhotoController::structuredData()`](../app/Http/Controllers/Publication/PublicPhotoController.php).
  All string values are Blade-escaped at render time — an attacker-controlled
  title cannot break out of the `<script type="application/ld+json">` tag.
- **Sitemaps**: `/sitemap.xml` lists numbered `/sitemap-fotos-{page}.xml`
  segments (5000 URLs per page), each built from the same
  `publiclyVisible()` predicate as every other route
  ([`SitemapController`](../app/Http/Controllers/Publication/SitemapController.php)).
  Sitemap pagination intentionally uses plain `LIMIT`/`OFFSET` chunking
  (crawlers fetch fixed numbered pages, not an infinite scroll) — this is
  different from the keyset-cursor pagination used on the interactive search
  page and is not a regression of that rule.
- **IIIF**: `/iiif/{slug}/manifest.json` returns a **IIIF Presentation API
  3.0** manifest only
  ([`IiifManifestController`](../app/Http/Controllers/Publication/IiifManifestController.php)).
  It exposes exactly one canvas whose image body is one of the existing,
  already-generated bounded JPEG derivatives (`preview2000`). **This is not
  an IIIF Image API implementation**: there is no region/size/rotation/
  quality/format parameter support, no `info.json` image service, and no
  deep-zoom tiling. Any Presentation-3-compatible viewer can still load and
  display the manifest and its single static image; it will not offer
  IIIF-native pan/zoom tiling beyond what the browser does with a normal
  image. The manifest is denied with a 404 for any private, embargoed,
  revoked, unscanned or (post-integration) deleted asset, via the same route
  binding used by the human-facing viewer.


