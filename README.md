# FotoArchief

FotoArchief is the Laravel modular-monolith MVP foundation for the historical
image bank described in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

The approved baseline is a provider-independent Laravel application that renders
the public portal and staff/admin workflows from one deployable codebase while
keeping versioned API and domain-module seams available for future clients.

## Current scope

The repository is organised for one application with two distribution formats:
an uploadable PHP archive and a Docker image. See
[repository and release organisation](docs/REPOSITORY.md) and
[contribution guidance](CONTRIBUTING.md). Public availability and licensing
remain separate owner decisions; no open-source licence has been selected.

This repository contains the installable application and the archive expansion:

- a conventional Laravel-oriented directory skeleton;
- PHP 8.5 / Laravel 13 dependencies resolved in [composer.lock](composer.lock);
- relational catalogue models, initial permission checks and quarantine tests;
- an Apache-based Docker build and portable Compose templates;
- a Dutch first-start wizard, private persistent settings, initial administrator
  login/logout and a minimal protected dashboard;
- safe example configuration in [.env.example](.env.example);
- multi-file upload, transactional database workers, private JPEG previews,
  optional ClamAV transport, ownership-aware access, metadata/rights editing
  and revision history; see [the photo workflow](docs/PHOTO_WORKFLOW.md);
- bounded CSV metadata import with a Dutch mapping preview, dry run, explicit
  confirmation and per-photo audit trail, plus authorised exports (metadata
  JSON/CSV and a private ZIP with originals, derivatives, manifest and
  checksums) released through short-lived, re-authorised download links; see
  [data exchange](docs/DATA_EXCHANGE.md);
- user invitations, role management, deactivation, session revocation, browser
  passkeys and one-time offline recovery codes;
- collection hierarchies, people and organisations, historical locations,
  provenance, tags and synonyms, advanced search, bulk metadata and staff
  worklists;
- publication review and revocation, rights/privacy/embargo checks, public
  discovery and collections, a photo viewer, moderated visitor suggestions,
  sitemaps and IIIF Presentation 3 manifests (not a full IIIF Image API); see
  [the public portal operator handbook](docs/PUBLIC_PORTAL.md);
- diagnostics, duplicate dossiers, scan versions, processing operations,
  integrity checks, storage relocation, recoverable deletion and optional OCR;
- a technical language-preference foundation with Dutch (`nl`) as the only
  active locale; see [language preference](docs/LANGUAGE_PREFERENCE.md);
- reproducible production PHP/deployment archives, container acceptance and a
  consistent local-volume backup/restore procedure.

No Composer dependencies are vendored. Install the resolved dependency set with
`composer install`; do not run `composer update` as an installation step.
The production PHP archive includes locked production dependencies, so Composer
is not required at the webhost. It is readable Laravel/PHP code, not a separate
framework-free implementation. PostgreSQL remains required in both formats.
Recovery uses offline one-time codes; automated recovery email is not promised.
Implementation and release acceptance are distinct: consult the
[acceptance ledger](docs/RELEASE_ACCEPTANCE.md) for measured results and remaining
gates before exposing the archive publicly.

## Prerequisites

For image-only Komodo/Dockhand deployment, see
[the stack deployment guide](docs/DEPLOYMENT_STACKS.md),
[Compose template](deploy/compose.yaml) and
[stack environment template](deploy/.env.example). Actual Linux container and
Dockhand API acceptance pass; use the versioned test-release artifacts and
consult the [acceptance boundaries](docs/RELEASE_ACCEPTANCE.md).

Install these locally before running the app:

- PHP 8.5 with GD (JPEG/PNG/WebP), EXIF, zip (package exports) and the other Composer-required extensions
  (validated locally with 8.5.10; see [composer.json](composer.json));
- Composer 2;
- PostgreSQL for durable metadata;
- writable private application storage;
- optionally an S3-compatible private object store.

Initial installation supports local private storage, file sessions/cache and
database queues without Redis. Ingest always uses the dedicated transactional
database queue, even when other queues/cache use Redis. S3 and a future
Redis/Valkey worker topology remain options for the full production archive.

