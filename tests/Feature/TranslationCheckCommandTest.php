<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

function fixturePath(string $fixture): string
{
    return dirname(__DIR__).'/Fixtures/translations/'.$fixture;
}

test('the command passes on a consistent fixture catalogue', function (): void {
    $base = fixturePath('passing');

    $this->artisan('translations:check', [
        '--base-path' => $base,
        '--lang-path' => $base.'/lang',
        '--scan-path' => ['src'],
        '--locale' => ['nl', 'en'],
    ])->assertExitCode(0);
});

test('an alternate base path does not inherit project-specific allowlists', function (): void {
    config(['translations.allowlist.dynamic' => [
        ['file' => 'app/ProjectOnly.php', 'keys' => ['project.*'], 'reason' => 'Project-only wrapper'],
    ]]);
    $base = fixturePath('passing');

    $this->artisan('translations:check', [
        '--base-path' => $base,
        '--lang-path' => $base.'/lang',
        '--scan-path' => ['src'],
        '--locale' => ['nl', 'en'],
    ])->assertExitCode(0);
});

test('the command fails on a broken fixture catalogue and names keys and paths', function (): void {
    $base = fixturePath('failing');

    $this->artisan('translations:check', [
        '--base-path' => $base,
        '--lang-path' => $base.'/lang',
        '--scan-path' => ['src'],
        '--locale' => ['nl', 'en'],
    ])->assertExitCode(1);

    Artisan::call('translations:check', [
        '--base-path' => $base,
        '--lang-path' => $base.'/lang',
        '--scan-path' => ['src'],
        '--locale' => ['nl', 'en'],
    ]);

    $output = Artisan::output();

    expect($output)->toContain('catalogue.missing_key')
        ->and($output)->toContain('src/views/broken.blade.php:2')
        ->and($output)->toContain('catalogue.obsolete')
        ->and($output)->toContain('catalogue.only_in_english')
        ->and($output)->toContain('$runtimeKey');
});

test('a configured locale without a catalogue fails the command', function (): void {
    $base = fixturePath('passing');

    $this->artisan('translations:check', [
        '--base-path' => $base,
        '--lang-path' => $base.'/lang',
        '--scan-path' => ['src'],
        '--locale' => ['nl', 'de'],
    ])->assertExitCode(1);
});

test('the repository itself passes its own translation check', function (): void {
    $this->artisan('translations:check')->assertExitCode(0);
});
