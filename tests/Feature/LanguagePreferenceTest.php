<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Localization\LanguagePreference;
use App\Modules\Localization\SupportedLocaleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::middleware('web')->get('/_test/locale', fn () => response(app()->getLocale()));
});

it('resolves anonymous requests from a supported session preference', function (): void {
    $key = app(SupportedLocaleRegistry::class)->sessionKey();

    $this->withSession([$key => 'nl'])
        ->get('/_test/locale')
        ->assertOk()
        ->assertSeeText('nl');
});

it('keeps Dutch as the only active default and fallback locale', function (): void {
    $locales = app(SupportedLocaleRegistry::class);

    expect($locales->supportedLocaleCodes())->toBe(['nl'])
        ->and($locales->defaultLocale())->toBe('nl')
        ->and($locales->fallbackLocale())->toBe('nl')
        ->and($locales->normalize('en'))->toBeNull()
        ->and($locales->normalize('fr'))->toBeNull()
        ->and($locales->normalize('de'))->toBeNull()
        ->and(config('app.locale'))->toBe('nl')
        ->and(config('app.fallback_locale'))->toBe('nl');
});

it('safely ignores unsupported anonymous session preferences', function (): void {
    $key = app(SupportedLocaleRegistry::class)->sessionKey();

    $this->withSession([$key => 'en'])
        ->get('/_test/locale')
        ->assertOk()
        ->assertSeeText('nl')
        ->assertSessionMissing($key);
});

it('ignores unsupported request locale inputs because no selector is active', function (): void {
    $this->get('/_test/locale?locale=en')
        ->assertOk()
        ->assertSeeText('nl');
});

it('resolves authenticated requests from the persisted user preference', function (): void {
    $user = User::query()->create([
        'name' => 'Locale user',
        'email' => 'locale@example.test',
        'password' => 'not-a-real-password',
        'preferred_locale' => 'nl',
    ]);

    $this->actingAs($user)
        ->get('/_test/locale')
        ->assertOk()
        ->assertSeeText('nl');
});

it('rejects unsupported anonymous and authenticated preference persistence', function (): void {
    $preferences = app(LanguagePreference::class);
    $user = User::query()->create([
        'name' => 'Locale user',
        'email' => 'locale-validation@example.test',
        'password' => 'not-a-real-password',
    ]);

    expect(fn () => $preferences->rememberAnonymous(request(), 'fr'))->toThrow(ValidationException::class);
    expect(fn () => $preferences->persistForUser($user, 'de'))->toThrow(ValidationException::class);

    expect($user->refresh()->preferred_locale)->toBe('nl');
});

it('fails closed when the configured default or fallback locale is unsupported', function (): void {
    $locales = app(SupportedLocaleRegistry::class);

    config()->set('localization.default_locale', 'en');
    expect(fn () => $locales->defaultLocale())
        ->toThrow(LogicException::class, 'The default locale must be present in localization.supported_locales.');

    config()->set('localization.default_locale', 'nl');
    config()->set('localization.fallback_locale', 'fr');
    expect(fn () => $locales->fallbackLocale())
        ->toThrow(LogicException::class, 'The fallback locale must be present in localization.supported_locales.');
});
