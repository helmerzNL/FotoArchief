# Private fotoworkflow

De applicatie levert een volledige **private** fotoketen na de onboarding:
uploaden, achtergrondverwerking, overzicht, voorbeelden, metadata, rechten
en revisiehistorie. Dit document beschrijft die keten. Zie daarnaast de
[publieke portal](PUBLIC_PORTAL.md), [import/export](DATA_EXCHANGE.md) en
[archiefoperaties met optionele OCR](OPERATIONS.md).

## Gebruik

1. Installeer via `/setup`, log in en open **Foto's**.
2. Kies bestanden of sleep ze naar het uploadvak. JPEG, PNG en WebP worden
   ondersteund; TIFF, SVG en PDF niet. Met JavaScript gaan maximaal 250
   geselecteerde bestanden afzonderlijk naar de server, met voortgang en
   een resultaat per bestand. Een fout stopt de andere bestanden niet.
3. Open **Bekijk verwerking**. Ontvangen betekent `queued`, niet verwerkt.
   Laat de worker draaien en gebruik **Status vernieuwen**.
4. Bij `completed` verschijnen private JPEG-voorbeelden van maximaal 300,
   1200 en 2000 pixels aan de langste zijde. Kleine beelden worden niet vergroot.
5. Bewerk titel, beschrijving, datering, tags, rechthebbende en rechtenbewijs.
   Jaar en decennium worden volledige kalenderbereiken. Datums in de historie
   blijven `YYYY-MM-DD`, zonder tijdzoneconversie.

Opslaan verhoogt de revisie en bewaart voor/na-waarden van metadata, tags en
rechten. Een formulier van een oudere revisie kan een nieuwe wijziging niet
overschrijven: laad de actuele foto en vergelijk voordat je opnieuw opslaat.
De detailpagina toont de laatste 50 gebeurtenissen; oudere gebeurtenissen
blijven bewaard in PostgreSQL.

### Hervatbare uploadbatches / Resumable upload batches

**Nederlands.** Open **Foto's → Hervatbare uploadbatches**. De selectie wordt
voor verzending gecontroleerd op extensie, browser-MIME, omvang en dubbele
SHA-256-inhoud. Dit vervangt nooit de servervalidatie of ClamAV. Ontvangstbewijzen
zijn alleen zichtbaar voor de uploader met actuele upload- en leesrechten.
Een willekeurige, lokaal bewaarde batchsleutel maakt opnieuw aanbieden na een
verloren antwoord idempotent; dezelfde sleutel met andere inhoud wordt geweigerd.
Na refresh/login selecteer je dezelfde lokale bestanden opnieuw. Er worden
alleen ontbrekende delen verstuurd; reeds ontvangen posities mogen uitsluitend
met dezelfde inhoud herhaald worden.

De nieuwe route heeft vaste bovengrenzen: **250 bestanden**, **104857600 bytes
per bestand**, **1073741824 bytes per batch**, **4194304 bytes per deel**,
**vier niet-opgeruimde batches per gebruiker**, **zeven dagen hervatbaarheid**.
Lagere ingestlimieten blijven gelden. Proxies voor deze route moeten minstens
**5242880 bytes per multipart-request** toelaten (deel plus overhead); dit
verlaagt de bestaande limieten voor de gewone upload niet.
PHP moet voor deze route minstens **4194304 bytes per bestand** toelaten.
Er geldt een limiet van **600 deelrequests per minuut per gebruiker**.
De browser wacht
**60 seconden per request**; bij een onbekende uitkomst hervat je via de
ontvangstbewijzen, niet via een nieuwe gewone upload.

Samenvoegen, volledige SHA-256-controle en formaatvalidatie draaien op de
bestaande `ingest`-worker, gevolgd door de normale scan/derivatenketen.
Een ontvangstreferentie is geen bewijs van geslaagde verwerking of scan.
De batch toont ontvangen, wachtend, verwerkt, afgewezen en herstelbare fouten
per bestand. Alleen technische fouten mogen opnieuw; geweigerde inhoud niet.
Afsluiten kan pas nadat ieder bestand ontvangen of definitief afgewezen is.
De afzonderlijke fotoketen kan daarna nog lopen.

Delen staan privaat op de **local**-disk, ook bij S3-originelen: web en worker
moeten dezelfde persistente lokale opslag delen. Reken maximaal **4 GiB
tijdelijke delen per gebruiker**, plus **100 MiB assemblageruimte per worker**
en de bestaande quarantine/original/derivatenruimte. Deze quota gelden niet
als globaal archiefquotum. `php artisan uploads:prune` verwijdert per uitvoering
maximaal **100** afgesloten/verlopen deelmappen; ontvangstbewijzen blijven.
De scheduler voert dit ieder uur uit. Zonder scheduler moet de operator dit
inplannen; ongepurgeerde verlopen batches blijven meetellen zodat vergeten
opruiming geen onbegrensde tijdelijke opslag toestaat. Er zijn geen nieuwe
environmentvariabelen of Compose-mappings.

