# AI-providerconfiguratie / AI provider configuration

## Nederlands

FotoArchief houdt AI standaard uit. Beheerders configureren de custom externe
provider, OpenAI, Anthropic, Gemini en OpenRouter in **Beheer > Operations >
AI-instellingen**. De database is de exclusieve runtimebron voor providerstatus,
API-sleutels, modellen, kosten en maandbudgetten.

### Capabilities

- OpenAI, Anthropic, Gemini en OpenRouter ondersteunen beeldanalyse.
- Alleen Gemini en een door FotoArchief toegelaten multimodaal
  OpenRouter-model ondersteunen beeld- en tekstembeddings in dezelfde ruimte.
- De custom externe provider moet via zijn capabilityprobe aantonen dat beeld-
  en tekstembeddings dezelfde modelruimte gebruiken.
- Er is nooit automatische provider- of modelfailover.

### Veilige configuratie

1. Open de AI-instellingen als beheerder.
2. Configureer per provider enabledstatus, modellen, kosten en een positieve
   maandlimiet. Stel voor de custom externe provider ook een publiek
   HTTPS-endpoint, regio en retentie-/trainingnotitie in.
3. Stel de API-sleutel in via de afzonderlijke sleutelactie. De sleutel wordt
   versleuteld met `APP_KEY`, nooit opnieuw getoond en niet opgenomen in HTML,
   redirects, logs, auditdetails of queuepayloads.
4. Verwijder een sleutel alleen via de afzonderlijke verwijderactie met
   expliciete bevestiging.
5. Kies daarna per capability een provider en geef expliciete toestemming voor
   externe doorgifte. Het gebruikte model komt uit het providerrecord.
6. Gebruik **Verbinding testen** voordat u betaalde verwerking start.

AI verwerkt alleen primaire bestanden die de malwarecontrole als schoon heeft
vrijgegeven. Bij een bestaand `unscanned` bestand voert een AI-taak automatisch
een nieuwe controle uit wanneer ClamAV actief is. Is `INGEST_SCANNER=none`, dan
stopt de taak met een concrete melding; activeer ClamAV en probeer de taak
daarna opnieuw.

AI-verwerking gebruikt alleen gevalideerde afgeleiden van maximaal 1024 pixels
zonder ingebedde metadata. Beeldanalyse maakt uitsluitend suggesties; metadata
wijzigt pas na menselijke acceptatie. Publieke semantische zoekopdrachten blijven
achter de actuele publicatie- en rechtencontroles.

### Foto's selecteren voor AI

Beeldanalyse en embeddingindexering accepteren **fotonummers of interne IDs**.
Gebruik bijvoorbeeld het volledige zichtbare `FA-...`-nummer of de interne ULID,
gescheiden door komma's of regels (bestaande whitespace-scheiding blijft werken).
Het fotonummer is een apart veld, niet de interne ID met een prefix.
Verwijder `FA-` daarom niet. Een exact fotonummer heeft bij vrije invoer voorrang
op een gelijkvormige interne ID; er wordt niet op deelwoorden gezocht.

FotoArchief controleert de volledige batch voordat deze wordt gestart en slaat
de werkelijke interne IDs op. Eén onbekende, verwijderde of niet-toegankelijke
foto houdt de hele batch tegen. De invoer blijft beschikbaar om te corrigeren.
Dubbele verwijzingen naar dezelfde foto tellen na controle eenmaal.
De ingestelde batchlimiet blijft gelden voor de ingevoerde selectie.

### AI-resultaten bekijken en beoordelen

De centrale reviewwerkvoorraad ondersteunt collectie, provideridentificatie,
datumbereik en status. Huidige metadata en het oorspronkelijke voorstel staan
bij elkaar. Een beschrijving kan voor acceptatie worden aangepast; afzonderlijke
tags zijn afzonderlijke voorstellen. Selecteer maximaal 25 voorstellen en
bevestig de bulkbeslissing; ieder voorstel krijgt een zichtbaar resultaat.
Bron- en formuliercontroles gelden ook bij bulkacties. Er wordt geen provider aangeroepen.

