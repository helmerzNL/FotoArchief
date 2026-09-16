# Uitwisseling: CSV-import en export van metadata en beeld

FotoArchief wisselt metadata uit via CSV. Deze module (`app/Modules/DataExchange`)
staat los van de fotoschermen: een import raakt nooit beeldbestanden aan en maakt
nooit nieuwe foto’s aan.

Schermen staan onder `/exchange` en zijn Nederlandstalig. De module heeft geen
API-only pad nodig: alles wat een vrijwilliger of beheerder doet, kan via het web.

## Wat een import wel en niet doet

- Werkt **alleen bestaande foto’s** bij, gevonden op `accession_number`.
- Maakt nooit een foto aan, verwijdert nooit een foto en raakt nooit
  originelen, afgeleiden of opslagpaden aan.
- Wist nooit gegevens met een lege cel. Een lege cel betekent "niet wijzigen".
- Wijzigt niets zolang de controle (dry run) niet is bevestigd.
- Legt elke gewijzigde foto vast als `metadata.imported` in de fotogeschiedenis,
  inclusief de waarden voor en na de wijziging en het importnummer.

## Kolommen

Verplicht:

| Kolom | Betekenis |
| --- | --- |
| `accession_number` | Archiefnummer van de bestaande foto |
| `lock_version` | Versienummer uit het fotoscherm of de export (optimistische vergrendeling) |

Optioneel: `title`, `description`, `date_precision`, `date_earliest`,
`date_latest`, `date_display`, `tags`, `rights_holder`, `rights_status`,
`rights_note`.

Nederlandse koppen worden herkend: `archiefnummer`, `versie`, `titel`,
`beschrijving`, `dateringstype`, `datum_vanaf`, `datum_tot`, `datumweergave`,
`trefwoorden`, `rechthebbende`, `rechtenstatus`, `rechtennotitie`. Onbekende
kolommen worden getoond als "genegeerd" en nooit geïnterpreteerd.

Waarden:

- `date_precision`: `unknown`, `exact`, `circa`, `year`, `range`, `before`,
  `after`, `decade` (of `onbekend`, `jaar`, `bereik`, `voor`, `na`, `decennium`).
- `date_earliest` / `date_latest`: `JJJJ-MM-DD`, of `JJJJ` bij `year`/`decade`.
- `tags`: gescheiden door komma of puntkomma, maximaal 20 van 100 tekens.
- `rights_status`: `unverified`, `verified` of `disputed`.

De importlezer verwijdert de formule-bescherming van exports: een cel die begint
met `'=`, `'+`, `'-` of `'@` wordt weer `=`, `+`, `-` of `@`. Een export/import
rondgang verandert de inhoud dus niet.

## Schrijfstanden

| Stand | Gedrag |
| --- | --- |
| `fill_empty` (standaard) | Vult alleen velden die nu leeg zijn. Trefwoorden worden aangevuld, nooit verwijderd. |
| `overwrite` | Vervangt ingevulde waarden waar de CSV een waarde heeft. Trefwoorden worden vervangen. |

In beide standen blijft een lege cel zonder gevolgen.

## Werkwijze

1. Ga naar **Uitwisseling** in de menubalk (rechten: `assets.view` +
   `assets.update`).
2. Kies het CSV-bestand (UTF-8) en de schrijfstand, en verstuur.
3. FotoArchief leest het bestand, toont de kolomherkenning en per rij wat er zou
   veranderen. Er is op dat moment **niets** gewijzigd.
4. Controleer de rijen met fouten. Die worden bij bevestiging overgeslagen.
5. Klik op **Ja, deze rijen bijwerken**. De import wordt in de wachtrij gezet en
   door een worker uitgevoerd.
6. Vernieuw de pagina voor het resultaat per rij.

Bij "Voorbeeld opnieuw maken" wordt het bestand opnieuw gecontroleerd, eventueel
met een andere schrijfstand. Een mislukte run kan opnieuw worden bevestigd; rijen
die al zijn bijgewerkt blijven ongemoeid.

