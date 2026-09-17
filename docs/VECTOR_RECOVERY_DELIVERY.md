# Vector- en hersteloplevering / Vector and recovery delivery

## Nederlands

### Voortgang

Dit verslag wordt tijdens de oplevering bijgewerkt. Een voorziening is alleen
afgerond wanneer de daadwerkelijke toepassingsroute en de genoemde test zijn
uitgevoerd; een stub, synthetische fixture of een overgeslagen omgevingscheck
is geen productieacceptatie.

| Functie | Status | Bewijs |
| --- | --- | --- |
| 1. pgvector-capabilitycontrole | Gebouwd | Ontbrekende extensie/kolom blokkeert indexering en zoeken; een ontbrekende actieve index blokkeert alleen zoeken, steeds vóór een providerrequest. |
| 2. pgvector-opslag/queryadapter | Gebouwd | Echte `vector`-opslag en exacte cosine-query, zonder JSON-fallback; expliciete provisioning voor later toegevoegde extensies. |
| 3. Queue-indexering | Gebouwd | Vector, succesreceipt, audit, cursor en telling worden per item samen gecommit; herpogingen hergebruiken duurzaam opgeslagen resultaten. |
| 4. Admin/publiek semantisch zoeken | Gebouwd | Echte routes getest tegen PostgreSQL; bron-, ownership- en publicatiefilters vóór de resultaatlimiet, met laatste autorisatiehercontrole. |
| 5. Generaties/modelwissel | Gebouwd | Afzonderlijke run-generaties, transactionele head-wissel, blokkeren van verouderde parallelle builds, modelwissel, bronhercontrole, generatie-audit en herstel na een daadwerkelijk afgebroken workerproces. |
| 6. Vorige Dockerrelease upgraden | Gebouwd, CI nog te draaien | Gepinde v0.9.52-image naar huidige build; behoud van account, installatie, sleutel en private bestanden. |
| 7. Vorige ZIPrelease upgraden | Lokaal uitgevoerd | Gepubliceerde v0.9.52-ZIP naar v0.9.53-productiepakket, PostgreSQL, HTTP-onboarding/login/upload, echte worker en tweemaal migreren. |
| 8. Versleutelde volledige restore | Gebouwd, CI nog te draaien | Volledige Compose-backup versleutelen, ontsleutelen en herstellen naar lege volumes/database; bestaande doeldata weigeren. |
| 9. S3-restore | Lokaal uitgevoerd | Afzonderlijke echte PostgreSQL- en SeaweedFS-diensten; bron gestopt en hersteld naar nieuwe lege diensten, inclusief private installatiestatus en objectchecksums. |
| 10. Opslagmigratie hervatten | Lokaal getest | Echte workeronderbreking na receipt/cursor; duurzame tellingen, werkelijke disk-omschakeling, afgeleide-checksums en hervatbare bronopruiming. |
| 11. AI-review in de browser | Lokaal uitgevoerd | Metadatarevisie wijzigen, beschrijving/tag accepteren, afwijzen met reden, echte bronwijziging weigeren en beslissingen teruglezen. |
| 12. Upload/ingest in de browser | Lokaal uitgevoerd | Echte multipart-upload en queueworker, private JPEG-preview, expliciet niet-gescand en zichtbare duplicaatfout. |
| 13. Rollen in de browser | Lokaal uitgevoerd | Echte logins voor viewer, vrijwilliger, editor en archivaris; ownership, formulieren en geweigerde directe routes. |
| 14. Publicatie intrekken/embargo/prullenbak | Lokaal uitgevoerd | Anonieme detail-, media- en IIIF-routes: 200 vóór intrekken/verwijderen, 404 erna; embargo altijd 404. |
| 15. Mobiel en toetsenbord | Lokaal uitgevoerd | Chromium op 360 px: echte stylesheet, preview, labels, zichtbare focus, Tab/Enter, opgeslagen wijziging en geen paginaoverflow. |
| 16–20 | Nog te bouwen | Geen claim op basis van bestaande stubs of fixtures. |

### Uitgevoerde lokale controles

Onderstaande volledige suite betreft de eerste batchcommit `4720821`, niet
automatisch de latere herstelwijzigingen.

