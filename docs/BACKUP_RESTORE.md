# Backup and restore

FotoArchief must back up PostgreSQL metadata, S3-compatible object storage,
deployment configuration and required secrets separately. A backup is not a
recovery control until a restore has been tested.

## Consistent local-volume backup scripts

On a Linux Docker host, from the application checkout:

```sh
export COMPOSE_FILE=deploy/compose.yaml
sh scripts/backup-compose.sh /private/backups/fotoarchief-2026-09-16
```

The destination must not exist. The script stops the web, worker and scheduler
services while collecting a custom PostgreSQL dump and the private `storage/app`
tree. It resumes only services which were previously running, including on an
error. Stop any additional replicas or external writers yourself first.
`SHA256SUMS`, `FORMAT` and `VERSION` identify the matching recovery set.
Protect the backup as a secret: installation state includes the application key
and database/storage credentials. Copy it encrypted to a second location.
Checksums detect accidental damage; they do not authenticate untrusted backups.

The automatic volume procedure refuses S3, mixed disks and unassigned legacy
file locations rather than claiming to have backed up absent originals. Those
installations need the object-storage snapshot procedure below together with a
database dump and installation-state backup during the same write-free window.
Webhosting operators can use their provider's PostgreSQL dump/file backup tools
with that same consistency requirement; these scripts require Docker Compose.

Restore into a **separate empty stack** using the same image version, database
name/user/password and private installation hostname (normally `postgres`):

```sh
export COMPOSE_PROJECT_NAME=fotoarchief-restore
export APP_HTTP_PORT=8081
sh scripts/restore-compose.sh /private/backups/fotoarchief-2026-09-16 --confirm-empty-target
```

The restore verifies checksums, refuses a non-empty database or private storage,
rejects unsafe archive entries and uses a single-transaction `pg_restore`.
The container's `scripts/restore-storage.php` reads the archive through standard
input into a private temporary `.tar` file, validates every entry before
extracting, and removes its temporary copy on success or failure. Keep that
helper with the matching image; do not substitute an unchecked `tar -xf`.
Existing empty directories and an identical image-supplied `app/.gitignore`
are retained; existing data files are refused, never overwritten.
It does not run `--clean`, erase volumes, regenerate keys or reopen onboarding.
On failure it leaves application services stopped; investigate before retrying
in a new empty target. Verify account login, original checksums and previews
before serving traffic. An existing installation must never be used as a test
restore destination.

## Optionele HTTP-herstelacceptatie / Optional HTTP restore acceptance

### Nederlands

Na een geverifieerde `operations:restore-drill` kan dezelfde applicatieversie
een echte inlogproef uitvoeren tegen uitsluitend de teruggezette testinstallatie:

```sh
php artisan operations:accept-restored DRILL_ID --asset=ASSET_ULID --confirm-isolated-target
```

Gebruik een bestaand account uit de backup dat de geselecteerde foto mag zien.
De opdracht vraagt het e-mailadres en een verborgen wachtwoord. Voor beveiligde
automatisering accepteert `--credentials-stdin` een JSON-object met `email` en
`password` via standaardinvoer. Zet wachtwoorden nooit in commandoregels,
shellgeschiedenis of bewijsreferenties.

De proef vereist een voltooide herstelde installatiestatus, dezelfde versie,
een afzonderlijke PostgreSQL-database met suffix `_restore_drill` en de eerder
geverifieerde lokale opslag buiten app, live opslag en backup. De bestaande
herstelde appkey blijft behouden; opgeslagen live database-/opslaginstellingen
worden niet toegepast. Alleen de testdatabase en lokale testopslag worden
geconfigureerd. Een tijdelijke PHP-server luistert uitsluitend op loopback en
accepteert alleen login, de installer-lockcontrole en de gekozen fotodetailroute.
E-mail, externe opslag en achtergrondwerkers worden niet gestart.

De controles zijn echte HTTP-login, anonieme weigering, geautoriseerde
fotodetailtoegang en gesloten installatie. Het is **geen browser-, Apache-,
S3- of previewweergavebewijs**; de voorafgaande restore-drill controleert de
originele bytes. De subprocessen en tijdelijke sessie-/cachebestanden worden
opgeruimd, terwijl database en herstelde opslag voor inspectie blijven bestaan.
Succes en mislukking krijgen afzonderlijke append-only bewijsregels in de
broninstallatie. Het drillrapport vermeldt de laatste acceptatiepoging; een
latere mislukking behoudt geen oude succesvlaggen. Fouten tonen uitsluitend
een vaste fase en eventueel HTTP-status, nooit responsinhoud of credentials.
Een onderbroken proef kan `running` achterlaten; start bewust een nieuwe proef.

