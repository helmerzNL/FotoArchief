<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Collection;
use App\Modules\Catalogue\Models\Contributor;
use App\Modules\Catalogue\Models\Location;
use App\Modules\Catalogue\Models\Person;
use App\Modules\Catalogue\Models\Source;
use App\Modules\Catalogue\Services\AssetReference;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves the visible photo reference field without bypassing ownership', function (string $storedCase, string $inputKind, string $surface): void {
    $user = User::query()->create(['name' => 'Reference editor', 'email' => 'reference@example.test', 'password' => 'test-password']);
    $role = Role::query()->create(['key' => 'reference-editor', 'name' => 'Reference editor']);
    foreach (['assets.view', 'assets.update', 'collections.manage'] as $key) {
        $role->permissions()->attach(Permission::query()->create(['key' => $key, 'name' => $key]));
    }
    $user->roles()->attach($role);
    $collection = match ($surface) {
        'people' => Person::query()->create(['display_name' => 'Reference person']),
        'locations' => Location::query()->create(['name' => 'Reference place', 'normalized_name' => 'reference place']),
        'sources' => Source::query()->create(['name' => 'Reference source']),
        'contributors' => Contributor::query()->create(['name' => 'Reference donor']),
        default => Collection::query()->create(['title' => 'Reference collection', 'slug' => 'reference-collection']),
    };
    $association = ['relationship_type' => 'other', 'verification_status' => 'unverified'];
    $id = '01M2KR9DVV8XP9QW8YN81SVT66';
    $asset = Asset::query()->create([
        'id' => $storedCase === 'lower' ? strtolower($id) : $id,
        'accession_number' => 'FA-Reference-001',
        'created_by_user_id' => $user->id,
    ]);
    $reference = match ($inputKind) {
        'upper' => $id,
        'lower', 'explicit' => strtolower($id),
        default => $asset->accession_number,
    };
    $field = $inputKind === 'explicit' ? 'asset_id' : 'accession_number';
    $url = route('catalogue.'.$surface.'.assets.add', $collection);
    $this->actingAs($user)->post($url, [$field => ' '.$reference.' '] + $association)
        ->assertRedirect(route('catalogue.'.$surface.'.show', $collection))
        ->assertSessionHasNoErrors();
    expect($collection->assets()->sole()->id)->toBe($asset->id);

    $collection->assets()->detach();
    $asset->update(['created_by_user_id' => null]);
    $this->post($url, [$field => $reference] + $association)->assertForbidden();
    expect($collection->assets()->count())->toBe(0);

    $this->post($url, ['accession_number' => '01M2KR9DVV8XP9QW8YN81SVT67'] + $association)
        ->assertSessionHasErrors('asset_id');
    expect($collection->assets()->count())->toBe(0);
})->with(['upper', 'lower'])->with(['upper', 'lower', 'accession', 'explicit'])
    ->with(['collections', 'people', 'locations', 'sources', 'contributors']);

it('preserves exact accession and explicit ID precedence without fuzzy matches', function (): void {
    $idAsset = Asset::query()->create(['id' => '01M2KR9DVV8XP9QW8YN81SVT66', 'accession_number' => 'FA-Exact']);
    $accessionAsset = Asset::query()->create(['accession_number' => $idAsset->id]);

    expect(AssetReference::resolve(null, $idAsset->id)?->id)->toBe($accessionAsset->id)
        ->and(AssetReference::resolve($idAsset->id, $idAsset->id)?->id)->toBe($idAsset->id)
        ->and(AssetReference::resolve('missing', 'FA-Exact'))->toBeNull()
        ->and(AssetReference::resolve(null, 'FA-Ex'))->toBeNull()
        ->and(AssetReference::resolve(null, ''))->toBeNull();
});
