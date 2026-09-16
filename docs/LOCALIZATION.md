# Lokalisatie en tekstinventaris

## Nederlands

Deze batch extraheert de eerste, gedeelde Nederlandstalige gebruikersinterface
naar conventionele Laravel-bestanden onder `lang/nl/`:

- `shell.php`: merknaam, navigatie, sessiemeldingen en footer uit de gedeelde
  applicatieschil.
- `auth.php`: loginformulier, herstelcodeblok, passkey-statussen en
  authenticatiefouten.
- `onboarding.php`: eerste-installatiewizard, installatiecontrole,
  installatieplatformfouten en het eerste beheerdersdashboard.

Er zijn bewust geen andere talen, taalkeuze of fallback-UI toegevoegd. De
Nederlandse uitvoer blijft leidend; HTML in bestaande uitlegteksten is alleen
naar vertaalwaarden verplaatst wanneer die markup al onderdeel was van de
gebruikerscopy.

De resterende gebruikerszichtbare tekst staat machineleesbaar in
`docs/localization/text-inventory.remaining.json`. De inventaris gebruikt
relatieve paden, regelnummers, tekstsoort en een voorgestelde extractiebatch.
De regressietest vergelijkt dit bestand met de scanner zodat nieuwe of
verplaatste resterende teksten expliciet zichtbaar worden.

Aanbevolen vervolgbatches op basis van de huidige inventaris:

| Batch | Aantal inventarisregels | Scope |
|---|---:|---|
| `catalogue` | 474 | Catalogusreferenties, werksets, bulkacties en tabelcomponenten. |
| `shared_remaining` | 408 | Console-/jobmeldingen en gedeelde backendservices buiten de eerste shell. |
| `operations` | 226 | Operationele dashboards, integriteit, verwerking, OCR, opslag en prullenbak. |
| `ai` | 158 | AI-instellingen, suggesties, reviewstatussen en semantisch zoeken. |
| `asset_admin` | 82 | Upload, detailweergave en assetbeheer. |
| `public_portal` | 64 | Publieke ontdekking, fotodetailpagina's, collecties en sitemaps. |
| `exchange` | 64 | Import/exportschermen en uitwisselingsstatussen. |
| `identity` | 55 | Gebruikersbeheer, uitnodigingen, passkeys en herstelcodes buiten login. |
| `publication_admin` | 20 | Publicatiebeheer en publieke suggestiebeoordeling. |

## English

This batch extracts the first shared Dutch user interface into conventional
Laravel files under `lang/nl/`:

- `shell.php`: brand name, navigation, session notices and footer from the
  shared application shell.
- `auth.php`: login form, recovery-code block, passkey statuses and
  authentication errors.
- `onboarding.php`: first-start setup wizard, installation checks,
  installation platform errors and the initial administrator dashboard.

No other languages, language selector or fallback UI were added. Dutch output
remains authoritative; HTML in existing explanatory text was only moved into
translation values where that markup was already part of the user-facing copy.

The remaining user-visible text is tracked in machine-readable form at
`docs/localization/text-inventory.remaining.json`. The inventory uses relative
paths, line numbers, text kind and a proposed extraction batch. The regression
test compares this file with the scanner so newly added or moved remaining text
is made explicit.

Recommended follow-up batches based on the current inventory:

| Batch | Inventory entries | Scope |
|---|---:|---|
| `catalogue` | 474 | Catalogue references, worklists, bulk actions and table components. |
| `shared_remaining` | 408 | Console/job messages and shared backend services outside the first shell. |
| `operations` | 226 | Operational dashboards, integrity, processing, OCR, storage and trash. |
| `ai` | 158 | AI settings, suggestions, review statuses and semantic search. |
| `asset_admin` | 82 | Upload, detail views and asset administration. |
| `public_portal` | 64 | Public discovery, photo detail pages, collections and sitemaps. |
| `exchange` | 64 | Import/export screens and exchange statuses. |
| `identity` | 55 | User management, invitations, passkeys and recovery codes outside login. |
| `publication_admin` | 20 | Publication administration and public suggestion review. |