## Grenzen

| Grens | Standaard | Instelling |
| --- | --- | --- |
| Bestandsgrootte | 5 MiB (5.242.880 bytes) | `EXCHANGE_MAX_IMPORT_BYTES` |
| Aantal rijen | 5.000 | `EXCHANGE_MAX_IMPORT_ROWS` |
| Synchrone controle tot | 262.144 bytes | `EXCHANGE_SYNC_ANALYSIS_BYTES` |
| Aantal kolommen | 60 | vast in `config/exchange.php` |
| Tekens per cel | 10.000 | vast in `config/exchange.php` |

Grotere bestanden worden niet in het verzoek gecontroleerd maar door de worker,
zodat een webverzoek nooit lang blijft hangen.

**Grenzen buiten deze repository.** Een upload van 5 MiB moet ook worden
toegelaten door alles wat vóór de applicatie staat: reverse proxy, CDN of
ingress. Sta daar minimaal **6 MiB (6.291.456 bytes)** per verzoek toe, anders
weigert die laag het verzoek voordat FotoArchief het ziet en verschijnt er geen
foutmelding in de applicatielogboeken. In `deploy/php.ini` gelden daarnaast
`upload_max_filesize` en `post_max_size`.

## Beveiliging en rechten

- Een import is privé voor de indiener; andere gebruikers krijgen 404.
- Rijen worden alleen voorbereid en uitgevoerd voor foto’s die de indiener op dat
  moment mag bijwerken (vrijwilligers: alleen eigen foto’s).
- Rechten en versies worden **twee keer** gecontroleerd: bij de controle en
  opnieuw binnen de transactie van elke rij.
- Komt de versie niet meer overeen, dan wordt de rij overgeslagen en blijft de
  bestaande waarde staan.
- Het CSV-bestand zelf staat in private opslag onder `exchange/imports/`; er is
  geen publieke URL naar dat bestand.

## Verwerking en herstel

- De controle en de uitvoering draaien op de `ingest`-wachtrijverbinding, op
  dezelfde database als de gegevens zelf.
- Zonder actieve worker (`php artisan queue:work --queue=ingest`) blijft een
  import in "Controle bezig" of "In wachtrij" staan.
- Wordt de worker gestopt terwijl een run bezig is, dan pakt de wachtrij de run
  vanzelf weer op; zie "Wat er gebeurt als de worker stopt" hieronder.
- Een mislukte run kan opnieuw worden bevestigd.
- Een tweede uitvoering van dezelfde import wijzigt niets extra: rijen die al
  zijn bijgewerkt staan niet meer op `ready`.

### Wat er gebeurt als de worker stopt

Een import of export die wordt verwerkt is *geclaimd* door de worker. Wordt die
worker afgebroken -- door een herstart, een crash, een container die wordt
vervangen of een tijdslimiet -- dan blijft die claim achter terwijl er niemand
meer aan werkt. Zonder herstel blijft het scherm dan voor altijd "bezig" tonen.
Er zijn twee herstelwegen, en samen dekken ze beide gevallen:

1. **De wachtrij levert de taak opnieuw af.** Dat gebeurt `retry_after` seconden
   (180 voor `ingest`) na de claim. De taak neemt de run dan over: de import gaat
   verder met de rijen die nog op `ready` staan, de export wordt opnieuw
   samengesteld. Kan de taak de claim nog niet overnemen -- omdat er misschien
   nog een levende worker aan werkt -- dan wordt de taak *teruggezet* in de
   wachtrij in plaats van afgerond, zodat er een taak blijft die de run kan
   afmaken.
2. **Is er geen taak meer over**, bijvoorbeeld omdat de wachtrijtabel is geleegd
   of alle pogingen op zijn, dan meldt de planner de run na
   `EXCHANGE_ABANDONED_CLAIM_SECONDS` (30 minuten) als mislukt, met een
   Nederlandse uitleg in het scherm. De indiener kan de import opnieuw
   bevestigen of de export opnieuw laten samenstellen. Handmatig:
   `php artisan exchange:recover-imports` en `php artisan exchange:prune-exports`.

