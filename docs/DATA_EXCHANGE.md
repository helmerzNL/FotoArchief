# Uitwisseling: CSV-import van metadata

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
- Een vastgelopen run wordt na drie minuten opnieuw claimbaar; een mislukte run
  kan opnieuw worden bevestigd.
- Een tweede uitvoering van dezelfde import wijzigt niets extra: rijen die al
  zijn bijgewerkt staan niet meer op `ready`.