Een eigen acceptatie uit versie 0.9.54 of later kan vanuit het filter
Geaccepteerd worden teruggedraaid, uitsluitend zolang de fotorevisie sinds die
acceptatie ongewijzigd is. Een bestaande tagkoppeling wordt niet verwijderd.
Het oorspronkelijke voorstel, herstelbewijs en auditgebeurtenissen blijven behouden.
Oudere acceptaties zonder herstelbewijs worden niet onveilig gereconstrueerd.

Tagvoorstellen volgen vaste regels (vanaf `0.9.74`). Een voorstel is een kleine-letter
woord van maximaal 60 tekens; dubbele schrijfwijzen tellen als één voorstel en tags die
de foto al heeft (ook via een synoniem) worden niet voorgesteld. Per foto worden hoogstens
8 tags voorgesteld (`ai.tag_suggestions.max_per_image`). Meldt de provider een zekerheid per
tag, dan wordt die bij het voorstel bewaard en vervalt een tag onder 0,5
(`ai.tag_suggestions.min_confidence`); providers zonder zekerheid (lokaal, extern) behouden
al hun tags binnen het maximum. Bij acceptatie hoort een tag bij de bestaande tag met
dezelfde slug, hetzelfde synoniem of dezelfde naam in andere hoofdletters; alleen als die
ontbreekt ontstaat een nieuwe tag. Dit geldt ook voor bulkbewerking, CSV-import, het
fotoformulier en het samenvoegen van duplicaten: ze gebruiken allemaal dezelfde slugregel.
De upgrade voegt bestaande tags samen die alleen verschilden in schrijfwijze (foto's en
namen blijven behouden; de oude naam wordt synoniem) en vereist een back-up volgens de
gebruikelijke upgradeprocedure.

Vanaf `0.9.76` kan de reviewlijst ook gefilterd worden op soort voorstel (beschrijving of
tag) en op een minimale zekerheid, en gesorteerd op zekerheid (zekerste eerst); de zekerheid
staat bij ieder voorstel. Voor veel zekere tags is er **Zekere tags bekijken en accepteren**:
je kiest een drempel (standaard 80%), ziet eerst exact welke tags bij welke foto's worden
toegevoegd en bevestigt dan expliciet. Alleen tagvoorstellen met een bekende zekerheid vanaf
de drempel doen mee, nooit beschrijvingen; hoogstens 200 per keer (`ai.tag_suggestions.bulk_accept_max`).
Foto's die intussen zijn gewijzigd, worden overgeslagen en gemeld. Iedere toevoeging blijft
terug te draaien en er wordt één batchregel vastgelegd. Er wordt geen provider aangeroepen.

#### Automatische beeldanalyse (vanaf `0.9.77`)

Onder **AI-instellingen** staat de kaart **Automatische beeldanalyse**. Standaard is die uit.
Een beheerder zet hem aan met een expliciete toestemming; de beheerder die dat doet wordt
vastgelegd als de uitvoerder. Daarna controleert de scheduler elke vijf minuten (`ai:sweep`) of er
foto's wachten en zet hoogstens één klein pakket in de wachtrij. Het resultaat zijn alleen
voorstellen: tags en beschrijvingen worden pas toegepast nadat iemand ze goedkeurt (zie hierboven).
Het uploaden, scannen en zoeken blijft ongemoeid; de ingest roept nooit een AI-provider aan.

Vaste grenzen (`config/ai.php`, sleutel `auto_analysis`): hoogstens 25 foto's per pakket (nooit
meer dan *Maximum aantal foto's per batch*), 1000 foto's per dag (UTC), één analyse tegelijk,
hoogstens 20 providerverzoeken per minuut (3 seconden tussen verzoeken) en nooit meer dan het
maandbudget van de provider: het pakket wordt zo klein gemaakt dat de kosten binnen het
resterende budget blijven. Een foto die mislukte, wordt 7 dagen met rust gelaten zodat één
kapot bestand de rest niet blokkeert. Een pakket stopt met nieuwe foto's te starten ruim vóór
de jobtime-out van 120 seconden en gaat in een volgend pakket verder. De sweep werkt alleen met
de lokale provider of een native provider met maandbudget; de externe provider telt niet mee
omdat de uitgaven daar niet worden bijgehouden. De sweep stopt bij de noodstop, als AI uit
staat, als de beheerder niet meer actief is of geen `users.manage` meer heeft, en bij een
pauze van de run.

