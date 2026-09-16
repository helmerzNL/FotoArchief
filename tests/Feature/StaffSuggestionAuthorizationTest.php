<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Publication\Models\AssetSuggestion;
use App\Modules\Publication\Models\Publication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * Security finding (HIGH): StaffSuggestionController::index()/show()/accept()/
 * reject() previously authorized only against the global assets.view/
 * assets.update permissions, with no per-asset ownership check. A volunteer
 * (assets.view + assets.update, no assets.publish) could therefore read
 * another owner's visitor-submitted PII (submitter name/email) and moderate
 * a suggestion for an asset they do not own. Fixed to scope exactly like
 * StaffPublicationController: without assets.publish, only suggestions on
 * assets the user themselves created are visible or moderatable; with
 * assets.publish, everything is. A trashed asset's suggestion now also
 * fails closed (404), for every role, because it is resolved through
 * Asset's default (non-trashed) query.
 */
uses(RefreshDatabase::class);

function suggestionAuthzUser(string $role): User
{
    $user = User::query()->create(['name' => $role, 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

    return $user;
}

function suggestionAuthzAssetWithSuggestion(User $owner, string $title = 'Kade bij avond'): array
{
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'created_by_user_id' => $owner->id, 'title' => $title, 'lock_version' => 1]);
    $prefix = 'derivatives/'.str()->ulid().'/';
    $derivatives = ['preview300' => $prefix.'preview300.jpg', 'preview1200' => $prefix.'preview1200.jpg', 'preview2000' => $prefix.'preview2000.jpg'];
    foreach ($derivatives as $key) {
        Storage::disk('local')->put($key, 'fake-jpeg-bytes');
    }
    AssetFile::query()->create([
        'asset_id' => $asset->id, 'storage_disk' => 'local', 'storage_key' => $prefix.'original.jpg',
        'sha256' => hash('sha256', (string) str()->uuid()), 'media_type' => 'image/jpeg', 'byte_size' => 1000,
        'pixel_width' => 2000, 'pixel_height' => 1000, 'derivatives' => $derivatives,
        'ingest_status' => 'ready_private', 'scanner_status' => 'clean', 'validated_at' => now(), 'processed_at' => now(), 'scanned_at' => now(),
    ]);
    $asset->rights()->create(['verification_status' => 'verified', 'rights_holder' => 'Gemeentearchief']);
    $publication = Publication::query()->create([
        'asset_id' => $asset->id, 'status' => 'published', 'privacy_cleared' => true, 'published_lock_version' => 1,
        'permalink_slug' => 'kade-'.str()->random(6), 'download_policy' => 'preview_only',
    ]);
    $suggestion = AssetSuggestion::query()->create([
        'asset_id' => $asset->id, 'publication_id' => $publication->id, 'suggestion_type' => 'identification',
        'message' => 'Dit is mijn overgrootvader.', 'submitter_name' => 'Anna Visser', 'submitter_email' => 'anna@example.test',
        'ip_hash' => hash('sha256', 'test'), 'status' => 'pending',
    ]);

    return [$asset, $suggestion];
}

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
});

it('excludes another owner\'s suggestion from a volunteer\'s index without assets.publish', function (): void {
    $volunteerA = suggestionAuthzUser('volunteer');
    $volunteerB = suggestionAuthzUser('volunteer');
    [, $ownSuggestion] = suggestionAuthzAssetWithSuggestion($volunteerA, 'Kade bij avond');
    [, $otherSuggestion] = suggestionAuthzAssetWithSuggestion($volunteerB, 'Markt op zaterdag');

    $response = $this->actingAs($volunteerA)->get('/admin/suggesties?status=all');

    // The index never lists submitter PII (only title/type/message/status);
    // ownership scoping is asserted by which asset's row/link appears.
    $response->assertOk()
        ->assertSee('Kade bij avond')
        ->assertDontSee('Markt op zaterdag')
        ->assertDontSee(route('admin.suggestions.show', $otherSuggestion));
});

it('403s a volunteer reading another owner\'s suggestion detail, leaking no submitter PII', function (): void {
    $volunteerA = suggestionAuthzUser('volunteer');
    $volunteerB = suggestionAuthzUser('volunteer');
    [, $otherSuggestion] = suggestionAuthzAssetWithSuggestion($volunteerB);

    $this->actingAs($volunteerA)->get(route('admin.suggestions.show', $otherSuggestion))
        ->assertForbidden()
        ->assertDontSee($otherSuggestion->submitter_email);
});

it('403s a volunteer accepting or rejecting another owner\'s suggestion', function (): void {
    $volunteerA = suggestionAuthzUser('volunteer');
    $volunteerB = suggestionAuthzUser('volunteer');
    [, $otherSuggestion] = suggestionAuthzAssetWithSuggestion($volunteerB);

    $this->actingAs($volunteerA)->post(route('admin.suggestions.accept', $otherSuggestion))->assertForbidden();
    $this->actingAs($volunteerA)->post(route('admin.suggestions.reject', $otherSuggestion))->assertForbidden();
    expect($otherSuggestion->fresh()->status)->toBe('pending');
});

it('lets an administrator with assets.publish see and moderate every owner\'s suggestion', function (): void {
    $admin = suggestionAuthzUser('administrator');
    $volunteer = suggestionAuthzUser('volunteer');
    [, $suggestion] = suggestionAuthzAssetWithSuggestion($volunteer, 'Haven bij zonsondergang');

    $this->actingAs($admin)->get('/admin/suggesties?status=all')->assertOk()->assertSee('Haven bij zonsondergang');
    $this->actingAs($admin)->get(route('admin.suggestions.show', $suggestion))->assertOk()->assertSee($suggestion->submitter_name);
    $this->actingAs($admin)->post(route('admin.suggestions.accept', $suggestion), ['moderator_note' => 'Bevestigd door archief.'])
        ->assertRedirect(route('admin.suggestions.index'));
    expect($suggestion->fresh()->status)->toBe('accepted');
});

it('fails closed on a trashed asset\'s suggestion for every role, including an administrator', function (): void {
    $admin = suggestionAuthzUser('administrator');
    $volunteer = suggestionAuthzUser('volunteer');
    [$asset, $suggestion] = suggestionAuthzAssetWithSuggestion($volunteer);

    $asset->delete();

    $this->actingAs($admin)->get('/admin/suggesties?status=all')->assertOk()->assertDontSee('Kade bij avond');
    $this->actingAs($admin)->get(route('admin.suggestions.show', $suggestion))->assertNotFound();
    $this->actingAs($admin)->post(route('admin.suggestions.accept', $suggestion))->assertNotFound();
    expect($suggestion->fresh()->status)->toBe('pending');
});
