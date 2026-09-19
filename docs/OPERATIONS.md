# Operations

FotoArchief is operated as a self-hosted Laravel application on a managed VPS or
equivalent server, with an additional shared-hosting deployment path. The
initial topology uses PostgreSQL, private local or S3-compatible storage,
file cache/sessions and database queues. Valkey/Redis is optional for larger
deployments, not required before the onboarding wizard. Ingest records its job
in the PostgreSQL transactional outbox before returning success; the scheduler
dispatches committed rows to the configured queue connection.

## Service responsibilities

- `app`: serves the Laravel web/API runtime behind a TLS reverse proxy such as
  Caddy or Nginx.
- `worker`: waits for completed installation, then processes the dedicated
  `ingest` queue: validation, optional scanning, checksums,
  technical metadata, private derivatives, OCR, AI indexing, import/export
  work and retry recovery. These features are implemented; availability still
  depends on their documented configuration and worker prerequisites.
- `scheduler`: waits for completed installation and runs scheduled Laravel tasks. Prefer the scheduler container;
  use a host cron/systemd timer running `php artisan schedule:run` every minute
  only as a fallback.
- `postgres`: stores authoritative metadata and workflow state.
- Redis/Valkey: optional external service when selecting Redis-compatible
  queue/cache/session drivers.