Zonder de optie *Ook foto's analyseren die al in het archief staan* doen alleen foto's mee die
na het aanzetten zijn toegevoegd. Voor het bestaande archief kun je die optie aanzetten, of een
eigen proef draaien:

```bash
# Schatting zonder iets in de wachtrij te zetten
docker compose exec --user www-data app php artisan ai:analyse-archive --dry-run --limit=200
# Daadwerkelijk 200 foto's, met een uitdrukkelijk kostenplafond in eurocenten
docker compose exec --user www-data app php artisan ai:analyse-archive --limit=200 --max-cost-cents=300 --as=beheerder@example.org
```

De schatting toont het aantal wachtende foto's, de kosten per foto, de verwachte kosten en het
resterende maandbudget. Bij een betaalde provider is `--max-cost-cents` verplicht; een schatting
boven het plafond of boven het resterende budget wordt geweigerd. Hoogstens 1000 foto's per
aanroep. De kaart toont wachtende foto's, de kostenschatting, de dagtelling, het resterende
budget en de laatste controle. Let op: voor iedere foto wordt het origineel uit de opslag
gelezen om de checksum te controleren; bij een S3-bucket buiten de serverregio kost dat
dataverkeer.

Vanaf `0.9.51` heeft iedere private fotopagina een link en een paneel
**AI-resultaten**, vóór het metadataformulier. Hier zie je de opgeslagen
beschrijving en tags, provider, model, tijdstip, bronrevisie en beoordelingsstatus.
Ook geaccepteerde, afgewezen en verouderde voorstellen blijven zichtbaar.
De historie is gepagineerd met 10 analyses/indexeringen per pagina.
Bekijken start geen providerrequest en wijzigt geen metadata.

Bevoegde medewerkers kunnen een voorstel accepteren of afwijzen en keren
daarna terug naar de foto. Metadatawijzigingen en acceptatie van een voorstel
maken andere voorstellen niet ongeldig: het primaire bestand en de checksum
bepalen of de beeldbron nog klopt. Ook eerder als verouderd gemarkeerde
voorstellen kunnen opnieuw worden beoordeeld als die bron nog overeenkomt.
Geaccepteerde en afgewezen voorstellen blijven afgesloten. Een intussen
gewijzigd formulier vereist wel herladen om gelijktijdige edits te beschermen.
Alleen bekijken geeft geen
beoordelingsrecht; private resultaten komen niet op de openbare fotopagina.

Bij een AI-achtergrondtaak staat **Foto's en AI-resultaten bekijken**. Deze link
toont maximaal 25 toegankelijke, succesvol verwerkte foto's per pagina op basis
van het volledige auditlog, inclusief eerdere pogingen. Iedere fotolink opent
de volledige AI-historie van die foto, niet alleen de uitvoer van deze taak.
Verwijderde en niet-toegankelijke foto's worden niet getoond. Oude taken zonder
succeslog verwijzen niet automatisch naar foto's; open de foto dan rechtstreeks.
Een lege analyse meldt expliciet dat er geen beschrijving of tags zijn teruggegeven.
Embeddingindexering toont modelruimte en dimensies, geen tekstvoorstellen of ruwe vector.

Deze weergave werkt ook voor al opgeslagen resultaten: opnieuw analyseren is
niet nodig. Versie `0.9.51` vereist geen nieuwe migraties of configuratie.
Haal de release-image op en maak web-, worker- en schedulercontainers opnieuw aan.

### Taakstatus en auditlog

Open **Beheer > Operations > Achtergrondtaken** om AI-taken te volgen. Een taak
waarvan geen enkel item kon worden verwerkt krijgt de status **Mislukt**, nooit
**Voltooid**. De kolom **Foutmelding** toont de laatste taakfout. Open
**Auditlog** voor de gebeurtenissen per poging en foto, inclusief provider,
model, bronbestand, scannerstatus en exceptionklasse.

