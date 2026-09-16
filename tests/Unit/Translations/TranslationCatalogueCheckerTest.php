<?php

declare(strict_types=1);

use App\Support\Translations\TranslationCatalogueChecker;
use App\Support\Translations\TranslationCheckConfiguration;
use App\Support\Translations\TranslationCheckReport;
use App\Support\Translations\TranslationProblem;

/**
 * @param  list<string>  $locales
 * @param  list<array{file: string, keys: list<string>, reason: string}>  $dynamicAllowlist
 * @param  list<string>  $unusedAllowlist
 */
function checkFixture(string $fixture, array $locales = ['nl'], array $dynamicAllowlist = [], array $unusedAllowlist = []): TranslationCheckReport
{
    $base = dirname(__DIR__, 2).'/Fixtures/translations/'.$fixture;

    $configuration = new TranslationCheckConfiguration(
        basePath: $base,
        langPath: $base.'/lang',
        locales: $locales,
        referenceLocale: $locales[0] ?? 'nl',
        scanPaths: ['src'],
        scanExtensions: ['php', 'blade.php'],
        dynamicAllowlist: $dynamicAllowlist,
        unusedAllowlist: $unusedAllowlist,
    );

    return (new TranslationCatalogueChecker)->check($configuration);
}

/**
 * @return list<string>
 */
function problemLines(TranslationCheckReport $report, string $type): array
{
    return array_map(
        static fn (TranslationProblem $problem): string => $problem->line(),
        $report->problemsOfType($type),
    );
}

test('a consistent catalogue passes for one locale and for several', function (): void {
    expect(checkFixture('passing')->passed())->toBeTrue()
        ->and(checkFixture('passing', ['nl', 'en'])->passed())->toBeTrue();
});

test('a referenced parent translation array marks its child keys as used', function (): void {
    expect(checkFixture('nested-parent')->passed())->toBeTrue();
});

test('it reports the count of scanned files and referenced keys', function (): void {
    $report = checkFixture('passing');

    expect($report->scannedFileCount)->toBe(2)
        ->and($report->referencedKeyCount)->toBe(6)
        ->and($report->catalogueSizes)->toBe(['nl' => 6]);
});

test('a referenced key that no catalogue defines fails with its call site', function (): void {
    $report = checkFixture('failing');

    expect($report->passed())->toBeFalse()
        ->and(problemLines($report, TranslationProblem::MISSING_KEY))
        ->toContain('src/views/broken.blade.php:2  ->  Sleutel [catalogue.missing_key] ontbreekt in lang/nl / key missing in lang/nl');
});

test('a catalogue key nothing references fails with its catalogue file', function (): void {
    $report = checkFixture('failing');

    $lines = problemLines($report, TranslationProblem::UNUSED_KEY);

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toContain('lang/nl/catalogue.php')
        ->and($lines[0])->toContain('catalogue.obsolete');
});

test('a runtime key fails unless the allowlist names the file and its patterns', function (): void {
    $report = checkFixture('failing');

    expect(problemLines($report, TranslationProblem::DYNAMIC_KEY))->toHaveCount(2);

    $allowlisted = checkFixture('failing', ['nl'], [
        ['file' => 'src/views/broken.blade.php', 'keys' => ['catalogue.obsolete'], 'reason' => 'Fixture'],
    ]);

    expect($allowlisted->problemsOfType(TranslationProblem::DYNAMIC_KEY))->toBe([])
        ->and($allowlisted->problemsOfType(TranslationProblem::UNUSED_KEY))->toBe([]);
});

test('locale parity gaps are reported per locale and key', function (): void {
    $report = checkFixture('failing', ['nl', 'en']);

    expect(problemLines($report, TranslationProblem::PARITY_GAP))
        ->toContain('lang/nl :: catalogue.only_in_english  ->  Aanwezig in [en] maar niet in [nl] / present in other locales only');
});

test('a configured locale without a catalogue fails closed', function (): void {
    $report = checkFixture('passing', ['nl', 'fr']);

    expect(problemLines($report, TranslationProblem::MISSING_CATALOGUE))
        ->toBe(['lang/fr  ->  Catalogus ontbreekt voor locale [fr] / catalogue missing for locale [fr]']);
});

test('an allowlist entry that no longer matches anything fails', function (): void {
    $report = checkFixture('passing', ['nl'], [
        ['file' => 'src/views/gone.blade.php', 'keys' => ['catalogue.*'], 'reason' => 'Fixture'],
    ], ['catalogue.removed.*']);

    $lines = problemLines($report, TranslationProblem::STALE_ALLOWLIST);

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toContain('allowlist.dynamic :: src/views/gone.blade.php')
        ->and($lines[1])->toContain('allowlist.unused :: catalogue.removed.*');
});

test('an unused key may be allowlisted by pattern', function (): void {
    $report = checkFixture('failing', ['nl'], [
        ['file' => 'src/views/broken.blade.php', 'keys' => [], 'reason' => 'Fixture'],
    ], ['catalogue.obsolete']);

    expect($report->problemsOfType(TranslationProblem::UNUSED_KEY))->toBe([])
        ->and($report->problemsOfType(TranslationProblem::STALE_ALLOWLIST))->toBe([]);
});
