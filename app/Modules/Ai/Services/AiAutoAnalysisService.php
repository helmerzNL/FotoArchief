<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Models\User;
use App\Modules\Ai\Jobs\ProcessAiAnalysisJob;
use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Models\AiSetting;
use App\Modules\ArchiveOperations\Models\OperationRun;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Starts image analysis without a person pressing the button, strictly inside
 * what an administrator consented to: the configured provider, its monthly
 * budget, a daily photo cap and one run at a time. It only queues the same
 * ProcessAiAnalysisJob as a manual run; the result is suggestions that a
 * person reviews. Ingest never calls this.
 */
final class AiAutoAnalysisService
{
    public const string LAST_SWEEP_KEY = 'auto_analysis_last_sweep';

    /** Providers whose spend is metered by the ledger (native) or that cost nothing per call (local). */
    private const array ALLOWED_PROVIDERS = ['local', 'openai', 'anthropic', 'gemini', 'openrouter'];

    public function __construct(
        private readonly AiConfigurationService $configuration,
        private readonly AiProviderConfigService $providerConfigs,
        private readonly AiBudgetLedgerService $ledger,
        private readonly AiBacklogSelector $backlog,
        private readonly AiDispatchService $dispatch,
    ) {}

    /**
     * Whether automatic analysis can be switched on, and why not.
     *
     * @param  array<string, mixed>  $settings
     */
    public function blocker(array $settings): ?string
    {
        if (! (bool) ($settings['active'] ?? false)) {
            return 'inactive';
        }
        if (! (bool) ($settings['image_analysis_enabled'] ?? false) || ! (bool) ($settings['image_analysis_ready'] ?? false)) {
            return 'not_ready';
        }
        if (! in_array((string) ($settings['image_analysis_provider'] ?? ''), self::ALLOWED_PROVIDERS, true)) {
            return 'provider';
        }

        return null;
    }

