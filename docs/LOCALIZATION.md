# Lokalisatie en tekstinventaris

## Nederlands

De Nederlandstalige gebruikersinterface is gecentraliseerd in conventionele
Laravel-catalogi onder `lang/nl/`. Nederlands blijft de enige huidige
UI-locale; deze extractie voegt geen taalkeuze of Engelstalige interface toe.

De catalogi dekken:

- `shell.php`, `auth.php`, `identity.php` en `onboarding.php`: gedeelde
  applicatieschil, authenticatie, identiteit en installatie;
- `ai.php`: AI-instellingen, providerstatus, resultaten, review en fouten;
- `operations.php`: diagnose, verwerking, integriteit, OCR, opslag,
  bestandsversies, prullenbak en achtergrondtaken;
- `catalogue.php`: upload, fotodetail, catalogusreferenties, collecties,
  werklijsten en bulkacties;
- `publication.php`: publieke portal, publicatiebeheer en bezoekerssuggesties;
- `exchange.php`: import- en exportschermen;
- `shared.php`: resterende gebruikers- en operatorberichten uit jobs,
  services en consolecommando's.

De bestaande Nederlandse uitvoer, escaping, bindings, markup en toegankelijke
labels blijven behouden. Stabiele machinecodes, SQL, query-aliassen,
HTTP-headers, provider/API-tokens en statische Artisan-commandmetadata worden
niet vertaald. De scanner verwijdert Blade-comments en negeert alleen expliciet
benoemde technische waarden en herkenbare parserfragmenten; complete bestanden
worden niet via `EXTRACTED_FILES` verborgen.

`docs/localization/text-inventory.remaining.json` is opnieuw gegenereerd met
`UserVisibleTextScanner` en bevat **0 resterende gebruikerszichtbare regels**.
De regressietest vergelijkt het bestand met de scanner en vereist een lege
inventaris. Nieuwe ruwe gebruikerscopy laat die test daardoor falen.

## English

The Dutch user interface is centralized in conventional Laravel catalogues
under `lang/nl/`. Dutch remains the only current UI locale; this extraction
does not add a language selector or an English interface.

The catalogues cover:

- `shell.php`, `auth.php`, `identity.php`, and `onboarding.php`: shared shell,
  authentication, identity, and installation;
- `ai.php`: AI settings, provider status, results, review, and errors;
- `operations.php`: diagnostics, processing, integrity, OCR, storage, file
  versions, trash, and background jobs;
- `catalogue.php`: upload, photo detail, catalogue references, collections,
  worklists, and bulk actions;
- `publication.php`: public portal, publication administration, and visitor
  suggestions;
- `exchange.php`: import and export screens;
- `shared.php`: remaining user/operator messages from jobs, services, and
  console commands.

Existing Dutch rendering, escaping, bindings, markup, and accessible labels
remain unchanged. Stable machine codes, SQL, query aliases, HTTP headers,
provider/API tokens, and static Artisan command metadata are not translated.
The scanner removes Blade comments and ignores only explicitly named technical
values and recognizable parser fragments; complete files are not hidden via
`EXTRACTED_FILES`.

`docs/localization/text-inventory.remaining.json` was regenerated with
`UserVisibleTextScanner` and contains **0 remaining user-visible entries**. The
regression test compares the file with the scanner and requires an empty
inventory, so newly introduced raw user copy fails the test.