Het auditlog bevat geen API-sleutels, afbeeldingsbytes of providerresponses.
Na een update naar `0.9.49` worden oudere AI-taken die als voltooid met mislukte
items waren opgeslagen automatisch naar **Mislukt** gecorrigeerd. Gebruik
**Opnieuw proberen**; FotoArchief start zo'n AI-taak opnieuw met schone tellers
vanaf het eerste item.

Vanaf `0.9.50` controleert **Opnieuw proberen** ook de fotoreferenties van oude
AI-taken. Bestaande interne IDs behouden voorrang; oude fotonummers worden naar
interne IDs omgezet. Het auditlog blijft behouden en krijgt een gebeurtenis
`references_normalized` met de gecontroleerde koppelingen. Bij ongeldige invoer
blijft de mislukte taak ongewijzigd. Reeds omgezette interne IDs worden nooit
opnieuw als fotonummer geïnterpreteerd.

Een fout kan tijdelijk naast **In wachtrij** staan terwijl de worker automatisch
opnieuw probeert; na uitgeputte pogingen wordt de taak **Mislukt**.
De melding "Foto ... bestaat niet meer" met lege bestands-/scannercontext
ontstaat vóór scanning of een providerrequest en bewijst op zichzelf geen
ClamAV- of OpenAI-storing. Een foto kan uiteraard ook echt verwijderd zijn.

Voor deze hotfix zijn geen Compose-mappings, omgevingsvariabelen of nieuwe
databasemigraties nodig. Haal na een geverifieerde backup de nieuwe image op en
maak web-, worker- en schedulercontainers opnieuw aan met die image.
Probeer daarna de mislukte taak handmatig opnieuw; deployment herstart geen
historische AI-taken automatisch.

### Eenmalige upgrade-import

De migratie importeert bestaande `AI_EXTERNAL_*`, `AI_OPENAI_*`,
`AI_ANTHROPIC_*`, `AI_GEMINI_*` en `AI_OPENROUTER_*` waarden éénmalig wanneer
nog geen providerrecord bestaat. De waarden in `.env` en Compose zijn alleen
upgrade-input; na een geslaagde migratie worden ze niet meer gelezen door web,
worker of scheduler.

Controleer na deployment in de beheerinterface alle providerrecords en voer de
verbindingstest uit. Daarna mogen de legacy providerwaarden uit de private
runtimeomgeving worden verwijderd. `AI_LOCAL_ENDPOINT` blijft staan wanneer de
lokale/eigen provider wordt gebruikt. Behoud `APP_KEY`: zonder dezelfde sleutel
kunnen bestaande credentials niet worden ontsleuteld. Herstel bij verlies de
oorspronkelijke `APP_KEY` uit de beveiligde back-up of vervang elke
providercredential via de beheerinterface.

De officiële native base-URL's, de Anthropic API-versie en de multimodale
OpenRouter-allowlist zijn vaste applicatiegegevens en niet wijzigbaar via
runtimevariabelen of de UI.

### Lokale AI-contractstub en echte proof

`tests/Smoke/local-ai-http-contract.php` start een geïsoleerde lokale
HTTP-stub die alleen het FotoArchief-contract voor `/v1/capabilities`,
`/v1/analyze-image`, `/v1/embed-image` en `/v1/embed-text` controleert. Dit is
geen bewijs dat een echt model draait, goed presteert of historisch relevante
resultaten levert. Het bewijst wel dat het protocol zonder externe fallback kan
worden aangesproken en dat beeld- en tekstembeddings dezelfde modelruimte en
dimensie rapporteren.

Voor een echte, operatorgekozen lokale service kan de contractprobe handmatig
worden gedraaid:

```sh
php scripts/ai-local-contract-probe.php https://local-ai.example.invalid --confirm-send-test-image
```

