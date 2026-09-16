# Taalvoorkeur / Language preference

## Nederlands

FotoArchief heeft vanaf versie 0.9.42 een technische basis voor taalvoorkeuren.
De enige actieve en ondersteunde runtime-taal is **Nederlands (`nl`)**. De
standaardtaal en fallback-taal zijn allebei `nl`; Engelse, Franse en Duitse
interfaces worden nog niet geactiveerd.

De technische registry staat in `config/localization.php`. De webmiddleware past
per request de taal toe op basis van:

1. de opgeslagen voorkeur van een ingelogde gebruiker;
2. een anonieme sessievoorkeur voor toekomstige selectorfunctionaliteit;
3. de standaardwaarde `nl`.

Niet-ondersteunde sessiewaarden worden veilig genegeerd en verwijderd. Nieuwe
persistente voorkeuren worden gevalideerd tegen de actieve registry en waarden
zoals `en`, `fr` en `de` worden geweigerd totdat die talen bewust worden
vrijgegeven. Er is nog geen taalkeuzer en er verandert niets aan de onboarding:
de installatiewizard blijft Nederlands en nieuwe gebruikers krijgen automatisch
`nl`.

De naam **Vistora** is alleen als toekomstige documentatienaam gereserveerd. De
technische identifiers, configuratiebestanden, databasekolommen en routes blijven
voorlopig FotoArchief-specifiek en worden in deze stap niet hernoemd.

### Operatorimpact

De Compose- en `.env.example`-templates bevatten voortaan expliciet
`APP_LOCALE=nl` en `APP_FALLBACK_LOCALE=nl`. Bestaande deployments blijven met
de standaardwaarden werken en hoeven hun private `.env` niet te wijzigen.
Operators die deze variabelen zelf toevoegen, moeten beide voorlopig exact op
`nl` zetten. Bestaande installaties moeten daarnaast de nieuwe migratie
uitvoeren; die vult `users.preferred_locale` met `nl`.

## English

FotoArchief has a technical foundation for language preferences as of version
0.9.42. The only active and supported runtime language is **Dutch (`nl`)**. The
default locale and fallback locale are both `nl`; English, French and German
interfaces are not activated yet.

The technical registry lives in `config/localization.php`. The web middleware
applies the request locale from:

1. the persisted preference of an authenticated user;
2. an anonymous session preference for future selector functionality;
3. the default value `nl`.

Unsupported session values are safely ignored and removed. New persisted
preferences are validated against the active registry, and values such as `en`,
`fr` and `de` are rejected until those languages are deliberately released. There
is no language selector yet and onboarding is unchanged: the setup wizard remains
Dutch and new users automatically receive `nl`.

The name **Vistora** is reserved only as a future documentation name. Technical
identifiers, configuration files, database columns and routes remain
FotoArchief-specific for now and are not renamed in this step.

### Operator impact

The Compose and `.env.example` templates now explicitly contain
`APP_LOCALE=nl` and `APP_FALLBACK_LOCALE=nl`. Existing deployments continue to
work with the defaults and do not need to change their private `.env`. Operators
who add these variables themselves must keep both values set to exactly `nl`
for now. Existing installations must also run the new migration, which fills
`users.preferred_locale` with `nl`.