- S3-compatible object storage: recommended for production originals and
  derivatives. Hetzner Object Storage is supported through the standard S3
  settings collected by onboarding; provider acceptance requires the opt-in
  real S3 test described in [ONBOARDING.md](ONBOARDING.md#real-s3hetzner-acceptance).

The first private pipeline is implemented. A running worker alone does not
prove job completion. Upload a test image and inspect its preview/status.
See [PHOTO_WORKFLOW.md](PHOTO_WORKFLOW.md) for statuses, host requirements,
limits, scanning and exact 0.3.0 operator/upgrade instructions.

System diagnostics measure background activity with persisted heartbeats. The
scheduler writes one every minute from the Laravel schedule. The worker writes a
startup heartbeat from the container entrypoint and updates the heartbeat again
when ingest jobs start, finish or fail. A missing heartbeat means activity has
not been observed by the application; it is not treated as proof that the
process is healthy.

## Routine commands

### Zoekindex en relevantie (Nederlands)

**Zoekindex beheren** toont per collectie de zichtbare foto's als actueel,
verouderd, ontbrekend, uitgesloten of mislukt. Actueel gebruikt dezelfde bron-,
revisie-, checksum- en pgvectorcontroles als semantisch zoeken. Ontbrekende
vectorondersteuning is een zichtbare fout, geen JSON- of externe fallback.
Selecteer maximaal 25 ontbrekende/verouderde foto's in een expliciete collectie
en bevestig een herstelopdracht. Provider, gevraagd model, modelruimte en actieve
generatie worden opnieuw gecontroleerd; bestaande dispatcher-, rechten- en
budgetcontroles blijven gelden. Mislukte items worden via hun taak herhaald.
Alleen eigen generatietaken zijn zichtbaar voor niet-beheerders. Een beheerder
kan een mislukte omschakeling opnieuw bevestigen: een oudere generatie kan een
nieuwere head niet overschrijven en veranderde bronnen verhinderen activatie.

Kies expliciet tekstzoeken (catalogusmetadata, geen providerverzoek) of semantisch
zoeken (ingestelde provider). Een collectiefilter wordt vóór vectorranking en
resultaatlimiet toegepast. Bevoegde beheerders/catalogusbeheerders kunnen een
getoond resultaat gedurende één uur als 0, 1 of 2 beoordelen. De ondertekende,
versleutelde resultaatreferentie bindt gebruiker, zoektekst, foto en modelruimte.
Opslaan start niet ongemerkt een nieuwe betaalde zoekopdracht. Herhaald beoordelen
wijzigt hetzelfde label en legt een nieuwe auditgebeurtenis vast. De JSONL-export
bevat maximaal 10000 eigen, nog toegankelijke labels inclusief zoekteksten:
behandel dit als interne data. Menselijke labels zijn geen automatische claim
van representatieve zoekkwaliteit.

### Search index and relevance (English)

**Manage search index** reports visible photos per collection as current, stale,
missing, excluded or failed. Currency reuses semantic search's source, revision,
checksum and pgvector checks. Missing vector support is an explicit error, not
a JSON or external fallback. Select at most 25 missing/stale photos within an
explicit collection and confirm repair. Provider, requested model, model space
and active generation are rechecked; existing dispatch, permission and budget
checks still apply. Retry failed items through their task. Non-administrators
see only their own generation tasks. An administrator can confirm another switch
attempt: an older generation cannot replace a newer head, and changed sources
prevent activation.

Choose text search explicitly (catalogue metadata, no provider request) or
semantic search (configured provider). Collection eligibility is applied before
vector ranking and result limits. Authorized administrators/catalogue managers
can grade a displayed result 0, 1 or 2 for one hour. An authenticated encrypted
result receipt binds user, query, photo and model space. Saving does not silently
repeat a paid search. Regrading updates the same label and records a new audit
event. JSONL export contains at most 10000 own, still-accessible labels including
queries: treat it as internal data. Human labels do not automatically establish
representative search quality.

### Taakwerkbank en auditlog (Nederlands)

Open een taak via het taaknummer: de detailpagina toont de opgeslagen,
expliciet toegestane instellingen, tijdstippen, workerclaims (niet uitsluitend
providerpogingen), gepagineerde AI-fotoselectie en gebeurtenissen. De laatste
itemgebeurtenis bepaalt de uitkomst; een latere geslaagde poging vervangt een
eerdere fout. Geannuleerde, nog niet verwerkte items zijn overgeslagen.
Onderhoudstaken zonder opgeslagen fotoselectie tonen hun instellingen en
gebeurtenissen, niet een verzonnen lijst van fotoresultaten.

Beheerders kunnen maximaal 25 geselecteerde, laatst mislukte AI-items uit een
afgeronde taak bevestigen en opnieuw starten. Dit maakt een nieuwe taak met
een verwijzing naar de oorspronkelijke taak; successen en niet-geselecteerde
items worden niet herhaald. Dezelfde items kunnen niet opnieuw vanuit de
oudertaak worden gestart: gebruik bij een volgende fout de vervolgtaak.
Een indexvervolgtaak krijgt geen kopie van de generatie van de oudertaak.
Normale bron-, toestemmings-, provider- en budgetcontroles blijven gelden.

Pauzeren houdt een lopende workerclaim vast totdat veilig kan worden gestopt:
AI en integriteitscontrole tussen items, opslagkopie tussen batches van
maximaal 25 bestanden. Een extern verzoek wordt niet afgebroken. Hervatten
behoudt cursor en tellers; volledig afgerond werk blijft voltooid. Andere
opruimtaken bieden bewust geen pauzeknop zonder veilig checkpoint.

Het auditlog vereist `audit.view` en doorzoekt foto- en taakgebeurtenissen op
foto-ID/archiefnummer, taak, gebruiker (actor of taakaanvrager), exact eventtype
en datumbereik. JSONL-export is begrensd op 10000 gebeurtenissen en bevat
uitsluitend ID's, eventtype en tijdstip; vrije tekst, technische context en
foto-inhoud zijn uitgesloten. Beperk de filters bij een te grote export.
De migratie voegt een pauzevlag toe; geen Compose- of omgevingswijziging nodig.

### Task workbench and audit log (English)

Open a task by its number: the detail page shows explicitly allowlisted saved
settings, timestamps, worker claims (not exclusively provider attempts),
paginated AI photo selection and events. The latest item event determines its
outcome; a later success supersedes an earlier failure. Unprocessed items in a
cancelled task are skipped. Maintenance tasks without a saved photo selection
show their settings and events, not a fabricated list of photo outcomes.

Administrators can confirm and retry at most 25 selected, latest-failed AI
items from a finished task. This creates a new task referencing the original;
successes and unselected items are not repeated. The same items cannot be
started again from the parent: use the follow-up task after another failure.
An index follow-up does not inherit the parent's generation.
Normal source, authorization, provider and budget checks still apply.

Pause retains an in-flight worker claim until a safe checkpoint: between items
for AI/integrity, between batches of at most 25 files for storage copy. It does
not abort an external request. Resume preserves cursor and counters; fully
finished work remains completed. Other cleanup tasks deliberately have no
pause control without a safe checkpoint.

The audit log requires `audit.view` and searches photo/task events by photo
ID/accession, task, user (actor or requester), exact event type and date range.
JSONL export is limited to 10000 events and contains only identifiers, event
type and timestamp; free text, technical context and photo content are excluded.
Narrow filters if the export is too large. The migration adds a pause flag;
no Compose or environment changes are required.

```powershell
docker compose ps
docker compose logs --tail=100 app
docker compose logs --tail=100 worker
docker compose logs --tail=100 scheduler
docker compose exec app php artisan about --only=environment
docker compose exec app php artisan queue:failed
docker compose exec scheduler php artisan schedule:list
docker compose exec scheduler php artisan operations:heartbeat scheduler
docker compose exec scheduler php artisan operations:check-alerts --dry-run
sh scripts/backup-copy-encrypted.sh /private/backups/latest /offsite/fotoarchief-latest.tar.gz.enc /private/fotoarchief-backup.key
```

## Operational alerts

### Installatie, upgrade en herstel / Installation, upgrade and recovery

**Nederlands.** Beheerders gebruiken `/admin/operations/recovery` voor expliciet
bevestigde diagnosecontroles en blijvende verslagen. De installatiecontrole
hergebruikt de bestaande probes (PHP 8.5+, extensies, opslag, database, limieten,
scanner en achtergrondactiviteit); dit is geen bewezen login/uploadflow.
De upgradevoorcontrole verlangt een hogere doelversie, een operatorinschatting
van benodigde vrije bytes, geen lokale openstaande migraties of actieve taken
(ook gepauzeerde taken tellen), een checksumcontrole van een backup van deze
versie binnen 24 uur en een geslaagde gegevensherstelproef binnen 30 dagen.
Vrije ruimte betreft het app-opslagbestandssysteem, **niet** een afzonderlijke
database- of backupdisk. Migraties uit een nog niet geinstalleerde doelrelease,
externe proxygrenzen, offsite-opslag en de volledige applicatiewerking moeten
apart worden beoordeeld. De controle voert geen upgrade uit.

**English.** Administrators use `/admin/operations/recovery` for explicitly
confirmed diagnostic probes and persistent reports. Installation reuses the
existing probes (PHP 8.5+, extensions, storage, database, limits, scanner and
background activity); this does not prove a login/upload flow. Upgrade preflight
requires a higher target version, operator-estimated free bytes, no pending local
migrations or active tasks (including paused tasks), a checksum-verified backup
of this version within 24 hours and a successful data restore within 30 days.
Space covers the app storage filesystem, **not** a separate database/backup disk.
Uninstalled target-release migrations, external proxy limits, offsite storage and
full application behaviour require separate review. No upgrade is executed.

```sh
php artisan operations:register-backup /private/backup
php artisan operations:restore-drill BACKUP_ID \
  --database=archive_restore_drill \
  --directory=/private/new-drill --confirm-empty-target
```

**Nederlands.** Gebruik uitsluitend vertrouwde lokale backups uit
`scripts/backup-compose.sh` met `database.dump`, `storage-app.tar`, `VERSION`,
`FORMAT` en `SHA256SUMS`. Registratie controleert de vier checksums en bewaart
versie, locatie, omvang en manifesthash; dit bewijst geen authenticiteit of
herstelbaarheid. De herstelproef vereist dezelfde appversie, PostgreSQL,
een compatibele `pg_restore` op PATH, het bestaande extractiescript en voldoende
ruimte. Voer de opdracht uit in een beheeromgeving met toegang tot de
appinstallatie, database en backupbestanden; de appimage bevat PostgreSQL-client
16. Kopieer of mount backups in die beheeromgeving en gebruik voor behouden
proefbestanden een aparte persistente map buiten de live-opslag. Maak vooraf
een aparte lege database op dezelfde server met
dezelfde credentials en achtervoegsel `_restore_drill`. Kies een nieuwe absolute
map buiten app, live-opslag en backup. De opdracht weigert bestaande objecten en
bestanden, gebruikt geen `--clean`, start geen server/worker en vernieuwt geen
sleutels. Elke lokale origineelrij wordt op bytes en SHA256 gecontroleerd;
niet-lokale opslag faalt expliciet. Een blijvend verslag vermeldt geslaagd/mislukt
en het controlebereik. Login, previews, externe opslag en een echte volledige
installatie blijven onbewezen. Doelen blijven ook na fouten staan: inspecteer en
verwijder alleen de expliciete proefdatabase/-map. De live-database bewaart het
proefverslag.

**English.** Only use trusted local backups from `scripts/backup-compose.sh`
containing `database.dump`, `storage-app.tar`, `VERSION`, `FORMAT` and `SHA256SUMS`.
Registration verifies four checksums and records version, location, size and
manifest hash, not authenticity or recoverability. Drills require the same app
version, PostgreSQL, a compatible `pg_restore` on PATH, the existing extraction
script and sufficient space. Run in an administration environment with access
to the app installation, database and backup files; the app image includes
PostgreSQL client 16. Copy or mount backups into that administration environment
and use a separate persistent directory outside live storage for retained trial
files. Pre-create a separate empty database on the
same server using the same credentials, ending `_restore_drill`, and choose a new
absolute directory outside the app, live storage and backup. Existing objects and
files are refused; there is no `--clean`, server/worker startup or key
regeneration. Every local original row is checked for size and SHA256; non-local
storage fails explicitly. A persistent report records success/failure and scope.
Login, previews, external storage and a complete working installation remain
unproven. Targets remain after failure: inspect and remove only the explicit test
database/directory. The live database retains the drill report.

### Incidentdeduplicatie / Incident deduplication

**Nederlands.** Nieuwe, heropende en in ernst gewijzigde incidenten krijgen een
nieuw event-ID. Ongewijzigde succesvol verzonden meldingen worden niet herhaald.
Ontvangstbevestiging door een beheerder lost niets op en onderdrukt geen herstel.
Wanneer een incident niet langer aan de ingestelde ernstgrens voldoet, volgt
`state=resolved`; dat betekent niet dat alle diagnostiek perfect is.
De webhook bevat per incident `state` (`open`/`resolved`) en `event_id`.
Identificaties worden voor transport duurzaam opgeslagen; transportfouten blijven
pending en worden met hetzelfde ID herhaald. De ontvanger moet zelf dedupliceren:
een onduidelijk netwerkantwoord kan dubbele bezorging veroorzaken. Het register
bewaart de laatste toestand per incident, geen volledige historische tijdlijn.
Zonder webhook blijven meldingen pending en worden ze lokaal gelogd; een later
herstel kan dan de nog niet verstuurde openmelding vervangen.

**English.** New, reopened and severity-changed incidents receive a new event ID.
Unchanged successfully delivered notifications are not resent. Administrator
acknowledgement neither resolves an incident nor suppresses recovery. Once an
incident no longer meets the configured severity threshold, `state=resolved` is
sent; this does not imply perfect diagnostic health. Each webhook incident carries
`state` (`open`/`resolved`) and `event_id`. IDs are committed before transport;
failed deliveries remain pending and retry with the same ID. Receivers must
deduplicate because ambiguous network responses may cause duplicate delivery.
The register retains the latest state per incident, not a complete historical
timeline. Without a webhook, pending alerts are logged locally; later recovery
may replace an open notification that was never delivered.

FotoArchief evaluates the same diagnostics used by the operations page through
`php artisan operations:check-alerts`. The scheduler runs this hourly. Alerts
are disabled by default; when incidents are found while disabled, the command
writes a structured warning to the application log instead of calling any
external service.

Configure a webhook only with an operator-owned endpoint:

```text
OPERATIONS_ALERTS_ENABLED=true
OPERATIONS_ALERT_WEBHOOK_URL=https://ops.example.invalid/fotoarchief
OPERATIONS_ALERT_MINIMUM_SEVERITY=warning
OPERATIONS_ALERT_FAILED_INGEST_THRESHOLD=5
OPERATIONS_ALERT_PENDING_INGEST_THRESHOLD=100
```

The payload contains the application name, environment, timestamp,
overall status and incident summaries. It does not include credentials, `.env`
contents, installation state or image metadata. Use `--dry-run` after changing
thresholds or routing so the payload can be inspected without sending a
notification.

## Upload limits

The default contractual upload ceiling is:

- `APP_MAX_UPLOAD_MB=100`
- `APP_MAX_UPLOAD_BYTES=104857600`
- `APP_UPLOAD_TIMEOUT_SECONDS=300`
- Ingest job timeout: 120 seconds; queue visibility: 180 seconds.
  The older `APP_WORKER_JOB_TIMEOUT_SECONDS` example does not alter ingest.

If an operator raises any size, rate or timeout limit, the same concrete value
must be allowed at every layer in front of or inside the app: reverse proxy,
CDN if present, PHP/web server, Laravel validation, temporary storage and worker
runtime. Do not describe the requirement as one proxy-specific directive; state
the numeric limit the deployment must accept.

## Dagelijkse meldingen, incidenttijdlijn en support / Daily operational evidence

### Nederlands

`Mijn meldingen` toont uitsluitend eigen afgeronde, mislukte, geannuleerde en
gepauzeerde taken, met 25 resultaten per pagina. Gelezen-status wordt per account
en taakversie bewaard. Een nieuwe poging of gewijzigde status wordt weer
ongelezen; een verouderde leesbevestiging wordt geweigerd. Dit is een inbox in de
app, geen e-mail- of pushabonnement, en verleent geen extra rechten.

Beheerders openen vanuit de herstelpagina de incidenttijdlijn: openen,
heropenen, ernstwijziging, erkenning, herstel en echte afleverpogingen. Gewone
herhaalde waarnemingen en dubbele erkenningen maken geen kunstmatige events.
Aflevering en retry behouden het notificatie-ID voor ontdubbeling. De tijdlijn
begint bij deze migratie; ontbrekende historische events worden niet verzonnen.

Onder `Acceptatiebewijs en support` selecteert de beheerder maximaal 25 taken,
25 incidenten en 10 controles voor een expliciet bevestigde JSON-download.
De pagina biedt de recentste 25/25/10 records. De export bevat alleen versie,
IDs, toegestane vaste statussen en tellers; geen foto-inhoud, namen, bestandspaden,
vrije foutdetails, providerconfiguratie of geheimen. Onbekende statussen worden
geweigerd, onbekende controletypes niet opgenomen. Er wordt niets automatisch
naar een supportdienst gestuurd. Bewaar en deel de download bewust.

Voer de normale forward-migraties uit. Er zijn geen nieuwe omgevingsvariabelen
of Compose-mappings. Bestaande deployments vereisen geen handmatige configuratie.
Zie [herstelacceptatie](BACKUP_RESTORE.md#optionele-http-herstelacceptatie--optional-http-restore-acceptance)
en het [bewijsregister](RELEASE_ACCEPTANCE.md#acceptatiebewijsregister--acceptance-evidence-register).

### English

`My notifications` shows only your own completed, failed, cancelled and paused
tasks, with 25 results per page. Read receipts are stored per account and task
version. A new attempt or changed status becomes unread again; stale read
acknowledgements are refused. This is an in-app inbox, not an email/push
subscription, and grants no additional permissions.

Administrators open the incident timeline from recovery: opening, reopening,
severity changes, acknowledgement, recovery and actual delivery attempts.
Repeated observations and acknowledgements do not invent additional events.
Delivery and retry retain their notification ID for receiver deduplication.
The timeline starts with this migration; missing historical events are not
fabricated.

Under `Acceptance evidence and support`, administrators select at most 25 tasks,
25 incidents and 10 checks for an explicitly confirmed JSON download. The page
offers the latest 25/25/10 records. Export contains only version, IDs, allowlisted
fixed statuses and counters, never photo content, names, paths, free-form errors,
provider configuration or secrets. Unknown statuses are refused and unknown
check types omitted. Nothing is automatically sent to a support service.
Store and share the download deliberately.

Apply the normal forward migrations. No new environment variables or Compose
mappings are needed; existing deployments need no manual configuration edits.
See [restore acceptance](BACKUP_RESTORE.md#optionele-http-herstelacceptatie--optional-http-restore-acceptance)
and the [evidence register](RELEASE_ACCEPTANCE.md#acceptatiebewijsregister--acceptance-evidence-register).

## Background archive operations

Every expensive archive operation runs on the dedicated database `ingest` queue,
never inside an HTTP request. Re-hashing originals, rebuilding derivatives,
copying between storage disks, purging trashed assets and cleaning orphan
quarantine uploads are started from `/admin/operations/...`, which only records
an `operation_runs` row and pushes a job; the worker performs the work.

- Status, progress, error message and retry are visible at
  `/admin/operations/runs` and in the panel on each operations page.
- A run is processed in bounded chunks of 25 items. Each chunk is a separate
  job that re-dispatches the remainder, so no single job approaches the worker
  timeout regardless of archive size.
- Job timeout is 120 seconds, equal to the worker timeout and strictly below the
  `ingest` queue visibility of 180 seconds. A per-job timeout overrides the
  worker `--timeout`, so it must never be raised above 120 without raising the
  worker timeout and the visibility window together.
- A failed run keeps its cursor. Retrying from the UI resumes where it stopped
  instead of restarting; retry requires `users.manage`.
- A crashed worker does not strand a run: a `running` row older than the job
  timeout plus 60 seconds is reclaimed by the next delivery.
- Storage cutover is the one operation that stays in the request: it only flips
  already-verified database references and moves no bytes.

Without a running worker these operations stay queued and nothing happens. Check
`docker compose logs --tail=100 worker` and `/admin/operations/runs` together.
## Reaching the operations pages

The global header carries one `Operaties` link, into system diagnostics, shown to
users holding `users.manage`. Every operations page then renders the same
`Archiefbewerkingen` menu, so duplicates, processing, integrity, storage
migration, trash, OCR, background runs and the photo overview are all one tap
apart. Menu entries are rendered only when the signed-in user may open them.

Two operations belong to a single photo and live on its detail page under
`Archiefbewerkingen`: starting text recognition (OCR) and moving the photo to the
trash. File versions and the processing log for each upload are linked from the
same page. File-version pages follow the asset ownership policy, exactly like the
photo detail page they are reached from.

Operator note: a user with `catalogue.manage` or `assets.update` but without
`users.manage` sees no `Operaties` link in the header and reaches the module only
through a photo detail page. Grant `users.manage`, or add a header entry, if
archivists should reach the module directly.

The interface is checked at a 390 px viewport. Wide tables scroll inside their own
container so the page itself never scrolls sideways.
## Reliability of background operations

The timing values below are a set, not independent knobs. Changing one without
the others reintroduces a failure that is silent in production:

- Worker timeout **120s** (`--timeout`), and each job declares the same value.
  A per-job timeout overrides the worker's, so it must never be higher.
- Stale-claim window **150s**. A run whose claim is older than this may be taken
  over, because no worker can still be running the chunk.
- Ingest `retry_after` **180s**. The queue redelivers a reserved message after
  this long.

The window must sit strictly between the other two. If it equalled `retry_after`,
a redelivery could arrive while the row was not yet stale, fail to claim, and be
dropped -- leaving the run "running" with nothing left to retry it.

A delivery that cannot take the claim is **released back to the queue**, not
deleted, while the run is unfinished. Deleting it is what strands an operation:
if the holder was killed between claiming and finishing, its own redelivery is
the only thing that can resume the run.

### Size limits

One job handles 25 items and then re-dispatches the remainder, so any run is made
of jobs that each finish well inside the timeout. The chain is bounded at 2500
jobs, which covers **62 500 items** -- a 50 000-file maintenance run completes in
one go.

A copy chunk is bounded by bytes as well as by items (256 MB), because twenty-five
100 MB originals do not copy inside 120s on a slow target disk. Copies are
idempotent: a file already copied and checksum-verified for that migration is
skipped rather than copied and counted twice.

Reaching a bound is **never reported as success**. The run is marked failed, with
`result.resume_cursor` recording the last processed file, and starting it again
continues from there. A run that stops making progress is stopped the same way
rather than chaining forever.

## Checking that OCR really works

`php artisan operations:ocr-smoke` runs the configured Tesseract binary against a
generated image and fails with a non-zero exit code if the binary is missing, the
language data is absent, or no text comes back. Configuration alone proves
nothing: a container can carry the right environment variables and have no binary
at all. Run it in the image build or in CI, where a missing binary stops the
pipeline instead of reaching an operator.

Use `--language=nld` to verify the Dutch language data specifically.

The command ships inside the image (it lives in `app/`, which the Dockerfile
copies; only `tests/` is excluded), so nothing has to be copied into a running
container:

```
docker compose exec --user www-data worker php artisan operations:ocr-smoke --language=nld
```

Run it against the **worker** container, because that is where the binary is
installed. Running it against the web container proves nothing about the
container that will actually do the work.

### Proving the whole path, not just the binary

```
docker compose exec --user www-data app php artisan operations:ocr-smoke --queued --wait=90
```

`--queued` skips the local binary probe entirely and instead creates a synthetic
dossier, pushes a real `ProcessAssetOcrJob` onto the ingest queue, and waits for
the worker to write the text back. It therefore proves the deployment an operator
depends on: the queue connection, the running worker, its Tesseract binary and its
language data. It is the mode to run against the **app** container, which may have
no binary of its own.

It runs against the live onboarded database on purpose -- a separate test database
would prove the schema and not the deployment. Consequently:

- It **never** resets, migrates or re-onboards anything. It only inserts its own
  synthetic rows.
- Everything it creates carries an `OCR-SMOKE-` accession number and is removed
  again in a `finally` block, including the stored image, so a failure or a
  timeout still leaves no dossier behind.
- Do **not** set `APP_ENV=testing` for it. It must run in the application's own
  environment, against the real configuration; forcing a testing environment would
  point it at other credentials and prove nothing about the deployment.

### What the test suite can and cannot prove

The suite covers the smoke command's own success path: a stub executable stands in
for Tesseract, answers `--version` and `--list-langs`, and returns known text. That
proves the parts this repository owns -- argument construction, the language check,
output parsing, the queued job writing machine text, and the diagnostics report --
so a failure in the real pipeline points at the image rather than at this code.

What it cannot prove is recognition itself. Whether the packaged binary reads an
actual scan, and whether the Dutch language data is present, is a property of the
image and is only established by running the command above against a real container.
### Which account runs it

The queued smoke test must run as the **runtime account**, not as root:

```
docker compose exec --user www-data app php artisan operations:ocr-smoke --queued --wait=90
docker compose exec --user www-data worker php artisan operations:ocr-smoke --language=nld
```

Stored files are private: Laravel creates directories `0700` and files `0600`, owned
by whoever wrote them. `deploy/entrypoint.sh` runs the worker and artisan under
`gosu www-data`, but `docker compose exec` bypasses the entrypoint and uses the image
default, **root**. A fixture written by root lands in a directory the worker cannot
enter, so the job reports the file as missing although it is plainly there — a
failure that reads like a broken engine and is not one.

The command refuses to run as the wrong account and names the right one. It does not
take ownership of the fixture to make itself work: that would let the acceptance pass
under an account no real request ever uses, proving nothing about the runtime that
actually serves files. Matching the runtime is the property being tested.
### Environment the worker needs

OCR is off unless it is switched on, and the setting has to reach the **worker**
container, not only the web one. Both read the same names:

| Variable | Default | Meaning |
|---|---|---|
| `OCR_ENABLED` | `false` | Master switch. While false, OCR records are written with status `disabled` and no binary is ever invoked. |
| `OCR_BINARY` | `tesseract` | Path or name of the executable. |
| `OCR_LANGUAGES` | `nld+eng` | Languages passed to `-l`. The image must carry the matching language data. |

A worker started without `OCR_ENABLED=true` will accept the job and record it as
disabled, which the queued smoke test reports as a failure rather than a pass.
## Which file a dossier currently serves

A dossier keeps every original it has ever received: an improved scan is added,
never written over the previous one. Exactly one of those files is the one the
archive currently serves, and any reader outside this module — the public
catalogue, the viewer, media and IIIF endpoints, exports — must select it
explicitly. Taking the first retained file of a dossier is wrong, because the
oldest withdrawn scan is a retained file too.

The contract:

- `asset_files.is_primary = true` marks the file the dossier currently serves.
  A dossier has **at most one**; this is enforced by the partial unique index
  `asset_files_single_primary_per_asset`, not merely by convention, so a reader
  may rely on it without defending against duplicates.
- `asset_versions.is_current = true` marks the matching version row, and always
  points at the same file as the primary flag. The two move together inside one
  transaction.
- A file that is not primary is retained history. It must never be served
  publicly, and it must never be reachable through a viewer, media or IIIF route
  by id alone.
- Files ingested before version tracking existed have been given a version row
  by the repair migration, so joining through `asset_versions` no longer loses
  them. Readers may use either flag; `is_primary` is the cheaper one.

### When a review becomes stale

`assets.lock_version` is the dossier revision the metadata form validates
against. It is incremented whenever the served bytes change, which invalidates
any open review rather than letting it be saved over a file that is no longer
the one being served:

- activating a different version (operations),
- rebuilding derivatives for a file (operations),
- ingesting a replacement scan that takes over as primary (ingest),
- merging a duplicate dossier (operations).

A consumer that caches rendered output should treat `lock_version` as the cache
key for a dossier; a bump means the previous rendition is withdrawn.
## Who may see which dossier

Every archive operation is authorised against the dossier it touches, not against
a global permission, because a global permission would let any account holding
`assets.view` read a private dossier belonging to someone else.

- Reading a dossier requires `assets.view` **and** either owning it or holding
  `assets.publish`. `assets.publish` is a role permission that widens private
  read access; it is not a published status on the dossier.
- Changing a dossier additionally requires `assets.update`; uploading a new file
  version additionally requires `assets.create`.
- OCR listing, searching, viewing, correcting and dispatching are scoped the same
  way. A search never returns text from a dossier the actor may not open, and rows
  whose dossier is trashed or gone are excluded everywhere.
- Dispatching OCR writes machine text onto the dossier, so it requires the update
  permission rather than only the read permission.
## Security and storage posture

- Keep originals, quarantine and private derivatives out of public buckets.
- Use short-lived signed URLs or authorised application endpoints for access.
- Treat image files and metadata as untrusted input until validation and scan
  jobs have completed.
- Keep database passwords, S3 keys and `APP_KEY` outside git.
- Rotate credentials after suspected exposure or before a public release.

## Deployment checklist

1. Pull the intended release on the VPS.
2. Review operator changes listed in `docs/DEPLOYMENT_LOCAL.md`.
3. Ensure `.env` contains safe production values and no placeholder remains.
4. Run `docker compose pull` when using published images, or
   `docker compose build` when building locally.
5. Start the database dependency: `docker compose up -d postgres`.
6. Start application services: `docker compose up -d app worker scheduler`.
7. On first installation, complete `/setup` using the private code from
   `docker compose exec --user www-data app php artisan installation:prepare`.
   The wizard migrates the empty database and creates the first administrator.
   On upgrades, run migrations according to the release notes instead.
8. Verify health, queue and scheduler status.
9. Confirm backups complete after the release.

## Incident response

- Web unavailable: check reverse proxy, `app` health, container logs and
  database connectivity.
- Ingest stalled: inspect `worker` logs, failed jobs and object-storage
  permissions.
- Scheduler stale: confirm `scheduler` is running or that the host cron/systemd
  fallback executes once per minute.
- Storage errors: verify S3 endpoint, region, bucket, credentials, private
  prefixes and network access, or local private-directory ownership and space.
- Installer incomplete: workers waiting for readiness is expected. Follow
  [ONBOARDING.md](ONBOARDING.md); do not delete persistent state to retry.
- Restore required: follow `docs/BACKUP_RESTORE.md` and restore into a test
  target first when time allows.
