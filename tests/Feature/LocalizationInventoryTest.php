<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Tests\Support\UserVisibleTextScanner;

it('keeps the remaining user-visible text inventory machine-readable and complete', function (): void {
    $inventory = json_decode(File::get(base_path('docs/localization/text-inventory.remaining.json')), true, 512, JSON_THROW_ON_ERROR);
    $entries = (new UserVisibleTextScanner)->remainingInventory();

    expect($inventory)
        ->toHaveKey('schema', 1)
        ->toHaveKey('locale', 'nl')
        ->toHaveKey('scope', 'remaining_surfaces')
        ->and($inventory['entries'])->toBe($entries)
        ->and($inventory['entries'])->not->toBeEmpty();
});
