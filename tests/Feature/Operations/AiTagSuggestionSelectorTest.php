<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Ai\Services\AiTagSuggestionSelector;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('ai.tag_suggestions.max_per_image', 3);
    config()->set('ai.tag_suggestions.min_confidence', 0.5);
    $user = User::query()->create(['name' => 'Selector', 'email' => 'selector@example.test', 'password' => 'password123']);
    $this->asset = Asset::query()->create(['accession_number' => 'FA-SEL-01', 'title' => 'Foto', 'created_by_user_id' => $user->id]);
    $this->selector = app(AiTagSuggestionSelector::class);
});

it('cleans, de-duplicates and orders tags by confidence', function (): void {
    $result = $this->selector->select($this->asset, [
        'tags' => ['  Dorpsplein ', 'dorpsplein', 'Fiets', 'Kerk.', '!!!'],
        'tag_confidences' => ['dorpsplein' => 0.9, 'fiets' => 0.7, 'kerk.' => 0.95],
    ]);

    expect($result)->toBe([
        ['value' => 'kerk', 'confidence' => 0.95],
        ['value' => 'dorpsplein', 'confidence' => 0.9],
        ['value' => 'fiets', 'confidence' => 0.7],
    ]);
});

it('drops tags below the minimum confidence but keeps tags without a reported confidence', function (): void {
    $result = $this->selector->select($this->asset, [
        'tags' => ['onzeker', 'zeker', 'zonder'],
        'tag_confidences' => ['onzeker' => 0.2, 'zeker' => 0.8],
    ]);

    expect(array_column($result, 'value'))->toBe(['zeker', 'zonder'])
        ->and($result[1]['confidence'])->toBeNull();
});

it('limits the number of suggestions per photo', function (): void {
    $result = $this->selector->select($this->asset, ['tags' => ['een', 'twee', 'drie', 'vier', 'vijf']]);

    expect(array_column($result, 'value'))->toBe(['een', 'twee', 'drie']);
});

it('skips tags the photo already has, also through a synonym', function (): void {
    $tag = Tag::query()->create(['name' => 'Kerk', 'slug' => 'kerk']);
    $tag->synonyms()->create(['name' => 'Godshuis', 'slug' => 'godshuis']);
    $this->asset->tags()->attach($tag->id, ['id' => (string) Str::ulid()]);

    $result = $this->selector->select($this->asset, ['tags' => ['kerk', 'godshuis', 'molen']]);

    expect(array_column($result, 'value'))->toBe(['molen']);
});

it('ignores a malformed analysis', function (): void {
    expect($this->selector->select($this->asset, ['tags' => 'geen lijst']))->toBe([])
        ->and($this->selector->select($this->asset, []))->toBe([]);
});
