<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Publication\Models\Publication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function publicationUser(string $role = 'administrator'): User
{
    $user = User::query()->create(['name' => $role, 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

    return $user;
}

function publishableAsset(User $owner): Asset
{
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'created_by_user_id' => $owner->id, 'title' => 'Marktplein', 'lock_version' => 1]);
    AssetFile::query()->create([
        'asset_id' => $asset->id, 'storage_disk' => 'local', 'storage_key' => 'derivatives/'.str()->ulid().'/original.jpg',
        'sha256' => hash('sha256', (string) str()->uuid()), 'media_type' => 'image/jpeg', 'byte_size' => 1000,
        'pixel_width' => 2000, 'pixel_height' => 1000, 'derivatives' => ['preview300' => 'a', 'preview1200' => 'b', 'preview2000' => 'c'],
        'ingest_status' => 'ready_private', 'scanner_status' => 'clean', 'validated_at' => now(), 'processed_at' => now(), 'scanned_at' => now(),
    ]);
    $asset->rights()->create(['verification_status' => 'verified', 'rights_holder' => 'Gemeentearchief']);

    return $asset;
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('walks a photo through draft, review and published while sharing one predicate', function (): void {
    $editor = publicationUser('editor');
    $asset = publishableAsset($editor);

    $this->actingAs($editor)->post("/admin/publications/{$asset->id}/submit", [
        'privacy_cleared' => '1', 'download_policy' => 'preview_only', 'credit_line' => 'Gemeentearchief',
    ])->assertRedirect();
    $publication = Publication::query()->where('asset_id', $asset->id)->sole();
    expect($publication->status)->toBe('in_review');
    expect(Publication::query()->publiclyVisible()->where('asset_id', $asset->id)->exists())->toBeFalse();

    $archivist = publicationUser('archivist');
    $this->actingAs($archivist)->post("/admin/publications/{$asset->id}/publish")->assertRedirect();
    $publication->refresh();
    expect($publication->status)->toBe('published')->and($publication->permalink_slug)->not->toBeNull();
    expect(Publication::query()->publiclyVisible()->where('asset_id', $asset->id)->exists())->toBeTrue();
});

it('hides embargoed unverified and unscanned assets from the shared public predicate', function (): void {
    $editor = publicationUser('editor');

    $embargoed = publishableAsset($editor);
    Publication::query()->create(['asset_id' => $embargoed->id, 'status' => 'published', 'privacy_cleared' => true, 'published_lock_version' => 1, 'embargo_until' => now()->addDay()->toDateString()]);
    expect(Publication::query()->publiclyVisible()->where('asset_id', $embargoed->id)->exists())->toBeFalse();

    $unverified = publishableAsset($editor);
    $unverified->rights()->update(['verification_status' => 'unverified']);
    Publication::query()->create(['asset_id' => $unverified->id, 'status' => 'published', 'privacy_cleared' => true, 'published_lock_version' => 1]);
    expect(Publication::query()->publiclyVisible()->where('asset_id', $unverified->id)->exists())->toBeFalse();

    $unscanned = publishableAsset($editor);
    $unscanned->files()->update(['scanner_status' => 'unscanned']);
    Publication::query()->create(['asset_id' => $unscanned->id, 'status' => 'published', 'privacy_cleared' => true, 'published_lock_version' => 1]);
    expect(Publication::query()->publiclyVisible()->where('asset_id', $unscanned->id)->exists())->toBeFalse();

    $notCleared = publishableAsset($editor);
    Publication::query()->create(['asset_id' => $notCleared->id, 'status' => 'published', 'privacy_cleared' => false, 'published_lock_version' => 1]);
    expect(Publication::query()->publiclyVisible()->where('asset_id', $notCleared->id)->exists())->toBeFalse();
});

it('revokes a published photo immediately and hides it from the predicate', function (): void {
    $editor = publicationUser('editor');
    $asset = publishableAsset($editor);
    $publication = Publication::query()->create(['asset_id' => $asset->id, 'status' => 'published', 'privacy_cleared' => true, 'published_lock_version' => 1, 'permalink_slug' => 'test-slug']);
    expect(Publication::query()->publiclyVisible()->where('asset_id', $asset->id)->exists())->toBeTrue();

    $archivist = publicationUser('archivist');
    $this->actingAs($archivist)->post("/admin/publications/{$asset->id}/revoke", ['revoked_reason' => 'Klacht ontvangen'])->assertRedirect();
    expect($publication->fresh()->status)->toBe('revoked');
    expect(Publication::query()->publiclyVisible()->where('asset_id', $asset->id)->exists())->toBeFalse();
});

it('hides a published photo again as soon as its metadata is edited, without a separate takedown step', function (): void {
    $editor = publicationUser('editor');
    $asset = publishableAsset($editor);
    Publication::query()->create(['asset_id' => $asset->id, 'status' => 'published', 'privacy_cleared' => true, 'published_lock_version' => 1, 'permalink_slug' => 'edit-test']);
    expect(Publication::query()->publiclyVisible()->where('asset_id', $asset->id)->exists())->toBeTrue();

    $this->actingAs($editor)->put("/admin/assets/{$asset->id}", [
        'lock_version' => 1, 'title' => 'Gewijzigde titel', 'date_precision' => 'unknown', 'rights_status' => 'verified',
    ])->assertRedirect();

    expect(Publication::query()->publiclyVisible()->where('asset_id', $asset->id)->exists())->toBeFalse();
    expect(Asset::find($asset->id)->publication->needsReReview())->toBeTrue();
});

it('denies a volunteer without assets.publish from approving a publication', function (): void {
    $volunteer = publicationUser('volunteer');
    $asset = publishableAsset($volunteer);
    $this->actingAs($volunteer)->post("/admin/publications/{$asset->id}/submit", ['privacy_cleared' => '1', 'download_policy' => 'preview_only'])->assertRedirect();
    $this->actingAs($volunteer)->post("/admin/publications/{$asset->id}/publish")->assertForbidden();
});
