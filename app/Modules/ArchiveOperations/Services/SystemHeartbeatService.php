<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Modules\ArchiveOperations\Models\SystemHeartbeat;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SystemHeartbeatService
{
    private const array ROLES = ['worker', 'scheduler'];

    /**
     * @param  array<string, mixed>  $details
     */
    public function record(string $role, string $state = 'ok', array $details = []): void
    {
        if (! in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException("Unknown heartbeat role [{$role}].");
        }

        try {
            DB::connection()->getPdo();
            if (! Schema::hasTable('system_heartbeats')) {
                return;
            }

            SystemHeartbeat::query()->updateOrCreate(
                ['role' => $role],
                [
                    'state' => $state,
                    'last_seen_at' => now(),
                    'details' => $details === [] ? null : $details,
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('System heartbeat could not be recorded.', [
                'role' => $role,
                'state' => $state,
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function snapshot(int $staleAfterMinutes = 5): array
    {
        $empty = [];
        foreach (self::ROLES as $role) {
            $empty[$role] = [
                'seen' => false,
                'state' => 'missing',
                'last_seen_at' => null,
                'age_seconds' => null,
                'stale' => true,
                'details' => null,
            ];
        }

        try {
            DB::connection()->getPdo();
            if (! Schema::hasTable('system_heartbeats')) {
                return $empty;
            }

            /** @var Collection<int, SystemHeartbeat> $heartbeats */
            $heartbeats = SystemHeartbeat::query()->get();

            foreach (self::ROLES as $role) {
                /** @var SystemHeartbeat|null $heartbeat */
                $heartbeat = $heartbeats->firstWhere('role', $role);
                if ($heartbeat === null) {
                    continue;
                }

                $ageSeconds = max(0, (int) $heartbeat->last_seen_at->diffInSeconds(now()));
                $empty[$role] = [
                    'seen' => true,
                    'state' => $heartbeat->state,
                    'last_seen_at' => $heartbeat->last_seen_at->toIso8601String(),
                    'age_seconds' => $ageSeconds,
                    'stale' => $ageSeconds > ($staleAfterMinutes * 60),
                    'details' => $heartbeat->details,
                ];
            }
        } catch (Throwable $exception) {
            Log::warning('System heartbeat snapshot could not be read.', [
                'exception' => $exception::class,
            ]);
        }

        return $empty;
    }
}
