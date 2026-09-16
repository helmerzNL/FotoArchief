# Release acceptance

A feature commit is not by itself a production-readiness claim. The same final
revision must pass the checks below before its packages are offered for testing.

## Automated gates

The Quality workflow provides independent jobs for:

- PHP unit/feature tests, formatting, level-8 analysis and dependency audit.
- Real PostgreSQL fresh onboarding and forward-only upgrade from the first four
  migrations, including immutable original/checksum constraints.
- Exactly 50,000 synthetic private metadata records and real HTTP measurements:
  40 measured requests after five warmups for listing, filtered listing and
  details. p95 limits are **800 ms**, **800 ms** and **400 ms** respectively.
- The same corpus then receives 45,000 eligible public publications and 5,000
  deliberately denied rows (draft, revoked, privacy, embargo, scan state, stale
  metadata, trash or unverified rights). Anonymous search combining text and
  collection filters must meet **700 ms p95**; public detail must meet **400 ms**.
- Production Apache Docker build, database-free first start, key persistence,
  complete HTTP onboarding/login/upload/worker/private JPEG and anonymous denial.
- A digest-pinned Dockhand v1.0.48 on a disposable runner imports the actual
  Compose and environment templates through its documented API. The independently
  created stack must complete the same HTTP onboarding/photo acceptance. This is
  manager API acceptance, not a browser UI walkthrough or Komodo verification.
- Consistent local-volume backup restored to a different Compose project with
  empty volumes/database; the old account, photos and setup lock must survive.
- PHP/deployment ZIPs from one clean commit and production dependency lock.
  The archive check compares every installed package/version with the production
  lock and rejects development tools and private runtime state.

Successful runs retain PHP archives and a compressed `docker save` image with
checksums for 14 days. Download those artifacts before the retention window
expires; use tagged releases for long-term retention.

Pushing a `v` tag matching `VERSION` on a commit already merged into `main`
starts Verified test release. It repeats Quality, then publishes those exact
tested archives and that saved image, without rebuilding them, to a persistent
GitHub prerelease and GHCR version tag. `IMAGE-DIGEST.txt` identifies the registry
digest. `IMAGE-LOCAL.txt` selects the versioned tag included in the offline image
archive. Publishing a test release does not declare the unresolved gates below
complete.

The manager acceptance container receives Docker socket access only on the
ephemeral CI runner, with its HTTP port bound to loopback. It is never bundled
in FotoArchief's production stack. The test removes its own container, volume
and application project afterward; do not run it against a shared manager.

## Local evidence before integrating the expansion

The v0.4.0 ZIP was built with PHP 8.5.10 / Composer 2.10.3, unpacked and booted
without Composer or development packages. A real HTTP wizard connected it to
PostgreSQL 16.14, created its administrator and processed a PNG through a real
queue worker. The preview was a JPEG and anonymous access was denied.

The pre-integration application passed 69 tests / 534 assertions, plus two
opt-in PostgreSQL tests / 39 assertions. On this Windows host the private-metadata
50,000-record HTTP p95 values were 145.57 ms (list), 185.73 ms (filtered list) and
179.30 ms (detail). These are not measurements of the subsequent integrated
features, production Apache, concurrent load or the public portal.

## Identity integration acceptance

After integrating invitations, user access management, passkeys and recovery,
the complete suite passed **79 tests / 602 assertions**, with formatting and
level-8 analysis passing. Real PostgreSQL fresh installation and forward upgrade
also passed separately.

A clean Chromium/Edge browser profile on `http://localhost:8791` exercised the
actual browser WebAuthn APIs against an isolated application and disposable
SQLite database. A CDP CTAP2 internal authenticator with resident credentials,
user verification and automatic presence completed enrollment and discoverable
passkey login. The stored credential counter advanced to 2, both ceremony
challenges were consumed and the credential's last-used timestamp was set.
Recovery login succeeded with one of ten generated codes; reuse of that same
code was denied. The test server, database, profile and temporary harness were
removed afterward.

This verifies browser encoding, local RP/origin alignment and a virtual
authenticator. It does not certify hardware keys, mobile platform authenticators,
cross-device flows or a production HTTPS origin.

## Limits of the evidence

### Operations correction acceptance

