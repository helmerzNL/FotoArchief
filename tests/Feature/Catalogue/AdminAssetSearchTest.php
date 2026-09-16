<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Collection;
use App\Modules\Catalogue\Models\Location;
use App\Modules\Catalogue\Models\Person;
use App\Modules\Catalogue\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createSearchTestUser(string $roleKey, array $permissions): User
{
    $role = Role::query()->firstOrCreate(['key' => $roleKey], ['name' => ucfirst($roleKey)]);
    $permissionModels = collect($permissions)->map(function ($key) {
        return Permission::query()->firstOrCreate(['key' => $key], ['name' => $key]);
    });
    $role->permissions()->sync($permissionModels->pluck('id'));

    $user = User::query()->create([
        'name' => 'User '.$roleKey,
        'email' => $roleKey.'@example.test',
        'password' => 'password123',
    ]);
    $user->roles()->attach($role);

    return $user;
}

it('filters assets by date range, person, location, collection, tag and rights status', function (): void {
    $admin = createSearchTestUser('admin', ['assets.view', 'assets.publish']);

    $person = Person::query()->create(['display_name' => 'Karel de Grote', 'sort_name' => 'Karel']);
    $location = Location::query()->create(['name' => 'Stadhuis Breda', 'normalized_name' => 'stadhuis breda', 'location_type' => 'building']);
    $collection = Collection::query()->create(['title' => 'Collectie Oude Stad', 'slug' => 'oude-stad', 'collection_type' => 'collection']);
    $tag = Tag::query()->create(['name' => 'monument', 'slug' => 'monument']);

    // Asset 1: matching all filters
    $asset1 = Asset::query()->create([
        'accession_number' => 'FA-SEARCH-01',
        'title' => 'Stadhuis toren 1925',
        'date_earliest' => '1925-01-01',
        'date_latest' => '1925-12-31',
        'date_precision' => 'year',
        'created_by_user_id' => $admin->id,
    ]);
    $asset1->people()->attach($person->id, ['id' => (string) Str::ulid(), 'relationship_type' => 'depicted']);
    $asset1->locations()->attach($location->id, ['id' => (string) Str::ulid(), 'relationship_type' => 'depicted_place']);
    $asset1->collections()->attach($collection->id, ['id' => (string) Str::ulid(), 'position' => 1]);
    $asset1->tags()->attach($tag->id, ['id' => (string) Str::ulid()]);
    $asset1->rights()->create(['verification_status' => 'verified', 'rights_holder' => 'Gemeente']);

    // Asset 2: other date and tags
    $asset2 = Asset::query()->create([
        'accession_number' => 'FA-SEARCH-02',
        'title' => 'Modern gebouw 1990',
        'date_earliest' => '1990-06-01',
        'date_latest' => '1990-06-01',
        'date_precision' => 'exact',
        'created_by_user_id' => $admin->id,
    ]);
    $asset2->rights()->create(['verification_status' => 'unverified']);

    // 1. Search by date range
    $this->actingAs($admin)
        ->get(route('admin.assets.index', ['date_from' => '1920-01-01', 'date_to' => '1930-12-31']))
        ->assertOk()
        ->assertSee('FA-SEARCH-01')
        ->assertDontSee('FA-SEARCH-02');

    // 2. Search by person
    $this->actingAs($admin)
        ->get(route('admin.assets.index', ['person_id' => $person->id]))
        ->assertOk()
        ->assertSee('FA-SEARCH-01')
        ->assertDontSee('FA-SEARCH-02');

    // 3. Search by location
    $this->actingAs($admin)
        ->get(route('admin.assets.index', ['location_id' => $location->id]))
        ->assertOk()
        ->assertSee('FA-SEARCH-01')
        ->assertDontSee('FA-SEARCH-02');

    // 4. Search by collection
    $this->actingAs($admin)
        ->get(route('admin.assets.index', ['collection_id' => $collection->id]))
        ->assertOk()
        ->assertSee('FA-SEARCH-01')
        ->assertDontSee('FA-SEARCH-02');

    // 5. Search by tag
    $this->actingAs($admin)
        ->get(route('admin.assets.index', ['tag_id' => $tag->id]))
        ->assertOk()
        ->assertSee('FA-SEARCH-01')
        ->assertDontSee('FA-SEARCH-02');

    // 6. Search by rights status
    $this->actingAs($admin)
        ->get(route('admin.assets.index', ['rights_status' => 'verified']))
        ->assertOk()
        ->assertSee('FA-SEARCH-01')
        ->assertDontSee('FA-SEARCH-02');
});

it('supports keyset pagination preserving filters and enforces ownership isolation', function (): void {
    $publisher = createSearchTestUser('publisher', ['assets.view', 'assets.publish']);
    $volunteer = createSearchTestUser('volunteer', ['assets.view']);

    // Create 30 assets for volunteer
    $volunteerAssets = [];
    for ($i = 1; $i <= 30; $i++) {
        $volunteerAssets[] = Asset::query()->create([
            'accession_number' => sprintf('FA-VOL-%03d', $i),
            'title' => 'Vrijwilliger Foto '.$i,
            'created_by_user_id' => $volunteer->id,
            'catalogue_status' => 'draft',
        ]);
    }

    // Create 5 assets for publisher
    for ($i = 1; $i <= 5; $i++) {
        Asset::query()->create([
            'accession_number' => sprintf('FA-PUB-%03d', $i),
            'title' => 'Publieke Foto '.$i,
            'created_by_user_id' => $publisher->id,
            'catalogue_status' => 'draft',
        ]);
    }

    // Volunteer sees only 25 items on first page
    $response = $this->actingAs($volunteer)->get(route('admin.assets.index', ['catalogue_status' => 'draft']));
    $response->assertOk();
    $response->assertSee('Volgende pagina');
    // Volunteer cannot see publisher's assets
    $response->assertDontSee('FA-PUB-001');

    // Get cursor from first page response
    $cursor = $response->viewData('nextCursor');
    expect($cursor)->not->toBeNull();

    // Fetch next page using cursor
    $page2 = $this->actingAs($volunteer)->get(route('admin.assets.index', [
        'catalogue_status' => 'draft',
        'cursor' => $cursor,
    ]));
    $page2->assertOk();
    $page2->assertSee('Vrijwilliger Foto');
    $page2->assertDontSee('FA-PUB-001');

    // Publisher can see assets of both users
    $pubResponse = $this->actingAs($publisher)->get(route('admin.assets.index', ['catalogue_status' => 'draft']));
    $pubResponse->assertOk();
    $pubResponse->assertSee('FA-PUB-');
});
