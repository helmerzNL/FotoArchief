<?php

declare(strict_types=1);

test('the test environment is configured', function (): void {
    expect(config('app.name'))->toBe('FotoArchief');
});
