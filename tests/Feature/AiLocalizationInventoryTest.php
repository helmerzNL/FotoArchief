<?php

declare(strict_types=1);

use Tests\Support\UserVisibleTextScanner;

it('does not hide localized AI surfaces in the remaining raw text inventory', function (): void {
    $aiEntries = collect((new UserVisibleTextScanner)->remainingInventory())
        ->filter(fn (array $entry): bool => $entry['batch'] === 'ai')
        ->values();

    expect($aiEntries)->toBeEmpty();
});
