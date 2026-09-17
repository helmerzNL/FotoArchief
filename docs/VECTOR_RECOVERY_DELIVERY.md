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
| 6. Vorige Dockerrelease upgraden | Linux-CI uitgevoerd | Gepinde v0.9.52-image naar huidige build; behoud van account, installatie, sleutel en private bestanden. |
| 7. Vorige ZIPrelease upgraden | Lokaal uitgevoerd | Gepubliceerde v0.9.52-ZIP naar v0.9.53-productiepakket, PostgreSQL, HTTP-onboarding/login/upload, echte worker en tweemaal migreren. |
| 8. Versleutelde volledige restore | Linux-CI uitgevoerd | Volledige Compose-backup versleutelen, ontsleutelen en herstellen naar lege volumes/database; bestaande doeldata weigeren. |
| 9. S3-restore | Lokaal uitgevoerd | Afzonderlijke echte PostgreSQL- en SeaweedFS-diensten; bron gestopt en hersteld naar nieuwe lege diensten, inclusief private installatiestatus en objectchecksums. |
| 10. Opslagmigratie hervatten | Lokaal getest | Echte workeronderbreking na receipt/cursor; duurzame tellingen, werkelijke disk-omschakeling, afgeleide-checksums en hervatbare bronopruiming. |
| 11. AI-review in de browser | Lokaal uitgevoerd | Metadatarevisie wijzigen, beschrijving/tag accepteren, afwijzen met reden, echte bronwijziging weigeren en beslissingen teruglezen. |
| 12. Upload/ingest in de browser | Lokaal uitgevoerd | Echte multipart-upload en queueworker, private JPEG-preview, expliciet niet-gescand en zichtbare duplicaatfout. |
| 13. Rollen in de browser | Lokaal uitgevoerd | Echte logins voor viewer, vrijwilliger, editor en archivaris; ownership, formulieren en geweigerde directe routes. |
| 14. Publicatie intrekken/embargo/prullenbak | Lokaal uitgevoerd | Anonieme detail-, media- en IIIF-routes: 200 vóór intrekken/verwijderen, 404 erna; embargo altijd 404. |
| 15. Mobiel en toetsenbord | Lokaal uitgevoerd | Chromium op 360 px: echte stylesheet, preview, labels, zichtbare focus, Tab/Enter, opgeslagen wijziging en geen paginaoverflow. |
| 16. Veilige 50k-fixture | Lokaal uitgevoerd | Expliciete opt-in, gemarkeerde tijdelijke opslag, loopback en lege benchmarkdatabase; 50.000 metadatarecords, waarvan 45.000 openbaar geschikt; vijf negatieve veiligheidscontroles geslaagd. |
| 17. Werkelijk gelijktijdig HTTP-verkeer | Gebouwd, latency nog niet geaccepteerd | Vier eigen PHP-workers met gemeten overlappende requestintervallen; 40 metingen per route, naast afzonderlijke sequentiele controles. |
| 18. pgvector-capaciteitsproef | Lokaal uitgevoerd | 50.000 echte 384-dimensionale vectoren, exacte cosine, correcte ranking; adapter-p95 603,65 ms bij een grens van 700 ms. |
| 19. Nederlandse zoekrelevantie | Lokaal uitgevoerd | Drie onafhankelijke verwachte resultatenlijsten tegen de echte publieke HTTP-zoekroute: precision@5/recall@5 1,0; verkeerde providerranking geeft 0,0 en exitcode 1. |
| 20. Provideruitval/budget/noodstop | Lokaal getest | 503, 429, timeout, ongeldig antwoord, budgetweigering, noodstop en herstel; eerder opgeslagen analyses blijven behouden bij een latere itemfout. |

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

### Capaciteit en storingsherstel 16-20

De nieuwe benchmark gebruikt uitsluitend eigen loopbackdiensten en synthetische
data: geen echte foto's, productiebelasting of betaalde providerrequests.
De volledige procedure staat in [RELEASE_ACCEPTANCE.md](RELEASE_ACCEPTANCE.md).
Vier PHP-processen ontvangen daadwerkelijk overlappende requests; alleen vier
clientpromises starten geldt niet als bewijs. Per route worden 40 requests
gemeten. De p95-drempels blijven **800 ms** voor de private lijst/tekstzoekroute,
**400 ms** voor private/publieke details en **700 ms** voor publiek gefilterd
zoeken, semantisch zoeken en de pgvector-adapter.

De herhaalde lokale Windows-run haalde de adapterdrempel, maar overschreed
meerdere HTTP-drempels. Dit is geen geslaagde totale capaciteitsacceptatie;
de controles zijn niet versoepeld. De private lijst/tekstzoekroute haalde
sequentieel 700,32/653,93 ms; de private detailroute 758,38 ms en publieke
filter/detailroutes 1070,19/651,75 ms. Met vier gelijktijdige clients was
semantisch zoeken 2931,31 ms, met database-p95 2339,52 ms. Alle zes routes
bewezen overlap tussen vier verschillende PHP-workers. Publieke metingen
zijn anoniem. De fixture stopt zijn eigen diensten ook bij deze rode gates.
SQL-planuitvoer is beschikbaar wanneer de afzonderlijke adapterdrempel faalt.
De relevantieproef verwacht precision@5 en recall@5 van **1,0** voor drie
vooraf beoordeelde synthetische onderwerpen. Een verkeerde embeddingprovider
moet diezelfde echte route laten zakken. Dit meet de zoekketen, niet de
kwaliteit van een echt taal-/beeldmodel.

