<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Translations\TranslationCatalogueChecker;
use App\Support\Translations\TranslationCheckConfiguration;
use App\Support\Translations\TranslationCheckReport;
use App\Support\Translations\TranslationProblem;
use Illuminate\Console\Command;

/**
 * Fails the build when the source and the locale catalogues disagree.
 *
 * Laravel renders an unknown key as the key itself, so a missing translation
 * reaches a visitor as "catalogue.asset.title" on the page and never as an
 * error anywhere. This command turns that silent class of defect into a failed
 * check, and reports the file, line and key so the fix needs no searching.
 */
class CheckTranslationsCommand extends Command
{
    protected $signature = 'translations:check
        {--locale=* : Locale to check; repeatable, overrides config(translations.locales)}
        {--scan-path=* : Source path to scan, relative to the base path; repeatable}
        {--base-path= : Project root the scan paths resolve against}
        {--lang-path= : Directory holding the locale catalogues}';

    protected $description = 'Controleer vertaalsleutels en locale-pariteit / check translation keys and locale parity';

    public function handle(TranslationCatalogueChecker $checker): int
    {
        $configuration = $this->configuration();

        if ($configuration->locales === []) {
            $this->components->error('Geen locales geconfigureerd / no locales configured (config/translations.php).');

            return self::FAILURE;
        }

        $report = $checker->check($configuration);

        $this->reportScope($configuration, $report);

        if ($report->passed()) {
            $this->components->info('Vertaalcontrole geslaagd / translation check passed.');

            return self::SUCCESS;
        }

        foreach ($report->groupedProblems() as $type => $problems) {
            $this->newLine();
            $this->line('<fg=red>'.$this->heading($type).' ('.count($problems).')</>');

            foreach ($problems as $problem) {
                $this->line('  - '.$problem->line());
            }
        }

        $this->newLine();
        $this->components->error(sprintf(
            '%d probleem(en) gevonden / %d problem(s) found. Zie docs/TRANSLATIONS.md.',
            count($report->problems),
            count($report->problems),
        ));

        return self::FAILURE;
    }

    private function configuration(): TranslationCheckConfiguration
    {
        $raw = config('translations');
        $configuration = TranslationCheckConfiguration::fromArray(
            is_array($raw) ? $raw : [],
            base_path(),
            lang_path(),
        );

        $basePath = $this->stringOption('base-path');
        $langPath = $this->stringOption('lang-path');
        if ($basePath !== null || $langPath !== null) {
            $configuration = $configuration->withPaths(
                $basePath ?? $configuration->basePath,
                $langPath ?? $configuration->langPath,
            )->withoutAllowlists();
        }

        $locales = $this->listOption('locale');
        if ($locales !== []) {
            $configuration = $configuration->withLocales($locales);
        }

        $scanPaths = $this->listOption('scan-path');
        if ($scanPaths !== []) {
            $configuration = $configuration->withScanPaths($scanPaths);
        }

        return $configuration;
    }

    private function reportScope(TranslationCheckConfiguration $configuration, TranslationCheckReport $report): void
    {
        $catalogues = [];
        foreach ($report->catalogueSizes as $locale => $size) {
            $catalogues[] = $locale.'='.$size;
        }

        $this->components->twoColumnDetail(
            'Locales',
            implode(', ', $configuration->locales),
        );
        $this->components->twoColumnDetail(
            'Gescande bestanden / scanned files',
            (string) $report->scannedFileCount,
        );
        $this->components->twoColumnDetail(
            'Gebruikte sleutels / referenced keys',
            (string) $report->referencedKeyCount,
        );
        $this->components->twoColumnDetail(
            'Catalogusomvang / catalogue size',
            $catalogues === [] ? '-' : implode(', ', $catalogues),
        );
    }

    private function heading(string $type): string
    {
        return match ($type) {
            TranslationProblem::MISSING_CATALOGUE => 'Ontbrekende catalogus / missing catalogue',
            TranslationProblem::MISSING_KEY => 'Ontbrekende sleutels / missing keys',
            TranslationProblem::UNUSED_KEY => 'Ongebruikte sleutels / unused keys',
            TranslationProblem::PARITY_GAP => 'Pariteitsgaten / locale parity gaps',
            TranslationProblem::DYNAMIC_KEY => 'Dynamische sleutels / dynamic keys',
            TranslationProblem::STALE_ALLOWLIST => 'Verouderde allowlist / stale allowlist',
            default => $type,
        };
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function listOption(string $name): array
    {
        $values = $this->option($name);

        if (! is_array($values)) {
            return [];
        }

        $strings = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $strings[] = $value;
            }
        }

        return $strings;
    }
}
