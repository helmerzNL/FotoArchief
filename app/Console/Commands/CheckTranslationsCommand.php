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
            $this->components->error(__('shared.generated.t_bfa3f6a3441d1731'));

            return self::FAILURE;
        }

        $report = $checker->check($configuration);

        $this->reportScope($configuration, $report);

        if ($report->passed()) {
            $this->components->info(__('shared.generated.t_e918bede0a3254a2'));

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
            __('shared.generated.t_5b0e7050e788e3b8'),
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
            __('shared.generated.t_7b6871e619734b6c'),
            (string) $report->scannedFileCount,
        );
        $this->components->twoColumnDetail(
            __('shared.generated.t_42a1a360a0ecc5aa'),
            (string) $report->referencedKeyCount,
        );
        $this->components->twoColumnDetail(
            __('shared.generated.t_1fbe3e831f5e6295'),
            $catalogues === [] ? '-' : implode(', ', $catalogues),
        );
    }

    private function heading(string $type): string
    {
        return match ($type) {
            TranslationProblem::MISSING_CATALOGUE => __('shared.generated.t_22415d68be169936'),
            TranslationProblem::MISSING_KEY => __('shared.generated.t_09f12eeca3809d0d'),
            TranslationProblem::UNUSED_KEY => __('shared.generated.t_eb1f5adcdc148563'),
            TranslationProblem::PARITY_GAP => __('shared.generated.t_3a9a150562288e7e'),
            TranslationProblem::DYNAMIC_KEY => __('shared.generated.t_a75f038d32c79bb6'),
            TranslationProblem::STALE_ALLOWLIST => __('shared.generated.t_88df6bfea0c8ac97'),
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