**English.** Open **Photos → Resumable upload batches**. Before transmission,
the browser checks extension, browser MIME, size and duplicate SHA-256 content;
server validation and ClamAV remain authoritative. Receipts are private to
their uploader with current upload/read permissions. A locally retained random
batch key makes retry after a lost response idempotent; different content with
the same key is rejected. Reselect the same local files after refresh/login.
Only missing chunks are sent; repeated positions must have identical content.

Limits are **250 files**, **104857600 bytes per file**, **1073741824 bytes per
batch**, **4194304 bytes per chunk**, **four unpurged batches per user** and
**seven days to resume**. Lower ingest limits still apply. Front proxies must
allow at least **5242880 bytes per multipart request**; existing ordinary-upload
limits are unchanged. PHP must accept at least **4194304 bytes per file**.
Chunk requests are limited to **600 per minute per user**.
Each browser request has a **60-second** timeout; recover
unknown outcomes using receipts rather than a new ordinary upload.

The existing `ingest` worker assembles and verifies the full hash/format before
normal scanning and derivatives. Receipt is not proof of processing or scan
success. Per-file states distinguish waiting, received, processed, rejected and
recoverable failures. Only technical failures may retry. Closing requires all
files received or permanently rejected; photo processing may still continue.

Chunks use private **local** storage even with S3 originals. Web and worker must
share that persistent disk. Budget **4 GiB of chunks per user**, **100 MiB of
assembly space per worker**, plus existing quarantine/original/derivative space.
This is not a global archive quota. The hourly scheduler runs
`php artisan uploads:prune`, removing at most **100** closed/expired chunk
directories per invocation while retaining receipts. Without a scheduler,
schedule that command yourself. Expired unpurged batches still consume quota.
No new environment variables or Compose mappings are required.

### Autorisatie

Beheerders, archivarissen en redacteuren kunnen alle foto's zien en bewerken.
Vrijwilligers zien/bewerken alleen hun eigen foto's. Een viewer kan uitsluitend
eigen toegewezen foto's lezen, niet uploaden/bewerken. Deze regels gelden ook
voor directe detail-, media- en retry-URL's, niet alleen voor de overzichtslijst.
Foto's zonder eigenaar uit de oude foundation zijn alleen zichtbaar voor
rollen met `assets.publish`. Die permissie geeft in deze versie archiefbrede
toegang. Publiceren vereist daarnaast de afzonderlijke publicatiereview.

### Verwerking en fouten

- HTTP controleert formaat/omvang en schrijft een willekeurige private
  quarantainesleutel. De uploadregistratie, databasejob en acceptatie-audit
  worden in dezelfde databasetransactie opgeslagen.
- De worker streamt het bestand naar begrensde tijdelijke opslag, scant indien
  geconfigureerd, berekent SHA-256 en controleert afmetingen en decodeerbaarheid.
- Een reeds bekende checksum wordt permanent afgewezen zonder de identiteit
  van een andere foto bekend te maken.
- Het origineel blijft byte-voor-byte ongewijzigd en privaat. De worker past
  JPEG-EXIF-orientatie toe op voorbeelden. EXIF/GPS wordt niet naar voorbeelden
  gekopieerd; het origineel kan die informatie dus nog wel bevatten.
- `rejected` betekent blijvend ongeldig, te groot, dubbel of besmet: upload
  alleen een gecorrigeerd bestand. Deze status heeft geen verwerkingsretry.
- Technische fouten krijgen maximaal drie workerpogingen. Daarna wordt de
  upload `failed`; herstel de oorzaak en gebruik **Opnieuw verwerken**.
  Een `running` upload die langer dan vier minuten vastzit mag ook opnieuw.
  Actieve en voltooide uploads worden niet opnieuw ingepland.
- Bij een browsernetwerkfout: controleer eerst het archief voordat je opnieuw
  verstuurt. De server kan het bestand al ontvangen hebben.
- Afgewezen/mislukte originelen blijven private quarantineobjecten. Gebruik de
  begrensde opruim-, bewaartermijn- en purgeprocessen in
  [Operaties](OPERATIONS.md); verwijder bestanden niet handmatig.

## Worker: PHP-webhosting en Docker

De eerste ingest gebruikt altijd de aparte **databaseverbinding `ingest`**
voor de Laravel-queue, op dezelfde PostgreSQL-database als het archief.
Hierdoor is acceptatie plus jobopslag atomair. `QUEUE_CONNECTION=redis`
verplaatst deze ingestjobs niet naar Redis. Dit is een bewuste, beperkte
afwijking van de toekomstige productiearchitectuur met Redis/Valkey-workers;
een latere overgang vereist een betrouwbare outbox, niet los dispatchen.

