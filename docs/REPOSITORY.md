# Repository and distribution policy

## One product, two packages

Keep one Laravel application and one version source in [VERSION](../VERSION).
Webhosting and Docker are distribution formats, not separate source forks.
Commercial hosting and possible future public distribution use the same core.
Publication and licensing are separate decisions; neither is authorised by
adding deployment templates.

| Location | Responsibility |
| --- | --- |
| `app/`, `routes/`, `config/`, `database/` | Shared application and domain code |
| `resources/`, `public/` | UI source and public web entrypoint |
| `tests/` | Application, integration and installation regression tests |
| `Dockerfile`, `docker-compose.yml` | Image build and local development stack |
| `deploy/compose.yaml`, `deploy/.env.example` | Image-only operator stack for Komodo/Dockhand |
| `.env.example` | Developer configuration template |
| `docs/` | Architecture, installation, operations and API documentation |
| `.github/` | CI and contribution templates |
| `VERSION` | Single product-version source; currently a pre-release foundation |

Deployment-specific credentials, client archives, database dumps, backups,
customer configuration and support exports stay outside the repository.
Do not create per-customer branches as an installation/configuration mechanism.
Generic branding and sample content must not include real archive material or
personal data.

## Planned release outputs

A verified version tag will identify one source revision and:

- `fotoarchief-vX.Y.Z-webhosting.zip`: PHP application, production Composer
  dependencies, built UI assets and install instructions; no development tools,
  credentials, user uploads or cached local configuration.
- A container image tagged with the same version, plus its immutable digest.
- `fotoarchief-vX.Y.Z-deploy.zip`: Compose, `.env.example` and operator guide
  for that exact image.
- Checksums, dependency/license notices and release notes including migration,
  backup and operator configuration instructions.

Build the PHP and deployment archives from a clean committed checkout using
`php scripts/build-release.php --composer=/absolute/path/composer.phar`.
The builder installs only locked production dependencies, retains their licence
files, adds a dependency-licence inventory and records the exact source commit
in `BUILD.json`. It rejects tracked modifications and existing output archives.
Outputs are written to ignored `dist/` (override with `--output=/private/path`).
ZIP entries are sorted with fixed timestamps and permissions for repeatability;
Composer and PHP versions must also match when comparing repeated builds.
The archive contains readable PHP and ready-to-use browser assets. The host
needs PHP 8.5 with the extensions from Composer, PostgreSQL, writable private
storage and cron/worker access, but does not need Composer or Node.js.
Set the hosting document root to `public/`; never expose the package root.
Container build and deployment acceptance remain separate release gates.
Build all formats from the same tested commit and dependency lock.
Keep container registry location configurable; do not embed a private registry
or a company-specific domain into application behavior.

## Public-readiness gate

Before changing repository visibility:

1. Choose a source-code licence and obtain permission for all included code,
   dependencies, logos, documentation and sample media. Commercial use and
   open-source distribution are not mutually exclusive; the business model
   does not itself decide the licence. Record the choice in `LICENSE` only
   after an explicit owner decision.
2. Review the full Git history, release artifacts, CI logs and repository
   metadata for secrets, customer data and personal information. `.gitignore`
   does not remove previously committed content. Revoke exposed credentials
   before considering history cleanup.
3. Complete the setup/upgrade/restore tests for webhosting and Docker, and
   test actual imports in Komodo and Dockhand. Do not advertise deployment
   support based only on YAML parsing.
4. Publish accurate prerequisites, supported versions, known limitations,
   support expectations and a verified private vulnerability-reporting route.
5. Decide the contribution policy before accepting external contributions;
   review ownership/licensing requirements for any future dual licensing.
6. Verify that required CI checks and release artifacts succeed without
   private infrastructure or data.

The owner controls the licence, publication date and repository visibility.
No licence, public support SLA, automatic release or open-source commitment is
implied by the current foundation.
