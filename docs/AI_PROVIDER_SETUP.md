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