- `vendor/bin/pint --test`: geslaagd.
- Echte PostgreSQL 16.14 / pgvector 0.8.1: `PgvectorApplicationAdapterTest`, inclusief afzonderlijk queue-workerproces en transactionele rollback.
- Volledige regressiesuite: **594 geslaagd, 12 overgeslagen, 3453 assertions**. De 17 echte pgvector-scenario's zijn hierin uitgevoerd; de 12 skips betreffen andere expliciet geconfigureerde omgevingscontroles.
- `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress`: geen fouten.
- `php artisan translations:check`.

De fotoreferentietests gebruiken een expliciete opslagmock om het normaliseren,
queueën, verwerken en tonen van fotonummers te testen, niet de vectoropslag.
De kandidaatselectietests voeren de gedeelde SQL-filters op SQLite uit. De
afzonderlijke adaptertests gebruiken echte PostgreSQL, pgvector en de
toepassingsjobs/zoekroutes. Alleen de modelprovider wordt met deterministische
HTTP-antwoorden nagebootst. Een echte `queue:work --once`-subprocess stopt met
exitcode 73 na de eerste commit; herstel bewijst dat alleen het tweede item
nog wordt opgevraagd. Dit is geen bewijs van modelkwaliteit of exactly-once
facturatie bij de provider.

### Operatorinstructies voor voorzieningen 1-5

1. Installeer pgvector op de PostgreSQL-host. Laat een bevoegd databasebeheerder
   `CREATE EXTENSION IF NOT EXISTS vector;` uitvoeren in de applicatiedatabase.
   FotoArchief installeert de extensie niet zelf en verhoogt geen privileges.
2. Voer de normale upgrade uit met `php artisan migrate --force`.
3. Is de extensie pas na die migratie toegevoegd, voer dan
   `php artisan ai:provision-pgvector` uit. Dit commando is herhaalbaar.
4. Start een nieuwe AI-indexbatch om een actieve vectorindex te maken. Oude
   JSON-generaties worden niet gebruikt voor semantisch zoeken. Zonder
   pgvector blijven gewone zoekopdrachten en beeldanalyse beschikbaar.

Elke batch krijgt een eigen generatie. Bij dezelfde modelruimte en dimensies
worden actuele, niet-geselecteerde embeddings overgenomen. Een andere ruimte
of dimensie neemt geen oude vectoren over. De vorige head blijft behouden
tot succesvolle activering; een verouderde parallelle build vraagt expliciet
om een nieuwe taak. Als het ingestelde model niet bij de actieve generatie
hoort, blijft semantisch zoeken expliciet geblokkeerd tot herindexering.

Bronwijzigingen tijdens een build blokkeren activering en zijn zichtbaar in
het taakauditlog. Een herpoging hergebruikt alleen nog actuele succesreceipts.
Een noodstop tijdens de laatste providerrequest verhindert activering.
Een providerantwoord dat ontvangen is maar nog niet lokaal gecommit, kan bij
herstel opnieuw worden opgevraagd en opnieuw kosten veroorzaken.

De zoekadapter gebruikt exacte cosine-afstand, geen ANN-index. Er is nog geen
50k-latencyclaim. Dit blok wijzigt geen Compose- of omgevingstemplate en
verhoogt geen upload-, rate- of timeoutlimiet. Eigen database-images moeten
wel pgvector bevatten wanneer semantisch zoeken gewenst is.
Docker-, S3-, live-provider-, fysieke-apparaat- en representatieve-datasetacceptatie
blijven afzonderlijke, nog uit te voeren bewijzen.

### Herstelvoorzieningen 6-10

De productie-ZIP miste taalcatalogi. De builder neemt nu de volledige
`lang`-map op; de archieftest vergelijkt iedere Nederlandse catalogus met
dezelfde bronrevision. De deploy-ZIP bevat ook de upgrade- en encryptiehelpers.

De onderbrekingsproef reproduceerde achterlopende tellers na een opgeslagen
receipt en een opgeslagen cursor zonder bijbehorende taaktelling. Bovendien
wijzigde omschakelen de geregistreerde bestandsdisk niet. Deze paden zijn
hersteld. OCR en definitieve verwijdering volgen nu de geregistreerde disk,
inclusief legacy `null` als `local`.

