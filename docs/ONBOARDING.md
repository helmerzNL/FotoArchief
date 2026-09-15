# First-start onboarding

## Delivered flow

The same PHP application now boots into `/setup` without a configured database,
Redis, S3 or application key. The Dutch wizard provides:

1. Ownership verification with a random installation code.
2. PostgreSQL connection settings and private local or S3-compatible storage.
3. A write/read/delete storage probe and a database connection test.
4. Schema migration, permission seeding and the first administrator.
5. A locked installer, login, logout and an initial administrator dashboard.

This is an installation slice, not a complete image bank. Photo management,
scanning, derivatives, metadata editing and publication UI are not delivered yet.

## Hosting prerequisites

- PHP 8.5 and production dependencies, including `pdo_pgsql`, OpenSSL,
  fileinfo, GD with JPEG/PNG/WebP, EXIF and the extensions required by Composer.
- An **empty PostgreSQL database**, already created by the hosting provider
  or the Compose database service. The wizard creates tables, not the server
  or database itself. Its user needs table, index, constraint and function
  creation privileges.
- Webroot set to `public/` only; the application and its storage directory must
  not be web-accessible. Never upload the entire repository into `public_html`.
  For shared hosting, keep the private application outside that directory and
  have the host point the webroot at the application's `public/`.
- Apache requires `mod_rewrite` and permission to apply `public/.htaccess`
  (`AllowOverride All`, or equivalent host-managed front-controller rules).
  Other web servers must route non-file URLs to `public/index.php`.
- Writable `storage/` and `bootstrap/cache/`; private installation directory
  permissions 0700 and configuration files 0600 on POSIX.
- HTTPS outside a local test environment. Ensure the web server/framework
  correctly recognises HTTPS when a trusted reverse proxy terminates TLS.
- Cron for `php artisan schedule:run`, and a queue worker or a bounded
  `php artisan queue:work ingest --stop-when-empty --max-time=50 --tries=3 --timeout=120`
  cron invocation with a process lock after installation. See
  [the worker requirements](PHOTO_WORKFLOW.md#worker-php-webhosting-en-docker).
  Large archives still require appropriately provisioned workers and storage.

The webhosting ZIP packaging workflow is still pending. The wizard works in
the PHP source deployment; this does not mean a ready-to-upload archive has
already been published.

## Ownership code

On first web access, or through `php artisan installation:prepare`, the app
creates a private installation directory in `storage/app/installation`.
The command displays the code while installation is pending.
Use `--quiet-code` in startup automation.

Without terminal access, the owner can retrieve `setup-code.txt` through their
host's **private** file manager. The code is never sent by a public HTTP route.
Do not share terminal output, this file or the installation state.

The code grants 20 minutes of installer access in a regenerated session.
Unlock is limited to five attempts per IP per minute and thirty attempts
globally per minute. Setup requests use Laravel CSRF protection. Sensitive
fields are never flashed into validation redirects or re-rendered as input.

## Credentials and configuration precedence

The initial authentication method is an explicitly limited local account:
minimum 14-character password, framework password hashing, server-side sessions
and throttled login. This makes installation and administrator login testable
without external providers. Passkeys, recovery email and user-management UI
are not implemented; this is not a claim that the final authentication design
has been completed.

`state.json` contains a generated application encryption key, the installation
code hash, status, and the submitted database/storage configuration. Database
and S3 credentials must be recoverable by workers and are stored in this
private file, **not hashed**. It is as sensitive as a production environment
file. Encrypt backups at rest and tightly restrict access.

Saved installation settings override environment values for:

- `app.key`;
- PostgreSQL connection and selected database driver;
- selected local/S3 storage disk and S3 connection settings.

The local storage path remains `storage/app/private`. It is not configurable
through HTTP. Other runtime settings (origin, queue, cache, session and Redis)
remain operator environment settings. Defaults after installation are database
queues and file cache/sessions; before completion the app forces file cache,
file sessions and no asynchronous queue dispatch.

Do not manually run `key:generate` after onboarding: the persisted key is
authoritative. `config:cache` is blocked before completion. After completion,
runtime installation settings are applied after the configuration cache loads.
Keep the same storage volume and key across web, worker and scheduler.
`INSTALLATION_ENABLED=false` only disables the module in `APP_ENV=testing`;
it is not an operator shortcut around production setup.

## Failure, retry and recovery

- Connection-only testing makes no schema changes and stores no credentials.
  Secrets must be re-entered before final submission.
- Final submission retests both connections, rejects a non-empty database,
  and persists an `installing` state before migrations.
- After a migration/write failure, the wizard can resume only with the same
  database/storage settings and administrator email. Do not change the target
  database halfway through installation.
- A transactional database receipt prevents a retry from creating another
  administrator or resetting its password if the database commit succeeded
  but the final state-file write failed.
- File locking rejects overlapping installations. Atomic state-file replacement
  avoids partially written configuration. A corrupt state file fails closed.
  A missing state file with an existing installation code does not regenerate
  the encryption key or reopen setup.
- The installer returns 404 after completion. `installation:ready` returns exit
  status 0 only for a completed installation; workers wait for it.

Errors shown in the wizard include a reference and safe guidance. Logs contain
the reference and exception types, not raw database/S3 exception messages
that can expose credentials. A filesystem failure may need operator repair;
the wizard cannot repair permissions or recover a lost encryption key.

Never delete the installation directory or a Docker volume to retry. Restore
the matching private configuration and database backup together. If the whole
volume is lost, a fresh wizard still rejects an already populated database.

## Verification boundaries

Automated feature tests exercise unlock, authorization expiry, rate limiting,
private storage probes, migrations/admin creation, retry receipts, existing
database rejection, configuration reload, setup lock, login and logout.
The default test suite uses isolated SQLite for orchestration and does not
substitute for PostgreSQL, S3-provider or Docker integration tests.

The storage probe establishes credential access, not the provider's complete
bucket policy. Explicitly disable anonymous bucket access at the provider.
No production image, Komodo import or Dockhand import is claimed until those
environments have actually passed their acceptance runs.

### PostgreSQL regression test

`tests/Feature/PostgresInstallationTest.php` is opt-in. Supply
`FOTOARCHIEF_TEST_PG_DATABASE` (an empty database whose name ends in
`_onboarding_test`), `FOTOARCHIEF_TEST_PG_HOST`, `FOTOARCHIEF_TEST_PG_PORT`,
`FOTOARCHIEF_TEST_PG_USER` and `FOTOARCHIEF_TEST_PG_PASSWORD`, then run:

```text
php vendor/bin/pest tests/Feature/PostgresInstallationTest.php
```

This creates the real schema and administrator, verifies login/setup lock and
checks that PostgreSQL rejects changes to original file keys/checksums. The
database is deliberately retained for inspection: use a **new disposable
database** for each run. Never point it at a real archive. The ordinary test
suite skips this case when the dedicated environment variables are absent.

### Local acceptance evidence

PHP 8.5.10 with an isolated PostgreSQL 16.14 instance has passed the browser
flow: rejected wrong code, rejected unreachable database, successful
connection-only probe (zero tables), completed installation, administrator
login, setup 404, private-state URL 404 and no-CSRF logout rejection (419).
An application-process restart retained the logged-in session and configuration.
The desktop wizard and narrow-screen dashboard were inspected.

This run exposed a PostgreSQL-only primary-key ordering issue in two
self-referencing foundation tables. It was fixed before publication; retrying
the interrupted wizard succeeded without replacing its saved target. The
separate fresh-database regression also passed, including both immutability
trigger assertions. These are PHP/PostgreSQL results, **not Docker image tests**.