    /**
     * @param  array{enabled: bool, backfill: bool}  $input
     * @return array<string, bool|int|string|null>
     */
    public function update(array $input, User $user): array
    {
        $settings = $this->configuration->effective();
        $enabled = $input['enabled'];
        if ($enabled) {
            $blocker = $this->blocker($settings);
            if ($blocker !== null) {
                throw ValidationException::withMessages(['auto_analysis_enabled' => match ($blocker) {
                    'inactive' => __('ai.auto.blocked.inactive'),
                    'provider' => __('ai.auto.blocked.provider'),
                    default => __('ai.auto.blocked.not_ready'),
                }]);
            }
        }
        $wasEnabled = (bool) ($settings['auto_analysis_enabled'] ?? false);
        $values = [
            'auto_analysis_enabled' => $enabled,
            'auto_analysis_backfill' => $input['backfill'],
        ];
        if ($enabled) {
            $values['auto_analysis_user_id'] = $user->id;
            if (! $wasEnabled || ! is_string($settings['auto_analysis_enabled_at'] ?? null)) {
                $values['auto_analysis_enabled_at'] = now()->toIso8601String();
            }
        }
        foreach ($values as $key => $value) {
            AiSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => ['value' => $value], 'updated_by_user_id' => $user->id],
            );
        }

        return $this->configuration->effective();
    }

    /**
     * One scheduled pass: queue at most one bounded run when everything allows it.
     *
     * @return array{status: string, dispatched: int, run_id: string|null}
     */
    public function sweep(): array
    {
        $settings = $this->configuration->effective();
        $plan = $this->plan($settings);
        $result = ['status' => $plan['status'], 'dispatched' => 0, 'run_id' => null];

        if ($plan['status'] === 'ready' && $plan['actor'] instanceof User) {
            $ids = $this->backlog->ids($plan['provider'], $plan['batch'], $plan['since']);
            if ($ids === []) {
                $result['status'] = 'idle';
            } else {
                $run = $this->dispatch->dispatchImageAnalysis($ids, $plan['provider'], $plan['actor'], ['source' => 'auto']);
                $result = ['status' => 'dispatched', 'dispatched' => count($ids), 'run_id' => $run->id];
            }
        }
        $this->remember($result);

        return $result;
    }

    /**
     * Explicit, operator-started backlog run over the whole archive (ai:analyse-archive).
     * Reports the estimate first; queuing needs a cost ceiling whenever calls cost money.
     *
     * @return array<string, mixed>
     */
    public function archiveRun(int $limit, ?int $maxCostCents, ?User $actor, bool $dryRun): array
    {
        $settings = $this->configuration->effective();
        if ($this->blocker($settings) !== null) {
            throw ValidationException::withMessages(['ai' => __('ai.auto.archive.not_ready')]);
        }
        $provider = (string) $settings['image_analysis_provider'];
        $limit = max(1, min($limit, (int) config('ai.auto_analysis.archive_max_per_command', 1000)));
        $cost = $this->costCents($provider);
        $backlog = $this->backlog->count($provider);
        $selected = min($limit, $backlog);
        $estimate = $selected * $cost;
        $budget = $this->budgetRemaining($provider);
        $report = [
            'provider' => $provider,
            'model' => (string) ($settings['image_analysis_model'] ?? ''),
            'backlog' => $backlog,
            'selected' => $selected,
            'cost_cents_per_photo' => $cost,
            'estimated_cost_cents' => $estimate,
            'budget_remaining_cents' => $budget,
            'runs' => [],
        ];
        if ($dryRun || $selected === 0) {
            return $report;
        }

        if ($cost > 0 && $maxCostCents === null) {
            throw ValidationException::withMessages(['max-cost-cents' => __('ai.auto.archive.cost_ceiling_required')]);
        }
        if ($maxCostCents !== null && $estimate > $maxCostCents) {
            throw ValidationException::withMessages(['max-cost-cents' => __('ai.auto.archive.over_ceiling', ['estimate' => $estimate, 'ceiling' => $maxCostCents])]);
        }
        if ($budget !== null && $estimate > $budget) {
            throw ValidationException::withMessages(['max-cost-cents' => __('ai.auto.archive.over_budget', ['estimate' => $estimate, 'budget' => $budget])]);
        }
        if ($this->activeRuns() > 0) {
            throw ValidationException::withMessages(['ai' => __('ai.auto.archive.busy')]);
        }
        if (! $actor instanceof User || ! $actor->hasPermission('users.manage')) {
            throw ValidationException::withMessages(['as' => __('ai.auto.archive.actor_required')]);
        }

        $perRun = max(1, min(25, (int) ($settings['max_assets_per_batch'] ?? 25)));
        foreach (array_chunk($this->backlog->ids($provider, $selected), $perRun) as $ids) {
            $report['runs'][] = $this->dispatch->dispatchImageAnalysis($ids, $provider, $actor, ['source' => 'archive'])->id;
        }

        return $report;
    }

    /**
     * What the next sweep would do and why, without queueing anything.
     *
     * @param  array<string, mixed>  $settings
     * @return array{status: string, provider: string, actor: User|null, since: CarbonImmutable|null, batch: int, cost_cents: int, daily_remaining: int, budget_remaining: int|null}
     */
    public function plan(array $settings): array
    {
        $provider = (string) ($settings['image_analysis_provider'] ?? '');
        $plan = [
            'status' => 'ready',
            'provider' => $provider,
            'actor' => null,
            'since' => null,
            'batch' => 0,
            'cost_cents' => $this->costCents($provider),
            'daily_remaining' => $this->dailyRemaining(),
            'budget_remaining' => $this->budgetRemaining($provider),
        ];

        if (! (bool) ($settings['auto_analysis_enabled'] ?? false)) {
            return [...$plan, 'status' => 'disabled'];
        }
        if ($this->blocker($settings) !== null) {
            return [...$plan, 'status' => 'not_ready'];
        }
        $actor = User::query()->find((string) ($settings['auto_analysis_user_id'] ?? ''));
        if (! $actor instanceof User || ! $actor->hasPermission('users.manage')) {
            return [...$plan, 'status' => 'actor'];
        }
        $plan['actor'] = $actor;
        $plan['since'] = $this->since($settings);
        if ($this->activeRuns() > 0) {
            return [...$plan, 'status' => 'busy'];
        }
        if ($plan['daily_remaining'] < 1) {
            return [...$plan, 'status' => 'daily_limit'];
        }

        $batch = min(
            (int) config('ai.auto_analysis.batch_size', 25),
            max(1, (int) ($settings['max_assets_per_batch'] ?? 25)),
            $plan['daily_remaining'],
        );
        if ($plan['cost_cents'] > 0 && $plan['budget_remaining'] !== null) {
            $batch = min($batch, intdiv($plan['budget_remaining'], $plan['cost_cents']));
        }
        if ($batch < 1) {
            return [...$plan, 'status' => 'budget'];
        }

        return [...$plan, 'batch' => $batch];
    }

    /**
     * Numbers for the readiness card.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $settings = $this->configuration->effective();
        $plan = $this->plan($settings);
        $provider = $plan['provider'];
        $backlog = $provider === '' ? 0 : $this->backlog->count($provider, $this->since($settings));

        return [
            'enabled' => (bool) ($settings['auto_analysis_enabled'] ?? false),
            'backfill' => (bool) ($settings['auto_analysis_backfill'] ?? false),
            'blocker' => $this->blocker($settings),
            'state' => $plan['status'],
            'provider' => $provider,
            'backlog' => $backlog,
            'cost_cents' => $plan['cost_cents'],
            'estimated_cents' => $backlog * $plan['cost_cents'],
            'analysed_today' => (int) config('ai.auto_analysis.daily_max', 1000) - $plan['daily_remaining'],
            'daily_max' => (int) config('ai.auto_analysis.daily_max', 1000),
            'budget_cents' => in_array($provider, AiProviderConfigService::PROVIDERS, true) ? $this->providerConfigs->monthlyBudget($provider) : 0,
            'budget_remaining' => $plan['budget_remaining'],
            'last_sweep' => $this->lastSweep(),
        ];
    }

    /**
     * Without the backfill option only photos added after automatic analysis was
     * switched on take part; older photos need the backfill or ai:analyse-archive.
     *
     * @param  array<string, mixed>  $settings
     */
    private function since(array $settings): ?CarbonImmutable
    {
        if ((bool) ($settings['auto_analysis_backfill'] ?? false)) {
            return null;
        }
        $enabledAt = $settings['auto_analysis_enabled_at'] ?? null;

        return is_string($enabledAt) ? CarbonImmutable::parse($enabledAt) : CarbonImmutable::now();
    }

    public function actorId(): ?string
    {
        $id = $this->configuration->effective()['auto_analysis_user_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function costCents(string $provider): int
    {
        return in_array($provider, AiConfigurationService::NATIVE_PROVIDERS, true)
            ? $this->providerConfigs->cost($provider, 'image_analysis')
            : 0;
    }

    public function budgetRemaining(string $provider): ?int
    {
        return in_array($provider, AiConfigurationService::NATIVE_PROVIDERS, true)
            ? $this->ledger->remainingCents($provider, 'image_analysis')
            : null;
    }

    public function dailyRemaining(): int
    {
        $used = AiRun::query()
            ->where('run_type', AiRun::TYPE_IMAGE_ANALYSIS)
            ->where('created_at', '>=', now('UTC')->startOfDay())
            ->count();

        return max(0, (int) config('ai.auto_analysis.daily_max', 1000) - $used);
    }

    public function activeRuns(): int
    {
        return OperationRun::query()
            ->where('operation_type', ProcessAiAnalysisJob::TYPE)
            ->whereIn('status', [OperationRun::STATUS_QUEUED, OperationRun::STATUS_RUNNING, OperationRun::STATUS_PAUSED])
            ->count();
    }

    /**
     * @return array{at: string, status: string, dispatched: int}|null
     */
    public function lastSweep(): ?array
    {
        $row = AiSetting::query()->find(self::LAST_SWEEP_KEY);
        $stored = $row instanceof AiSetting ? $row->value : null;
        $value = is_array($stored) ? ($stored['value'] ?? null) : null;
        $decoded = is_string($value) ? json_decode($value, true) : null;
        if (! is_array($decoded) || ! is_string($decoded['at'] ?? null) || ! is_string($decoded['status'] ?? null)) {
            return null;
        }

        return ['at' => $decoded['at'], 'status' => $decoded['status'], 'dispatched' => (int) ($decoded['dispatched'] ?? 0)];
    }

    /**
     * @param  array{status: string, dispatched: int, run_id: string|null}  $result
     */
    private function remember(array $result): void
    {
        AiSetting::query()->updateOrCreate(
            ['key' => self::LAST_SWEEP_KEY],
            ['value' => ['value' => json_encode([
                'at' => now()->toIso8601String(),
                'status' => $result['status'],
                'dispatched' => $result['dispatched'],
            ], JSON_THROW_ON_ERROR)]],
        );
    }
}