## First install

From the repository root:

```powershell
composer install
Copy-Item .env.example .env
```

Do not generate a separate key or migrate manually for a fresh wizard-based
installation. Instead configure the web server to serve `public/`, make
`storage/` and `bootstrap/cache/` writable, and obtain the private setup code:

```powershell
php artisan installation:prepare
```

Open `/setup` and follow [the onboarding guide](docs/ONBOARDING.md). The wizard
generates and retains the app key, checks database/storage and creates the
administrator. Keep your database empty before beginning.

Then start `php artisan queue:work ingest --sleep=3 --tries=3 --timeout=120`
and open **Foto's**. Upgrading an existing installation also requires the new
migration; follow [operator changes](docs/PHOTO_WORKFLOW.md#upgraden-en-exacte-operatorwijzigingen).

For development validation:

```powershell
composer test
composer lint
composer analyse
```

Use a real `.env` file only on the developer or deployment machine. Never commit
`.env`, secrets, generated vendor files or generated caches. Commit
changes to `composer.lock` only when intentionally updating and verifying
dependencies.

## Architecture boundaries

Keep implementation aligned with the approved architecture:

- PostgreSQL owns structured metadata, workflow state, rights, revisions and
  audit data.
- Image binaries never go in PostgreSQL.
- Immutable originals and rebuildable derivatives use application-owned,
  private storage keys (local or S3-compatible).
- Upload validation, scanning, checksums, metadata extraction, derivative
  generation, OCR and indexing run as idempotent queued jobs, never as heavy
  HTTP request work.
- Search starts with indexed PostgreSQL queries and keyset pagination. Add a
  dedicated search engine only after measurements prove it is needed.
- A future standalone public frontend must consume the versioned API; it must
  not read application tables or object storage directly.

## Directory guide

- [app/Modules/](app/Modules/) is reserved for domain modules such as assets,
  metadata, rights, catalogues, people, locations, collections, search, audit
  and contributions.
- [routes/](routes/) contains Laravel route entrypoints.
- [config/](config/) contains framework configuration with environment-driven
  defaults.
- [tests/](tests/) contains onboarding, photo processing, authorization,
  scanner-protocol and opt-in PostgreSQL regression tests.
- [lang/](lang/) holds the locale catalogues; the Dutch catalogue leads and
  is guarded by [the translation check](docs/TRANSLATIONS.md).
- [docs/](docs/) contains the approved architecture and research notes.

## Validation

After dependencies are installed, the expected local checks are:

```bash
composer test
composer lint
composer analyse
```

`composer lint` also runs `php artisan translations:check`, which fails on
missing, unused or runtime-built translation keys and on locale catalogue gaps;
see [translation keys and locale parity](docs/TRANSLATIONS.md).

Verified on PHP 8.5.10: Pest, Pint, Larastan, Composer metadata/platform checks
and the locked-dependency audit. Tests use isolated settings with SQLite and
fake storage; they do not prove PostgreSQL constraints or real S3 integration.
Both Compose files pass the Compose parser.

Separately, real PostgreSQL 16.14 passed the opt-in onboarding/immutability
regression, and browser acceptance exercised the wizard, administrator login,
setup locking, CSRF and persistence after an application restart. See
[ONBOARDING.md](docs/ONBOARDING.md) for the test command and boundaries.

PostgreSQL 16.14 also passed the previous-schema upgrade, real queue-processing
and photo-edit regression. Browser acceptance exercised the new private photo
workflow; see [PHOTO_WORKFLOW.md](docs/PHOTO_WORKFLOW.md) for scope and limits.

The [quality workflow](.github/workflows/quality.yml) includes an Apache
pending-onboarding and persistent-state restart smoke test. Linux run
`35051355300` passes the complete container, restore and Dockhand API gates;
the local Windows machine has no Docker engine. The production PHP ZIP has been built, unpacked without Composer and exercised
through real HTTP onboarding and queue processing. No Docker image or
Komodo/Dockhand installation is runtime-verified yet. See
[ONBOARDING.md](docs/ONBOARDING.md) for the implemented wizard and its test scope.
