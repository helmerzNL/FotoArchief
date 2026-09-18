<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Modules\ArchiveOperations\Models\BackupRecord;
use App\Modules\ArchiveOperations\Models\OperationRun;
use Illuminate\Database\Migrations\Migrator;

final class RecoveryReadinessService
{
    public function __construct(private readonly SystemDiagnosticsService $diagnostics) {}

    /** @return array<string, mixed> */
    public function check(string $kind, ?string $targetVersion, int $requiredFreeBytes): array
    {
        $diagnostics = $this->diagnostics->getAllDiagnostics();
        $checks = [];
        foreach (['php', 'extensions', 'storage', 'database', 'limits', 'scanner', 'worker', 'activity'] as $key) {
            $section = $diagnostics[$key] ?? [];
            $checks[] = ['key' => $key, 'status' => $section['status'] ?? 'unknown',
                'detail' => $section['remediation'] ?? $section['message'] ?? null];
        }
        $version = trim((string) file_get_contents(base_path('VERSION')));
        if ($kind === 'upgrade') {
            $checks[] = ['key' => 'target_version', 'status' => $targetVersion !== null && version_compare($targetVersion, $version, '>') ? 'ok' : 'blocked', 'detail' => $version.' -> '.($targetVersion ?? '?')];
            $free = disk_free_space(storage_path());
            $checks[] = ['key' => 'free_space', 'status' => $free !== false && $free >= $requiredFreeBytes ? 'ok' : 'blocked',
                'detail' => ['available_bytes' => $free === false ? null : (int) $free, 'required_bytes' => $requiredFreeBytes]];
            $migrator = app('migrator');
            if (! $migrator instanceof Migrator) {
                throw new \RuntimeException(__('recovery.errors.migrations_unavailable'));
            }
            $pending = array_values(array_diff(array_keys($migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')])), $migrator->getRepository()->getRan()));
            $checks[] = ['key' => 'migrations', 'status' => $pending === [] ? 'ok' : 'blocked', 'detail' => $pending];
            $active = OperationRun::query()->whereIn('status', ['queued', 'running', 'paused'])->count();
            $checks[] = ['key' => 'active_tasks', 'status' => $active === 0 ? 'ok' : 'blocked', 'detail' => $active];
            $backup = BackupRecord::query()->where('version', $version)->where('checksum_verified_at', '>=', now()->subDay())->latest('checksum_verified_at')->first();
            $drill = $backup?->drills()->where('status', 'verified')->where('finished_at', '>=', now()->subDays(30))->latest('finished_at')->first();
            $checks[] = ['key' => 'backup_evidence', 'status' => $backup !== null && $drill !== null ? 'ok' : 'blocked',
                'detail' => ['backup_id' => $backup?->id, 'drill_id' => $drill?->getKey(), 'proof_scope' => 'database-and-local-originals']];
        }

        return ['kind' => $kind, 'checked_at' => now()->toIso8601String(), 'current_version' => $version,
            'ready' => collect($checks)->every(fn (array $check): bool => $check['status'] === 'ok'), 'checks' => $checks,
            'target_release_migrations_inspected' => false, 'external_proxy_verified' => false];
    }
}
