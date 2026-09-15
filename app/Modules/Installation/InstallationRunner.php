<?php

declare(strict_types=1);

namespace App\Modules\Installation;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class InstallationRunner
{
    public function __construct(
        private readonly InstallationStore $store,
        private readonly InstallationDatabase $database,
        private readonly InstallationStorage $storage,
    ) {}

    public function check(InstallationSettings $settings): void
    {
        try {
            $this->storage->check($settings);
        } catch (Throwable $exception) {
            throw new InstallationFailure('Opslagcontrole mislukt. Controleer het endpoint, de bucket en rechten voor schrijven, lezen en verwijderen. Bij lokale opslag moet storage/app/private schrijfbaar zijn.', 0, $exception);
        }
        try {
            $this->database->connect($settings);
        } catch (Throwable $exception) {
            throw new InstallationFailure('Databaseverbinding mislukt. Controleer de PostgreSQL-host, poort, databasenaam, gebruiker, wachtwoord en TLS-instelling.', 0, $exception);
        }
    }

    public function complete(InstallationSettings $settings, string $name, string $email, string $password): void
    {
        $this->store->locked(function () use ($settings, $name, $email, $password): void {
            $state = $this->store->read();
            if ($state === null || $state->phase === 'complete') {
                throw new InstallationFailure('Deze installatie kan niet worden gestart.');
            }
            $fingerprint = $settings->fingerprint($email);
            if ($state->fingerprint !== null && ! hash_equals($state->fingerprint, $fingerprint)) {
                throw new InstallationFailure('Hervat met dezelfde database, opslaginstellingen en beheerder als de eerdere poging.');
            }
            $this->check($settings);
            if ($state->phase === 'pending') {
                $this->database->requireEmptyDatabase();
                $state->settings = $settings;
                $state->fingerprint = $fingerprint;
                $state->phase = 'installing';
                $this->store->save($state);
            }
            if (Artisan::call('migrate', ['--force' => true]) !== 0) {
                throw new InstallationFailure('Databasemigratie is niet voltooid. Controleer of de databasegebruiker tabellen en functies mag maken.');
            }
            DB::transaction(function () use ($state, $name, $email, $password): void {
                // The receipt makes retry safe if the DB committed but the state-file write failed.
                if (DB::table('installation_receipts')->where('id', $state->id)->exists()) {
                    return;
                }
                if (User::query()->exists() || DB::table('installation_receipts')->exists()) {
                    throw new InstallationFailure('Deze database bevat al een andere installatie.');
                }
                app(DatabaseSeeder::class)->run();
                $user = User::query()->create([
                    'name' => $name,
                    'email' => strtolower($email),
                    'password' => Hash::make($password),
                ]);
                $user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
                DB::table('installation_receipts')->insert(['id' => $state->id, 'created_at' => now()]);
            });
            $state->phase = 'complete';
            $this->store->save($state);
        });
    }
}
