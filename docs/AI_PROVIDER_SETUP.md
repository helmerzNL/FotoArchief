# Native AI-providerinstellingen

FotoArchief houdt AI standaard uit. Deze handleiding activeert uitsluitend
native externe providers: OpenAI, Anthropic (Claude), Google Gemini en
OpenRouter. Een lokale/eigen HTTP-AI-service blijft als afzonderlijke optie
bestaan.

## Kies per capability

Een installatie kiest afzonderlijk:

- **beeldanalyse:** maakt een Nederlandse conceptbeschrijving en tags;
- **embeddings:** maakt compatibele beeld- en tekstvectoren voor semantisch
  zoeken.

OpenAI, Anthropic, Gemini en OpenRouter zijn beschikbaar voor beeldanalyse.
Voor embeddings kiest u alleen Gemini of een expliciet toegelaten OpenRouter
multimodaal model. OpenAI's gedocumenteerde embeddings zijn tekst-only en
Anthropic biedt geen eigen embedding-API; FotoArchief gebruikt ze daarom nooit
voor beeldzoekresultaten. Er is geen automatische provider- of model-failover.

## Private runtimeconfiguratie

Kopieer [`.env.example`](../.env.example) naar de private runtimeomgeving of
vul dezelfde variabelen in uw Compose- of manager-configuratie in. Compose
geeft elke `AI_OPENAI_*`, `AI_ANTHROPIC_*`, `AI_GEMINI_*` en
`AI_OPENROUTER_*` variabele ongewijzigd door aan `app`, `worker` en
`scheduler`; standaard is elke provider uit en elk budget 0. Zet nooit een
API-sleutel in de beheerinterface, git, een export of een logbestand.

Voorbeeld voor Gemini:

```dotenv
AI_GEMINI_ENABLED=true
AI_GEMINI_API_KEY=plaats-dit-alleen-in-de-private-runtime
AI_GEMINI_VISION_MODEL=uw-goedgekeurde-beeldmodel
AI_GEMINI_EMBEDDING_MODEL=gemini-embedding-2
AI_GEMINI_COST_CENTS_PER_IMAGE=<actuele-kosten-in-centen>
AI_GEMINI_COST_CENTS_PER_EMBEDDING=<actuele-kosten-in-centen>
AI_GEMINI_MONTHLY_BUDGET_CENTS=<positieve-maandlimiet>
```

Configureer voor iedere andere provider alleen de bijbehorende
`AI_OPENAI_*`, `AI_ANTHROPIC_*` of `AI_OPENROUTER_*` groep. Een provider
wordt pas als geconfigureerd beschouwd wanneer sleutel, vaste officiële
base-URL en een positieve maandlimiet aanwezig zijn. Stel de kosten per
request in als een conservatieve bovengrens in eurocenten: FotoArchief
reserveert dit bedrag vooraf en weigert werk boven de maandlimiet. Onjuiste of
niet-actuele prijswaarden zijn geen bescherming tegen kosten; controleer de
actuele providerprijs vóór activering.

Bij OpenRouter moet `AI_OPENROUTER_EMBEDDING_MODEL` ook exact voorkomen in
`AI_OPENROUTER_EMBEDDING_MODEL_ALLOWLIST`. Laat de allowlist beperkt tot
modellen waarvoor u zelf de compatibele beeld- én tekstembeddingruimte heeft
geverifieerd. OpenRouter kan naar een externe upstream routeren; diens
gegevensverwerking is een afzonderlijke keuze en wordt niet door FotoArchief
geverifieerd.

Herstart na een wijziging van runtimevariabelen de `app`, `worker` en
`scheduler` containers. Een PHP-webhost moet de variabelen ook beschikbaar
maken voor de queue-worker; alleen de webprocessen configureren is
onvoldoende.

## Activeren in FotoArchief

1. Meld u als beheerder aan en open **Beheer > Operations > AI-instellingen**.
2. Schakel **AI globaal**, de gekozen capability en precies de gewenste
   provider in. Laat de noodstop uit.
3. Kies voor beeldanalyse en/of embeddings de provider en het model.
4. Geef voor elke capability expliciete toestemming voor de doorgifte.
   Beeldanalyse verstuurt alleen een gevalideerde afgeleide van maximaal
   1024 pixels zonder ingebedde metadata. Embeddings sturen zulke afgeleiden
   en/of zoektekst naar de gekozen provider.
5. Gebruik eerst **Verbinding testen**. Dit controleert alleen sleutel- en
   modelzichtbaarheid; het verwerkt geen archiefbeeld en is geen bewijs van
   modelkwaliteit.
6. Start met maximaal 25 expliciet geselecteerde assets. Beeldanalyse maakt
   alleen te beoordelen suggesties; metadata en publicatie wijzigen pas na
   menselijke acceptatie.
7. Bouw daarna een nieuwe embeddinggeneratie voor dezelfde selectie. Bij een
   provider-, model- of dimensiewijziging is altijd een nieuwe indexgeneratie
   nodig; vectorruimtes worden nooit gemengd.

Voor publieke semantische zoekopdrachten met een externe provider toont
FotoArchief een expliciete opt-in per bezoeker. Zonder toestemming blijft de
gewone zoekfunctie beschikbaar en wordt geen zoektekst naar de provider
verstuurd.

## Voor productie

Voer voor elke provider en elk model eerst de kleine, toegestane proof-set uit
uit [AI_CAPABILITY_DECISION.md](AI_CAPABILITY_DECISION.md). Leg modelversie,
licentie/voorwaarden, regio, retentie/traininginstellingen, prijs, latency,
foutpercentage en Nederlandse relevantie vast. Gebruik geen privéarchiefbeelden
voor die proef zonder passende rechten en toestemming.

Live providerbereikbaarheid, facturering en modelkwaliteit kunnen niet worden
bewezen met de meegeleverde testdubbelingen. De applicatie blokkeert AI-fouten
expliciet; upload, onboarding en de gewone zoekfunctie blijven beschikbaar.
