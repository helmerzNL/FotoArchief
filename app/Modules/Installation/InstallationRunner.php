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
        private readonly InstallationPlatform $platform,
    ) {}

    public function check(InstallationSettings $settings): void
    {
        // Cheapest to detect and most confusing to hit later, so it goes first.
        $this->platform->check();
        try {
            $this->storage->check($settings);
        } catch (Throwable $exception) {
            throw new InstallationFailure(InstallationText::get('onboarding.setup.errors.storage_check_failed'), 0, $exception);
        }
        try {
            $this->database->connect($settings);
        } catch (Throwable $exception) {
            throw new InstallationFailure(InstallationText::get('onboarding.setup.errors.database_connection_failed'), 0, $exception);
        }
    }

    public function complete(InstallationSettings $settings, string $name, string $email, string $password): void
    {
        $this->store->locked(function () use ($settings, $name, $email, $password): void {
            $state = $this->store->read();
            if ($state === null || $state->phase === 'complete') {
                throw new InstallationFailure(InstallationText::get('onboarding.setup.errors.cannot_start'));
            }
            $fingerprint = $settings->fingerprint($email);
            if ($state->fingerprint !== null && ! hash_equals($state->fingerprint, $fingerprint)) {
                throw new InstallationFailure(InstallationText::get('onboarding.setup.errors.resume_mismatch'));
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
                throw new InstallationFailure(InstallationText::get('onboarding.setup.errors.migration_failed'));
            }
            DB::transaction(function () use ($state, $name, $email, $password): void {
                // The receipt makes retry safe if the DB committed but the state-file write failed.
                if (DB::table('installation_receipts')->where('id', $state->id)->exists()) {
                    return;
                }
                if (User::query()->exists() || DB::table('installation_receipts')->exists()) {
                    throw new InstallationFailure(InstallationText::get('onboarding.setup.errors.existing_installation'));
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
