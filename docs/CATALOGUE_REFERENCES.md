# Foto's koppelen

Het veld **Aanwinstnummer of Foto-ID** bij collecties, personen, locaties,
bronnen en bijdragers accepteert een exact aanwinstnummer of een volledige
ULID van 26 tekens. Foto-ID's mogen in hoofdletters of kleine letters worden
geplakt; spaties voor en na de invoer worden verwijderd.

Een exact aanwinstnummer heeft voorrang wanneer het zelf op een ULID lijkt.
De afzonderlijke `asset_id`-parameter blijft uitsluitend een ID-verwijzing.
Een ontbrekende foto geeft een validatiefout. Koppelen vereist zowel lees-
als bewerktoegang tot de betreffende foto; een Foto-ID omzeilt geen eigendom.

Op smalle schermen schuiven brede catalogustabellen binnen hun eigen gebied,
niet de hele pagina. Het tabelgebied is met Tab bereikbaar en met de
pijltjestoetsen horizontaal te verschuiven.

## Reflow-regressie

`CatalogueTableReflowTest` controleert alle twaalf tabellen en rendert een
gevulde collectie. Stel `CATALOGUE_REFLOW_HTML` in op een tijdelijk HTML-pad
om die pagina als browserfixture te bewaren. Voer daarna
`node tests/Browser/catalogue-table-reflow.cjs <HTML-pad>` uit met Playwright
beschikbaar (eventueel via `PLAYWRIGHT_MODULE`).

De browsercontrole meet bij 390 en 1280 pixels de documentbreedte, test
toetsenbordscrollen en verwijdert vervolgens de wrappers om te bewijzen dat
dezelfde inhoud zonder de fix overloopt. Alleen het catalogusoppervlak wordt
gemeten; de afzonderlijk beheerde globale header en footer worden verwijderd.
