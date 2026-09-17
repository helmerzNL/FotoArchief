<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\Support\UserVisibleTextScanner;

uses(RefreshDatabase::class);

it('renders representative shared, authentication and onboarding text from Dutch named translation files', function (): void {
    config(['app.locale' => 'nl', 'installation.enabled' => false]);

    $this->get('/login')->assertOk()
        ->assertSee('Jouw geschiedenis, zorgvuldig bewaard')
        ->assertSee('Welkom terug')
        ->assertSee('Inloggen met passkey')
        ->assertSee('Herstelcode gebruiken')
        ->assertSee('Deze browser ondersteunt geen passkeys.');

    $this->withSession(['installation_authorized_until' => now()->addMinutes(20)->timestamp])
        ->get('/setup')->assertOk()
        ->assertSee('Een thuis voor je fotoarchief')
        ->assertSee('De installatiecontrole weigert ontbrekende PHP-vereisten zoals', false)
        ->assertSee('Controleren en installeren')
        ->assertSee('https://s3.example.org');
});

it('renders representative installed onboarding shell text from translations', function (): void {
    config(['app.locale' => 'nl', 'installation.enabled' => false]);
    app(DatabaseSeeder::class)->run();
    $admin = User::query()->create([
        'name' => 'Archive owner',
        'email' => 'owner@example.test',
        'password' => Hash::make('a-long-test-password'),
    ]);
    $admin->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());

    $this->actingAs($admin)->get('/admin')->assertOk()
        ->assertSee('Welkom, Archive owner')
        ->assertSee('Wizard vergrendeld')
        ->assertSee('Naar foto\'s')
        ->assertSee('Uitloggen');

    $this->actingAs($admin)->get(route('identity.security.show'))->assertOk()
        ->assertSee('Passkeys en herstelcodes')
        ->assertSee('Nieuwe herstelcodes maken');

    $this->actingAs($admin)->get(route('identity.users.index'))->assertOk()
        ->assertSee('Identiteit en toegang')
        ->assertSee('Nieuwe uitnodiging');
});

it('keeps extracted shared shell, auth, onboarding and AI blades free of raw rendered text', function (): void {
    $scanner = new UserVisibleTextScanner;
    $bladeFiles = array_values(array_filter(
        UserVisibleTextScanner::EXTRACTED_FILES,
        static fn (string $file): bool => str_ends_with($file, '.blade.php'),
    ));

    expect($scanner->scanExtractedBladeFiles($bladeFiles))->toBe([]);
});

it('only adds the Dutch locale for this extraction batch', function (): void {
    expect(array_map('basename', File::directories(base_path('lang'))))->toBe(['nl']);
});
