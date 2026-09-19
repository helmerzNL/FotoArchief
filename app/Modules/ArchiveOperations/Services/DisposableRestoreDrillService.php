<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Modules\ArchiveOperations\Models\BackupRecord;
use App\Modules\ArchiveOperations\Models\RestoreDrill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class DisposableRestoreDrillService
{
    public function __construct(private readonly RestoreDrillService $drills) {}

    public function runLatest(string $parentDirectory, bool $confirmed): RestoreDrill
    {
        if (! $confirmed || DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Disposable restore drill requires PostgreSQL and explicit confirmation.');
        }
        $parent = realpath($parentDirectory);
        if ($parent === false || ! is_dir($parent) || is_link($parentDirectory)) {
            throw new RuntimeException('Disposable restore drill parent directory is invalid.');
        }
        $version = trim((string) File::get(base_path('VERSION')));
        $backup = BackupRecord::query()
            ->where('version', $version)
            ->where('manifest_schema_version', 2)
            ->latest('checksum_verified_at')
            ->firstOrFail();
        $suffix = bin2hex(random_bytes(8));
        $database = 'fotoarchief_'.$suffix.'_restore_drill';
        $target = $parent.DIRECTORY_SEPARATOR.'fotoarchief-restore-drill-'.$suffix;
        DB::statement('CREATE DATABASE "'.$database.'"');

        try {
            $drill = $this->drills->run($backup, $database, $target, true);
        } catch (Throwable $exception) {
            $this->cleanup($database, $target);
            throw $exception;
        }

        try {
            $this->cleanup($database, $target);
        } catch (Throwable $exception) {
            $message = 'Disposable restore drill cleanup failed: '.$exception::class;
            $drill->update([
                'status' => 'cleanup_failed',
                'error' => $message,
                'finished_at' => now(),
            ]);
            throw new RuntimeException($message, previous: $exception);
        }
        $report = is_array($drill->report) ? $drill->report : [];
        $drill->update(['report' => [...$report, 'disposable_cleanup' => 'verified']]);

        return $drill->refresh();
    }

    private function cleanup(string $database, string $target): void
    {
        DB::statement('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()', [$database]);
        DB::statement('DROP DATABASE IF EXISTS "'.$database.'"');
        if (is_dir($target) && ! File::deleteDirectory($target)) {
            throw new RuntimeException('Disposable restore directory could not be removed.');
        }
    }
}
