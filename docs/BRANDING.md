# Vistora

## Nederlands

### Toegepast ontwerp

Vistora is de publieksnaam van FotoArchief vanaf 0.9.64. De gedeelde
Laravel-interface volgt het
[Vistora-brandbook](https://github.com/Flux76HQ/App-Guidance/blob/main/projects/fotoarchief/branding/vistora-brandbook.md):
warm papier, archiefgroen en een terracotta accent; Source Serif 4 voor
redactionele koppen en Source Sans 3 voor formulieren en beheer.

- Het originele open fotokader bestaat uit twee ongelijk doorlopende hoeken.
  Geen camera-, scan- of AI-symbool. Het woordmerk is levende tekst, geen
  afbeelding, zodat het scherp en toegankelijk blijft.
- [Semantische tokens](../public/brand/tokens.css) zijn de bron voor beide
  thema's. [De stylesheet](../public/app.css) behoudt bestaande component-
  selectors; donkere modus verandert geen foto's.
- Systeem/Licht/Donker bewaart uitsluitend de voorkeur in `vistora.theme`.
  De keuze wordt voor de eerste paginaweergave toegepast. Systeemwijzigingen
  en wijzigingen vanuit een ander tabblad worden gevolgd. Bij geblokkeerde
  opslag verschijnt een melding; de huidige pagina blijft bruikbaar.
  Zonder JavaScript blijft de lichte interface werken; de themakeuze is verborgen.
- Publieke navigatie en beheernavigatie zijn gescheiden. De overslaanlink,
  zichtbare toetsenbordfocus, 44px-bediening en verminderde animatie zijn
  onderdeel van de gedeelde shell. Gewone zoekfilters blijven direct zichtbaar;
  zoeken op betekenis staat in een uitklapbaar blok met ongewijzigde
  expliciete providertoestemming.
- De Nederlandse interface blijft de ondersteunde standaard. Er zijn Engelse
  catalogi voor enkele bestaande workflows, maar geen volledige Engelse shell;
  deze huisstijlwijziging claimt geen nieuwe taalondersteuning.

### Schil, navigatie en accentkleur

De beheeromgeving heeft vier bestemmingen in plaats van negen losse links:
**Foto's**, **Catalogus**, **Publiceren** en **Beheer**. Welke pagina's onder een
bestemming vallen en wie ze ziet staat in `app/Modules/Archive/StaffNavigation.php`;
de zichtbaarheid volgt dezelfde rechten als de pagina's zelf.

- Vanaf 60rem staat primaire navigatie links in een inklapbare zijbalk (de keuze
  wordt in `vistora.sidebar` bewaard); onder 60rem is dat een glazen onderbalk met
  dezelfde bestemmingen. Er is steeds één navigator zichtbaar.
- De pagina's binnen de actieve bestemming staan als sectietabs boven de inhoud.
  Account, beveiliging, weergave en uitloggen zitten in het accountmenu.
- Weergave en kleur: Systeem/Licht/Donker plus vijf accentkleuren (Archiefgroen is
  standaard, daarnaast Terracotta, Blauw, Pruim en Oker). Wie is ingelogd bewaart
  thema, accent en inklapstand van de zijbalk op het eigen account
  (`users.interface_preferences`, `PUT /admin/identiteit/voorkeuren`), zodat de keuze
  op elk apparaat terugkomt; bezoekers zonder account bewaren ze alleen in de browser
  (`vistora.theme`, `vistora.accent`, `vistora.sidebar`). De browser houdt daarnaast
  een kopie als cache en brengt een eerdere lokale keuze eenmalig over naar een
  account dat nog niets heeft opgeslagen; de waarde van het account wint.
  Elke combinatie haalt WCAG AA (minstens 4,5:1) voor tekst op de knop en voor de
  accentkleur als tekst op de pagina, in licht en donker. Glas wordt alleen voor
  zwevende balken en menu's gebruikt en valt terug op een effen vlak bij
  `prefers-reduced-transparency`.
- Statussen zijn tekst met een stip (badge), nooit alleen kleur. De publieke site
  houdt een bovenbalk; de verenigingsnaam staat daar naast het Vistora-merk.
- Vaste hexkleuren in de beheerschermen zijn vervangen door themakleuren, zodat de
  donkere modus overal leesbaar is. Nieuwe schermen gebruiken tokens uit
  `public/brand/tokens.css`, geen eigen kleuren of inline stijlen.

### Installatie-iconen en delen

[Het webmanifest](../public/manifest.webmanifest) start op `/`, heeft een
stabiele `/`-identiteit en gebruikt alleen lokale iconen. Geleverd: SVG, ICO met
16/32/48px, PNG op 16/32/48/180/192/512px, maskable 512px en monochroom SVG.
De maskable-vorm blijft binnen de centrale veilige cirkel met straal 204,8px.
Het icoon heeft geen ingebakken afgeronde buitenhoeken.

Sociale merkbeelden op 1200x630, 1080x1080 en 1080x1350 bevatten alleen het merk,
geen collectiebeelden of private gegevens. De generieke Open Graph-afbeelding
en titel zijn bewust niet afgeleid van een beheerscherm, herstelcode of foto.
Lokale fonts veroorzaken geen verzoek aan een externe fontprovider.

Installeren/toevoegen aan het beginscherm hangt af van browser en HTTPS.
Er is **geen serviceworker en geen offline fotocache** toegevoegd. Dit is
installatiemetadata, geen belofte dat privéfoto's of beheer offline werken.
Een Android-native adaptive icon en aparte splashscreen-afbeeldingen vallen
buiten deze webimplementatie; de browser gebruikt manifestkleur en appicoon.

### Technische continuiteit en update

Repository, Composer-pakket, GHCR-image, releasebestanden, API-statusnaam,
`APP_NAME`, sessie-/cookienamen, database en opslagpaden blijven FotoArchief.
De bestaande passkey RP-ID, origin en ingestelde RP-weergavenaam veranderen
niet. Verenigingsnamen, fototitels, bronvermelding en rechten blijven inhoud
van de eigen installatie, geen vervangbaar merk. De verenigingsnaam wordt bij
de eerste installatie vastgelegd en naast, niet in plaats van, het
Vistora-merk getoond; zie [onboarding](ONBOARDING.md).

Geen Compose- of environmenttemplatewijzigingen, nieuwe variabelen of
migraties. Bestaande installaties blijven zonder configuratieaanpassing werken.
Gebruik de bestaande upgradeprocedure en maak vooraf een onafhankelijke backup.
Upload bij webhosting het volledige releasepakket, inclusief `public/brand`,
fonts/licenties, `public/theme.js`, `public/favicon.ico` en het webmanifest.
Stylesheets en themascript krijgen een SHA-256-inhoudshash als cacheversie;
dit werkt ook bij reproduceerbare release-ZIP's met vaste bestandstijden.
De browser haalt gewijzigde bestanden na een update opnieuw op.
Browser- en OS-iconencaches kunnen een herstart of opnieuw toevoegen vereisen.

### Herkomst en onderhoud

Fonts zijn ongewijzigde Adobe-uitgaven onder SIL OFL 1.1, met originele
licenties naast de WOFF2-bestanden:

- Source Sans 3, Regular/Semibold/Bold:
  [`87b37a2daaed80fcb8e8ccb0085c4d72ddade12e`](https://github.com/adobe-fonts/source-sans/tree/87b37a2daaed80fcb8e8ccb0085c4d72ddade12e).
- Source Serif 4, Semibold:
  [`80d3f8894c09c937bebfa9011247d2e1c79fd6f4`](https://github.com/adobe-fonts/source-serif/tree/80d3f8894c09c937bebfa9011247d2e1c79fd6f4).

De bronvormen staan in [favicon.svg](../public/brand/favicon.svg) en
[monochrome.svg](../public/brand/monochrome.svg). De header gebruikt hetzelfde
pad inline met `aria-hidden`, naast de toegankelijke merknaam.
Genereer rasterassets opnieuw met `node scripts/generate-brand-assets.cjs`
na installatie van de bestaande Playwright-tooling in `tests/Browser`.
Er is geen frontend-buildstap nodig voor deployment. Houd het headerpad en
SVG-bronnen gelijk bij een latere logowijziging.

[PHP-tests](../tests/Feature/BrandingTest.php) bewaken naam, privacy in metadata,
icoonformaten, veilige maskable-pixels en fontlicenties.
[Browsertests](../tests/Browser/branding.spec.ts) bewaken thema's, opslagfouten,
toetsenbordbediening, gereduceerde beweging, fontladen, 320px/desktop-reflow,
JavaScript-vrije bediening en AA-kleurcontrasten. De bestaande releasecheck
weigert ontbrekende of verouderde merkassets en fonts in het webhostingpakket.
Dit is geen volledige WCAG 2.2-certificering of merkenrechtelijke vrijgave.

## English

### Applied design

Vistora is FotoArchief's user-facing name from 0.9.64. The shared Laravel UI
implements the
[Vistora brandbook](https://github.com/Flux76HQ/App-Guidance/blob/main/projects/fotoarchief/branding/vistora-brandbook.md):
warm paper, archive green and terracotta; Source Serif 4 for editorial headings
and Source Sans 3 for forms and staff tools.

- The original open-frame mark consists of two asymmetrically extended corners,
  not a camera, scanner or AI symbol. The wordmark stays accessible live text.
- [Semantic tokens](../public/brand/tokens.css) define both themes.
  [The stylesheet](../public/app.css) preserves existing component selectors.
  Dark mode never alters photographs.
- System/Light/Dark stores only a preference in `vistora.theme`, applied before
  first paint. System changes and changes from another tab are followed. Blocked
  storage displays an explanation without preventing use. Without JavaScript
  the light UI remains usable and the theme selector stays hidden.
- Public and staff navigation are separated. Shared features include a skip
  link, visible keyboard focus, 44px controls and reduced motion. Regular
  search filters remain visible; meaning-based search sits in an expandable
  section with unchanged explicit provider consent.
- Dutch remains the supported default. Some existing workflows have English
  catalogues, but there is no complete English shell; this change adds no new
  language support.

### Shell, navigation and accent colour

The staff workspace has four destinations instead of nine flat links: **Photos**,
**Catalogue**, **Publishing** and **Administration**. The pages under each
destination and who sees them are defined in
`app/Modules/Archive/StaffNavigation.php`; visibility follows the same abilities
the pages themselves enforce.

- From 60rem, primary navigation is a collapsible left sidebar (the state is kept
  in `vistora.sidebar`); below 60rem it is a glass bottom bar with the same
  destinations. Exactly one navigator is shown at a time.
- The pages inside the active destination appear as section tabs above the content.
  Account, security, appearance and sign-out live in the account menu.
- Appearance and colour: System/Light/Dark plus five accent colours (Archive green
  by default, then Terracotta, Blue, Plum and Ochre). Signed-in people keep theme,
  accent and the sidebar state on their own account (`users.interface_preferences`,
  `PUT /admin/identiteit/voorkeuren`), so the choice follows them to every device;
  visitors without an account keep them in the browser only (`vistora.theme`,
  `vistora.accent`, `vistora.sidebar`). The browser also holds a copy as a cache and
  uploads an earlier local choice once to an account that has stored nothing yet; the
  account value wins. Every
  pair reaches WCAG AA (at least 4.5:1) for text on the button and for the accent as
  text on the page, in light and dark. Glass is used only for floating bars and
  menus and falls back to a solid surface under `prefers-reduced-transparency`.
- Status is text with a dot (a badge), never colour alone. The public site keeps a
  top bar; the society's name sits next to the Vistora mark.
- Hard-coded hex colours in the staff screens were replaced by theme tokens so dark
  mode is readable everywhere. New screens use the tokens in
  `public/brand/tokens.css`, not their own colours or inline styles.

### Install icons and sharing

[The manifest](../public/manifest.webmanifest) starts at `/`, uses a stable `/`
identity and references local icons only. Included: SVG, ICO containing
16/32/48px, PNG at 16/32/48/180/192/512px, maskable 512px and monochrome SVG.
Maskable artwork remains inside the central safe circle with a 204.8px radius.
Raster icons have no baked-in rounded outer corners.

Brand-only social images at 1200x630, 1080x1080 and 1080x1350 contain no
collection images or private data. Generic Open Graph imagery and title are
deliberately independent of staff screens, recovery codes and photographs.
Self-hosted fonts make no requests to an external font provider.

Installation/add-to-home-screen support depends on the browser and HTTPS.
**No service worker or offline photo cache** is introduced. This is install
metadata, not an offline staff/private-photo capability. Native Android adaptive
resources and separate splash images are outside this web implementation;
browsers use the manifest color and app icon.

### Technical continuity and upgrades

Repository, Composer package, GHCR image, release filenames, API status name,
`APP_NAME`, session/cookie names, database and storage paths remain FotoArchief.
Existing passkey RP-ID, origin and configured RP display name are unchanged.
Association names, photo titles, credits and rights remain installation content.

No Compose/environment-template changes, new variables or migrations. Existing
deployments need no configuration edits. Follow the existing upgrade procedure
and make an independent backup first. Webhosting uploads must include the full
release, including `public/brand`, fonts/licenses, `public/theme.js`,
`public/favicon.ico` and the manifest. Stylesheets and theme script use SHA-256
content hashes as cache versions, including in reproducible release ZIPs with
fixed timestamps, to reload changed files after upgrades.
Browser/OS icon caches may require a restart or adding the application again.

### Provenance and maintenance

Unmodified Adobe fonts use SIL OFL 1.1 with original licenses beside the WOFF2
files: Source Sans 3 Regular/Semibold/Bold at
[`87b37a2daaed80fcb8e8ccb0085c4d72ddade12e`](https://github.com/adobe-fonts/source-sans/tree/87b37a2daaed80fcb8e8ccb0085c4d72ddade12e)
and Source Serif 4 Semibold at
[`80d3f8894c09c937bebfa9011247d2e1c79fd6f4`](https://github.com/adobe-fonts/source-serif/tree/80d3f8894c09c937bebfa9011247d2e1c79fd6f4).

Source artwork lives in [favicon.svg](../public/brand/favicon.svg) and
[monochrome.svg](../public/brand/monochrome.svg). The header uses the same inline
path with `aria-hidden` alongside the accessible name. Rebuild raster assets
with `node scripts/generate-brand-assets.cjs` after installing the existing
Playwright tooling in `tests/Browser`. Deployment needs no frontend build step.
Keep the header path and SVG sources synchronized during future logo changes.

[PHP tests](../tests/Feature/BrandingTest.php) cover naming, private metadata,
icon dimensions, maskable safe pixels and font licenses.
[Browser tests](../tests/Browser/branding.spec.ts) cover themes, denied storage,
keyboard access, reduced motion, fonts, 320px/desktop reflow, JavaScript-free
use and AA color contrast. Release validation rejects missing/stale brand
assets and fonts in webhosting archives. This is not complete WCAG 2.2
certification or trademark clearance.