De probe print provider, endpoint zonder credentials, model, modelversie of
digest voor zover de service die meldt, modelruimte, dimensies en afstandsmaat.
Hij print geen afbeeldingsbytes, ruwe embeddings of geheimen. Dit blijft een
contractcontrole: productieacceptatie vereist daarnaast een goedgekeurde
niet-gevoelige proofset, vastgelegde modelcode- en gewichtslicenties, p50/p95
latency, foutpercentage, retrygedrag, CPU/GPU/geheugenprofiel en een gekozen
relevantiedrempel met positieve en negatieve Nederlandse queries. Modeldownload,
providerkosten en live uitvoering zijn geblokkeerd totdat de operator die
middelen expliciet levert.

### Synthetische binaire AI-ingest-smoke

`tests/Smoke/ai-image-load.php` is nu een echte, herbruikbare binaire
ingest-harness voor lokaal bewijs van de applicatiecode die FotoArchief zelf
bezit. Hij weigert zonder `FOTOARCHIEF_DISPOSABLE_AI_IMAGE_LOAD=1`, gebruikt
alleen een lege map met markerbestand `.fotoarchief-disposable-ai-load`, zet de
Laravel testomgeving op met `sqlite::memory:` en schrijft private opslag naar
die unieke fixturemap. De `--images=1..25` optie bepaalt hoeveel lokaal
gegenereerde JPEG/PNG-bestanden echt door `QuarantineUploadService`,
`ProcessUpload` en de private previewcontroller gaan. De uitvoer rapporteert
geaccepteerde uploads, succesvol verwerkte items, mislukkingen,
geverifieerde private JPEG-previews, bytes en looptijd.

Er is bewust geen `--workers` optie meer. Deze smoke is single-process en
synthetisch; hij bewijst geen queueconcurrency, proxygedrag, S3-latency,
providerlatency of representatieve 50k-productiebelasting. Gebruik de echte
gekozen deploymentruntime en een door de operator goedgekeurde beeldcorpus voor
die acceptatie. CI draait alleen een kleine `--images=2` controle als regressie
op echte binaire upload/ingest/preview-code.

### Compatibele embeddings en vectorbackend

Semantische beeldzoekopdrachten vereisen echte multimodale beeld- en
tekstembeddings in dezelfde modelruimte. OpenAI `text-embedding-*` en andere
tekst-alleen modellen mogen niet als beeldretrieval worden geconfigureerd.
FotoArchief weigert tekstqueries waarvoor geen actieve beeldindex met dezelfde
provider, modelruimte en dimensies bestaat. Een beeldindexrun weigert ook een
bestaande modelruimte wanneer de provider of dimensie niet overeenkomt.

De huidige applicatie slaat vectoren nog op als `database_json` en gebruikt
PostgreSQL als bron van waarheid. `pgvector` blijft de beoogde eerste
productie-vectorbackend, maar is niet stilzwijgend aangezet zolang de
applicatiepad en migraties daar nog niet op zijn omgebouwd. De opt-in test
`tests/Feature/Operations/PgvectorAcceptanceTest.php` gebruikt een disposable
PostgreSQL-schema met de echte `vector`-extensie om modelisolatie, stale
filtering en rebuildgedrag te bewijzen wanneer `FOTOARCHIEF_TEST_PGVECTOR_*`
naar een testdatabase wijst.

## English

FotoArchief keeps AI disabled by default. Administrators configure the custom
external provider, OpenAI, Anthropic, Gemini, and OpenRouter in
**Administration > Operations > AI settings**. The database is the exclusive
runtime source for provider state, API keys, models, costs, and monthly budgets.

### Capabilities

- OpenAI, Anthropic, Gemini, and OpenRouter support image analysis.
- Only Gemini and a multimodal OpenRouter model allowed by FotoArchief support
  image and text embeddings in the same space.
- The custom external provider must prove through its capability probe that
  image and text embeddings share one model space.
- Automatic provider or model failover never occurs.

### Secure configuration

1. Open AI settings as an administrator.
2. Configure each provider's enabled state, models, costs, and positive monthly
   limit. For the custom external provider, also configure a public HTTPS
   endpoint, region, and retention/training notice.
3. Set the API key through the separate key action. The key is encrypted with
   `APP_KEY`, is never displayed again, and is excluded from HTML, redirects,
   logs, audit details, and queue payloads.