Geverifieerde afgeleide-checksums worden duurzaam opgeslagen. Bronopruiming
kan daardoor na een gedeeltelijke verwijdering hervatten zonder ontbrekende
bronbytes als bewijs te gebruiken. Beschadigde doelen blokkeren opruiming;
een mislukte delete levert een fout op, geen afgeronde migratie. Oude receipts
krijgen bij omschakelen/opruimen checksums zolang bron en doel verifieerbaar
zijn. Ontbrekende legacy-bronbytes worden niet automatisch goedgekeurd.

De gerichte regressiecontrole van opslagmigratie, echte workeronderbrekingen,
OCR-diskkeuze en achtergrondtaaktellingen: **28 geslaagd, 200 assertions**.
Aanvullende eindcontrole van opslagmigratie en workerherstel, inclusief
buiten de toepassing gewijzigde disk/key/checksumbindingen:
**14 geslaagd, 161 assertions**; PHPStan en vertaalcontrole geslaagd.
De ZIP-upgradeproef gebruikte een tussentijdse packagefixture; de uiteindelijke
release moet opnieuw uit de definitieve commit worden gebouwd en getest.
De volledige S3-proef is geslaagd met PostgreSQL 16.14 en SeaweedFS 4.47:
afzonderlijke lege diensten, behoud van originele/afgeleide SHA-256,
installatiesleutel en account, gesloten installer en werkende private preview.
Een beschadigde snapshot, anonieme objecttoegang en een niet-leeg doel werden
geweigerd. Na een eerste initialisatietimeout slaagde de herhaling met zichtbare
diagnostiek en maximaal 300 seconden per databasebeheercommando.

Voor bestaande installaties volstaat de normale forward-migratie. Er zijn
geen nieuwe Compose-mappings of `.env`-variabelen en geen verhoogde
applicatielimieten. De upgradehelper wacht expliciet op gezonde diensten.
De S3-proef is een begrensde testharness, geen algemene productiebackup-tool
en geen Hetzner/offsite-acceptatie.

### Browservoorzieningen 11-15

Na handmatige Playwright-browserinteracties zijn vijf geautomatiseerde
Chromium-scenario's gebouwd: **5 geslaagd in 2,0 minuten** tegen PostgreSQL
16.14. De fixture doorloopt de echte HTTP-installatiewizard, login, CSRF,
multipart-upload en ingest-worker. Iedere run vereist een lege loopbackdatabase
met suffix `_browser_test`; opslag, sessies en caches liggen in een gemarkeerde
tijdelijke map. De toepassingseigen `.env` wordt niet gelezen. Alleen eigen
server-/workerprocessen en tijdelijke bestanden worden na afloop opgeruimd.
De database blijft beschikbaar voor diagnose en wordt niet gewist/hergebruikt.

De browserproef vond een echte redirectfout: verwijderen vanaf een dossier
leidde terug naar het soft-deleted dossier en daarmee naar 404. Het antwoord
verwijst nu naar de prullenbak met bevestiging. De gerichte PHP-regressiesuite
is geslaagd: **5 tests, 44 assertions**. PHPStan, Pint, TypeScript,
vertaalcontrole en workflowvalidatie zijn uitgevoerd.

Uitvoeren op een eigen wegwerpdatabase (PHP 8.5, PostgreSQL, Node 22):

```sh
cd tests/Browser
npm ci
npm run typecheck
npx playwright install chromium
cd ../..
FOTOARCHIEF_DISPOSABLE_BROWSER=1 \
FOTOARCHIEF_BROWSER_DATABASE=acceptance_browser_test \
FOTOARCHIEF_BROWSER_DB_PORT=5432 \
FOTOARCHIEF_BROWSER_DB_USER=browser_fixture \
php tests/Browser/fixture.php --run
```

Het testaccount gebruikt uitsluitend het vaste wegwerpwachtwoord
`disposable-fixture-password` voor PostgreSQL. De CI-job maakt deze database
zelf in een aparte service. Zonder `--run` blijft de fixture maximaal een uur
beschikbaar voor handmatige browserinteractie; een bestand `stop` in de
afgedrukte tijdelijke root stopt de eigen processen.

AI-resultaten en schone publiceerbare bestanden zijn expliciet synthetisch
voorbereid: geen provider is aangeroepen en ClamAV is niet uitgevoerd.
Nieuwe uploads doorlopen echte verwerking met scanner `none` en tonen
terecht **NIET GESCAND**. Dit is geen live-OpenAI-, ClamAV-, fysieke-mobiele-
of volledige WCAG-acceptatie. Geen nieuwe operatorvariabelen, Compose-mappings
of gewijzigde applicatielimieten.