Geen nieuwe Compose-mapping of permanente omgevingsvariabele is nodig. Start
deze opdracht nooit als vervanging voor de volledige releaseacceptatie.

### English

After a verified `operations:restore-drill`, the same application version can
perform a real login check against only the restored test installation:

```sh
php artisan operations:accept-restored DRILL_ID --asset=ASSET_ULID --confirm-isolated-target
```

Use an existing account from the backup authorized to view the selected photo.
The command prompts for email and a hidden password. Secure automation can
provide a JSON object containing `email` and `password` through standard input
with `--credentials-stdin`. Never put passwords in command arguments, shell
history or evidence references.

The check requires completed restored installation state, the same version,
a separate PostgreSQL database ending `_restore_drill`, and previously verified
local storage outside the app, live storage and backup. It preserves the
restored application key without applying saved live database/storage settings.
Only the test database and local test storage are configured. A temporary PHP
server listens only on loopback and accepts only login, installer-lock checking
and the selected photo-detail route. No mail, external storage or workers start.

Checks cover real HTTP login, anonymous denial, authorized photo-detail access
and a locked installer. This is **not browser, Apache, S3 or rendered-preview
evidence**; the preceding restore drill verifies original bytes. Subprocesses
and temporary session/cache files are cleaned up while the restored database
and storage remain available for inspection. Success and failure append separate
evidence entries in the source installation. The drill report describes the
latest acceptance attempt; a later failure does not retain old success flags.
Errors expose only a fixed phase and possibly HTTP status, never response bodies
or credentials. An interrupted check can remain `running`; deliberately start
another check.

No new Compose mapping or permanent environment variable is required. This
command does not replace full release acceptance.

## Backup contents

- PostgreSQL database: authoritative metadata, workflow state, audit events and
  object keys.
- S3-compatible object storage: immutable originals, derivatives that should be
  retained, quarantine when operationally required, and exports.
- Deployment configuration: `docker-compose.yml`, `.env.example`, operator
  `.env` values from a secure secret store, reverse-proxy configuration and
  release/version identifiers.
- Private installation state: the complete `storage/app/installation`
  directory from the shared application storage volume. It contains the
  generated Laravel app key, installation lock/status, setup code material while
  pending, and the submitted database/storage settings after completion.
- Required secrets: database password, S3 access keys when S3 is selected,
  the generated app key inside installation state, and any future scanner or
  mail credentials. Store operator-managed secrets in a password manager or
  secret manager, not in git.

Keep at least one backup copy outside the primary VPS and outside the active
object-storage location.

## Encrypted second location

After creating a consistent local or S3/object-storage backup set, copy it to a
second location only in encrypted form. The helper below wraps an existing
backup directory in an AES-256-CBC archive with PBKDF2-SHA256, records the
encrypted checksum and keeps the original backup's `FORMAT`, `VERSION` and
`SHA256SUMS` inside the encrypted payload:

```sh
printf '%s\n' 'a long random secret from your password manager' > /private/fotoarchief-backup.key
chmod 600 /private/fotoarchief-backup.key
sh scripts/backup-copy-encrypted.sh \
  /private/backups/fotoarchief-2026-09-16 \
  /offsite/fotoarchief-2026-09-16.tar.gz.enc \
  /private/fotoarchief-backup.key
```

Restore the encrypted copy into a new empty directory before using the normal
Compose restore helper:

```sh
sh scripts/backup-restore-encrypted-copy.sh \
  /offsite/fotoarchief-2026-09-16.tar.gz.enc \
  /private/restored-backups/fotoarchief-2026-09-16 \
  /private/fotoarchief-backup.key
sh scripts/restore-compose.sh /private/restored-backups/fotoarchief-2026-09-16 --confirm-empty-target
```

The restore helper verifies the encrypted checksum before decrypting, refuses
unsafe archive entries, extracts into a new directory only and rechecks the
backup's own `SHA256SUMS`. Store the key outside the application server and
rotate it under change control. Losing the key makes the second copy unusable;
storing it beside the copy removes most of the protection.

Use retention at the destination, not by overwriting backup files. Keep enough
generations to cover accidental deletion and delayed ransomware discovery. For
S3-based archives, the second location must include the database dump,
`storage/app/installation`, deployment config and the object-storage snapshot or
sync result for originals and retained derivatives.

## Geplande versleutelde tweede kopie / Scheduled encrypted second copy

### Nederlands

