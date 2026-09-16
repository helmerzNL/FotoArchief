<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Exceptions\AiProviderException;
use App\Modules\Ai\Models\AiBudgetLedger;
use Illuminate\Support\Facades\DB;

/**
 * Enforces a real monthly budget cap per native provider/capability with a
 * row-locked reserve-then-consume-or-release pattern, so two concurrent AI
 * jobs cannot both pass a "is there budget left" check and jointly overspend
 * it. A reservation is created before a paid API call is made and either
 * settled to the actual cost (consume) or fully released if the call fails.
 */
class AiBudgetLedgerService
{
    /**
     * @throws AiProviderException when the reservation would exceed the configured monthly cap.
     */
    public function reserve(string $providerKind, string $capability, int $cents): AiBudgetLedger
    {
        $limitCents = (int) config("ai.native_providers.{$providerKind}.monthly_budget_cents", 0);
        $periodKey = now('UTC')->format('Y-m');

        return DB::transaction(function () use ($providerKind, $capability, $cents, $limitCents, $periodKey): AiBudgetLedger {
            $ledger = AiBudgetLedger::query()
                ->where('period_key', $periodKey)
                ->where('provider_kind', $providerKind)
                ->where('capability', $capability)
                ->lockForUpdate()
                ->first();

            if ($ledger === null) {
                $ledger = AiBudgetLedger::query()->create([
                    'period_key' => $periodKey,
                    'provider_kind' => $providerKind,
                    'capability' => $capability,
                    'cents_limit' => $limitCents,
                    'cents_reserved' => 0,
                    'cents_consumed' => 0,
                ]);
            }

            $committed = $ledger->cents_reserved + $ledger->cents_consumed;
            if ($limitCents <= 0 || $committed + $cents > $limitCents) {
                throw new AiProviderException("Maandbudget voor {$providerKind}/{$capability} is bereikt of niet geconfigureerd.");
            }

            $ledger->increment('cents_reserved', $cents);

            return $ledger->refresh();
        });
    }

    public function consume(AiBudgetLedger $ledger, int $reservedCents, int $actualCents): void
    {
        DB::transaction(function () use ($ledger, $reservedCents, $actualCents): void {
            AiBudgetLedger::query()->where('id', $ledger->id)->lockForUpdate()->first();
            $ledger->decrement('cents_reserved', $reservedCents);
            $ledger->increment('cents_consumed', $actualCents);
        });
    }

    public function release(AiBudgetLedger $ledger, int $reservedCents): void
    {
        DB::transaction(function () use ($ledger, $reservedCents): void {
            AiBudgetLedger::query()->where('id', $ledger->id)->lockForUpdate()->first();
            $ledger->decrement('cents_reserved', $reservedCents);
        });
    }
}
