<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the Vistora shell on public and authentication pages without changing the technical API name', function (): void {
    foreach (['/', '/ontdek', '/collecties', '/login'] as $path) {
        $this->get($path)->assertOk()
            ->assertSee('Vistora')
            ->assertDontSee('FotoArchief')
            ->assertSee('href="#main"', false)
            ->assertSee('href="/manifest.webmanifest"', false)
            ->assertSee('href="/brand/apple-touch-icon.png"', false)
            ->assertSee('id="theme-preference"', false)
            ->assertSee('data-storage-error=', false)
            ->assertSee('src="/theme.js?v='.hash_file('sha256', public_path('theme.js')).'"', false)
            ->assertSee('href="/brand/tokens.css?v='.hash_file('sha256', public_path('brand/tokens.css')).'"', false)
            ->assertSee('href="/app.css?v='.hash_file('sha256', public_path('app.css')).'"', false)
            ->assertDontSee('class="staff-nav"', false);
    }
    $this->getJson('/api/status')->assertJsonPath('name', 'FotoArchief');
});

it('keeps staff navigation and sensitive notices inside the authenticated shell', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::query()->create(['name' => 'Brand fixture', 'email' => 'brand@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', 'administrator')->sole());
    $response = $this->actingAs($user)->withSession(['recovery_codes' => ['private-recovery-fixture']])
        ->get('/admin')->assertOk()
        ->assertSee('class="staff-nav"', false)
        ->assertSee('Vistora')
        ->assertDontSee('FotoArchief')
        ->assertSee('private-recovery-fixture');
    $head = explode('</head>', $response->getContent())[0];
    expect($head)->not->toContain('private-recovery-fixture')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
});

it('ships a public-root install manifest with local complete icon assets and no offline cache code', function (): void {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, 512, JSON_THROW_ON_ERROR);
    expect($manifest)->toMatchArray([
        'id' => '/', 'start_url' => '/', 'scope' => '/', 'name' => 'Vistora',
        'short_name' => 'Vistora', 'display' => 'standalone', 'lang' => 'nl',
    ]);
    expect(array_column($manifest['icons'], 'purpose'))->toContain('any', 'maskable', 'monochrome');
    foreach ($manifest['icons'] as $icon) {
        expect($icon['src'])->toStartWith('/brand/');
        expect(is_file(public_path(ltrim($icon['src'], '/'))))->toBeTrue();
    }
    foreach ([16, 32, 48, 192, 512] as $size) {
        $image = getimagesize(public_path('brand/icon-'.$size.'.png'));
        expect([$image[0], $image[1]])->toBe([$size, $size]);
    }
    foreach (['apple-touch-icon' => [180, 180], 'maskable-512' => [512, 512], 'social-preview' => [1200, 630], 'social-square' => [1080, 1080], 'social-portrait' => [1080, 1350]] as $file => $dimensions) {
        $image = getimagesize(public_path('brand/'.$file.'.png'));
        expect([$image[0], $image[1]])->toBe($dimensions);
    }
    $ico = file_get_contents(public_path('favicon.ico'));
    expect(unpack('vreserved/vtype/vcount', substr($ico, 0, 6)))->toBe(['reserved' => 0, 'type' => 1, 'count' => 3]);
    foreach ([16, 32, 48] as $index => $size) {
        expect(ord($ico[6 + 16 * $index]))->toBe($size);
    }
    expect(file_get_contents(public_path('theme.js')))->not->toContain('serviceWorker', 'caches.open');
});

it('keeps all maskable foreground pixels inside the central safe circle', function (): void {
    $image = imagecreatefrompng(public_path('brand/maskable-512.png'));
    $background = imagecolorat($image, 0, 0);
    $foreground = 0;
    for ($y = 0; $y < 512; $y++) {
        for ($x = 0; $x < 512; $x++) {
            if (imagecolorat($image, $x, $y) === $background) {
                continue;
            }
            $foreground++;
            if (hypot($x + .5 - 256, $y + .5 - 256) > 204.8) {
                $this->fail('Maskable foreground pixel outside safe circle at '.$x.','.$y);
            }
        }
    }
    expect($foreground)->toBeGreaterThan(10000);
});

it('ships self-hosted font files with their original licenses', function (): void {
    foreach (['source-sans-regular', 'source-sans-semibold', 'source-sans-bold', 'source-serif-semibold'] as $name) {
        $font = file_get_contents(public_path('brand/fonts/'.$name.'.woff2'));
        expect(substr($font, 0, 4))->toBe('wOF2');
    }
    foreach (['SOURCE-SANS', 'SOURCE-SERIF'] as $name) {
        expect(file_get_contents(public_path('brand/fonts/'.$name.'-LICENSE.md')))->toContain('SIL OPEN FONT LICENSE');
    }
});