Er is een uitgeschakeld voorbeeld voor een geplande tweede kopie:

- `deploy/fotoarchief-encrypted-second-backup.conf.example`
- `deploy/systemd/fotoarchief-encrypted-second-backup.service.example`
- `deploy/systemd/fotoarchief-encrypted-second-backup.timer.example`
- `scripts/backup-second-copy-scheduled.sh`

Kopieer het configuratievoorbeeld naar een private locatie, vul pas daarna
`BACKUP_PARENT_DIR`, `ENCRYPTED_COPY_DIR`, `KEY_FILE`, `LOCK_DIR`,
`RETENTION_POLICY` en eventueel `FAILURE_REPORT_COMMAND` in, en zet
`FOTOARCHIEF_SECOND_BACKUP_ENABLED=true` alleen na die keuzes. De helper maakt
eerst met `scripts/backup-compose.sh` een consistente lokale backup en maakt
daarna met `scripts/backup-copy-encrypted.sh` een versleutelde tweede kopie.
Hij gebruikt een expliciete lockdirectory en weigert een tweede run zolang die
lock bestaat. Bij een fout schrijft hij naar stderr en voert hij alleen de
operatorgekozen `FAILURE_REPORT_COMMAND` uit; er is geen standaard webhook of
bestemmingspad.

De retentie is bewust alleen een verplichte tekstuele keuze. Deze helper
verwijdert geen oude backups en voert geen brede `rm` uit op een bestemming.
Configureer retentie op de tweede locatie of voer aparte, beoordeelde
operatoropschoning uit. Bewaar de sleutel buiten de application checkout,
buiten het actieve backup/serverpad en buiten de tweede locatie. De bestaande
encryptie gebruikt AES-256-CBC met PBKDF2-SHA256 en een checksummanifest; dat is
een kopieer-/rustversleutelingshulpmiddel, geen authenticatie van een
onvertrouwde backupbron.

### English

A disabled example is available for a scheduled second copy:

- `deploy/fotoarchief-encrypted-second-backup.conf.example`
- `deploy/systemd/fotoarchief-encrypted-second-backup.service.example`
- `deploy/systemd/fotoarchief-encrypted-second-backup.timer.example`
- `scripts/backup-second-copy-scheduled.sh`

Copy the configuration example to a private location, fill in
`BACKUP_PARENT_DIR`, `ENCRYPTED_COPY_DIR`, `KEY_FILE`, `LOCK_DIR`,
`RETENTION_POLICY` and optionally `FAILURE_REPORT_COMMAND`, and set
`FOTOARCHIEF_SECOND_BACKUP_ENABLED=true` only after making those choices. The
helper first creates a consistent local backup with `scripts/backup-compose.sh`
and then creates the encrypted second copy with
`scripts/backup-copy-encrypted.sh`. It uses an explicit lock directory and
refuses a second run while that lock exists. On failure it writes to stderr and
only runs the operator-chosen `FAILURE_REPORT_COMMAND`; there is no default
webhook or destination path.

Retention is deliberately only a required textual decision. This helper does
not delete old backups and does not run broad `rm` commands against a
destination. Configure retention at the second location or run separate,
reviewed operator cleanup. Keep the key outside the application checkout,
outside the active backup/server path and outside the second location. The
existing encryption uses AES-256-CBC with PBKDF2-SHA256 plus a checksum
manifest; it is a copy/encryption-at-rest helper, not authentication for an
untrusted backup source.

## Database backup

Example local PostgreSQL dump into the persistent `postgres-backups` volume:

```powershell
docker compose exec postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc -f "/backups/fotoarchief.dump"'
docker compose cp postgres:/backups/fotoarchief.dump .\fotoarchief.dump
```

Move the copied dump to the chosen off-host backup location.

## Object backup

Use an S3-compatible tool such as `rclone`, `aws s3 sync` with a custom endpoint,
or the provider's S3-compatible client configuration. Back up from the active
private bucket or prefixes to a second location.

Required behavior:

- preserve object keys and metadata needed for verification;
- include immutable originals;
- include derivatives only when retention policy says they are not disposable;
- never make quarantine or originals public;
- record the source endpoint, bucket, prefixes and backup timestamp.
- record and verify a SHA-256 checksum for each retained object; an S3 ETag
  alone is not a portable checksum, particularly for multipart uploads;
- freeze application and external writers for the matching database,
  installation-state and object snapshot.

## Restore test steps

Run a restore drill into an empty test environment:

1. Create a fresh `.env` with test-only secrets and S3-compatible restore
   credentials.