## English

### Progress

This report is updated during delivery. A provision is complete only when its
real application path and stated test have run; a stub, synthetic fixture, or
skipped environment check is not production acceptance.

| Feature | Status | Evidence |
| --- | --- | --- |
| 1. pgvector capability check | Built | Missing extension/column blocks indexing and search; a missing active index blocks only search, always before a provider request. |
| 2. pgvector storage/query adapter | Built | Real `vector` storage and exact cosine queries, no JSON fallback; explicit provisioning for extensions installed later. |
| 3. Queue indexing | Built | Vector, success receipt, audit, cursor and count commit together per item; retries reuse durably stored results. |
| 4. Admin/public semantic search | Built | Real routes tested against PostgreSQL; source, ownership and publication filters before the result limit, with final authorization rechecks. |
| 5. Generations/model switching | Built | Separate per-run generations, transactional head switching, outdated concurrent-build rejection, model switching, source rechecks, generation audit and recovery from an actually interrupted worker process. |
| 6. Previous Docker release upgrade | Built, CI pending | Pinned v0.9.52 image to current build; preserve account, installation, key and private files. |
| 7. Previous ZIP release upgrade | Run locally | Published v0.9.52 ZIP to v0.9.53 production package, PostgreSQL, HTTP onboarding/login/upload, real worker and two migration runs. |
| 8. Encrypted full restore | Built, CI pending | Encrypt the complete Compose backup, decrypt and restore into empty volumes/database; reject existing target data. |
| 9. S3 restore | Run locally | Separate real PostgreSQL and SeaweedFS services; source stopped and restored into new empty services, including private installation state and object checksums. |
| 10. Resumable storage migration | Tested locally | Actual worker interruption after receipt/cursor; durable counts, actual disk cutover, derivative checksums and resumable source cleanup. |
| 11. Browser AI review | Run locally | Edit metadata revision, accept description/tag, reject with reason, refuse changed source and read back decisions. |
| 12. Browser upload/ingest | Run locally | Real multipart upload and queue worker, private JPEG preview, explicit unscanned state and visible duplicate failure. |
| 13. Browser roles | Run locally | Real viewer, volunteer, editor and archivist logins; ownership, forms and denied direct routes. |
| 14. Revocation/embargo/trash | Run locally | Anonymous detail, media and IIIF routes: 200 before revocation/trash, 404 afterwards; embargo always 404. |
| 15. Mobile and keyboard | Run locally | Chromium at 360 px: real stylesheet, preview, labels, visible focus, Tab/Enter, persisted edit and no page overflow. |
| 16–20 | Still to build | No claim is made from existing stubs or fixtures. |

### Local checks performed

The full suite below covers first-batch commit `4720821`, not automatically
the subsequent recovery changes.

- `vendor/bin/pint --test`: passed.
- Real PostgreSQL 16.14 / pgvector 0.8.1: `PgvectorApplicationAdapterTest`, including a separate queue-worker process and transactional rollback.
- Full regression suite: **594 passed, 12 skipped, 3453 assertions**. The 17 real pgvector scenarios ran in this suite; the 12 skips concern other explicitly configured environment checks.
- `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress`: no errors.
- `php artisan translations:check`.

Photo-reference tests use an explicit storage mock to test normalization,
queueing, processing, and display of photo references, not vector storage.
Candidate-selection tests execute shared SQL filters on SQLite. Separate
adapter tests use real PostgreSQL, pgvector and application jobs/search routes.
Only the model provider is simulated with deterministic HTTP responses. A real
`queue:work --once` subprocess exits with code 73 after the first commit;
recovery proves only the second item is requested afterwards. This does not
prove model quality or exactly-once provider billing.

### Operator instructions for features 1-5

1. Install pgvector on the PostgreSQL host. Have an authorized database operator
   execute `CREATE EXTENSION IF NOT EXISTS vector;` in the application database.
   FotoArchief does not install the extension or elevate privileges.
2. Perform the normal upgrade with `php artisan migrate --force`.
3. If the extension was added after that migration, run
   `php artisan ai:provision-pgvector`. This command is repeatable.