Revision `326b6d6` integrates OCR's 120-second job deadline, bounded process
timeout, explicit ingest-connection dispatch, persistent asynchronous archive
maintenance runs, discoverable operation links, OCR/file-version object
authorization, and the database-enforced single-primary-file invariant.
The integrated full suite passes **303 tests / 2206 assertions**, with eight
environment-gated skips and three portal fixture failures still being corrected.
The failures attempt impossible duplicate primaries or recreate an existing
column; they are not waived.

Separate fresh PostgreSQL databases execute operation acceptance plus fresh
onboarding and legacy forward upgrade: **7 tests / 140 assertions pass**.
The operation tests use real private files, native immutable-original triggers,
JPEG derivative decoding, verified storage cutover, trash/restore/purge, and a
32 MB queued-maintenance fixture. The CI PostgreSQL job now explicitly runs
these tests rather than silently skipping them. Real Linux Tesseract extraction
and standalone maintenance-worker acceptance remain separate gates.

### Portal correction acceptance

Revision `f936e64` integrates object-level suggestion authorization and a shared
active-public-file resolver. The integrated targeted run has **9 passing tests
and 2 failing tests**: the latter try to create an `is_primary` column already
provided by archive operations. These tests and the active-version integration
contract are being corrected before release; a feature-branch pass does not
establish compatibility with the integrated schema. Quality now provisions a
dedicated PostgreSQL portal database so this boundary cannot remain an opt-in
skip in CI.

A real browser against the PostgreSQL-backed synthetic corpus submitted an
anonymous visitor suggestion, then logged in and accepted it through the staff
queue. At viewport **390**, the populated accepted-suggestions page after the
table fix measures document width **375**, with a **343**-pixel internal region
around **1095** pixels of table content. The region has `tabindex="0"` and
ArrowRight advances its scroll position by **40** pixels. Acceptance records the
moderation decision only; no automatic metadata edit is asserted.

The clean private-then-public seed sequence also succeeds against a new
PostgreSQL database. Repeating either seeder now exits **1**, preserving exactly
50,000 assets, files and publications and one benchmark collection. Composer
metadata validation, locked dependency audit and PHP platform checks pass.

The same 50,000-record HTTP gate was repeated after the portal correction.
Private list/filter/detail p95 values are **606.50 / 376.81 / 353.87 ms**, all
within their limits. Public detail passes at **224.45 ms**, but filtered public
search regresses to **1912.93 ms** against its **700 ms** ceiling. This is a
release-blocking regression in the active-file query work, not a waived
threshold; the earlier 437.79 ms result cannot establish the new query's speed.

### Exchange recovery and deletion acceptance

Revisions `ab96843` and `2af9ef5` integrate worker-stop recovery and soft-deletion
delivery guards. The complete integrated exchange subset passes **58 tests /
323 assertions**, with whole-application formatting and level-8 analysis green.
Both Compose templates parse with the new timing mappings in `54ac52a`.

The exchange worktree's real PostgreSQL/HTTP/standalone-worker acceptance
completed CSV dry-run and confirmation plus JSON, CSV and ZIP downloads.
A worker killed mid-export left a running claim; redelivery reclaimed it on
attempt 2 and produced an 18-asset, 74-file bundle with no checksum mismatches.
Premature redelivery released rather than silently deleting the job. Abandoned
claims are explicitly failed with a Dutch diagnostic. Import processing was too
short to kill reliably, so import crash timing is covered deterministically,
not claimed as a successful process-kill experiment. Trashed assets are denied
at selection, build and delivery, including a link issued before deletion.
Linux image execution of these corrections remains a separate pending gate.

### Integrated browser and package checks

Real Playwright browser interactions against the isolated PostgreSQL-backed
50,000-record harness exercised password login, catalogue creation, filtered
photo search, photo detail, exchange and diagnostics pages. The benchmark router
now serves allowlisted public styles/scripts; traversal and private configuration
requests still return 404. At a 390-pixel viewport the styled dashboard, photo
search and diagnostics have no document-level horizontal overflow.

The browser exposed a photo-reference lookup defect: the visible
"Aanwinstnummer of Foto-ID" field initially rejected an existing photo ULID.
After integration revision `c52ace5`, submitting the exact same ID succeeds,
displays "Foto toegevoegd aan collectie", increments the collection count to
one and links the expected photo. Catalogue regression tests pass **62 / 605**;
formatting and level-8 analysis pass. Revision `4225d4a` fixes the populated
collection table: a real full-page browser at viewport 390 measures document
width 375 and an internal scroll region of 309 pixels around 657 pixels of
table content. The region is keyboard-focusable; ArrowRight advances its scroll
position by 40 pixels. These checks are not a WCAG 2.2 AA certification.

