<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Tag;
use App\Modules\Catalogue\Models\TagSynonym;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function runTagSlugMigration(): void
{
    $migration = require database_path('migrations/2026_10_23_000000_normalize_tag_slugs.php');
    $migration->up();
}

it('gives bulk-created tags a readable slug and keeps their photos', function (): void {
    $user = User::query()->create(['name' => 'Slug', 'email' => 'slug@example.test', 'password' => 'password123']);
    $tag = Tag::query()->create(['name' => 'Molen', 'slug' => hash('sha256', 'Molen')]);
    $asset = Asset::query()->create(['accession_number' => 'FA-SLG-01', 'title' => 'Molen', 'created_by_user_id' => $user->id]);
    $asset->tags()->attach($tag->id, ['id' => (string) Str::ulid()]);

    runTagSlugMigration();

    expect($tag->fresh()->slug)->toBe('molen')
        ->and($asset->tags()->pluck('tags.id')->all())->toBe([$tag->id]);
});

it('merges a hash-slug duplicate into the tag that already has the readable slug', function (): void {
    $user = User::query()->create(['name' => 'Slug', 'email' => 'slug2@example.test', 'password' => 'password123']);
    $survivor = Tag::query()->create(['name' => 'kerk', 'slug' => 'kerk']);
    $duplicate = Tag::query()->create(['name' => 'Kerk', 'slug' => hash('sha256', 'Kerk')]);
    $duplicate->synonyms()->create(['name' => 'Godshuis', 'slug' => 'godshuis']);
    $both = Asset::query()->create(['accession_number' => 'FA-SLG-02', 'title' => 'Beide', 'created_by_user_id' => $user->id]);
    $only = Asset::query()->create(['accession_number' => 'FA-SLG-03', 'title' => 'Alleen', 'created_by_user_id' => $user->id]);
    $both->tags()->attach($survivor->id, ['id' => (string) Str::ulid()]);
    $both->tags()->attach($duplicate->id, ['id' => (string) Str::ulid()]);
    $only->tags()->attach($duplicate->id, ['id' => (string) Str::ulid()]);

    runTagSlugMigration();

    expect(Tag::query()->find($duplicate->id))->toBeNull()
        ->and($survivor->assets()->pluck('assets.id')->sort()->values()->all())->toBe(collect([$both->id, $only->id])->sort()->values()->all())
        ->and(TagSynonym::query()->where('tag_id', $survivor->id)->pluck('name')->sort()->values()->all())->toBe(['Godshuis', 'Kerk']);
});

it('is idempotent and leaves canonical tags alone', function (): void {
    $tag = Tag::query()->create(['name' => 'Haven', 'slug' => hash('sha256', 'Haven')]);
    Tag::query()->create(['name' => 'Brug', 'slug' => 'brug']);

    runTagSlugMigration();
    runTagSlugMigration();

    expect(Tag::query()->orderBy('name')->pluck('slug')->all())->toBe(['brug', 'haven'])
        ->and($tag->fresh()->slug)->toBe('haven');
});

it('gives two hash-slug spellings of one word a single surviving tag', function (): void {
    Tag::query()->create(['name' => 'Brug', 'slug' => hash('sha256', 'Brug')]);
    Tag::query()->create(['name' => 'brug', 'slug' => hash('sha256', 'brug')]);

    runTagSlugMigration();

    expect(Tag::query()->where('slug', 'brug')->count())->toBe(1)
        ->and(Tag::query()->count())->toBe(1);
});
