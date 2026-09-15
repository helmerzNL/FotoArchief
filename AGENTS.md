# AGENTS.md — FotoArchief agent instructions

This repository is the FotoArchief Laravel modular-monolith MVP. The approved
architecture is in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md); read it before
changing application structure or domain boundaries.

## Project baseline

- Build a Laravel modular monolith for the MVP.
- Keep Laravel responsible for the first public portal, staff/admin workflows,
  versioned API seams and background job dispatch.
- Use PostgreSQL as the authoritative metadata and workflow-state store.
- Use private S3-compatible object storage for immutable originals and
  rebuildable derivatives at production scale; private local storage is also
  supported by onboarding for constrained installs and test deployments.
- Use Redis or Valkey for production queue/cache operation; database-backed
  queues are acceptable only for development or explicitly constrained installs.
- Do not introduce Vercel, serverless, Kubernetes or provider-specific managed
  runtime assumptions into the MVP foundation.

## Boundaries to preserve

- Do not store image binaries in PostgreSQL.
- Do not perform heavy ingest, validation, scanning, checksum, metadata,
  derivative, OCR or indexing work inside synchronous HTTP handlers.
- Keep upload and publication processing observable, idempotent and retryable.
- Treat uploads, image decoders, SVG/PDF content and embedded metadata as
  untrusted input.
- Keep a future API/client split possible, but do not add a separate frontend
  app until the architecture migration triggers are met.
- Start search with PostgreSQL indexes/full-text/trigram patterns and keyset
  pagination; require measurements before adding a dedicated search engine.

## Runtime and dependency baseline

- Use PHP 8.5; the foundation is locally tested with PHP 8.5.10 and Laravel 13.
- Install from [composer.lock](composer.lock) with `composer install`.
- Never fabricate Composer output; resolve dependency updates with Composer and
  run tests, analysis, platform checks and the locked-dependency audit.
- Do not commit [vendor/](vendor/) or generated framework caches.
- Tests use [tests/Fixtures/test-settings](tests/Fixtures/test-settings) and
  [phpunit.xml](phpunit.xml), not private developer configuration.
- SQLite/fake-storage tests do not establish PostgreSQL or S3 correctness.
- Docker uses Apache, not the PHP development server. A Compose parse is not
  a successful image build or a verified Komodo/Dockhand deployment.

## Secrets and configuration

- Never read or commit `.env` or any secret-bearing environment file.
- [.env.example](.env.example) is the safe configuration schema.
- Keep all committed configuration placeholder-only.
- Store object-storage credentials, database credentials, app keys and service
  credentials outside git.
- First-start settings and the generated app key live privately under
  `storage/app/installation`; they override database/storage environment values.
  Never reopen setup or regenerate the key as an error-recovery shortcut.
- Preserve database-free setup, installation receipt retry behaviour and the
  completed-installer lock. Read [docs/ONBOARDING.md](docs/ONBOARDING.md).

## Development workflow

- Preserve existing docs unless the task explicitly updates the documented
  decision.
- Do not modify deployment or CI files owned by another agent unless the task
  explicitly assigns that work.
- Prefer conventional Laravel structure and names over custom framework
  abstractions.
- Keep domain implementation inside [app/Modules/](app/Modules/) once domain
  work begins.
- Add tests with any application or domain behaviour change.
- Run the smallest relevant checks available. If PHP/Composer are unavailable,
  state that limitation and at minimum run `git diff --check`.

## Minimum checks after dependencies exist

```bash
composer test
composer lint
composer analyse
```