2. Restore the application storage volume, including
   `storage/app/installation`, from the matching backup. Do not run
   `key:generate`, delete the installation directory or start a fresh wizard
   against a restored database.
3. Start PostgreSQL and Valkey:
   `docker compose up -d postgres valkey`.
4. Copy the dump into the database container:
   `docker compose cp .\fotoarchief.dump postgres:/backups/fotoarchief.dump`.
5. Confirm the destination database contains no application tables, then
   restore without destructive cleanup:
   `docker compose exec postgres sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --single-transaction --no-owner --no-acl "/backups/fotoarchief.dump"'`.
6. Restore or sync object storage into the configured private bucket/prefixes
   when the installation uses S3-compatible storage. For private local storage,
   restore the relevant `storage/app/private` contents with the same storage
   backup.
7. Start app, worker and scheduler from the same release:
   `docker compose up -d app worker scheduler`.
8. Run migrations only if the release procedure requires them for the restored
   version.
9. Verify app health, queue health, scheduler listing, representative asset
   detail pages, signed downloads and derivative rebuild behavior.
10. Document restore duration, missing objects, failed jobs and corrective
   actions.

## Resumable storage migration / Hervatbare opslagmigratie

### Nederlands

De kopieerfase slaat per bestand een receipt en bijbehorende tellingen
transactioneel op. Een herpoging verwerkt ontbrekende receipts verder;
eerder geverifieerde originelen worden niet blind opnieuw gekopieerd.
Omschakelen controleert bronbinding, originele checksum en alle afgeleiden
opnieuw, en wijzigt vervolgens de geregistreerde bestandsdisk. Pas daarna
mag bronopruiming worden gestart.

De migratie `2026_10_02_000000_add_storage_relocation_derivative_checksums`
bewaart afgeleide-checksums in de receipts. Opruimen controleert eerst alle
doelen en daarna ieder bestand nogmaals vóór verwijderen. Een failed delete
laat de migratie onafgerond; hervatten mag reeds ontbrekende bronbestanden
overslaan, maar alleen met nog steeds geldige doelchecksums. Oude receipts
krijgen checksums zolang bron én doel nog leesbaar zijn. Zonder die gegevens
weigert de toepassing opruiming; herstel de bron uit backup of onderzoek
het afwijkende doel in plaats van de controle te omzeilen.

Voer de normale forward-migraties uit. Geen nieuwe omgevingsvariabelen of
Compose-mappings zijn nodig. Houd een onafhankelijke backup buiten zowel
bron- als doelopslag; opslagmigratie is geen backup.

### English

Copying saves each file receipt and matching counts transactionally. Retry
continues missing receipts without blindly recopying previously verified
originals. Cutover rechecks the source binding, original checksum and all
derivatives, then changes the registered file disk. Only then may source
cleanup begin.

Migration `2026_10_02_000000_add_storage_relocation_derivative_checksums`
stores derivative checksums in receipts. Cleanup verifies all targets first
and rechecks each file before deleting. A failed delete leaves the migration
unfinished; retry may skip already absent source files only while target
checksums still match. Legacy receipts receive checksums while both source
and target remain readable. Without that evidence cleanup is refused:
recover the source from backup or investigate the changed target rather than
bypassing verification.

Run the normal forward migrations. No new environment variables or Compose
mappings are required. Keep an independent backup outside both source and
target storage; storage migration is not a backup.

## Configuration precedence and cache

After onboarding, the wizard-owned state overrides deployment environment values
for:

- `app.key`;
- `database.default` and the PostgreSQL connection used by the application;
- `filesystems.default`, private local disk settings and S3 settings.

Deployment environment still owns queue, cache and session settings. Defaults
are `QUEUE_CONNECTION=database`, `CACHE_STORE=file` and `SESSION_DRIVER=file`.
Redis-compatible Valkey is optional operator configuration after setup.

Do not restore or create a configuration cache that predates the installation
state. `php artisan config:cache` is disallowed while onboarding is pending, and
after completion the application must reload installation settings after cached
configuration loads. A stale cache must never supersede the restored app key,
database or storage configuration.

## Recovery checks

After restore, confirm:

- PostgreSQL row counts and migration state match expectations.
- A sample of originals has matching SHA-256 checksums.
- Private objects are not publicly accessible without a signed URL.
- Workers can retry failed ingest or derivative jobs.
- Search indexes, thumbnails, previews and other disposable outputs can be
  rebuilt from PostgreSQL plus object storage.
- The restored `storage/app/installation` state remains complete, and
  `/setup` does not reopen after redeploy.
