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
| 6–20 | Niet gestart | Geen claim op basis van bestaande stubs of fixtures. |

### Uitgevoerde lokale controles

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
| 6–20 | Not started | No claim is made from existing stubs or fixtures. |

### Local checks performed

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