Continu, na installatie:

```bash
php artisan queue:work ingest --sleep=3 --tries=3 --timeout=120
```

Voor webhosting met cron kan een begrensde drain worden gebruikt:

```bash
php artisan queue:work ingest --stop-when-empty --max-time=50 --tries=3 --timeout=120
```

Plan die bijvoorbeeld elke minuut, **met een proceslock tegen overlap**. De
50 seconden zijn een tussen-jobsgrens: een lopende job kan langer duren.
De host moet maximaal 120 seconden per job daadwerkelijk kunnen afdwingen
(Laravel gebruikt daarvoor PCNTL op Linux), voldoende geheugen en tijdelijke
schijfruimte bieden. Een host met alleen korte HTTP-verzoeken en zonder
bruikbare worker/cron kan deze workflow niet draaien.

De zichtbaarheidstermijn van de ingestqueue is vast 180 seconden, groter dan
de 120-secondenjobtimeout. De oude voorbeeldvariabele
`APP_WORKER_JOB_TIMEOUT_SECONDS` verandert deze ingestlimieten niet. Verhoog
de CLI-timeout niet los van claimherstel, queuezichtbaarheid en procesbeheer.
De Compose-worker gebruikt al expliciet bovenstaande ingestverbinding.

## Scanning

`INGEST_SCANNER=none` is de standaard: beelden worden **NIET GESCAND** gemarkeerd,
niet als schoon. `INGEST_SCANNER=clamav` gebruikt ClamAV's TCP INSTREAM-protocol,
met een timeout van 30 seconden. Stel `CLAMAV_HOST` en `CLAMAV_PORT` in op een
bereikbare private daemon; loopback `127.0.0.1:3310` verwijst in Docker naar de
eigen container. De Compose-stack bevat een optionele ClamAV-service achter het
profiel `clamav`; zet dan `COMPOSE_PROFILES=clamav`, `INGEST_SCANNER=clamav` en
`CLAMAV_HOST=clamav`. Zonder dat profiel blijft de applicatie bewust
ongescand en claimt zij nooit dat bestanden schoon zijn.

Gebruik uitsluitend een afgeschermd netwerk voor dit niet-geauthenticeerde
protocol. Configureer de daemon voor minstens 104857600 bytes per stream en
zorg voor actuele signatures. Een onbereikbare scanner of ongeldige respons
blokkeert verwerking en leidt tot herpogingen; er is geen fallback naar schoon.
Een schone scan geeft **nooit** publicatietoestemming. `publishable_at` blijft
leeg en alle previews worden via geautoriseerde, niet-cachebare routes geleverd.
De Linux-releaseacceptatie start de optionele ClamAV-container, verwerkt een
schoon testbeeld via de echte worker en controleert dat EICAR door dezelfde
runtime wordt geweigerd. Lokale ontwikkeltests slaan die echte daemoncontrole
over tenzij `FOTOARCHIEF_TEST_CLAMAV_HOST` is gezet.

## Limieten

| Grens | Standaard |
|---|---:|
| Bestand (`APP_MAX_UPLOAD_BYTES`) | 104857600 bytes (100 MiB) |
| Docker PHP POST, inclusief multipart | 115343360 bytes (110 MiB) |
| PHP/Apache uploadverzoek en browser-XHR | 300 seconden |
| Workerjob / ingestzichtbaarheid | 120 / 180 seconden |
| Pixels (`APP_MAX_IMAGE_PIXELS`) | 80000000 |
| JavaScript-selectie (`APP_MAX_BATCH_UPLOAD_FILES`) | 250, een bestand per verzoek |
| Tags | 20, elk maximaal 100 tekens |

De pixelgrens is een bovengrens, geen geheugengarantie: een strengere dynamische
geheugencontrole weigert beelden die niet veilig in de worker passen. Docker
heeft 512 MiB PHP-geheugen. Zonder JavaScript gelden bovendien de totale
POST-grens en PHP `max_file_uploads` van de host (standaard 20); selecteer niet
meer dan de host toestaat. Verhoog `max_file_uploads` alleen samen met voldoende
POST- en tijdelijke-opslagcapaciteit.

Elke proxy/CDN voor de app moet minstens 115343360 bytes per verzoek en de
benodigde 300 seconden toestaan. Alleen een appvariabele aanpassen verhoogt
de ingebouwde PHP/Apache/browserlimieten niet. Kies lagere grenzen als de
webhoster de standaard niet aankan; grote archieven vereisen passende workers.

