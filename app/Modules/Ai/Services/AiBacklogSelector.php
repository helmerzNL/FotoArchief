<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Catalogue\Models\Asset;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Photos that are ready for image analysis and have not been analysed yet by a
 * provider. "Analysed" is the same fact the job's idempotency key records: an
 * ai_runs row for the primary file's current checksum, so the backlog never
 * pays twice for the same bytes. A photo that failed recently is left alone
 * for a cooldown so one broken file cannot block the sweep.
 */
final class AiBacklogSelector
{
    /**
     * @return Builder<Asset>
     */
    public function query(string $provider, ?CarbonInterface $since = null): Builder
    {
        $cooldown = now()->subDays(max(0, (int) config('ai.auto_analysis.failure_cooldown_days', 7)));

        return Asset::query()
            ->when($since !== null, fn (Builder $query): Builder => $query->where('assets.created_at', '>=', $since))
            ->whereExists(function (QueryBuilder $files) use ($provider): void {
                $files->select(DB::raw('1'))
                    ->from('asset_files')
                    ->whereColumn('asset_files.asset_id', 'assets.id')
                    ->where('asset_files.is_primary', true)
                    ->where('asset_files.ingest_status', 'ready_private')
                    ->whereNotNull('asset_files.derivatives->preview1200')
                    ->whereNotExists(function (QueryBuilder $runs) use ($provider): void {
                        $runs->select(DB::raw('1'))
                            ->from('ai_runs')
                            ->whereColumn('ai_runs.asset_file_id', 'asset_files.id')
                            ->whereColumn('ai_runs.source_file_sha256', 'asset_files.sha256')
                            ->where('ai_runs.run_type', 'image_analysis')
                            ->where('ai_runs.provider_kind', $provider);
                    });
            })
            ->whereNotExists(function (QueryBuilder $failures) use ($cooldown): void {
                $failures->select(DB::raw('1'))
                    ->from('operation_run_audit_events')
                    ->whereColumn('operation_run_audit_events.asset_id', 'assets.id')
                    ->where('operation_run_audit_events.event_type', 'ai.analysis.item_failed')
                    ->where('operation_run_audit_events.created_at', '>=', $cooldown);
            })
            ->orderBy('assets.created_at')
            ->orderBy('assets.id');
    }

    public function count(string $provider, ?CarbonInterface $since = null): int
    {
        return $this->query($provider, $since)->count();
    }

    /**
     * @return list<string>
     */
    public function ids(string $provider, int $limit, ?CarbonInterface $since = null): array
    {
        if ($limit < 1) {
            return [];
        }

        return array_values($this->query($provider, $since)->limit($limit)->pluck('assets.id')->map(fn ($id): string => (string) $id)->all());
    }
}
