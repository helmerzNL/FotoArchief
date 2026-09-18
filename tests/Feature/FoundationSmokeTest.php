<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('boots with isolated test settings instead of a private environment file', function (): void {
    expect(app()->environmentPath())->toBe(dirname(__DIR__).'/Fixtures')
        ->and(app()->environmentFile())->toBe('test-settings');
});

it('exposes the HTTP liveness endpoint', function (): void {
    $this->get('/up')->assertOk();
});

it('renders the Laravel foundation landing page', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('Vistora');
});

it('exposes a minimal API status endpoint', function (): void {
    $this->getJson('/api/status')
        ->assertOk()
        ->assertJson([
            'name' => 'FotoArchief',
            'status' => 'ok',
        ]);
});
