<?php

declare(strict_types=1);

use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\AssetRight;
use App\Modules\Catalogue\Models\AssetVersion;
use App\Modules\Catalogue\Models\Collection;
use App\Modules\Catalogue\Models\Contributor;
use App\Modules\Catalogue\Models\License;
use App\Modules\Catalogue\Models\Location;
use App\Modules\Catalogue\Models\LocationAlias;
use App\Modules\Catalogue\Models\Person;
use App\Modules\Catalogue\Models\PersonAlias;
use App\Modules\Catalogue\Models\RightsStatement;
use App\Modules\Catalogue\Models\Source;
use App\Modules\Catalogue\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates relational catalogue tables without JSON relationship columns', function (): void {
    expect(Schema::hasColumns('assets', [
        'id',
        'accession_number',
        'date_earliest',
        'date_latest',
        'date_precision',
        'date_display',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('people', ['birth_date_earliest', 'birth_date_latest', 'birth_date_precision']))->toBeTrue()
        ->and(Schema::hasColumns('asset_files', ['asset_id', 'storage_key', 'sha256', 'byte_size']))->toBeTrue()
        ->and(Schema::hasColumns('asset_people', ['asset_id', 'person_id', 'confidence', 'verification_status']))->toBeTrue()
        ->and(Schema::hasColumns('asset_locations', ['asset_id', 'location_id', 'confidence', 'verification_status']))->toBeTrue();

    foreach (['asset_people', 'asset_locations', 'asset_tags', 'collection_assets', 'asset_sources', 'asset_contributors', 'asset_rights'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('persists catalogue relationships and queryable uncertainty', function (): void {
    $asset = Asset::query()->create([
        'accession_number' => 'FA-000001',
        'title' => 'Market square',
        'date_earliest' => '1910-01-01',
        'date_latest' => '1919-12-31',
        'date_precision' => 'decade',
        'date_display' => 'ca. 1910s',
    ]);
    $file = AssetFile::query()->create([
        'asset_id' => $asset->id,
        'storage_key' => 'originals/sha256/ab/cd/market-square.tif',
        'sha256' => str_repeat('a', 64),
        'media_type' => 'image/tiff',
        'byte_size' => 1_024,
    ]);
    $version = AssetVersion::query()->create([
        'asset_id' => $asset->id,
        'asset_file_id' => $file->id,
        'version_number' => 1,
        'change_type' => 'original',
    ]);
    $person = Person::query()->create(['display_name' => 'Ada Archivist']);
    $location = Location::query()->create(['name' => 'Market Square', 'normalized_name' => 'market-square']);
    $tag = Tag::query()->create(['name' => 'Market', 'slug' => 'market']);
    $collection = Collection::query()->create(['title' => 'Town views', 'slug' => 'town-views', 'collection_type' => 'album']);
    $source = Source::query()->create(['name' => 'Municipal archive']);
    $contributor = Contributor::query()->create(['name' => 'A. Donor']);
    $license = License::query()->create(['code' => 'CC-BY-4.0', 'name' => 'CC BY 4.0']);
    $statement = RightsStatement::query()->create(['code' => 'IN-C', 'name' => 'In Copyright']);

    PersonAlias::query()->create(['person_id' => $person->id, 'name' => 'A. Archivist', 'normalized_name' => 'a-archivist']);
    LocationAlias::query()->create(['location_id' => $location->id, 'name' => 'De Markt', 'normalized_name' => 'de-markt']);
    $asset->people()->attach($person->id, ['relationship_type' => 'depicted', 'confidence' => 0.7500, 'verification_status' => 'proposed']);
    $asset->locations()->attach($location->id, ['relationship_type' => 'depicted_at', 'confidence' => 0.9000, 'verification_status' => 'verified']);
    $asset->tags()->attach($tag->id);
    $asset->collections()->attach($collection->id, ['position' => 1]);
    $asset->sources()->attach($source->id, ['relationship_type' => 'provenance', 'confidence' => 1, 'verification_status' => 'verified']);
    $asset->contributors()->attach($contributor->id, ['relationship_type' => 'donor', 'confidence' => 1, 'verification_status' => 'verified']);
    AssetRight::query()->create([
        'asset_id' => $asset->id,
        'license_id' => $license->id,
        'rights_statement_id' => $statement->id,
        'verification_status' => 'verified',
    ]);

    expect($asset->id)->toHaveLength(26)
        ->and($version->file->is($file))->toBeTrue()
        ->and($asset->people()->first()?->pivot->confidence)->toEqual('0.7500')
        ->and($asset->locations()->first()?->pivot->verification_status)->toBe('verified')
        ->and($asset->tags)->toHaveCount(1)
        ->and($asset->collections)->toHaveCount(1)
        ->and($asset->collections->first()?->collection_type)->toBe('album')
        ->and($asset->sources)->toHaveCount(1)
        ->and($asset->contributors)->toHaveCount(1)
        ->and($asset->rights)->toHaveCount(1)
        ->and($person->aliases)->toHaveCount(1)
        ->and($location->aliases)->toHaveCount(1);
});

it('does not allow an asset file storage identifier to be changed', function (): void {
    $asset = Asset::query()->create(['accession_number' => 'FA-000002']);
    $file = AssetFile::query()->create([
        'asset_id' => $asset->id,
        'storage_key' => 'originals/sha256/ef/01/immutable.tif',
        'sha256' => str_repeat('b', 64),
        'media_type' => 'image/tiff',
        'byte_size' => 2_048,
    ]);

    $file->storage_key = 'originals/sha256/ef/01/replaced.tif';
    $file->save();
})->throws(LogicException::class);
