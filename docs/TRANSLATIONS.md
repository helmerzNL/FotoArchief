# Vertaalsleutels en locale-pariteit / Translation keys and locale parity

## Nederlands

### Waarom deze controle bestaat

Laravel faalt niet op een ontbrekende vertaalsleutel. Het toont de sleutel zelf.
Een hernoemde of vergeten sleutel levert dus geen fout op in logboeken, tests of
monitoring: een bezoeker ziet `catalogue.asset.title` op de pagina staan en
niemand krijgt er een melding van. Een tweede taal maakt dat erger, omdat een
onvolledige catalogus per pagina terugvalt op een andere taal.

`translations:check` maakt die stille fout hard. De controle sluit standaard af
(fail-closed): alles wat niet bewezen kan worden, faalt.

### Wat de controle afdwingt

| Bevinding | Waarom het faalt |
| --- | --- |
| Gebruikte sleutel ontbreekt in een catalogus | Laravel rendert de ruwe sleutel naar de pagina |
| Catalogussleutel wordt nergens gebruikt | Hernoemde sleutels laten dode teksten achter die vertalers blijven onderhouden |
| Sleutel wordt op runtime opgebouwd (`__($key)`) | De scanner kan die niet verifiëren; alleen een expliciete allowlist mag dat toestaan |
| Locale mist een sleutel die een andere locale wel heeft | Pariteit tussen `nl`, en later `en`/`fr`/`de`, moet compleet zijn |
| Catalogusmap van een geconfigureerde locale ontbreekt | Een verwijderde catalogus mag niet stilzwijgend slagen |
| Allowlist-regel dekt niets meer | De uitzondering mag de code waarvoor zij geschreven is niet overleven |

### Uitvoeren

```bash
composer translations          # alleen de vertaalcontrole
composer lint                  # Pint plus dezelfde vertaalcontrole
php artisan translations:check # rechtstreeks
```

CI voert `composer lint` uit, dus de controle blokkeert een pull request zonder
extra workflowstap. `composer test` bevat daarnaast een test die de controle op
deze repository zelf uitvoert.

### Configuratie

Alles staat in [config/translations.php](../config/translations.php):

- `locales`: talen die een identieke sleutelset moeten hebben. Vandaag alleen
  `nl`. Een taal toevoegen is genoeg om een complete catalogus te eisen; er is
  geen codewijziging voor nodig.
- `reference_locale`: de leidende catalogus, nu `nl`.
- `scan_paths` en `scan_extensions`: waar naar `__()`, `trans()`,
  `trans_choice()`, de Blade-`@lang`-directive en de `Lang`-facade gezocht wordt.
- `allowlist.dynamic`: sleutels die op runtime worden samengesteld. Elke regel
  noemt het bestand, de sleutelpatronen die het kan opleveren en de reden. De
  patronen tellen die catalogussleutels ook als gebruikt.
- `allowlist.unused`: catalogussleutels die zonder verwijzing mogen bestaan,
  bijvoorbeeld framework-validatieteksten. Een `*` aan het eind is toegestaan.

Een allowlist-regel die niets meer raakt, faalt als `stale_allowlist`. Dat is
opzet: een lijst met uitzonderingen die niemand opruimt, wordt vanzelf een lijst
met blinde vlekken.

### Catalogus toevoegen

De map [lang/nl](../lang/nl) bestaat en is nog leeg: de applicatie gebruikt op
dit moment geen vertaalsleutels. Zodra de eerste sleutel wordt toegevoegd, bewaakt
de controle vanaf dat moment beide kanten — gebruik en catalogus.

Een tweede taal toevoegen doe je zo:

1. Maak `lang/en/` met dezelfde groepsbestanden als `lang/nl/`.
2. Zet `'locales' => ['nl', 'en']` in `config/translations.php`.
3. Draai `composer translations` en los elk pariteitsgat op.

### Een falende controle lezen

Elke regel noemt de plek en de sleutel, zodat zoeken niet nodig is:

