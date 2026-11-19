<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Modules\ArchiveOperations\Support\StoredZipWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;

/**
 * Writes the archive backup: backup.json alone (metadata only), or a ZIP with backup.json plus
 * every original loose under files/<its own name>. One export function serves the download and
 * the automatic backups, so what is (not) in the file holds for both.
 */
class ArchiveBackupExporter
{
    public const string FORMAT = 'fotoarchief-archive-backup';

    public const int FORMAT_VERSION = 1;

    public function __construct(private readonly ArchiveBackupSchema $schema) {}

    /**
     * Streams backup.json to $path and returns the row counts and the originals to add.
     *
     * @return array{counts: array<string, int>, files: list<array{id: string, archive_path: string, disk: string, key: string, sha256: string, byte_size: int}>}
     */
    public function writeDocument(string $path, bool $includesFiles): array
    {
        $out = fopen($path, 'wb');
        if ($out === false) {
            throw new RuntimeException('Cannot create backup.json.');
        }
        $counts = [];
        $files = [];
        try {
            $this->put($out, '{'.substr(json_encode([
                'format' => self::FORMAT,
                'format_version' => self::FORMAT_VERSION,
                'app_version' => self::appVersion(),
                'created_at' => now()->toIso8601String(),
                'includes_files' => $includesFiles,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 1, -1).',"sections":{');

            foreach ($this->schema->insertOrder() as $index => $table) {
                $this->put($out, ($index > 0 ? ',' : '').json_encode($table).':[');
                $count = 0;
                foreach (DB::table($table)->lazyById(500, 'id') as $row) {
                    $row = (array) $row;
                    foreach ($row as $column => $value) {
                        if (is_resource($value)) {
                            throw new LogicException("Binary column {$table}.{$column} cannot be written to a backup.");
                        }
                    }
                    $this->put($out, ($count > 0 ? ',' : '').json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
                    $count++;
                }
                $this->put($out, ']');
                $counts[$table] = $count;
            }
            $this->put($out, '}');

            if ($includesFiles) {
                $names = [];
                $this->put($out, ',"files":{');
                $first = true;
                foreach (DB::table('asset_files')->select(['id', 'storage_disk', 'storage_key', 'sha256', 'byte_size', 'original_filename'])->lazyById(500, 'id') as $file) {
                    $archivePath = 'files/'.$this->uniqueName((string) ($file->original_filename ?? ''), (string) $file->id, $names);
                    $entry = ['archive_path' => $archivePath, 'sha256' => (string) $file->sha256, 'byte_size' => (int) $file->byte_size];
                    $this->put($out, ($first ? '' : ',').json_encode((string) $file->id).':'.json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    $first = false;
                    $files[] = [
                        'id' => (string) $file->id,
                        'archive_path' => $archivePath,
                        'disk' => (string) ($file->storage_disk ?: config('filesystems.default')),
                        'key' => (string) $file->storage_key,
                        'sha256' => (string) $file->sha256,
                        'byte_size' => (int) $file->byte_size,
                    ];
                }
                $this->put($out, '}');
            }
            $this->put($out, '}');
        } finally {
            fclose($out);
        }

        return ['counts' => $counts, 'files' => $files];
    }

    /**
     * Copies one original from storage into the ZIP and checks it against the catalogue.
     *
     * @param  array{id: string, archive_path: string, disk: string, key: string, sha256: string, byte_size: int}  $file
     */
    public function addOriginal(StoredZipWriter $zip, array $file): void
    {
        $stream = Storage::disk($file['disk'])->readStream($file['key']);
        if (! is_resource($stream)) {
            throw new ArchiveBackupRefused('operations.backup.errors.original_missing', ['file' => $file['archive_path']]);
        }
        try {
            $result = $zip->addStream($file['archive_path'], $stream, $file['byte_size']);
        } catch (RuntimeException $exception) {
            throw new ArchiveBackupRefused('operations.backup.errors.original_unreadable', ['file' => $file['archive_path'], 'reason' => $exception->getMessage()]);
        } finally {
            fclose($stream);
        }
        if (! hash_equals($file['sha256'], $result['sha256'])) {
            throw new ArchiveBackupRefused('operations.backup.errors.original_changed', ['file' => $file['archive_path']]);
        }
    }

    public static function appVersion(): string
    {
        return ltrim(trim((string) @file_get_contents(base_path('VERSION'))), 'v');
    }

    /**
     * The original's own name, made safe as a single path segment; a second file with the same
     * name gets " (2)".
     *
     * @param  array<string, true>  $names
     */
    private function uniqueName(string $original, string $id, array &$names): string
    {
        $name = str_replace(['/', '\\'], '_', basename(str_replace('\\', '/', $original)));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
        if ($name === '' || $name === '.' || $name === '..') {
            $name = $id;
        }
        if (mb_strlen($name) > 180) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 160).($extension !== '' ? '.'.mb_substr($extension, 0, 10) : '');
        }
        $candidate = $name;
        $counter = 2;
        while (isset($names[mb_strtolower($candidate)])) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $base = $extension !== '' ? mb_substr($name, 0, -mb_strlen($extension) - 1) : $name;
            $candidate = $base.' ('.$counter.')'.($extension !== '' ? '.'.$extension : '');
            $counter++;
        }
        $names[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    /**
     * @param  resource  $out
     */
    private function put($out, string $bytes): void
    {
        if (fwrite($out, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Writing backup.json failed (disk full?).');
        }
    }
}
