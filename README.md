# Vistora (FotoArchief)

> Historische beeldbank als provider-onafhankelijke Laravel-modulaire monoliet.
>
> Historical image archive built as a provider-independent Laravel modular
> monolith.

[Nederlands](#nederlands) · [English](#english)

---

## Nederlands

### Overzicht

De publieksnaam en huisstijl zijn **Vistora**. Zie
[huisstijl, thema's en installatie-iconen](docs/BRANDING.md).
Technische namen en bestaande deploymentconfiguratie blijven ongewijzigd.

FotoArchief is de installeerbare basis voor de historische beeldbank uit de
[architectuurbeschrijving](docs/ARCHITECTURE.md). De publieke portal en de
beheeromgeving draaien in één deploybare Laravel-applicatie, met duidelijke
domein- en API-grenzen voor toekomstige clients.

De applicatie wordt geleverd als uploadbaar PHP-archief en als Docker-image.
Zie de [repository- en release-indeling](docs/REPOSITORY.md) en de
[bijdragerichtlijnen](CONTRIBUTING.md). Publieke beschikbaarheid en licentiëring
zijn afzonderlijke beslissingen van de eigenaar; er is nog geen
opensource-licentie gekozen.

### Geleverde mogelijkheden

- PHP 8.5 en Laravel 13 met vergrendelde afhankelijkheden;
- Nederlandse eerste-startwizard zonder vooraf ingestelde database, Redis, S3
  of applicatiesleutel;
- PostgreSQL voor metadata, workflows, rechten, revisies en auditgegevens;
- private lokale of S3-compatibele opslag voor immutable originelen;
- uploads in quarantaine, bestandsvalidatie, optionele ClamAV-scan,
  SHA-256-controle en asynchrone JPEG-afgeleiden;
- metadata- en rechtenbeheer met optimistic locking en revisiehistorie;
- begrensde CSV-import en geautoriseerde JSON-, CSV- en ZIP-export;
- uitnodigingen, rollen, sessie-intrekking, passkeys en eenmalige
  herstelcodes;
- collecties, personen, organisaties, historische locaties, herkomst, tags,
  synoniemen, geavanceerd zoeken en bulkacties;
- publicatiereview, embargo- en privacycontrole, publieke collecties,
  bezoekerssuggesties, sitemaps, IIIF Presentation 3-manifesten en een
  toetsenbordbedienbare ingebouwde viewer;
- diagnostiek, processingoperaties, integriteitscontrole, opslagverplaatsing,
  herstelbaar verwijderen en optionele OCR;
- een technische taalvoorkeurbasis met Nederlands (`nl`) als enige actieve
  locale; zie [taalvoorkeur](docs/LANGUAGE_PREFERENCE.md);
- gecoördineerde automatische deploymentmigraties voor app, worker en
  scheduler; zie [deploymentmigraties](docs/DEPLOYMENT_MIGRATIONS.md);
- reproduceerbare PHP- en deploymentarchieven, containeracceptatie en een
  consistente back-up- en herstelprocedure voor lokale volumes;
- versiebeheerbare 50.000-fotobenchmarks, regressiebudgetten, semantische
  relevantiesets en brede toegankelijkheidsregressies; zie
  [meetbare kwaliteit](docs/QUALITY_ACCEPTANCE.md);
- versiegebonden back-upmanifesten, providerneutrale versleutelde
  offsite-kopie, optionele wegwerpbare PostgreSQL-herstelproeven en
  opslagmigratiepreflight met verplichte S3-versioneringscontrole.

Composer-afhankelijkheden worden niet in de repository opgenomen. Installeer
de vergrendelde set met `composer install`; gebruik `composer update` niet als
installatiestap. Het productie-PHP-archief bevat wel de vergrendelde
productieafhankelijkheden, zodat Composer niet nodig is op de webhost.
PostgreSQL blijft verplicht voor beide distributievormen.

Implementatie en releaseacceptatie zijn niet hetzelfde. Raadpleeg het
[acceptatieregister](docs/RELEASE_ACCEPTANCE.md) voor meetresultaten en
resterende poorten voordat de applicatie publiek beschikbaar wordt gemaakt.
Het gegenereerde [functieregister](docs/CAPABILITIES.md) koppelt iedere
geleverde of voorwaardelijke mogelijkheid aan concreet bronbewijs.

### Vereisten

Voor image-only deployment via Komodo of Dockhand:

- [deploymenthandleiding](docs/DEPLOYMENT_STACKS.md);
- [Compose-template](deploy/compose.yaml);
- [omgevingstemplate](deploy/.env.example).

Voor lokale ontwikkeling zijn nodig:

- PHP 8.5 met GD (JPEG/PNG/WebP), EXIF, zip en de overige door Composer vereiste
  extensies;
- Composer 2;
- PostgreSQL;
- schrijfbare private applicatieopslag;
- optioneel een private S3-compatibele objectstore.

De eerste installatie ondersteunt private lokale opslag, bestandssessies en
-cache en databasequeues zonder Redis. Ingest schrijft atomair naar de
transactionele outbox; de scheduler levert daarna aan de databasequeue of,
optioneel, de private Redis/Valkey-queue.

### Eerste installatie

Voer vanuit de repositoryroot uit:

```powershell
composer install
Copy-Item .env.example .env
php artisan installation:prepare
```

Genereer voor een nieuwe wizardinstallatie niet handmatig een applicatiesleutel
en voer migraties niet los uit. Configureer de webserver met `public/` als
webroot, maak `storage/` en `bootstrap/cache/` schrijfbaar en open daarna
`/setup`. De [onboardinghandleiding](docs/ONBOARDING.md) beschrijft de volledige
procedure.

Na installatie:

```powershell
php artisan queue:work ingest --sleep=3 --tries=3 --timeout=120
```

Open vervolgens **Foto's**. Bij upgrades coördineren app, worker en scheduler
de databasewijzigingen automatisch. Controleer of herstel de toestand met de
opdrachten uit [deploymentmigraties](docs/DEPLOYMENT_MIGRATIONS.md).

### Architectuurgrenzen

- PostgreSQL is bron van waarheid voor gestructureerde gegevens.
- Afbeeldingsbestanden worden nooit in PostgreSQL opgeslagen.
- Originelen gebruiken stabiele private opslagkeys en blijven immutable;
  afgeleiden zijn herbouwbaar.
- Validatie, scanning, checksums, metadata-extractie, afgeleiden, OCR en
  indexering draaien als idempotente jobs, niet als zware HTTP-requests.
- Zoeken begint met geïndexeerde PostgreSQL-query's en keysetpaginering.
- Een toekomstige losse frontend gebruikt de versie-API en leest niet
  rechtstreeks uit tabellen of objectopslag.

### Repository-indeling

| Pad | Doel |
|---|---|
| [`app/Modules/`](app/Modules/) | Domeinmodules voor assets, metadata, rechten, catalogi, personen, locaties, collecties, zoeken, audit en bijdragen |
| [`routes/`](routes/) | Laravel-route-entrypoints |
| [`config/`](config/) | Frameworkconfiguratie met omgevingsgestuurde standaardwaarden |
| [`tests/`](tests/) | Onboarding-, processing-, autorisatie-, protocol- en PostgreSQL-regressietests |
| [`lang/`](lang/) | Localecatalogi; Nederlands is leidend en wordt bewaakt door de [vertaalcontrole](docs/TRANSLATIONS.md) |
| [`docs/`](docs/) | Architectuur, onderzoek en operationele handleidingen |

### Validatie

```powershell
composer test
composer lint
composer analyse
```

`composer lint` voert ook `php artisan translations:check` uit. Die controle
faalt bij ontbrekende, ongebruikte of dynamisch opgebouwde vertaalsleutels en
bij verschillen tussen localecatalogi.

Pest, Pint, Larastan, Composer-metadata/platformcontroles en de audit van
vergrendelde afhankelijkheden zijn op PHP 8.5.10 gevalideerd. De standaardtests
gebruiken geïsoleerde SQLite-instellingen en fake storage; aanvullende
acceptatie bewijst PostgreSQL-, S3- en containerpaden.

PostgreSQL 16.14 is gebruikt voor onboarding-, immutability-, schema-upgrade-,
queue- en fotobewerkingsregressies. Linux Quality-run `35051355300` heeft de
PHP 8.5.10/Apache-image, echte Dockhand v1.0.48 API-import, onboarding,
queueverwerking, herstartpersistentie en een afzonderlijke back-up/herstelstack
geaccepteerd. Komodo-UI-import blijft een afzonderlijke nog niet uitgevoerde
acceptatiegrens.

Gebruik uitsluitend versiegebonden testreleaseartefacten en controleer altijd
het [acceptatieregister](docs/RELEASE_ACCEPTANCE.md).

---

## English

### Overview

The user-facing name and visual identity are **Vistora**. See
[branding, themes and install icons](docs/BRANDING.md).
Technical names and existing deployment configuration remain unchanged.

FotoArchief is the installable foundation for the historical image archive
described in the [architecture document](docs/ARCHITECTURE.md). The public
portal and staff administration run in one deployable Laravel application with
clear domain and API boundaries for future clients.

The application is distributed as an uploadable PHP archive and a Docker
image. See the [repository and release structure](docs/REPOSITORY.md) and the
[contribution guide](CONTRIBUTING.md). Public availability and licensing remain
separate owner decisions; no open-source licence has been selected.

### Delivered capabilities

- PHP 8.5 and Laravel 13 with locked dependencies;
- a Dutch first-start wizard without a preconfigured database, Redis, S3 or
  application key;
- PostgreSQL for metadata, workflows, rights, revisions and audit data;
- private local or S3-compatible storage for immutable originals;
- quarantined uploads, file validation, optional ClamAV scanning, SHA-256
  verification and asynchronous JPEG derivatives;
- metadata and rights management with optimistic locking and revision history;
- bounded CSV import and authorised JSON, CSV and ZIP export;
- invitations, roles, session revocation, passkeys and one-time recovery codes;
- collections, people, organisations, historical locations, provenance, tags,
  synonyms, advanced search and bulk actions;
- publication review, embargo and privacy controls, public collections,
  visitor suggestions, sitemaps, IIIF Presentation 3 manifests, and a
  keyboard-operable embedded viewer;
- diagnostics, processing operations, integrity checks, storage relocation,
  recoverable deletion and optional OCR;
- a technical language-preference foundation with Dutch (`nl`) as the only
  active locale; see [language preference](docs/LANGUAGE_PREFERENCE.md);
- coordinated automatic deployment migrations for app, worker and scheduler;
  see [deployment migrations](docs/DEPLOYMENT_MIGRATIONS.md);
- reproducible PHP and deployment archives, container acceptance and a
  consistent local-volume backup and restore procedure;
- version-controlled 50,000-photo benchmarks, regression budgets, semantic
  relevance sets, and broad accessibility regressions; see
  [measurable quality](docs/QUALITY_ACCEPTANCE.md);
- version-bound backup manifests, provider-neutral encrypted offsite copying,
  optional disposable PostgreSQL restore drills and storage-migration
  preflight with mandatory S3 versioning verification.

Composer dependencies are not vendored in the repository. Install the locked
set with `composer install`; do not use `composer update` as an installation
step. The production PHP archive does include locked production dependencies,
so Composer is not required on the webhost. PostgreSQL remains mandatory for
both distribution formats.

Implementation and release acceptance are not the same. Consult the
[acceptance ledger](docs/RELEASE_ACCEPTANCE.md) for measurements and remaining
gates before exposing the application publicly.
The generated [capability register](docs/CAPABILITIES.md) ties every shipped
or conditional capability to concrete source evidence.

### Requirements

For image-only deployment through Komodo or Dockhand, use:

- the [deployment guide](docs/DEPLOYMENT_STACKS.md);
- the [Compose template](deploy/compose.yaml);
- the [environment template](deploy/.env.example).

Local development requires:

- PHP 8.5 with GD (JPEG/PNG/WebP), EXIF, zip and the other Composer-required
  extensions;
- Composer 2;
- PostgreSQL;
- writable private application storage;
- optionally, a private S3-compatible object store.

The initial installation supports private local storage, file sessions/cache
and database queues without Redis. Ingest writes atomically to the
transactional outbox; the scheduler then delivers to the database queue or,
optionally, the private Redis/Valkey queue.

### First installation

Run from the repository root:

```powershell
composer install
Copy-Item .env.example .env
php artisan installation:prepare
```

Do not manually generate an application key or run standalone migrations for a
new wizard-based installation. Configure the web server with `public/` as its
webroot, make `storage/` and `bootstrap/cache/` writable, and open `/setup`.
The [onboarding guide](docs/ONBOARDING.md) documents the complete procedure.

After installation:

```powershell
php artisan queue:work ingest --sleep=3 --tries=3 --timeout=120
```

Then open **Foto's**. During upgrades, app, worker and scheduler coordinate
database changes automatically. Inspect or recover the state with the commands
in [deployment migrations](docs/DEPLOYMENT_MIGRATIONS.md).

### Architecture boundaries

- PostgreSQL is the source of truth for structured data.
- Image binaries are never stored in PostgreSQL.
- Originals use stable private storage keys and remain immutable; derivatives
  are rebuildable.
- Validation, scanning, checksums, metadata extraction, derivatives, OCR and
  indexing run as idempotent jobs, not heavy HTTP requests.
- Search starts with indexed PostgreSQL queries and keyset pagination.
- A future standalone frontend uses the versioned API and does not read tables
  or object storage directly.

### Repository structure

| Path | Purpose |
|---|---|
| [`app/Modules/`](app/Modules/) | Domain modules for assets, metadata, rights, catalogues, people, locations, collections, search, audit and contributions |
| [`routes/`](routes/) | Laravel route entrypoints |
| [`config/`](config/) | Framework configuration with environment-driven defaults |
| [`tests/`](tests/) | Onboarding, processing, authorisation, protocol and PostgreSQL regression tests |
| [`lang/`](lang/) | Locale catalogues; Dutch leads and is guarded by the [translation check](docs/TRANSLATIONS.md) |
| [`docs/`](docs/) | Architecture, research and operational guides |

### Validation

```powershell
composer test
composer lint
composer analyse
```

`composer lint` also runs `php artisan translations:check`. It fails on missing,
unused or runtime-built translation keys and on differences between locale
catalogues.

Pest, Pint, Larastan, Composer metadata/platform checks and the locked dependency
audit have been validated on PHP 8.5.10. The default tests use isolated SQLite
settings and fake storage; additional acceptance covers PostgreSQL, S3 and
container paths.

PostgreSQL 16.14 was used for onboarding, immutability, schema-upgrade, queue and
photo-edit regressions. Linux Quality run `35051355300` accepted the PHP
8.5.10/Apache image, real Dockhand v1.0.48 API import, onboarding, queue
processing, restart persistence and a separate backup/restore stack. Komodo UI
import remains a separate acceptance boundary that has not yet been executed.

Use only versioned test-release artifacts and always consult the
[acceptance ledger](docs/RELEASE_ACCEPTANCE.md).
