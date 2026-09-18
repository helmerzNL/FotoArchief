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
use App\Modules\Catalogue\Models\Tag;
use App\Modules\Catalogue\Models\Worklist;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('executes complete HTTP browser flow for catalogue CRUD, bulk operations, and worklist progress', function (): void {
    // 1. Setup administrator role and user with full permissions
    $adminRole = Role::query()->firstOrCreate(['key' => 'admin'], ['name' => 'Administrator']);
    $permissions = ['assets.view', 'assets.create', 'assets.update', 'assets.publish'];
    $permModels = collect($permissions)->map(fn ($k) => Permission::query()->firstOrCreate(['key' => $k], ['name' => $k]));
    $adminRole->permissions()->sync($permModels->pluck('id'));

    $user = User::query()->create([
        'name' => 'Archief Beheerder',
        'email' => 'beheerder@fotoarchief.test',
        'password' => 'geheim123',
    ]);
    $user->roles()->attach($adminRole);

    // 2. HTTP visit to Catalogue Dashboard
    $this->actingAs($user)
        ->get(route('catalogue.index'))
        ->assertOk()
        ->assertSee('Catalogus')
        ->assertSee('Collecties &amp; Albums', false)
        ->assertSee('Personen &amp; Organisaties', false)
        ->assertSee('Locaties')
        ->assertSee('Bronnen &amp; Herkomst', false)
        ->assertSee('Tags &amp; Trefwoorden', false)
        ->assertSee('Werklijsten &amp; Curatie', false);

    // 3. Collection CRUD Flow
    $colRes = $this->actingAs($user)->post(route('catalogue.collections.store'), [
        'title' => 'Markt & Omgeving 1920',
        'collection_type' => 'album',
        'description' => 'Historische fotoalbums van de centrummarkt',
    ]);
    $colRes->assertRedirect();
    $collection = Collection::query()->where('title', 'Markt & Omgeving 1920')->firstOrFail();

    $this->actingAs($user)
        ->get(route('catalogue.collections.show', $collection))
        ->assertOk()
        ->assertSee('Markt &amp; Omgeving 1920', false);

    // 4. Person & Location CRUD Flow
    $personRes = $this->actingAs($user)->post(route('catalogue.people.store'), [
        'display_name' => 'Jan de Bakker',
        'sort_name' => 'Bakker, Jan de',
        'entity_type' => 'person',
        'birth_date_precision' => 'unknown',
        'death_date_precision' => 'unknown',
        'biographical_note' => 'Lokale bakker aan de Markt',
        'aliases' => 'Bakker Jan, J. de Bakker',
    ]);
    $personRes->assertRedirect();
    $person = Person::query()->where('display_name', 'Jan de Bakker')->firstOrFail();

    $locRes = $this->actingAs($user)->post(route('catalogue.locations.store'), [
        'name' => 'Grote Markt Breda',
        'location_type' => 'place',
        'historical_period' => '1900-1940',
        'aliases' => 'Markt, Forum',
    ]);
    $locRes->assertRedirect();
    $location = Location::query()->where('name', 'Grote Markt Breda')->firstOrFail();

    // 5. Provenance (Source & Contributor) CRUD Flow
    $sourceRes = $this->actingAs($user)->post(route('catalogue.sources.store'), [
        'name' => 'Stadsarchief Breda Fonds A',
        'source_type' => 'archive',
        'reference_code' => 'SAB-FA-1920',
        'acquisition_date' => '1985-04-12',
        'custody_history' => 'Overgedragen door gemeentesecretarie in 1985.',
    ]);
    $sourceRes->assertRedirect();
    $source = Source::query()->where('name', 'Stadsarchief Breda Fonds A')->firstOrFail();

    $contribRes = $this->actingAs($user)->post(route('catalogue.contributors.store'), [
        'name' => 'Familie Van Houten',
        'contributor_type' => 'donor',
        'email' => 'contact@vanhouten.test',
        'contact_details' => 'Schenking collectie glasplaten 1992',
    ]);
    $contribRes->assertRedirect();
    $contributor = Contributor::query()->where('name', 'Familie Van Houten')->firstOrFail();

    // 6. Create Assets for Associations and Search
    $asset1 = Asset::query()->create([
        'accession_number' => 'FA-BROWSER-01',
        'title' => 'Bakkerij op de Grote Markt',
        'date_earliest' => '1925-05-01',
        'date_latest' => '1925-05-01',
        'date_precision' => 'exact',
        'date_display' => '1 mei 1925',
        'created_by_user_id' => $user->id,
        'lock_version' => 1,
    ]);
    $asset1->rights()->create(['verification_status' => 'unverified']);

    $asset2 = Asset::query()->create([
        'accession_number' => 'FA-BROWSER-02',
        'title' => 'Standbeeld Marktplein',
        'date_precision' => 'unknown',
        'created_by_user_id' => $user->id,
        'lock_version' => 1,
    ]);

    // Attach entities to asset 1
    $this->actingAs($user)->post(route('catalogue.collections.assets.add', $collection), [
        'asset_id' => $asset1->id,
        'position' => 1,
    ])->assertRedirect();

    $this->actingAs($user)->post(route('catalogue.people.assets.add', $person), [
        'asset_id' => $asset1->id,
        'relationship_type' => 'depicted',
        'confidence' => '0.95',
        'verification_status' => 'verified',
    ])->assertRedirect();

    $this->actingAs($user)->post(route('catalogue.locations.assets.add', $location), [
        'asset_id' => $asset1->id,
        'relationship_type' => 'depicted_place',
        'confidence' => '1.0',
        'verification_status' => 'verified',
    ])->assertRedirect();

    $this->actingAs($user)->post(route('catalogue.sources.assets.add', $source), [
        'asset_id' => $asset1->id,
        'relationship_type' => 'provenance',
        'verification_status' => 'verified',
    ])->assertRedirect();

    $this->actingAs($user)->post(route('catalogue.contributors.assets.add', $contributor), [
        'asset_id' => $asset1->id,
        'relationship_type' => 'donor',
        'verification_status' => 'verified',
    ])->assertRedirect();

    // 7. Advanced Filtered Search UI
    $this->actingAs($user)
        ->get(route('admin.assets.index', [
            'collection_id' => $collection->id,
            'person_id' => $person->id,
            'location_id' => $location->id,
        ]))
        ->assertOk()
        ->assertSee('FA-BROWSER-01')
        ->assertDontSee('FA-BROWSER-02');

    // 8. Bulk Selection & Confirmation Flow
    $this->actingAs($user)
        ->get(route('catalogue.bulk.confirm', [
            'asset_ids' => [$asset1->id, $asset2->id],
        ]))
        ->assertOk()
        ->assertSee('Batch-bewerking foto’s (2 geselecteerd)')
        ->assertSee('FA-BROWSER-01')
        ->assertSee('FA-BROWSER-02');

    $preview = $this->actingAs($user)
        ->post(route('catalogue.bulk.preview'), [
            'asset_ids' => [$asset1->id, $asset2->id],
            'lock_versions' => [
                $asset1->id => 1,
                $asset2->id => 1,
            ],
            'tags_to_add' => 'centrum, erfgoed',
            'update_rights' => 1,
            'rights_status' => 'verified',
            'rights_holder' => 'Stadsarchief',
            'update_status' => 1,
            'catalogue_status' => 'catalogued',
        ])
        ->assertOk();
    $this->post(route('catalogue.bulk.apply'), ['receipt' => $preview->viewData('receipt'), 'confirm' => 1])->assertOk();

    $asset1->refresh();
    $asset2->refresh();
    expect($asset1->lock_version)->toBe(2);
    expect($asset2->lock_version)->toBe(2);
    expect($asset1->tags->pluck('name')->all())->toContain('centrum', 'erfgoed');

    // 9. Tag Synonym & Merge Flow
    $tagRes = $this->actingAs($user)->post(route('catalogue.tags.store'), [
        'name' => 'Bakkerswinkel',
        'synonyms' => 'Bakkerij, Broodzaak',
    ]);
    $tagRes->assertRedirect();
    $tag = Tag::query()->where('name', 'Bakkerswinkel')->firstOrFail();

    $this->actingAs($user)
        ->get(route('catalogue.tags.index', ['q' => 'Broodzaak']))
        ->assertOk()
        ->assertSee('Bakkerswinkel')
        ->assertSee('Broodzaak');

    $targetTag = Tag::query()->where('name', 'centrum')->firstOrFail();
    $this->actingAs($user)
        ->post(route('catalogue.tags.merge', $tag), [
            'target_tag_id' => $targetTag->id,
        ])
        ->assertRedirect(route('catalogue.tags.show', $targetTag));

    expect(Tag::query()->find($tag->id))->toBeNull();
    expect($targetTag->synonyms->pluck('name')->all())->toContain('Bakkerswinkel', 'Bakkerij', 'Broodzaak');

    // 10. Worklist Dynamic Creation, Assignment, and Progress Flow
    $this->actingAs($user)
        ->get(route('catalogue.worklists.index'))
        ->assertOk()
        ->assertSee('Slimme curatiewachtrijen');

    $wlRes = $this->actingAs($user)->post(route('catalogue.worklists.store'), [
        'title' => 'Dateringen invullen Q3',
        'worklist_type' => 'missing_date',
        'assigned_to_user_id' => $user->id,
        'description' => 'Controleer datum van ongeregistreerde foto’s',
    ]);
    $wlRes->assertRedirect();
    $worklist = Worklist::query()->where('title', 'Dateringen invullen Q3')->firstOrFail();

    // Verify item was created for asset2 (which has date_precision=unknown)
    expect($worklist->items)->toHaveCount(1);
    $item = $worklist->items->first();
    expect($item->asset_id)->toBe($asset2->id);

    // Show worklist
    $this->actingAs($user)
        ->get(route('catalogue.worklists.show', $worklist))
        ->assertOk()
        ->assertSee('Dateringen invullen Q3')
        ->assertSee('FA-BROWSER-02')
        ->assertSee('0% (0 van 1 afgerond)', false);

    // Update item progress to completed
    $this->actingAs($user)
        ->post(route('catalogue.worklists.items.update', [$worklist, $item]), [
            'status' => 'completed',
            'note' => 'Gedateerd via historisch bevolkingsregister 1928',
        ])
        ->assertRedirect();

    $worklist->refresh();
    $item->refresh();
    expect($item->status)->toBe('completed');
    expect($item->note)->toBe('Gedateerd via historisch bevolkingsregister 1928');
    expect($worklist->progressPercentage())->toBe(100);
    expect($worklist->status)->toBe('completed');
});