De storingsproeven vonden twee productfouten: een afgeronde herpoging hield
de eerdere foutmelding, en een succesvol eerste item kreeg nog geen duurzame
cursor/telling wanneer een later item faalde. Resultaat, suggesties, audit,
cursor en absolute telling worden nu per item transactioneel opgeslagen.
Een herpoging vraagt een bevestigd item niet opnieuw op. Een ingetrokken
workerclaim mag een later providerantwoord niet alsnog opslaan; foutafhandeling
overschrijft geen geannuleerde taak of nieuwe claimhouder.

503, 429, timeout en ongeldige antwoorden worden zichtbaar gelogd; reserveringen
worden vrijgegeven en definitieve metadata wordt niet automatisch overschreven.
Een uitgeput budget weigert de request voordat transport plaatsvindt. Een
ontvangen providerantwoord telt wel mee in het lokale budget, ook als de
worker daarna geen schrijfbevoegdheid meer heeft. Een onzekere upstreamuitkomst
tussen provideracceptatie en lokale commit kan nog dubbele kosten veroorzaken:
dit is nadrukkelijk geen exactly-once-facturatiegarantie.

Geen nieuwe productievariabelen, Compose-mappings of verhoogde
applicatielimieten. De benchmarkvariabelen zijn uitsluitend testconfiguratie.
De gerichte eindcontrole is geslaagd: **43 tests, 486 assertions**, inclusief
herstel, claimwisseling en productiearchieven. De volledige suite vond een
achtergebleven ZIP-testfixture zonder taalcatalogi; die is bijgewerkt en
ontbrekende/verouderde catalogi hebben nu expliciete weigeringstests.
PHPStan, Pint, vertaalcontrole, JavaScript-syntax en workflowvalidatie slagen.
De uiteindelijke Linux-CI- en releasebewijzen worden bij de PR/release vastgelegd;
lokale Windows-metingen bewijzen geen productiecapaciteit.

### Linux-CI-correcties

Run `35235157534` bevestigt de volledige PHP-suite, browseracceptatie en
Docker-upgrade/versleutelde restore. Twee andere gates faalden terecht:
de tijdelijke S3-hersteldatabase gebruikte een niet-schrijfbare Linux-socketmap;
de adapter-p95 was **815,39 ms** en gelijktijdig publiek semantisch zoeken
**5727,92 ms**, beide boven **700 ms**.

De hersteldatabase gebruikt nu alleen loopback-TCP, zonder Unix-socket.
Het echte SQL-plan toonde 48.500 herhaalde asset-opzoekingen per dubbele
controle. De kandidaatquery gebruikt de modelscope rechtstreeks op de
gejoinde asset; publicatievoorwaarden delen nu één asset-EXISTS. De toegestane
ID-subquery blijft in PostgreSQL een afzonderlijke querygrens (`OFFSET 0`),
zodat de bronchecksum-join de publicatiecontroles niet per vector herhaalt.
Een omhullende subquery behoudt bestaande caller-limieten/offsets.
Alle bron-, scan-, eigendoms-, embargo-, rechten- en prullenbakcontroles
blijven vóór de resultaatlimiet staan. Er is geen ANN-benadering, cache van
autorisatie of versoepelde drempel toegevoegd.

