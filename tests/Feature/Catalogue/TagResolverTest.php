<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Tag;
use App\Modules\Catalogue\Models\TagSynonym;
use App\Modules\Catalogue\Services\TagMergeService;
use App\Modules\Catalogue\Services\TagResolver;
use App\Modules\Catalogue\Support\TagSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('gives every spelling of a word the same readable slug', function (): void {
    expect(TagSlug::for('Kerk'))->toBe('kerk')
        ->and(TagSlug::for('  kerk '))->toBe('kerk')
        ->and(TagSlug::for('Café Centraal'))->toBe('cafe-centraal');
});

it('falls back to a stable hash slug for a name without letters or digits', function (): void {
    $slug = TagSlug::for('!!!');

    expect($slug)->toStartWith('t-')
        ->and($slug)->toBe(TagSlug::for('!!!'))
        ->and($slug)->not->toBe(TagSlug::for('???'));
});

it('finds a tag by slug, by synonym and by name that differs only in case', function (): void {
    $tag = Tag::query()->create(['name' => 'Kerk', 'slug' => 'kerk']);
    $tag->synonyms()->create(['name' => 'Godshuis', 'slug' => 'godshuis']);
    $resolver = app(TagResolver::class);

    expect($resolver->find('kerk')?->id)->toBe($tag->id)
        ->and($resolver->find('  KERK ')?->id)->toBe($tag->id)
        ->and($resolver->find('godshuis')?->id)->toBe($tag->id)
        ->and($resolver->find('molen'))->toBeNull()
        ->and($resolver->find('   '))->toBeNull();
});

it('creates a missing tag with the readable slug and reuses it afterwards', function (): void {
    $resolver = app(TagResolver::class);

    $created = $resolver->resolve('  Dorps   plein ');
    $again = $resolver->resolve('dorps plein');

    expect($created->name)->toBe('Dorps plein')
        ->and($created->slug)->toBe('dorps-plein')
        ->and($again->id)->toBe($created->id)
        ->and(Tag::query()->where('slug', 'dorps-plein')->count())->toBe(1);
});

it('merges a tag into another without losing links or names', function (): void {
    $user = User::query()->create(['name' => 'Merger', 'email' => 'merger@example.test', 'password' => 'password123']);
    $source = Tag::query()->create(['name' => 'Automobiel', 'slug' => 'automobiel']);
    $source->synonyms()->create(['name' => 'Wagen', 'slug' => 'wagen']);
    $target = Tag::query()->create(['name' => 'Auto', 'slug' => 'auto']);
    $first = Asset::query()->create(['accession_number' => 'FA-MRG-01', 'title' => 'Een', 'created_by_user_id' => $user->id]);
    $second = Asset::query()->create(['accession_number' => 'FA-MRG-02', 'title' => 'Twee', 'created_by_user_id' => $user->id]);
    $first->tags()->attach($source->id, ['id' => (string) Str::ulid()]);
    $second->tags()->attach($source->id, ['id' => (string) Str::ulid()]);
    $second->tags()->attach($target->id, ['id' => (string) Str::ulid()]);

    app(TagMergeService::class)->merge($source, $target);

    expect(Tag::query()->find($source->id))->toBeNull()
        ->and($target->assets()->pluck('assets.id')->sort()->values()->all())->toBe(collect([$first->id, $second->id])->sort()->values()->all())
        ->and(TagSynonym::query()->where('tag_id', $target->id)->pluck('name')->sort()->values()->all())->toBe(['Automobiel', 'Wagen']);
});

it('does not create a colliding synonym when two spellings of one word are merged', function (): void {
    $target = Tag::query()->create(['name' => 'kerk', 'slug' => 'kerk']);
    $first = Tag::query()->create(['name' => 'Kerk', 'slug' => 'kerk-a']);
    $second = Tag::query()->create(['name' => 'KERK', 'slug' => 'kerk-b']);
    $merger = app(TagMergeService::class);

    $merger->merge($first, $target);
    $merger->merge($second, $target);

    expect(Tag::query()->whereIn('id', [$first->id, $second->id])->count())->toBe(0)
        ->and(TagSynonym::query()->where('tag_id', $target->id)->pluck('name')->all())->toBe(['Kerk']);
});
