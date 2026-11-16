<?php

declare(strict_types=1);

namespace App\Console\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Measures a folder before it is imported: how many files of each type, how large,
 * and what ingest:import-directory would import, skip or refuse. Only reads; it stores
 * nothing and needs no account, so it can run against a copied OneDrive folder first.
 */
class SurveyDirectoryCommand extends Command
{
    protected $signature = 'ingest:survey-directory
        {path : Folder on the server to measure (read only)}
        {--json : Print the result as JSON}';

    protected $description = 'Count files per extension with total and average size, and show what the folder import would do with them';

    public function handle(): int
    {
        $root = realpath((string) $this->argument('path'));
        if ($root === false || ! is_dir($root) || ! is_readable($root)) {
            $this->error('De map bestaat niet of is niet leesbaar.');

            return self::FAILURE;
        }

        $maxBytes = (int) config('ingest.max_upload_bytes');
        $types = [];
        $folders = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            /** @var SplFileInfo $entry */
            if ($entry->isLink() || ! $entry->isFile()) {
                continue;
            }
            $extension = strtolower($entry->getExtension());
            $key = $extension === '' ? '(geen)' : $extension;
            $size = max(0, (int) $entry->getSize());
            $types[$key] ??= ['files' => 0, 'bytes' => 0, 'largest' => 0, 'too_large' => 0, 'action' => $this->action($extension)];
            $types[$key]['files']++;
            $types[$key]['bytes'] += $size;
            $types[$key]['largest'] = max($types[$key]['largest'], $size);
            if ($types[$key]['action'] === 'import' && ($size === 0 || $size > $maxBytes)) {
                $types[$key]['too_large']++;
            }
            $folders[$entry->getPath()] = true;
        }
        uasort($types, fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        $result = [
            'path' => $root,
            'folders' => count($folders),
            'files' => array_sum(array_column($types, 'files')),
            'bytes' => array_sum(array_column($types, 'bytes')),
            'max_upload_bytes' => $maxBytes,
            'types' => array_map(fn (array $type): array => $type + ['average' => $type['files'] > 0 ? intdiv($type['bytes'], $type['files']) : 0], $types),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $labels = ['import' => 'wordt geïmporteerd', 'unsupported' => 'nog niet ondersteund, overgeslagen', 'ignored' => 'genegeerd'];
        $this->table(
            ['Extensie', 'Bestanden', 'Totaal', 'Gemiddeld', 'Grootste', 'Te groot of leeg', 'Import'],
            array_map(fn (string $extension, array $type): array => [
                $extension,
                number_format($type['files'], 0, ',', '.'),
                $this->size($type['bytes']),
                $this->size($type['average']),
                $this->size($type['largest']),
                $type['too_large'],
                $labels[$type['action']],
            ], array_keys($result['types']), $result['types']),
        );
        $this->line('Mappen: '.number_format($result['folders'], 0, ',', '.').' · Bestanden: '.number_format($result['files'], 0, ',', '.').' · Totaal: '.$this->size($result['bytes']));
        $this->line('Grootste toegestane upload: '.$this->size($maxBytes).'. Er is niets opgeslagen.');

        return self::SUCCESS;
    }

    private function action(string $extension): string
    {
        return match (true) {
            in_array($extension, ImportDirectoryCommand::SUPPORTED, true) => 'import',
            in_array($extension, ImportDirectoryCommand::NOT_YET_SUPPORTED, true) => 'unsupported',
            default => 'ignored',
        };
    }

    private function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => number_format($bytes / 1073741824, 1, ',', '.').' GiB',
            $bytes >= 1048576 => number_format($bytes / 1048576, 1, ',', '.').' MiB',
            default => number_format($bytes / 1024, 0, ',', '.').' KiB',
        };
    }
}
