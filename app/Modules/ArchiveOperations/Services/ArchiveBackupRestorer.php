<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Models\User;
use App\Modules\ArchiveOperations\Models\BackupJob;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Reads, checks and restores an archive backup (App-Guidance backup-restore.md, rule 2): the
 * file is not trusted, every original is read again and checked against its SHA-256, and the
 * data is swapped in one transaction, so a refused or failed restore changes nothing.
 *
 * The checks are those the schema can prove: the header and version, known sections and
 * columns, unique ids, every link pointing at a row that exists, checksums and sizes; the
 * database's own constraints run inside the transaction. People are not restored: links to a
 * user who does not exist on this installation are emptied, or given to the administrator who
 * restores when the link is required (a work list's owner).
 */
class ArchiveBackupRestorer
{
    public function __construct(private readonly ArchiveBackupSchema $schema) {}

    public static function isZip(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = fread($handle, 4);
        fclose($handle);

        return $head === "PK\x03\x04";
    }

    /**
     * Reads backup.json from a staged file (a ZIP or a plain JSON file).
     *
     * @return array<string, mixed>
     */
    public function read(string $path): array
    {
        $limit = (int) config('operations.backup.max_document_bytes');
        if (self::isZip($path)) {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw new ArchiveBackupRefused('operations.backup.errors.not_a_backup');
            }
            try {
                $stat = $zip->statName('backup.json');
                if ($stat === false) {
                    throw new ArchiveBackupRefused('operations.backup.errors.not_a_backup');
                }
                if ((int) $stat['size'] > $limit) {
                    throw new ArchiveBackupRefused('operations.backup.errors.document_too_large');
                }
                $json = $zip->getFromName('backup.json');
            } finally {
                $zip->close();
            }
        } else {
            if ((int) @filesize($path) > $limit) {
                throw new ArchiveBackupRefused('operations.backup.errors.document_too_large');
            }
            $json = @file_get_contents($path);
        }
        if (! is_string($json)) {
            throw new ArchiveBackupRefused('operations.backup.errors.not_a_backup');
        }
        try {
            $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new ArchiveBackupRefused('operations.backup.errors.not_a_backup');
        }
        if (! is_array($document) || ($document['format'] ?? null) !== ArchiveBackupExporter::FORMAT) {
            throw new ArchiveBackupRefused('operations.backup.errors.not_a_backup');
        }

        return $document;
    }

    /**
     * Checks the whole document (and, for a ZIP, that every original is in it with the right
     * size) and returns the summary shown before the owner confirms. Nothing is changed.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function check(array &$document, string $path): array
    {
        $current = ArchiveBackupExporter::appVersion();
        $version = $document['format_version'] ?? null;
        $appVersion = is_string($document['app_version'] ?? null) ? $document['app_version'] : '';
        if (! is_int($version) || $version < 1) {
            throw new ArchiveBackupRefused('operations.backup.errors.not_a_backup');
        }
        if ($version > ArchiveBackupExporter::FORMAT_VERSION || UpdateCheckService::isNewer($appVersion, $current)) {
            throw new ArchiveBackupRefused('operations.backup.errors.newer_version', ['version' => $appVersion !== '' ? $appVersion : '?', 'current' => $current]);
        }
        $older = UpdateCheckService::isNewer($current, $appVersion);
        $isZip = self::isZip($path);
        $includesFiles = ($document['includes_files'] ?? null) === true;
        if ($includesFiles !== $isZip) {
            throw new ArchiveBackupRefused('operations.backup.errors.not_a_backup');
        }
        $sections = $document['sections'] ?? null;
        if (! is_array($sections)) {
            throw new ArchiveBackupRefused('operations.backup.errors.not_a_backup');
        }
        $known = $this->schema->sections();
        foreach (array_keys($sections) as $section) {
            if (! in_array($section, $known, true)) {
                throw new ArchiveBackupRefused('operations.backup.errors.unknown_section', ['section' => (string) $section]);
            }
        }

        // Rows: known columns, an id, unique ids.
        $ids = [];
        foreach ($known as $table) {
            $rows = $sections[$table] ?? [];
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new ArchiveBackupRefused('operations.backup.errors.bad_section', ['section' => $table]);
            }
            $columns = array_flip($this->schema->columns($table));
            $sections[$table] = $rows;
            $ids[$table] = [];
            foreach ($rows as $index => $row) {
                if (! is_array($row) || ! isset($row['id']) || ! (is_string($row['id']) || is_int($row['id']))) {
                    throw new ArchiveBackupRefused('operations.backup.errors.bad_row', ['section' => $table, 'row' => $index + 1]);
                }
                foreach (array_keys($row) as $column) {
                    if (! isset($columns[$column])) {
                        if (! $older) {
                            throw new ArchiveBackupRefused('operations.backup.errors.unknown_column', ['section' => $table, 'column' => (string) $column]);
                        }
                        // A column a later version removed: an older file still restores.
                        unset($sections[$table][$index][$column]);
                    }
                }
                foreach ($row as $value) {
                    if (is_array($value)) {
                        throw new ArchiveBackupRefused('operations.backup.errors.bad_row', ['section' => $table, 'row' => $index + 1]);
                    }
                }
                $id = (string) $row['id'];
                if (isset($ids[$table][$id])) {
                    throw new ArchiveBackupRefused('operations.backup.errors.duplicate_row', ['section' => $table, 'row' => $index + 1]);
                }
                $ids[$table][$id] = true;
            }
            $sections[$table] = array_values($sections[$table]);
        }

        // Links: every link points at a row in the file, or (outside the archive) at a row here.
        foreach ($known as $table) {
            foreach ($this->schema->foreignKeys($table) as $key) {
                $column = $key['column'];
                $target = $key['table'];
                foreach ($sections[$table] as $index => $row) {
                    $value = $row[$column] ?? null;
                    if ($value === null) {
                        if (! $key['nullable'] && $target !== 'users') {
                            throw new ArchiveBackupRefused('operations.backup.errors.broken_link', ['section' => $table, 'row' => $index + 1, 'column' => $column]);
                        }

                        continue;
                    }
                    if (in_array($target, $known, true) && ! isset($ids[$target][(string) $value])) {
                        throw new ArchiveBackupRefused('operations.backup.errors.broken_link', ['section' => $table, 'row' => $index + 1, 'column' => $column]);
                    }
                }
            }
        }

        // Originals: checksums and sizes in the catalogue, and in the ZIP.
        $files = $document['files'] ?? [];
        if ($includesFiles && ! is_array($files)) {
            throw new ArchiveBackupRefused('operations.backup.errors.not_a_backup');
        }
        $bytes = 0;
        $maxFile = (int) config('ingest.max_upload_bytes');
        $zip = null;
        if ($isZip) {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw new ArchiveBackupRefused('operations.backup.errors.not_a_backup');
            }
        }
        try {
            foreach ($sections['asset_files'] ?? [] as $index => $row) {
                $sha = $row['sha256'] ?? null;
                $size = $row['byte_size'] ?? null;
                if (! is_string($sha) || preg_match('/^[0-9a-f]{64}$/', $sha) !== 1 || ! is_numeric($size) || (int) $size < 0) {
                    throw new ArchiveBackupRefused('operations.backup.errors.bad_row', ['section' => 'asset_files', 'row' => $index + 1]);
                }
                $bytes += (int) $size;
                if ($zip === null) {
                    continue;
                }
                $entry = $files[(string) $row['id']] ?? null;
                $name = is_array($entry) && is_string($entry['archive_path'] ?? null) ? $entry['archive_path'] : (string) ($row['original_filename'] ?? $row['id']);
                if (! is_array($entry) || ! str_starts_with($name, 'files/') || ($entry['sha256'] ?? null) !== $sha || (int) ($entry['byte_size'] ?? -1) !== (int) $size) {
                    throw new ArchiveBackupRefused('operations.backup.errors.file_missing', ['file' => $name]);
                }
                $stat = $zip->statName($name);
                if ($stat === false || (int) $stat['size'] !== (int) $size) {
                    throw new ArchiveBackupRefused('operations.backup.errors.file_missing', ['file' => $name]);
                }
                if ((int) $size > $maxFile) {
                    throw new ArchiveBackupRefused('operations.backup.errors.file_too_large', ['file' => $name]);
                }
            }
        } finally {
            $zip?->close();
        }
        $document['sections'] = $sections;

        $counts = [];
        foreach ($known as $table) {
            $counts[$table] = count($sections[$table]);
        }

        return [
            'created_at' => is_string($document['created_at'] ?? null) ? $document['created_at'] : null,
            'app_version' => $appVersion,
            'includes_files' => $includesFiles,
            'counts' => $counts,
            'file_bytes' => $bytes,
        ];
    }

    /**
     * Reads one original from the ZIP, checks it and puts it in storage. An identical original
     * that is already in storage here (the same installation) is used instead of a second copy.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $entry
     * @return array{disk: string, key: string, written: bool, derivatives: bool}
     */
    public function placeFromArchive(ZipArchive $zip, array $row, array $entry): array
    {
        $name = (string) $entry['archive_path'];
        $sha = (string) $row['sha256'];
        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw new ArchiveBackupRefused('operations.backup.errors.file_missing', ['file' => $name]);
        }
        $temporary = tempnam(sys_get_temp_dir(), 'fotoarchief-restore-');
        if ($temporary === false) {
            fclose($stream);
            throw new RuntimeException('Cannot create a temporary file for the restore.');
        }
        try {
            $out = fopen($temporary, 'wb');
            if ($out === false) {
                throw new RuntimeException('Cannot write a temporary file for the restore.');
            }
            $hash = hash_init('sha256');
            $size = 0;
            $max = (int) config('ingest.max_upload_bytes');
            while (! feof($stream)) {
                $chunk = fread($stream, 1048576);
                if ($chunk === false) {
                    break;
                }
                $size += strlen($chunk);
                if ($size > $max) {
                    throw new ArchiveBackupRefused('operations.backup.errors.file_too_large', ['file' => $name]);
                }
                hash_update($hash, $chunk);
                fwrite($out, $chunk);
            }
            fclose($out);
            fclose($stream);
            $stream = null;
            if ($size !== (int) $row['byte_size'] || ! hash_equals($sha, hash_final($hash))) {
                throw new ArchiveBackupRefused('operations.backup.errors.file_damaged', ['file' => $name]);
            }

            $existing = DB::table('asset_files')->where('sha256', $sha)->first(['storage_disk', 'storage_key']);
            if ($existing !== null) {
                $disk = (string) ($existing->storage_disk ?: config('filesystems.default'));
                if (Storage::disk($disk)->exists((string) $existing->storage_key) && Storage::disk($disk)->size((string) $existing->storage_key) === $size) {
                    return ['disk' => $disk, 'key' => (string) $existing->storage_key, 'written' => false, 'derivatives' => $this->derivativesExist($disk, $row['derivatives'] ?? null)];
                }
            }

            $disk = (string) config('filesystems.default');
            $key = 'quarantine/'.strtolower((string) str()->ulid()).'/'.str()->random(32);
            $input = fopen($temporary, 'rb');
            if ($input === false) {
                throw new RuntimeException('Cannot read a temporary file for the restore.');
            }
            try {
                if (! Storage::disk($disk)->writeStream($key, $input, ['visibility' => 'private'])) {
                    throw new RuntimeException('Storing a restored original failed.');
                }
            } finally {
                fclose($input);
            }

            return ['disk' => $disk, 'key' => $key, 'written' => true, 'derivatives' => false];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($temporary);
        }
    }

    /**
     * Without originals in the file, each original must still be in storage here, at the place
     * and with the size the file records.
     *
     * @param  array<string, mixed>  $row
     * @return array{disk: string, key: string, written: bool, derivatives: bool}
     */
    public function placeInStorage(array $row): array
    {
        $disk = (string) (($row['storage_disk'] ?? null) ?: config('filesystems.default'));
        $key = (string) ($row['storage_key'] ?? '');
        $name = (string) ($row['original_filename'] ?? $row['id']);
        if ($key === '' || ! array_key_exists($disk, (array) config('filesystems.disks'))) {
            throw new ArchiveBackupRefused('operations.backup.errors.original_not_here', ['file' => $name]);
        }
        $storage = Storage::disk($disk);
        if (! $storage->exists($key) || $storage->size($key) !== (int) $row['byte_size']) {
            throw new ArchiveBackupRefused('operations.backup.errors.original_not_here', ['file' => $name]);
        }

        return ['disk' => $disk, 'key' => $key, 'written' => false, 'derivatives' => $this->derivativesExist($disk, $row['derivatives'] ?? null)];
    }

    /**
     * Replaces the archive data in one transaction and marks the job done inside it.
     *
     * @param  array<string, mixed>  $document  checked by check()
     * @param  array<string, array{disk: string, key: string, written: bool, derivatives: bool}>  $placements  per asset_files id
     * @param  Closure(int, int): void  $progress
     * @return array{rebuild: list<string>}
     */
    public function replace(array $document, array $placements, BackupJob $job, ?User $actor, Closure $progress, Closure $markDone): array
    {
        $sections = $document['sections'];
        $order = $this->schema->insertOrder();
        $cleared = $this->schema->clearedTables();
        $total = array_sum(array_map(static fn (string $table): int => count($sections[$table] ?? []), $order));
        $userIds = array_flip(DB::table('users')->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all());
        $rebuild = [];

        DB::transaction(function () use ($sections, $order, $cleared, $total, $userIds, $placements, $actor, $progress, $markDone, $job, &$rebuild): void {
            // Empty every optional link first, so the order of deleting only has to follow the
            // links that cannot be empty.
            foreach ($this->schema->detachedColumns($cleared) as $link) {
                DB::table($link['table'])->whereNotNull($link['column'])->update([$link['column'] => null]);
            }
            foreach ($this->schema->deleteOrder($cleared) as $table) {
                DB::table($table)->delete();
            }

            $done = 0;
            $deferred = [];
            $known = $this->schema->sections();
            foreach ($order as $table) {
                $deferredColumns = $this->schema->deferredColumns($table);
                $userColumns = array_values(array_filter($this->schema->foreignKeys($table), static fn (array $key): bool => $key['table'] === 'users'));
                $outside = array_values(array_filter($this->schema->foreignKeys($table), static fn (array $key): bool => $key['table'] !== 'users' && ! in_array($key['table'], $known, true)));
                $batch = [];
                foreach ($sections[$table] ?? [] as $row) {
                    foreach ($userColumns as $key) {
                        $value = $row[$key['column']] ?? null;
                        if ($value !== null && ! isset($userIds[(string) $value])) {
                            $row[$key['column']] = $key['nullable'] ? null : $actor?->id;
                        } elseif ($value === null && ! $key['nullable']) {
                            $row[$key['column']] = $actor?->id;
                        }
                    }
                    foreach ($outside as $key) {
                        $value = $row[$key['column']] ?? null;
                        if ($value !== null && ! DB::table($key['table'])->where('id', $value)->exists()) {
                            if (! $key['nullable']) {
                                throw new ArchiveBackupRefused('operations.backup.errors.broken_link', ['section' => $table, 'row' => $done + 1, 'column' => $key['column']]);
                            }
                            $row[$key['column']] = null;
                        }
                    }
                    if ($table === 'asset_files') {
                        $placement = $placements[(string) $row['id']] ?? null;
                        if ($placement === null) {
                            throw new ArchiveBackupRefused('operations.backup.errors.file_missing', ['file' => (string) ($row['original_filename'] ?? $row['id'])]);
                        }
                        $row['storage_disk'] = $placement['disk'];
                        $row['storage_key'] = $placement['key'];
                        if (! $placement['derivatives']) {
                            $row['derivatives'] = null;
                            $rebuild[] = (string) $row['id'];
                        }
                    }
                    foreach ($deferredColumns as $column) {
                        if (($row[$column] ?? null) !== null) {
                            $deferred[$table][(string) $row['id']][$column] = $row[$column];
                            $row[$column] = null;
                        }
                    }
                    $batch[] = $row;
                    if (count($batch) >= 200) {
                        $this->insert($table, $batch);
                        $done += count($batch);
                        $batch = [];
                        $progress($done, $total);
                    }
                }
                if ($batch !== []) {
                    $this->insert($table, $batch);
                    $done += count($batch);
                    $progress($done, $total);
                }
            }
            foreach ($deferred as $table => $rows) {
                foreach ($rows as $id => $values) {
                    DB::table($table)->where('id', $id)->update($values);
                }
            }
            if (DB::getDriverName() === 'pgsql') {
                foreach ($order as $table) {
                    foreach ($this->schema->autoIncrementColumns($table) as $column) {
                        DB::statement("SELECT setval(pg_get_serial_sequence(?, ?), COALESCE((SELECT MAX({$column}) FROM {$table}), 0) + 1, false)", [$table, $column]);
                    }
                }
            }
            // Previews of originals that came without them are made again by the worker.
            foreach (array_chunk($rebuild, 200) as $ids) {
                $now = now();
                DB::table('integrity_checks')->insert(array_map(static fn (array $file): array => [
                    'id' => strtolower((string) str()->ulid()),
                    'asset_id' => $file['asset_id'],
                    'asset_file_id' => $file['id'],
                    'check_type' => 'full',
                    'status' => 'missing_derivative',
                    'expected_sha256' => $file['sha256'],
                    'actual_sha256' => $file['sha256'],
                    'details' => json_encode(['missing' => ['preview300', 'preview1200', 'preview2000'], 'source' => 'archive_restore']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], array_map(static fn (object $row): array => (array) $row, DB::table('asset_files')->whereIn('id', $ids)->get(['id', 'asset_id', 'sha256'])->all())));
            }
            // "The data was replaced" and "the job says done" cannot disagree.
            $markDone($job);
        });

        return ['rebuild' => $rebuild];
    }

    /**
     * Takes the originals this restore wrote out of storage again (a failed restore).
     *
     * @param  array<string, array{disk: string, key: string, written: bool, derivatives: bool}>  $placements
     */
    public function removeWritten(array $placements): void
    {
        foreach ($placements as $placement) {
            if ($placement['written']) {
                try {
                    Storage::disk($placement['disk'])->delete($placement['key']);
                } catch (Throwable) {
                    // A file left behind is reported by the integrity check; nothing points at it.
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(string $table, array $rows): void
    {
        // Rows of one table normally share their columns; group them when an older file does not.
        $groups = [];
        foreach ($rows as $row) {
            $signature = implode(',', array_keys($row));
            $groups[$signature][] = $row;
        }
        foreach ($groups as $group) {
            DB::table($table)->insert($group);
        }
    }

    private function derivativesExist(string $disk, mixed $derivatives): bool
    {
        if (is_string($derivatives)) {
            $derivatives = json_decode($derivatives, true);
        }
        if (! is_array($derivatives) || $derivatives === []) {
            return false;
        }
        $storage = Storage::disk($disk);
        foreach (['preview300', 'preview1200', 'preview2000'] as $size) {
            $key = $derivatives[$size] ?? null;
            if (! is_string($key) || ! $storage->exists($key)) {
                return false;
            }
        }

        return true;
    }
}
