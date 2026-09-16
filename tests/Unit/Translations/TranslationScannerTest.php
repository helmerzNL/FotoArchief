<?php

declare(strict_types=1);

use App\Support\Translations\DynamicTranslationReference;
use App\Support\Translations\TranslationScanner;
use App\Support\Translations\TranslationScanResult;

function fixtureScan(string $fixture, string $path = 'src'): TranslationScanResult
{
    $base = dirname(__DIR__, 2).'/Fixtures/translations/'.$fixture;

    return (new TranslationScanner($base))->scan([$path], ['php', 'blade.php']);
}

test('it finds literal keys from helpers, directives and the facade', function (): void {
    $result = fixtureScan('passing');

    expect($result->keys())->toBe([
        'catalogue.double',
        'catalogue.help',
        'catalogue.nested.label',
        'catalogue.photos',
        'catalogue.subtitle',
        'catalogue.title',
    ]);
});

test('it records the file and line of every reference', function (): void {
    $result = fixtureScan('passing');

    $title = collect($result->references)->firstWhere('key', 'catalogue.title');

    expect($title)->not->toBeNull()
        ->and($title->file)->toBe('src/views/index.blade.php')
        ->and($title->line)->toBe(1);
});

test('it ignores commented out calls', function (): void {
    $result = fixtureScan('passing');

    expect($result->keys())->not->toContain('catalogue.commented_out');
});

test('it reports runtime keys separately instead of guessing', function (): void {
    $result = fixtureScan('failing');

    $locations = array_map(
        static fn (DynamicTranslationReference $reference): string => $reference->location(),
        $result->dynamicReferences,
    );

    expect($result->dynamicReferences)->toHaveCount(2)
        ->and($locations)->toBe(['src/views/broken.blade.php:3', 'src/views/broken.blade.php:4'])
        ->and($result->dynamicReferences[0]->expression)->toBe('$runtimeKey')
        ->and($result->dynamicReferences[1]->expression)->toContain('$suffix');
});

test('it does not mistake method calls for translation helpers', function (): void {
    $directory = sys_get_temp_dir().'/fotoarchief-scanner-'.bin2hex(random_bytes(6));
    mkdir($directory.'/src', 0o777, true);
    file_put_contents($directory.'/src/Sample.php', <<<'PHP'
        <?php

        $formatter->trans('not.a.translation');
        $translator->__('also.not.one');
        echo __('real.key');
        PHP);

    $result = (new TranslationScanner($directory))->scan(['src'], ['php']);

    expect($result->keys())->toBe(['real.key'])
        ->and($result->dynamicReferences)->toBe([]);

    unlink($directory.'/src/Sample.php');
    rmdir($directory.'/src');
    rmdir($directory);
});

test('it scans the installation translation wrapper', function (): void {
    $directory = sys_get_temp_dir().'/fotoarchief-scanner-'.bin2hex(random_bytes(6));
    mkdir($directory.'/src', 0o777, true);
    file_put_contents($directory.'/src/Sample.php', <<<'PHP'
        <?php

        echo InstallationText::get('onboarding.setup.errors.cannot_start');
        echo InstallationText::get($runtimeKey);
        PHP);

    $result = (new TranslationScanner($directory))->scan(['src'], ['php']);

    expect($result->keys())->toBe(['onboarding.setup.errors.cannot_start'])
        ->and($result->dynamicReferences)->toHaveCount(1)
        ->and($result->dynamicReferences[0]->expression)->toBe('$runtimeKey');

    unlink($directory.'/src/Sample.php');
    rmdir($directory.'/src');
    rmdir($directory);
});
