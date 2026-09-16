<?php

declare(strict_types=1);

use App\Modules\Ai\Exceptions\AiProviderException;
use App\Modules\Ai\Models\AiBudgetLedger;
use App\Modules\Ai\Services\AiBudgetLedgerService;
use App\Modules\Ai\Services\AiProviderConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(AiProviderConfigService::class)->update('gemini', [
        'enabled' => true,
        'monthly_budget_cents' => 100,
    ]);
});

it('reserves budget for a new period/provider/capability ledger row', function (): void {
    $ledger = app(AiBudgetLedgerService::class)->reserve('gemini', 'embeddings', 40);

    expect($ledger->cents_limit)->toBe(100)
        ->and($ledger->cents_reserved)->toBe(40)
        ->and($ledger->cents_consumed)->toBe(0)
        ->and(AiBudgetLedger::query()->count())->toBe(1);
});

it('refuses a reservation that would exceed the configured monthly cap', function (): void {
    $service = app(AiBudgetLedgerService::class);
    $service->reserve('gemini', 'embeddings', 70);

    expect(fn () => $service->reserve('gemini', 'embeddings', 40))
        ->toThrow(AiProviderException::class, 'Maandbudget');
});

it('refuses any reservation when the provider has no configured monthly budget', function (): void {
    app(AiProviderConfigService::class)->update('openai', [
        'monthly_budget_cents' => 0,
    ]);

    expect(fn () => app(AiBudgetLedgerService::class)->reserve('openai', 'image_analysis', 1))
        ->toThrow(AiProviderException::class, 'niet geconfigureerd');
});

it('settles a reservation into consumed cents on success', function (): void {
    $service = app(AiBudgetLedgerService::class);
    $ledger = $service->reserve('gemini', 'embeddings', 40);

    $service->consume($ledger, 40, 35);

    $ledger->refresh();
    expect($ledger->cents_reserved)->toBe(0)
        ->and($ledger->cents_consumed)->toBe(35);
});

it('releases a reservation in full when the provider call fails', function (): void {
    $service = app(AiBudgetLedgerService::class);
    $ledger = $service->reserve('gemini', 'embeddings', 40);

    $service->release($ledger, 40);

    $ledger->refresh();
    expect($ledger->cents_reserved)->toBe(0)
        ->and($ledger->cents_consumed)->toBe(0);
});

it('frees a released reservation so a later reservation can use the same budget', function (): void {
    $service = app(AiBudgetLedgerService::class);
    $first = $service->reserve('gemini', 'embeddings', 70);
    $service->release($first, 70);

    $second = $service->reserve('gemini', 'embeddings', 70);

    expect($second->cents_reserved)->toBe(70);
});

it('keeps separate ledgers per capability so image analysis budget cannot spend embeddings budget', function (): void {
    app(AiProviderConfigService::class)->update('gemini', [
        'monthly_budget_cents' => 100,
    ]);
    $service = app(AiBudgetLedgerService::class);

    $service->reserve('gemini', 'image_analysis', 90);
    $embeddingLedger = $service->reserve('gemini', 'embeddings', 90);

    expect($embeddingLedger->cents_reserved)->toBe(90)
        ->and(AiBudgetLedger::query()->count())->toBe(2);
});