4. Start a new AI indexing batch to create an active vector index. Old JSON
   generations are not used for semantic search. Ordinary search and image
   analysis remain available without pgvector.

Every batch receives a separate generation. In the same model space and
dimensions, current unselected embeddings are copied. A different space or
dimension never copies old vectors. The previous head remains until successful
activation; an outdated concurrent build explicitly requires a new task.
If the configured model differs from the active generation, semantic search
remains explicitly blocked until reindexing.

Source changes during a build block activation and appear in the operation
audit. Retries reuse only still-current success receipts. An emergency stop
during the final provider request prevents activation. A received provider
response that has not yet committed locally may be requested and billed again
during recovery.

The search adapter uses exact cosine distance, not an ANN index. No 50k latency
claim is made yet. This batch changes no Compose/environment template and
raises no upload, rate or timeout limit. Operator-owned database images must
include pgvector when semantic search is required.
Docker, S3, live-provider, physical-device and representative-dataset acceptance
remain separate evidence that has not yet been executed.

### Recovery features 6-10

The production ZIP omitted translation catalogs. The builder now includes
the entire `lang` directory; the archive test compares every Dutch catalog
with the same source revision. The deploy ZIP includes upgrade and encryption
helpers as well.

Interruption tests reproduced stale counts after a committed receipt and a
saved cursor without matching operation counts. Cutover also failed to change
the registered file disk. These paths are fixed. OCR and permanent deletion
now follow the registered disk, including legacy `null` as `local`.

Verified derivative checksums are stored durably. Source cleanup can resume
after partial deletion without treating missing source bytes as evidence.
Damaged targets block cleanup; a failed delete reports an error rather than
a completed migration. Legacy receipts receive checksums during cutover or
cleanup while source and target remain verifiable. Missing legacy source
bytes are never approved automatically.

Targeted storage migration, real worker interruption, OCR disk selection and
operation-count regression checks: **28 passed, 200 assertions**.
Final storage migration and worker recovery checks, including out-of-band
disk/key/checksum binding changes: **14 passed, 161 assertions**; PHPStan
and translation checks passed.
The ZIP upgrade used an intermediate package fixture; the final release
must be rebuilt and tested from its final commit. Full S3 recovery passed with
PostgreSQL 16.14 and SeaweedFS 4.47: separate empty services, matching
original/derivative SHA-256, preserved installation key/account, locked
installer and working private preview. A damaged snapshot, anonymous object
access and a nonempty target were refused. After an initial initialization
timeout, the retry passed with visible diagnostics and a maximum of 300
seconds per database administration command.

Existing installations require the normal forward migration. No new Compose
mappings or `.env` variables, and no higher application limits. The upgrade
helper explicitly waits for healthy services. The S3 scenario is a bounded
test harness, not a general production backup tool or Hetzner/offsite
acceptance.

### Browser features 11-15

Following manual Playwright browser interactions, five automated Chromium
scenarios were built: **5 passed in 2.0 minutes** against PostgreSQL 16.14.
The fixture uses the real HTTP installation wizard, login, CSRF, multipart
upload and ingest worker. Each run requires an empty loopback database ending
in `_browser_test`; storage, sessions and caches live in a marked temporary
directory. The application's own `.env` is never loaded. Only owned server/
worker processes and temporary files are cleaned up. The populated database
remains available for diagnosis and is neither wiped nor reused.

The browser test found a real redirect bug: trashing from an asset returned
to the soft-deleted detail page and produced 404. It now redirects to the
trash dashboard with confirmation. Targeted PHP regression checks passed:
**5 tests, 44 assertions**. PHPStan, Pint, TypeScript, translation checks and
workflow validation were executed.

Use the command block in the Dutch section with PHP 8.5, PostgreSQL and Node
22 on a disposable database. Its database account uses the fixed disposable
password `disposable-fixture-password`; CI creates this in a separate service.
Without `--run`, the fixture stays available for manual browser interaction
for at most one hour; creating `stop` inside the printed temporary root stops
its processes.

AI outputs and clean publishable files are explicitly synthetic fixtures:
no provider was called and ClamAV was not executed. New uploads use real
processing with scanner `none` and correctly display **NIET GESCAND**.
This is not live OpenAI, ClamAV, physical mobile-device or full WCAG
acceptance. No new operator variables, Compose mappings or application limits.