4. Delete a key only through the separate delete action with explicit
   confirmation.
5. Select a provider per capability and explicitly consent to external data
   transfer. The provider record supplies the model.
6. Use **Test connection** before starting billable processing.

AI processes only primary files that passed malware scanning as clean. For an
existing `unscanned` file, an AI job automatically performs a new scan when
ClamAV is active. If `INGEST_SCANNER=none`, the job stops with an explicit
message; enable ClamAV and retry the job.

AI processing uses only validated derivatives up to 1024 pixels with embedded
metadata removed. Image analysis creates suggestions only; metadata changes
only after human acceptance. Public semantic queries remain subject to current
publication and rights checks.

### Selecting photos for AI

Image analysis and embedding indexing accept **photo numbers or internal IDs**.
Use the full visible `FA-...` number or the internal ULID, separated by commas
or lines (existing whitespace separation remains supported). The photo number
is a separate field, not the internal ID with a prefix. Do not remove `FA-`.
For free-form input, an exact photo number takes precedence over an identically
shaped internal ID; partial matching is not used.

FotoArchief validates the entire batch before starting it and stores the actual
internal IDs. One unknown, deleted, or inaccessible photo blocks the entire
batch. Input remains available for correction. Duplicate references to the same
photo count once after validation. The configured batch limit still applies to
the submitted selection.

### Viewing and reviewing AI results

The central review queue supports collection, provider identifier, date range
and status filters. Current metadata and the original proposal appear together.
Descriptions can be edited before acceptance; individual tags are individual
proposals. Select at most 25 proposals and confirm the bulk decision; each
proposal receives a visible outcome. Source and form guards also apply to bulk
actions. No provider is called.

Your own acceptance from version 0.9.54 onwards can be undone from the Accepted
filter only while the photo revision remains unchanged since that acceptance.
An existing tag link is not removed. The original proposal, recovery receipt
and audit events remain stored. Older acceptances without recovery receipts
are not reconstructed unsafely.

Tag suggestions follow fixed rules (from `0.9.74`). A suggestion is a lower-case word of
at most 60 characters; duplicate spellings count as one suggestion and tags the photo already
has (also through a synonym) are not suggested. At most 8 tags are suggested per photo
(`ai.tag_suggestions.max_per_image`). When the provider reports a confidence per tag, it is
stored with the suggestion and a tag below 0.5 is dropped (`ai.tag_suggestions.min_confidence`);
providers that report none (local, external) keep all their tags within the maximum. On
acceptance a tag belongs to the existing tag with the same slug, the same synonym or the same
name in different capitals; a new tag is created only when none exists. This also applies to
bulk edit, CSV import, the photo form and duplicate merging: they all use the same slug
rule. The upgrade merges existing tags that differed only in spelling (photos and names are
kept; the old name becomes a synonym) and needs a backup under the usual upgrade procedure.

From `0.9.76`, the review list can also be filtered by kind of proposal (description or tag)
and by a minimum confidence, and sorted by confidence (most certain first); the confidence is
shown on each proposal. For highly confident tags there is **Review and accept confident
tags**: you choose a threshold (default 80%), first see exactly which tags would be added to
which photos, and then confirm explicitly. Only tag proposals with a known confidence at or
above the threshold take part, never descriptions, at most 200 at a time
(`ai.tag_suggestions.bulk_accept_max`). Photos that changed in the meantime are skipped and
reported. Every addition remains undoable and one batch record is written. No provider is called.

#### Automatic image analysis (from `0.9.77`)

**AI settings** has an **Automatic image analysis** card. It is off by default. An administrator
switches it on with an explicit consent; that administrator is recorded as the actor. From then on
the scheduler checks every five minutes (`ai:sweep`) whether photos are waiting and queues at most
one small batch. The outcome is suggestions only: tags and descriptions are applied only after a
person approves them (see above). Upload, scanning and search are untouched; ingest never calls
an AI provider.

