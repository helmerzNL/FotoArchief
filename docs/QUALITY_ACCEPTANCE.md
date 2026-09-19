# Meetbare kwaliteit / Measurable quality

## Nederlands

### Benchmarkcontract

De PostgreSQL-acceptatiepipeline maakt uitsluitend na expliciete opt-in een
lege, wegwerpbare `*_benchmark_test`-database met exact 50.000 synthetische
foto's. De versiebeheerbare grenzen staan in
`tests/Performance/budgets.v1.json`. De pipeline meet na vijf opwarmverzoeken
veertig verzoeken per route en controleert de p95 voor private catalogus-,
publieke, semantische en pgvectorpaden. De concurrencyproef vereist vier
gelijktijdige clients én aantoonbare overlap tussen verschillende PHP-workers.

Ontbrekende metingen zijn een fout. Bij iedere run ontstaat
`benchmark-results.json` met het budgetcontract, de datasetgrootte, de gemeten
waarden en het resultaat per pad. CI publiceert dit bestand als artifact.

### Semantische relevantie

`tests/Performance/relevance-dataset.v1.json` bevat de vaste vragen,
verwachte rankings, `k` en drempels. De normale proef moet alle drempels halen;
de verkeerde-ranking-negatieve controle moet falen. Dit bewijst de
applicatierangschikking met de deterministische lokale testprovider. Kwaliteit
van een extern model en een echte collectie blijft afzonderlijk
acceptatiebewijs.

### Toegankelijkheid

De Chromium-acceptatiesuite voert axe-regels voor WCAG A en AA uit op de
homepage, login, publieke ontdekking, fotodetail, dashboard, catalogus,
upload- en publicatiebeheer. De viewer heeft zichtbare bediening, een benoemde
focusbare viewport, toetsenbordzoom/pan/reset, statusmeldingen en een
no-JavaScript-link naar het IIIF-manifest. Dit is breed regressiebewijs en geen
claim van volledige WCAG 2.2 AA-conformiteit; handmatige toetsing met
ondersteunende technologie blijft vereist.

### IIIF-viewer

De ingebouwde viewer leest het bestaande IIIF Presentation 3-manifest en toont
de daarin beschreven begrensde JPEG-afgeleide. Hij implementeert geen IIIF
Image API, tileserver of onbeperkte deep zoom. Bij een manifestfout blijft de
zichtbare voorbeeldafbeelding beschikbaar en meldt de viewer de fallback.

## English

### Benchmark contract

After explicit opt-in, the PostgreSQL acceptance pipeline creates an empty,
disposable `*_benchmark_test` database with exactly 50,000 synthetic photos.
Version-controlled limits live in `tests/Performance/budgets.v1.json`. After
five warm-up requests, the pipeline measures forty requests per route and
checks p95 for private catalogue, public, semantic, and pgvector paths. The
concurrency run requires four concurrent clients and verified overlap between
different PHP workers.

Missing measurements fail the run. Every run produces
`benchmark-results.json` with the budget contract, dataset size, measurements,
and per-path outcome. CI publishes that file as an artifact.

### Semantic relevance

`tests/Performance/relevance-dataset.v1.json` contains fixed queries, expected
rankings, `k`, and thresholds. The normal run must meet every threshold; the
wrong-ranking negative control must fail. This proves application ranking with
the deterministic local test provider. External-model and real-collection
quality remain separate acceptance evidence.

### Accessibility

The Chromium acceptance suite runs axe WCAG A and AA rules across the home
page, login, public discovery, photo detail, dashboard, catalogue, upload, and
publication management. The viewer provides visible controls, a named
focusable viewport, keyboard zoom/pan/reset, status messages, and a no-JavaScript
manifest link. This is broad regression evidence, not a claim of complete WCAG
2.2 AA conformance; manual assistive-technology testing remains required.

### IIIF viewer

The embedded viewer reads the existing IIIF Presentation 3 manifest and shows
its bounded JPEG derivative. It does not implement the IIIF Image API, a tile
server, or unbounded deep zoom. If manifest loading fails, the visible preview
remains available and the viewer announces the fallback.