Gerichte regressies: **47 tests, 175 assertions**, inclusief echte pgvector-
zoekroutes, publicatie en begrensde autorisatiescopes. De definitieve
capaciteitsacceptatie vereist een nieuwe volledige Linux-run op deze reparatie.

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
| 6. Previous Docker release upgrade | Run in Linux CI | Pinned v0.9.52 image to current build; preserve account, installation, key and private files. |
| 7. Previous ZIP release upgrade | Run locally | Published v0.9.52 ZIP to v0.9.53 production package, PostgreSQL, HTTP onboarding/login/upload, real worker and two migration runs. |
| 8. Encrypted full restore | Run in Linux CI | Encrypt the complete Compose backup, decrypt and restore into empty volumes/database; reject existing target data. |
| 9. S3 restore | Run locally | Separate real PostgreSQL and SeaweedFS services; source stopped and restored into new empty services, including private installation state and object checksums. |
| 10. Resumable storage migration | Tested locally | Actual worker interruption after receipt/cursor; durable counts, actual disk cutover, derivative checksums and resumable source cleanup. |
| 11. Browser AI review | Run locally | Edit metadata revision, accept description/tag, reject with reason, refuse changed source and read back decisions. |
| 12. Browser upload/ingest | Run locally | Real multipart upload and queue worker, private JPEG preview, explicit unscanned state and visible duplicate failure. |
| 13. Browser roles | Run locally | Real viewer, volunteer, editor and archivist logins; ownership, forms and denied direct routes. |
| 14. Revocation/embargo/trash | Run locally | Anonymous detail, media and IIIF routes: 200 before revocation/trash, 404 afterwards; embargo always 404. |
| 15. Mobile and keyboard | Run locally | Chromium at 360 px: real stylesheet, preview, labels, visible focus, Tab/Enter, persisted edit and no page overflow. |
| 16. Safe 50k fixture | Run locally | Explicit opt-in, marked temporary storage, loopback and empty benchmark database; 50,000 metadata records, including 45,000 eligible public records; five negative safety checks passed. |
| 17. Real concurrent HTTP traffic | Built, latency not accepted yet | Four owned PHP workers with measured overlapping request intervals; 40 samples per route alongside separate sequential checks. |
| 18. pgvector capacity test | Run locally | 50,000 real 384-dimensional vectors, exact cosine and correct ranking; adapter p95 603.65 ms against 700 ms. |
| 19. Dutch search relevance | Run locally | Three independent expected-result lists against the actual public HTTP search route: precision@5/recall@5 1.0; wrong provider ranking gives 0.0 and exit code 1. |
| 20. Provider outage/budget/emergency stop | Tested locally | 503, 429, timeout, malformed response, budget refusal, emergency stop and recovery; previously persisted analyses survive a later item failure. |

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

### Capacity and fault recovery 16-20

The new benchmark uses only owned loopback services and synthetic data: no
real photos, production load or paid provider requests. The full procedure is
in [RELEASE_ACCEPTANCE.md](RELEASE_ACCEPTANCE.md). Four PHP processes receive
actually overlapping requests; merely starting four client promises is not
proof. Each route has 40 measured requests. The p95 thresholds remain **800 ms**
for private listing/text search, **400 ms** for private/public details and
**700 ms** for public filtered search, semantic search and the pgvector adapter.

The repeated local Windows run passed the adapter threshold but exceeded
several HTTP thresholds. This is not successful overall capacity acceptance;
the controls were not weakened. Sequential private list/text search measured
700.32/653.93 ms, private detail 758.38 ms, and public filtered/detail routes
1070.19/651.75 ms. With four concurrent clients, semantic search measured
2931.31 ms, including database p95 of 2339.52 ms. All six routes proved overlap
between four distinct PHP workers. Public measurements are anonymous. The
fixture stops its owned services even with red gates. SQL plan output is
available when the standalone adapter threshold fails. Relevance requires
precision@5 and recall@5 of **1.0** for three independently judged synthetic
topics. A wrong embedding provider must fail the same real route. This tests
the search pipeline, not the quality of a real language/vision model.

Fault tests found two product bugs: a completed retry retained its earlier
error, and a successful first item had no durable cursor/count when a later
item failed. Result, suggestions, audit, cursor and absolute count now commit
transactionally per item. Retrying does not request a confirmed item again.
A revoked worker claim cannot persist a late provider response; exception
handling does not overwrite a cancelled task or a new claim owner.

503, 429, timeout and malformed responses are visibly audited; reservations
are released and definitive metadata is not overwritten automatically.
An exhausted budget refuses the request before transport. A received provider
response still counts toward the local budget even when the worker subsequently
loses write ownership. An ambiguous upstream outcome between provider
acceptance and local commit can still cause duplicate charges: this explicitly
does not guarantee exactly-once billing.

No new production variables, Compose mappings or increased application limits.
Benchmark variables are test configuration only.
Final targeted checks passed: **43 tests, 486 assertions**, including recovery,
claim replacement and production archives. The full suite found a stale ZIP
test fixture without translation catalogues; this was updated and missing/stale
catalogues now have explicit rejection tests. PHPStan, Pint, translation checks,
JavaScript syntax and workflow validation pass. Final Linux CI and release
evidence will be recorded with the PR/release; local Windows measurements do
not establish production capacity.

### Linux CI corrections

Run `35235157534` confirms the full PHP suite, browser acceptance and Docker
upgrade/encrypted restore. Two other gates correctly failed: the temporary
S3 recovery database used an unwritable Linux socket directory; adapter p95
was **815.39 ms** and concurrent public semantic search **5727.92 ms**,
both exceeding **700 ms**.

The recovery database now uses loopback TCP only, without a Unix socket.
The actual SQL plan showed 48,500 repeated asset lookups per duplicated
check. The candidate query applies model scopes directly to the joined asset;
publication requirements now share one asset EXISTS. The eligible-ID subquery
remains a separate PostgreSQL query boundary (`OFFSET 0`), preventing the
source-checksum join from repeating publication checks for every vector.
A wrapping subquery preserves existing caller limits/offsets.
All source, scan, ownership, embargo, rights and trash checks still precede
the result limit. No ANN approximation, authorization cache or relaxed
threshold was introduced.

Targeted regressions: **47 tests, 175 assertions**, including actual pgvector
search routes, publication and bounded authorization scopes. Final capacity
acceptance requires another complete Linux run on this correction.