Fixed limits (`config/ai.php`, key `auto_analysis`): at most 25 photos per batch (never above
*Maximum photos per batch*), 1000 photos per day (UTC), one analysis at a time, at most 20
provider requests per minute (3 seconds between requests) and never more than the provider's
monthly budget: the batch is sized so that its cost stays within the remaining budget. A photo
that failed is left alone for 7 days so one broken file cannot block the rest. A batch stops
starting new photos well before the 120 second job timeout and continues in the next batch. The
sweep works only with the local provider or a native provider with a monthly budget; the
external provider is excluded because its spend is not tracked. The sweep stops on the emergency
stop, when AI is off, when the administrator is no longer active or no longer has `users.manage`,
and when the run is paused.

Without the option *Also analyse photos already in the archive*, only photos added after
switching on take part. For the existing archive you can turn that option on, or run a trial of your own:

```bash
# Estimate without queueing anything
docker compose exec --user www-data app php artisan ai:analyse-archive --dry-run --limit=200
# Actually queue 200 photos, with an explicit cost ceiling in euro cents
docker compose exec --user www-data app php artisan ai:analyse-archive --limit=200 --max-cost-cents=300 --as=admin@example.org
```

The estimate shows the number of waiting photos, the cost per photo, the expected cost and the
remaining monthly budget. With a paid provider `--max-cost-cents` is required; an estimate above
the ceiling or above the remaining budget is refused. At most 1000 photos per call. The card shows
waiting photos, the cost estimate, the daily count, the remaining budget and the last check. Note
that for every photo the original is read from storage to verify its checksum; with an S3 bucket
outside the server's region that costs egress.

From `0.9.51`, every private photo page has an **AI results** link and panel,
before the metadata form. It shows the stored description and tags, provider,
model, timestamp, source revision, and review status. Accepted, rejected, and
superseded suggestions remain visible. History is paginated with 10 analysis/
indexing records per page. Viewing starts no provider request and changes no metadata.

Authorized staff can accept or reject a suggestion and return to the photo.
Metadata edits and accepting one suggestion do not invalidate the others:
the current primary file and checksum determine whether the image source still
matches. Previously superseded suggestions can be reviewed again when their
source still matches. Accepted and rejected suggestions remain closed. A stale
form still requires reloading to protect concurrent edits.
Read access does not grant review permission; private results are not
shown on the public photo page.

AI background jobs have a **View photos and AI results** link. It shows up to
25 accessible, successfully processed photos per page using the full audit log,
including previous attempts. Each photo link opens that photo's complete AI
history, not just output from this job. Deleted and inaccessible photos are
excluded. Old jobs without success logs cannot automatically link to photos;
open the photo directly instead. An empty analysis explicitly reports that no
description or tags were returned. Embedding indexing shows model space and
dimensions, not text suggestions or raw vectors.

This view also works with existing stored results: another analysis is not
needed. Version `0.9.51` requires no new migrations or configuration. Pull the
release image and recreate the web, worker, and scheduler containers.

### Job status and audit log

Open **Administration > Operations > Background jobs** to monitor AI jobs. A
job that could not process any item receives the **Failed** status, never
**Completed**. The **Error message** column shows the latest job error. Open
**Audit log** for events per attempt and photo, including the provider, model,
source file, scanner status, and exception class.

The audit log never contains API keys, image bytes, or provider responses.
After updating to `0.9.49`, older AI jobs stored as completed with failed items
are automatically corrected to **Failed**. Use **Retry**; FotoArchief restarts
such an AI job with clean counters from the first item.

From `0.9.50`, **Retry** also validates photo references in old AI jobs.
Existing internal IDs retain precedence; old photo numbers are converted to
internal IDs. Audit history is preserved and a `references_normalized` event
records the validated mappings. Invalid input leaves the failed job unchanged.
Already converted internal IDs are never reinterpreted as photo numbers.

An error can temporarily appear alongside **Queued** while the worker retries
automatically; exhausted attempts change the job to **Failed**.
The message "Photo ... no longer exists" with empty file/scanner context occurs
before scanning or a provider request and does not itself establish a ClamAV or
OpenAI failure. A photo may of course also have genuinely been deleted.

This hotfix requires no Compose mappings, environment variables, or new database
migrations. After a verified backup, pull the new image and recreate the web,
worker, and scheduler containers with that image. Then retry the failed job
manually; deployment does not automatically restart historical AI jobs.