The same revision integrates all five public-portal milestones and their
soft-delete interoperability checks. The complete suite passes **245 tests /
1846 assertions**, with Pint and level-8 analysis green. Fresh installation and
legacy upgrade against isolated PostgreSQL databases separately pass **2 tests /
39 assertions**. The public-portal security review and public 50,000-record
performance gate were pending at that revision. A security review of the preceding private
application identified two high-severity missing object-policy checks in OCR
and file-version operations; their corrective tests and integration are required
before release.

The real v0.8.6 production PHP archive from revision `3909bb1` passes the
archive/provenance contract with **79 production packages**. Its SHA-256 is
`33a5bfd554e306cac1a1b0399b04e048870bebd4079730db06f44c14985123d2`;
the deployment ZIP SHA-256 is
`f8ac9aea69d9da68dead90ecff85ea797bf15dfa33e24c47d0361fc00bc3387b`.
This intermediate package predates the reference fix, remaining operations
corrections and public portal; it is not the final 30-feature test release.

Repeating the private 50,000-record HTTP benchmark after all operations
migrations gives p95 **324.24 / 228.81 / 345.75 ms** for list, filtered list and
detail, below **800 / 800 / 400 ms** respectively.

After integrating archive diagnostics, duplicates, file versions, processing,
integrity, storage relocation, trash and OCR, plus the catalogue HTTP workflow
regression test, revision `fdd38b4` passes **171 tests / 1273 assertions**,
formatting and level-8 analysis. The complete migration set also passes the real
PostgreSQL fresh installation and legacy forward-upgrade tests (**2 / 39**).
The catalogue workflow is a Laravel HTTP feature test, not a standalone browser
execution. OCR engine/language execution is a separate Docker CI gate; neither
installing its package in the Dockerfile nor fake-process tests prove it ran.
Integrated asynchronous operation, storage-trigger and portal acceptance remain
pending.

At integration revision `a4e3ed6`, identity, the eight catalogue milestones and
CSV import/exports pass **135 tests / 1005 assertions**, formatting and level-8
analysis. Both real PostgreSQL fresh/upgrade tests pass **39 assertions** after
correcting catalogue user foreign keys to ULIDs. Repeating the same 50,000-record
private HTTP benchmark after applying the additional migrations gives p95
**222.38 ms** for listing, **233.24 ms** for filtered listing and **240.81 ms**
for details, all below their respective limits. The publication and archive
operations modules are not included in this result.

After portal integration the private HTTP p95 results are **313.62 / 262.67 /
280.07 ms**, below their limits. The real PostgreSQL public fixture verifies
exactly **45,000 eligible publications out of 50,000 assets**. Anonymous
text-plus-collection search measures **437.79 ms p95 < 700 ms**, and public
detail **320.82 ms p95 < 400 ms**, each over 40 samples after five warmups.
The public fixture is transactional and refuses populated publication/file/right
tables. CI runs both harnesses in order against its disposable database.

The benchmark has synthetic metadata and no 50,000-image binary corpus. It does
not establish ingest throughput, multi-user concurrency, S3 latency, image
delivery performance or production Apache latency.

Accessibility review, real passkey authenticator coverage, manager UI imports,
S3-provider operations, antivirus detection and OCR accuracy need explicit
evidence appropriate to the capabilities enabled in a deployment. Do not infer
those results from unit-test fakes or Compose parsing. Record unresolved gates
in release notes; never label an incomplete validation set production-ready.

## Running the isolated benchmark

Use an empty PostgreSQL database whose name ends in `_benchmark_test`. Set
`FOTOARCHIEF_BENCH_DATABASE`, `FOTOARCHIEF_BENCH_HOST`, `FOTOARCHIEF_BENCH_PORT`,
`FOTOARCHIEF_BENCH_USER` and `FOTOARCHIEF_BENCH_PASSWORD` in the test process.
Run `php tests/Performance/seed.php`, then serve
`php -S 127.0.0.1:8767 -t public tests/Performance/router.php` and run
`php tests/Performance/measure.php`. Stop the test server afterward. The fixture
refuses a non-empty database and never reads a developer environment file.
Its disposable account and testing router must never be deployed publicly.
