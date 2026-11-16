<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Services\UpdateCheckService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

const UPDATE_CHECK_LATEST_URL = 'https://github.com/helmerzNL/FotoArchief/releases/latest';

function updateUser(string $role): User
{
    $user = User::query()->create(['name' => 'Update '.$role, 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

    return $user;
}

function currentVersion(): string
{
    return trim((string) file_get_contents(base_path('VERSION')));
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    config(['app.locale' => 'nl', 'installation.enabled' => false, 'operations.updates.enabled' => true]);
    Cache::flush();
});

it('compares versions numerically, part by part, and never calls an unparsable value newer', function (): void {
    expect(UpdateCheckService::isNewer('1.10.0', '1.9.0'))->toBeTrue()
        ->and(UpdateCheckService::isNewer('v2.1', '2.1.0'))->toBeFalse()
        ->and(UpdateCheckService::isNewer('2.1.1', '2.1'))->toBeTrue()
        ->and(UpdateCheckService::isNewer('0.9.80', '0.9.80'))->toBeFalse()
        ->and(UpdateCheckService::isNewer('not-a-version', '0.9.80'))->toBeFalse();
});

it('reads the latest release from the redirect without following it and sends nothing about the installation', function (): void {
    Http::fake([UPDATE_CHECK_LATEST_URL => Http::response('', 302, ['Location' => 'https://github.com/helmerzNL/FotoArchief/releases/tag/v99.0.0'])]);

    $status = app(UpdateCheckService::class)->status();

    expect($status)->toMatchArray(['state' => 'available', 'latest' => '99.0.0', 'available' => true, 'error' => null,
        'url' => 'https://github.com/helmerzNL/FotoArchief/releases/tag/v99.0.0', 'current' => currentVersion()]);
    Http::assertSent(fn ($request): bool => $request->url() === UPDATE_CHECK_LATEST_URL
        && $request->header('User-Agent') === ['FotoArchief-update-check']
        && ! str_contains($request->url(), currentVersion()));
    Http::assertSentCount(1);
});

it('reports the same version as up to date and no release yet as no error', function (): void {
    Http::fakeSequence(UPDATE_CHECK_LATEST_URL)
        ->push('', 302, ['Location' => 'https://github.com/helmerzNL/FotoArchief/releases/tag/v'.currentVersion()])
        ->push('', 302, ['Location' => 'https://github.com/helmerzNL/FotoArchief/releases']);
    $service = app(UpdateCheckService::class);

    expect($service->status()['state'])->toBe('current');

    $this->travel(7)->hours();
    $none = $service->status();
    expect($none['state'])->toBe('no_release')->and($none['error'])->toBeNull()->and($none['latest'])->toBeNull();
});

it('remembers the answer and lets Check now through only after the floor', function (): void {
    Http::fake([UPDATE_CHECK_LATEST_URL => Http::response('', 302, ['Location' => 'https://github.com/helmerzNL/FotoArchief/releases/tag/v99.0.0'])]);
    $service = app(UpdateCheckService::class);

    $service->status();
    $service->status();
    $service->status(force: true);
    Http::assertSentCount(1);

    $this->travel(31)->seconds();
    $service->status(force: true);
    Http::assertSentCount(2);

    expect($service->cached()['latest'])->toBe('99.0.0');
});

it('turns an unreachable GitHub into a named, logged error that keeps the last good answer', function (): void {
    Log::spy();
    Http::fakeSequence(UPDATE_CHECK_LATEST_URL)
        ->push('', 302, ['Location' => 'https://github.com/helmerzNL/FotoArchief/releases/tag/v99.0.0'])
        ->pushResponse(Http::response('', 403))
        ->push('', 200);
    $service = app(UpdateCheckService::class);
    $service->status();

    $this->travel(31)->seconds();
    $failed = $service->status(force: true);
    expect($failed)->toMatchArray(['latest' => '99.0.0', 'available' => true, 'error' => 'HTTP 403 (rate limit)', 'state' => 'available']);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => ($context['reason'] ?? null) === 'HTTP 403 (rate limit)');

    $this->travel(31)->seconds();
    expect($service->status(force: true)['error'])->toBe('HTTP 200');
});

it('names a connection failure instead of crashing', function (): void {
    Http::fake([UPDATE_CHECK_LATEST_URL => Http::failedConnection('cURL error 28: Operation timed out after 5001 milliseconds')]);

    $status = app(UpdateCheckService::class)->status();

    expect($status)->toMatchArray(['state' => 'failed', 'error' => 'Timeout', 'latest' => null]);
});

it('can be switched off and then asks nothing', function (): void {
    config(['operations.updates.enabled' => false]);
    Http::fake();

    expect(app(UpdateCheckService::class)->status())->toMatchArray(['enabled' => false, 'state' => 'disabled', 'available' => false]);
    Http::assertNothingSent();
});

it('gives the status, the System page and the banner only to a signed-in administrator', function (): void {
    Http::fake([UPDATE_CHECK_LATEST_URL => Http::response('', 302, ['Location' => 'https://github.com/helmerzNL/FotoArchief/releases/tag/v99.0.0'])]);

    $this->getJson(route('admin.operations.system.update-status'))->assertUnauthorized();
    $this->actingAs(updateUser('viewer'))->getJson(route('admin.operations.system.update-status'))->assertForbidden();
    $this->get(route('admin.operations.system'))->assertForbidden();
    Http::assertNothingSent();

    $admin = updateUser('administrator');
    $this->actingAs($admin)->getJson(route('admin.operations.system.update-status'))
        ->assertOk()->assertJson(['available' => true, 'latest' => '99.0.0']);

    $this->get(route('admin.operations.system'))->assertOk()
        ->assertSee('Systeem')
        ->assertSee('Er is een nieuwe versie beschikbaar.')
        ->assertSee('https://github.com/helmerzNL/FotoArchief/releases/tag/v99.0.0', false)
        ->assertSee('data-update-steps="compose"', false)
        ->assertSee('Nu controleren');

    $this->get(route('admin.assets.index'))->assertOk()
        ->assertSee('data-update-banner', false)
        ->assertSee('data-available="1"', false)
        ->assertSee('/update-check.js', false);

    $this->actingAs(updateUser('viewer'))->get(route('admin.assets.index'))->assertOk()->assertDontSee('data-update-banner', false);
});

it('checks now on request and shows the steps for the configured deployment', function (): void {
    config(['operations.updates.deployment' => 'webhosting']);
    Http::fake([UPDATE_CHECK_LATEST_URL => Http::response('', 302, ['Location' => 'https://github.com/helmerzNL/FotoArchief/releases/tag/v99.0.0'])]);
    $admin = updateUser('administrator');

    $this->actingAs($admin)->post(route('admin.operations.system.check'))
        ->assertRedirect(route('admin.operations.system'))
        ->assertSessionHas('status', 'Er is een nieuwe versie beschikbaar: v99.0.0.');

    $this->get(route('admin.operations.system'))->assertOk()
        ->assertSee('Deze installatie: Webhostingpakket (ZIP)')
        ->assertSee('fotoarchief-v99.0.0-webhosting.zip');
});