De drie tijden horen in deze volgorde te staan, en `.env.example` legt uit
waarom:

| Instelling | Standaard | Betekenis |
| --- | --- | --- |
| `EXCHANGE_JOB_TIMEOUT_SECONDS` | 120 | de worker breekt de taak hierna af |
| `EXCHANGE_STALE_CLAIM_SECONDS` | 150 | pas hierna mag een claim worden overgenomen |
| `retry_after` (`ingest`) | 180 | hierna levert de wachtrij de taak opnieuw af |

Staat het overnamevenster *boven* `retry_after`, dan komt de enige heraflevering
te vroeg om de run over te nemen. Staat het *onder* de taaklimiet, dan kan een
run die nog gewoon draait een tweede keer worden opgepakt.

---

# Export van metadata en beeld

Een export is een **aangevraagd, goedgekeurd en tijdelijk** bestand. Het wordt
door de wachtrij samengesteld, staat in private opslag en is alleen te downloaden
via een persoonlijke, kortlopende link. Er bestaat geen publieke of directe
opslag-URL naar een export of naar een origineel.

## Wie mag exporteren

- Alleen aangemelde gebruikers met het recht `exports.create`
  (standaard: archivaris en beheerder). Zonder dat recht: 403.
- Een export bevat alleen foto’s die de aanvrager op dat moment mag inzien.
  Vrijwilligers zien alleen eigen foto’s, publicerende rollen alle foto’s.
- Een export is privé voor de aanvrager. Een andere gebruiker krijgt 404, ook
  met het exportnummer in de hand.

## De drie formaten

| Formaat | Inhoud |
| --- | --- |
| Metadata (JSON) | `assets[]` met archiefnummer, titel, beschrijving, datering, trefwoorden, rechten, bestandsnamen en controlegetallen |
| Metadata (CSV) | Dezelfde kolommen als de import, dus rechtstreeks opnieuw importeerbaar |
| Volledig pakket (ZIP) | `metadata.json`, `metadata.csv`, `manifest.json`, `checksums.sha256`, `originals/<archiefnummer>/<bestandsnaam>` en `derivatives/<archiefnummer>/<maat>.jpg` |

De CSV gebruikt dezelfde kolommen als de import, inclusief `lock_version`, zodat
een export → bewerken → import ronde de optimistische vergrendeling respecteert.
Waarden die met `=`, `+`, `-`, `@`, tab of carriage return beginnen worden met een
apostrof geneutraliseerd, zodat een spreadsheet ze nooit als formule uitvoert; de
import verwijdert die apostrof weer.

`manifest.json` beschrijft elk bestand in het pakket met pad, soort, aantal bytes
en SHA-256, plus de lijst overgeslagen foto’s met reden. `checksums.sha256` heeft
het gebruikelijke `<sha256>  <pad>`-formaat en is te controleren met
`sha256sum -c checksums.sha256`. Een manifest bevat nooit opslagsleutels of
schijfnamen: een pakket beschrijft bestanden op naam en controlegetal.

Oude registraties zonder opslagschijf (`storage_disk` leeg) worden overgeslagen
en met reden in het manifest vermeld; het pakket blijft bruikbaar.

## De gang van een export

1. **Aanvragen** op `/exchange`: formaat kiezen, en ofwel een selectie foto’s
   ofwel "alles wat ik mag inzien". Rechten worden hier voor elke foto gecheckt.
2. **Samenstellen** door de worker op de `ingest`-verbinding. De rechten worden
   *opnieuw* gecheckt; foto’s die intussen buiten bereik vielen komen niet in het
   bestand maar in de lijst overgeslagen foto’s.
3. **Klaar**: het exportscherm toont grootte, SHA-256, geldigheidsduur en het
   manifest.
