# Deploymentmigraties / Deployment migrations

## Nederlands

Na een voltooide onboarding voert iedere app-, worker- en schedulercontainer
voor het starten `php artisan installation:migrate-ready` uit. Een PostgreSQL
advisory lock zorgt dat slechts één proces de forward-only migraties uitvoert.
De andere processen wachten maximaal
`DEPLOYMENT_MIGRATION_LOCK_TIMEOUT_SECONDS` seconden (standaard **300**) en
controleren daarna opnieuw of het schema actueel is.

De startup faalt gesloten wanneer de lock niet binnen de ingestelde tijd
vrijkomt, de migratie faalt of er achterstallige migraties overblijven. De
applicatie start dan niet met een gedeeltelijk bijgewerkt schema. De
installatiewizard blijft vóór de eerste installatie databasevrij bereikbaar.

Controleer de toestand met:

```sh
php artisan installation:migration-status
```

De opdracht meldt afzonderlijk of onboarding nog niet is voltooid, een andere
migratiecoördinator actief is, migraties achterstallig zijn of het schema
actueel is. Foutdetails en databasegeheimen worden niet naar de console
geschreven; gebruik de exceptionklasse en beveiligde applicatielogs voor
diagnose.

Herstel eerst de databaseverbinding of mislukte migratie en voer daarna
`php artisan installation:migrate-ready` opnieuw uit. De migraties en de
coördinatie zijn herhaalbaar; verwijder nooit het installatievolume en genereer
de applicatiesleutel niet opnieuw. Maak vóór iedere upgrade een geverifieerde
back-up en herstel database en private installatieconfiguratie altijd samen.

### Operatorimpact

Nieuwe templates bevatten
`DEPLOYMENT_MIGRATION_LOCK_TIMEOUT_SECONDS=300`. Bestaande deployments hoeven
niets aan te passen: de applicatie gebruikt dezelfde standaardwaarde wanneer
de variabele ontbreekt. Wie de waarde expliciet beheert, moet deze mapping aan
app, worker en scheduler doorgeven. Kies een positieve gehele waarde in
seconden die langer is dan de langste verwachte forward-migratie.

## English

After onboarding has completed, every app, worker and scheduler container runs
`php artisan installation:migrate-ready` before starting. A PostgreSQL advisory
lock ensures that only one process executes the forward-only migrations. Other
processes wait for at most `DEPLOYMENT_MIGRATION_LOCK_TIMEOUT_SECONDS` seconds
(default **300**) and then verify again that the schema is current.

Startup fails closed when the lock is not released within the configured time,
a migration fails, or migrations remain pending. The application therefore
never starts against a partially updated schema. Before the first installation,
the setup wizard remains available without a database.

Inspect the state with:

```sh
php artisan installation:migration-status
```

The command distinguishes incomplete onboarding, an active migration
coordinator, pending migrations and a current schema. It does not print
exception details or database secrets; use the exception class and protected
application logs for diagnosis.

Repair the database connection or failed migration first, then rerun
`php artisan installation:migrate-ready`. Both the migrations and coordination
are repeatable; never delete the installation volume or regenerate the
application key. Create a verified backup before every upgrade and always
restore the database and private installation configuration together.

### Operator impact

New templates contain `DEPLOYMENT_MIGRATION_LOCK_TIMEOUT_SECONDS=300`. Existing
deployments need no change: the application uses the same default when the
variable is absent. Operators who manage it explicitly must pass the mapping to
the app, worker and scheduler. Choose a positive integer in seconds longer than
the longest expected forward migration.