```
Ontbrekende sleutels / missing keys (1)
  - resources/views/catalogue/index.blade.php:12  ->  Sleutel [catalogue.title] ontbreekt in lang/nl
Ongebruikte sleutels / unused keys (1)
  - lang/nl/catalogue.php  ->  Sleutel [catalogue.obsolete] wordt nergens gebruikt
```

## English

### Why this check exists

Laravel does not fail on a missing translation key. It renders the key itself.
A renamed or forgotten key therefore produces no error in logs, tests or
monitoring: a visitor sees `catalogue.asset.title` on the page and nobody is
told. A second language makes it worse, because an incomplete catalogue falls
back to another language page by page.

`translations:check` turns that silent defect into a failed check. It is
fail-closed by default: anything it cannot prove, it rejects.

### What the check enforces

| Finding | Why it fails |
| --- | --- |
| A referenced key is missing from a catalogue | Laravel renders the raw key to the page |
| A catalogue key is never referenced | Renamed keys leave dead text behind that translators keep maintaining |
| A key is built at runtime (`__($key)`) | The scanner cannot verify it; only an explicit allowlist may permit it |
| A locale misses a key another locale has | Parity between `nl`, and later `en`/`fr`/`de`, must be complete |
| A configured locale has no catalogue directory | A deleted catalogue must not pass quietly |
| An allowlist entry matches nothing | The exception must not outlive the code it was written for |

### Running it

```bash
composer translations          # translation check only
composer lint                  # Pint plus the same translation check
php artisan translations:check # directly
```

CI runs `composer lint`, so the check blocks a pull request without an extra
workflow step. `composer test` additionally contains a test that runs the check
against this repository itself.

### Configuration

Everything lives in [config/translations.php](../config/translations.php):

- `locales`: languages that must carry an identical key set. Today only `nl`.
  Adding a language is enough to demand a complete catalogue for it; no code
  change is needed.
- `reference_locale`: the leading catalogue, currently `nl`.
- `scan_paths` and `scan_extensions`: where `__()`, `trans()`, `trans_choice()`,
  the Blade `@lang` directive and the `Lang` facade are looked for.
- `allowlist.dynamic`: keys assembled at runtime. Each entry names the file, the
  key patterns it can produce and the reason. Those patterns also mark the
  matching catalogue keys as used.
- `allowlist.unused`: catalogue keys allowed to exist without a reference, such
  as framework validation messages. A trailing `*` is supported.

An allowlist entry that no longer matches anything fails as `stale_allowlist`.
That is deliberate: a list of exceptions nobody prunes becomes a list of blind
spots.

### Adding a catalogue

The [lang/nl](../lang/nl) directory exists and is still empty: the application
uses no translation keys yet. From the first key onwards the check guards both
sides — usage and catalogue.

Adding a second language:

1. Create `lang/en/` with the same group files as `lang/nl/`.
2. Set `'locales' => ['nl', 'en']` in `config/translations.php`.
3. Run `composer translations` and close every parity gap.

### Reading a failure

Every line names the place and the key, so no searching is needed:

```
Ontbrekende sleutels / missing keys (1)
  - resources/views/catalogue/index.blade.php:12  ->  Sleutel [catalogue.title] ontbreekt in lang/nl
Ongebruikte sleutels / unused keys (1)
  - lang/nl/catalogue.php  ->  Sleutel [catalogue.obsolete] wordt nergens gebruikt
```

### Tests

[tests/Unit/Translations/](../tests/Unit/Translations/) and
[tests/Feature/TranslationCheckCommandTest.php](../tests/Feature/TranslationCheckCommandTest.php)
run the checker against deliberately broken fixtures in
[tests/Fixtures/translations/](../tests/Fixtures/translations/): a missing key, an
unused key, a runtime key, a parity gap, a missing catalogue and a stale
allowlist entry. Those fixtures are excluded from Pint so the broken examples
stay broken.