4. **Downloaden**: de knop vraagt een persoonlijke link aan. De rechten worden
   voor de derde keer gecheckt, op elke foto in het bestand.
5. **Opruimen**: na `EXCHANGE_EXPORT_TTL_MINUTES` verloopt de export en wordt het
   bestand verwijderd.

## Waarom een latere rechtenwijziging niet kan lekken

Een bestand dat gisteren terecht is samengesteld, mag vandaag niet meer bruikbaar
zijn voor iemand die intussen toegang verloor. Daarom:

- wordt bij het aanvragen van elke downloadlink opnieuw gecontroleerd of de
  aanvrager `exports.create` heeft én elke foto in het bestand nog mag inzien;
- wordt bij de download zelf dezelfde controle herhaald;
- wordt de export bij een mislukte controle **ingetrokken**: het bestand wordt
  direct verwijderd, de status wordt `revoked` en de gebruiker krijgt 403 met de
  uitleg dat er een nieuwe export nodig is;
- is de downloadlink kortlopend (`EXCHANGE_DOWNLOAD_TTL_MINUTES`, standaard 10
  minuten), eenmalig uit te geven per aanvraag en alleen geldig voor de eigenaar.
  De token staat gehasht in de database, dus een databasekopie levert geen
  bruikbare link op.

Elke download wordt per foto vastgelegd als `export.downloaded` in de
fotogeschiedenis; opname in een pakket als `export.included`.

## Grenzen

| Instelling | Standaard | Betekenis |
| --- | --- | --- |
| `EXCHANGE_MAX_EXPORT_ASSETS` | 500 | Maximaal aantal foto’s per export |
| `EXCHANGE_MAX_EXPORT_BYTES` | 1073741824 (1 GiB) | Grootste bestand dat wordt samengesteld |
| `EXCHANGE_EXPORT_TTL_MINUTES` | 120 | Bewaartijd van het bestand |
| `EXCHANGE_DOWNLOAD_TTL_MINUTES` | 10 | Geldigheid van een downloadlink |

Deze grenzen gelden binnen de applicatie. Wat er vóór de applicatie draait —
reverse proxy, CDN, ingress — heeft eigen grenzen die de applicatie niet kan
zien. Een download van 1 GiB komt alleen aan als elke laag ervoor minstens
1073741824 bytes aan antwoord toestaat en de verbinding lang genoeg openhoudt om
dat te streamen. Wordt zo’n limiet elders bereikt, dan ziet FotoArchief de
afbreking niet en staat er niets in het applicatielogboek.

## Beheer en opruimen

- De ZIP wordt gemaakt met de PHP-extensie `zip` (`ext-zip`). Die staat in
  `composer.json` als platformeis en in het Docker-image; zonder die extensie
  mislukt een pakketexport met een duidelijke foutmelding. De installatiewizard
  controleert de extensie vooraf, omdat een uitgepakte release geen Composer
  draait en de eis daar anders pas bij de eerste export zou opvallen.
- Verlopen exports worden elk kwartier opgeruimd door de planner
  (`php artisan schedule:work` of een cron op `schedule:run`). Handmatig:
  `php artisan exchange:prune-exports`.
- Een mislukte export (bijvoorbeeld door een verdwenen origineel) is opnieuw in
  te plannen met de knop "Opnieuw samenstellen". De aanvraag zelf blijft bewaard.
- Zonder actieve worker (`php artisan queue:work --queue=ingest`) blijft een
  export in "In wachtrij" staan.
- Wordt de worker afgebroken terwijl een export wordt samengesteld, dan neemt de
  opnieuw afgeleverde taak de export over; blijft er geen taak over, dan meldt
  `php artisan exchange:prune-exports` de export als mislukt zodat hij opnieuw
  kan worden ingepland. Zie "Wat er gebeurt als de worker stopt" hierboven.
- Exportbestanden staan onder `exchange/exports/` op de private schijf. Ze horen
  **niet** in een backup thuis: het zijn afgeleide, kortlopende kopieën.