## Upgraden en exacte operatorwijzigingen

1. Maak een consistente backup van PostgreSQL, private bestanden en
   `storage/app/installation/`. Bewaar de bestaande appkey.
2. Stop/drain workers. Deploy code en `composer install --no-dev` vanuit de
   lockfile. PHP 8.5 vereist nu ook GD met JPEG/PNG/WebP en EXIF. De Dockerfile
   installeert die; bouw een nieuwe image in plaats van een oude image te hergebruiken.
3. Bij een reeds voltooide installatie: `php artisan installation:migrate-ready`.
   De vijfde migratie voegt workflowkolommen, uploads en auditgebeurtenissen toe.
   Bij een nieuwe installatie voert de wizard alle vijf migraties uit.
4. Start workers met **`queue:work ingest --sleep=3 --tries=3 --timeout=120`**,
   niet alleen `queue:work`. Herstart web/worker/scheduler met dezelfde release.
5. Upload een proefbeeld en controleer verwerking, preview en een metadatarevisie.

Legacy `asset_files` behouden hash, omvang en opslagsleutel. Hun nieuwe
`storage_disk` blijft bewust leeg: de migratie gokt geen opslaglocatie en
backfillt geen previews. Bewaar deze originelen; automatische herverwerking
van legacy records is niet ondersteund. Een gecontroleerde migratie moet
eerst de echte disk herleiden en verifiëren; opnieuw uploaden van dezelfde
bytes wordt door duplicaatbescherming afgewezen.

Voor **beide** Compose-templates zijn dit de exacte nieuwe mappings in de
gedeelde application-environment:

```yaml
APP_MAX_IMAGE_PIXELS: ${APP_MAX_IMAGE_PIXELS:-80000000}
APP_MAX_BATCH_UPLOAD_FILES: ${APP_MAX_BATCH_UPLOAD_FILES:-250}
INGEST_SCANNER: ${INGEST_SCANNER:-none}
CLAMAV_HOST: ${CLAMAV_HOST:-127.0.0.1}
CLAMAV_PORT: ${CLAMAV_PORT:-3310}
```

Neem de mappings en nieuwe workercommand over in een eigen Compose-kopie.
Nieuwe `.env`-regels zijn niet verplicht bij deze defaults; bestaande
omgevingen blijven zonder scanner werken, expliciet als ongescand. Voor
ClamAV voeg je **zelf** `INGEST_SCANNER=clamav`, de juiste `CLAMAV_HOST` en
eventueel `CLAMAV_PORT` toe. Voor aangepaste pixel-/batchlimieten voeg je
de betreffende variabele zelf toe. Secrets en setupinstellingen niet kopieren
naar een publieke environment-template. Deze release verhoogt de bestaande
100 MiB-bestands-, 110 MiB-request- of 300-secondenrequestlimiet niet.

## Verificatie en grenzen van het bewijs

De Pest-suite test echte databasequeueverwerking, afwijzingen, herpogingen,
toegangscontrole, revisieconflicten, datering, EXIF-orientatie en previews voor
JPEG/PNG/WebP. De S3-variant gebruikt fake storage. Scannertransporttests
gebruiken een lokale TCP-protocolfixture, **geen echte ClamAV-virusdetectie**.

`tests/Feature/PhotoUpgradeTest.php` is opt-in en vereist een eigen lege
PostgreSQL-database met suffix `_workflow_test`. Stel
`FOTOARCHIEF_TEST_PG_UPGRADE_DATABASE`, `FOTOARCHIEF_TEST_PG_HOST`,
`FOTOARCHIEF_TEST_PG_PORT`, `FOTOARCHIEF_TEST_PG_USER` en
`FOTOARCHIEF_TEST_PG_PASSWORD` in en voer `composer test -- --group=postgres`
uit. De test past eerst de vier oude migraties toe, bewaart een legacyfile,
upgrade naar vijf migraties en verwerkt/bewerkt een nieuw beeld. Hij weigert
een niet-lege database en verwijdert zelf geen bestaande tabellen.

Browseracceptatie gebruikt een geisoleerde PHP-server en echte PostgreSQL:
wizard/login, filepicker en multi-file drag/drop, resultaten per bestand,
private preview, metadata/revisiehistorie, zoeken en uitloggen. Herhaal die
scenario's op de echte host, inclusief upload zonder JavaScript en een
geweigerde anonieme media-URL. Een mobiele viewport van 390 pixels hoort
geen horizontale paginaoverflow te hebben.

Docker/Apache runtime, echte S3, ClamAV, Komodo/Dockhand-import en de
uploadbare release-ZIP blijven aparte acceptatie-/releasegates. Deze
applicatietests bewijzen die distributie- en infrastructuurstappen niet.
