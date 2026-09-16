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
- Production Apache Docker build, database-free first start, key persistence,
  complete HTTP onboarding/login/upload/worker/private JPEG and anonymous denial.
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

## Limits of the evidence

The benchmark has synthetic metadata and no 50,000-image binary corpus. It does
not establish ingest throughput, multi-user concurrency, S3 latency or public
search performance. Public filtered search still needs its own representative
published corpus and **700 ms p95** control.

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
