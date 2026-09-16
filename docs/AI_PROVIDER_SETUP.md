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

AI-verwerking gebruikt alleen gevalideerde afgeleiden van maximaal 1024 pixels
zonder ingebedde metadata. Beeldanalyse maakt uitsluitend suggesties; metadata
wijzigt pas na menselijke acceptatie. Publieke semantische zoekopdrachten blijven
achter de actuele publicatie- en rechtencontroles.

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

AI processing uses only validated derivatives up to 1024 pixels with embedded
metadata removed. Image analysis creates suggestions only; metadata changes
only after human acceptance. Public semantic queries remain subject to current
publication and rights checks.

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
