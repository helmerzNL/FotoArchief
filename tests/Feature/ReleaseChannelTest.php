<?php

declare(strict_types=1);

use App\Support\ReleaseChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('chooses the image with one FOTOARCHIEF_TAG variable in Compose and passes it into the app', function (): void {
    $compose = (string) file_get_contents(base_path('deploy/compose.yaml'));
    $environment = (string) file_get_contents(base_path('deploy/.env.example'));
    $komodo = (string) file_get_contents(base_path('deploy/komodo-stack.example.toml'));

    expect($compose)
        ->toContain('image: ghcr.io/helmerznl/fotoarchief:${FOTOARCHIEF_TAG:-latest}')
        ->toContain('FOTOARCHIEF_TAG: ${FOTOARCHIEF_TAG:-latest}')
        ->not->toContain('APP_IMAGE')
        ->and(preg_match_all('/^FOTOARCHIEF_TAG=latest$/m', $environment))->toBe(1)
        ->and($environment)->not->toContain('APP_IMAGE')
        ->and($komodo)->toContain('FOTOARCHIEF_TAG=latest')->not->toContain('APP_IMAGE');
});

it('derives the beta channel only from a tag that contains beta', function (): void {
    expect(ReleaseChannel::forTag('beta'))->toBe('beta')
        ->and(ReleaseChannel::forTag('beta-0123abc'))->toBe('beta')
        ->and(ReleaseChannel::forTag('latest'))->toBe('stable')
        ->and(ReleaseChannel::forTag('v0.9.79'))->toBe('stable')
        ->and(ReleaseChannel::forTag(null))->toBe('stable');
});

it('shows a beta label and title suffix on a beta build and nothing on a stable one', function (): void {
    config(['app.locale' => 'nl', 'installation.enabled' => false, 'app.channel' => 'beta']);
    $this->get('/login')->assertOk()
        ->assertSee('channel-badge', false)
        ->assertSee('(beta)</title>', false);
    $this->getJson('/api/status')->assertOk()->assertJson(['channel' => 'beta']);

    config(['app.channel' => 'stable']);
    $this->get('/login')->assertOk()
        ->assertDontSee('channel-badge', false)
        ->assertDontSee('(beta)', false);
    $this->getJson('/api/status')->assertOk()->assertJson(['channel' => 'stable']);
});