### One-time upgrade import

The migration imports existing `AI_EXTERNAL_*`, `AI_OPENAI_*`,
`AI_ANTHROPIC_*`, `AI_GEMINI_*`, and `AI_OPENROUTER_*` values once when no
provider record exists. Values in `.env` and Compose are upgrade input only;
after a successful migration they are no longer read by the web app, worker, or
scheduler.

After deployment, verify every provider record in the administration UI and run
the connection test. You may then remove the legacy provider values from the
private runtime environment. Keep `AI_LOCAL_ENDPOINT` when using the
local/organisation-owned provider. Preserve `APP_KEY`: existing credentials cannot
be decrypted without the same key. If it is lost, restore the original
`APP_KEY` from secure backup or replace every provider credential through the
administration UI.

Official native base URLs, the Anthropic API version, and the multimodal
OpenRouter allowlist are fixed application data and cannot be changed through
runtime variables or the UI.

### Local AI contract stub and real proof

`tests/Smoke/local-ai-http-contract.php` starts an isolated local HTTP stub that
checks only FotoArchief's contract for `/v1/capabilities`, `/v1/analyze-image`,
`/v1/embed-image`, and `/v1/embed-text`. This is not proof that a real model is
running, performs well, or produces historically relevant results. It does prove
that the protocol can be called without external fallback and that image and
text embeddings report the same model space and dimensions.

For a real operator-chosen local service, run the contract probe manually:

```sh
php scripts/ai-local-contract-probe.php https://local-ai.example.invalid --confirm-send-test-image
```

The probe prints provider, endpoint without credentials, model, model version
or digest where the service reports one, model space, dimensions, and distance
metric. It does not print image bytes, raw embeddings, or secrets. This remains
a contract check: production acceptance also requires an approved non-sensitive
proof set, recorded model-code and model-weight licenses, p50/p95 latency,
failure rate, retry behavior, CPU/GPU/memory profile, and a chosen relevance
threshold with positive and negative Dutch queries. Model download, provider
spend, and live execution are blocked until the operator explicitly supplies
those resources.

### Synthetic binary AI ingest smoke

`tests/Smoke/ai-image-load.php` is now a real, reusable binary ingest harness
for local evidence of the application code FotoArchief owns. It refuses to run
without `FOTOARCHIEF_DISPOSABLE_AI_IMAGE_LOAD=1`, accepts only an empty
directory with the `.fotoarchief-disposable-ai-load` marker, boots the Laravel
test environment with `sqlite::memory:`, and writes private storage to that
unique fixture directory. The `--images=1..25` option controls how many locally
generated JPEG/PNG files actually pass through `QuarantineUploadService`,
`ProcessUpload`, and the private preview controller. Output reports accepted
uploads, successfully processed items, failures, verified private JPEG
previews, bytes, and elapsed time.

There is deliberately no `--workers` option anymore. This smoke is
single-process and synthetic; it does not prove queue concurrency, proxy
behavior, S3 latency, provider latency, or representative 50k production load.
Use the real chosen deployment runtime and an operator-approved image corpus
for that acceptance. CI runs only a small `--images=2` check as a regression for
real binary upload/ingest/preview code.

### Compatible embeddings and vector backend

Semantic image search requires real multimodal image and text embeddings in the
same model space. OpenAI `text-embedding-*` and other text-only models must not
be configured as image retrieval. FotoArchief refuses text queries when no
active image index exists with the same provider, model space, and dimensions.
An image-index run also refuses an existing model space when the provider or
dimension does not match.

The current application still stores vectors as `database_json` and uses
PostgreSQL as the source of truth. `pgvector` remains the intended first
production vector backend, but it is not silently enabled until the application
path and migrations have been converted to it. The opt-in test
`tests/Feature/Operations/PgvectorAcceptanceTest.php` uses a disposable
PostgreSQL schema with the real `vector` extension to prove model isolation,
stale filtering, and rebuild behavior when `FOTOARCHIEF_TEST_PGVECTOR_*` points
to a test database.
