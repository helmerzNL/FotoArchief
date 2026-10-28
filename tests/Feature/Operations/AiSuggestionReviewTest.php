<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Models\AiSuggestion;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\Collection;
use App\Modules\Catalogue\Models\Tag;
use App\Modules\Ingest\Models\AssetAuditEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->user = User::query()->create([
        'name' => 'AI Reviewer',
        'email' => 'ai-reviewer@example.test',
        'password' => Hash::make('secret12345'),
    ]);
    $this->user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());

    $this->asset = Asset::query()->create([
        'accession_number' => 'AI-REVIEW-'.(string) str()->ulid(),
        'title' => 'Review contract',
        'created_by_user_id' => $this->user->id,
    ])->fresh();
    $this->file = AssetFile::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/review.jpg',
        'sha256' => str_repeat('b', 64),
        'media_type' => 'image/jpeg',
        'byte_size' => 100,
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
        'is_primary' => true,
    ]);
    $this->run = AiRun::query()->create([
        'run_type' => AiRun::TYPE_IMAGE_ANALYSIS,
        'status' => AiRun::STATUS_SUCCEEDED,
        'asset_id' => $this->asset->id,
        'asset_file_id' => $this->file->id,
        'source_asset_lock_version' => $this->asset->lock_version,
        'source_file_sha256' => $this->file->sha256,
        'provider_kind' => 'local',
        'provider_name' => 'owned-http',
        'model_id' => 'review-proof',
        'idempotency_key' => 'review-'.$this->file->id,
        'input_contract' => ['metadata_stripped' => true],
    ]);
});

function aiSuggestion(string $type, string $value): AiSuggestion
{
    return AiSuggestion::query()->create([
        'ai_run_id' => test()->run->id,
        'asset_id' => test()->asset->id,
        'asset_file_id' => test()->file->id,
        'source_asset_lock_version' => test()->asset->lock_version,
        'source_file_sha256' => test()->file->sha256,
        'suggestion_type' => $type,
        'value' => $value,
    ]);
}

it('filters the review work queue without hiding the original proposal or current metadata', function (): void {
    $this->asset->update(['description' => 'Current human description']);
    $suggestion = aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, 'Original proposal');
    $collection = Collection::query()->create(['title' => 'Review collection', 'slug' => 'review-collection']);
    $this->asset->collections()->attach($collection->id, ['id' => (string) str()->ulid()]);
    $this->actingAs($this->user)->get(route('admin.operations.ai.suggestions.index', [
        'collection' => $collection->id, 'provider' => 'owned-http', 'from' => now()->toDateString(),
    ]))->assertOk()->assertSee('Current human description')->assertSee('Original proposal')
        ->assertViewHas('suggestions', fn ($rows) => $rows->total() === 1);
    $this->get(route('admin.operations.ai.suggestions.index', ['provider' => 'missing']))
        ->assertOk()->assertViewHas('suggestions', fn ($rows) => $rows->total() === 0);
    $suggestion->update(['review_status' => 'rejected']);
    $this->get(route('admin.operations.ai.suggestions.index', ['status' => 'rejected']))
        ->assertOk()->assertSee('Original proposal');
});

it('accepts an edited description and safely undoes its own acceptance without altering the original', function (): void {
    $this->asset->update(['description' => 'Human original']);
    $suggestion = aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, 'AI original');
    $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.accept', $suggestion), [
        'lock_version' => 1, 'edited_description' => 'Human corrected AI',
    ])->assertSessionHasNoErrors();
    expect($this->asset->fresh()->description)->toBe('Human corrected AI')
        ->and($suggestion->fresh()->value)->toBe('AI original');
    $this->post(route('admin.operations.ai.suggestions.undo', $suggestion), ['lock_version' => 2])
        ->assertSessionHasNoErrors();
    expect($this->asset->fresh()->description)->toBe('Human original')
        ->and($suggestion->fresh()->review_status)->toBe('reverted')
        ->and(AssetAuditEvent::where('event_type', 'ai.suggestion.reverted')->count())->toBe(1);
    $this->post(route('admin.operations.ai.suggestions.undo', $suggestion), ['lock_version' => 3])
        ->assertSessionHasErrors('suggestion');
});

it('never undoes another reviewers acceptance or a subsequent metadata revision', function (): void {
    $suggestion = aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, 'Accepted');
    $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.accept', $suggestion), ['lock_version' => 1])
        ->assertSessionHasNoErrors();
    $suggestion->update(['reviewed_by_user_id' => null]);
    $this->post(route('admin.operations.ai.suggestions.undo', $suggestion), ['lock_version' => 2])
        ->assertSessionHasErrors('suggestion');
    $suggestion->update(['reviewed_by_user_id' => $this->user->id]);
    $this->asset->update(['description' => 'Later edit', 'lock_version' => 3]);
    $this->post(route('admin.operations.ai.suggestions.undo', $suggestion), ['lock_version' => 3])
        ->assertSessionHasErrors('lock_version');
    expect($this->asset->fresh()->description)->toBe('Later edit');
});

it('keeps existing tags when undoing an acceptance that did not add a link', function (): void {
    $tag = Tag::query()->create(['name' => 'Plein', 'slug' => 'plein']);
    $this->asset->tags()->attach($tag->id, ['id' => (string) str()->ulid()]);
    $suggestion = aiSuggestion(AiSuggestion::TYPE_TAG, 'Plein');
    $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.accept', $suggestion), ['lock_version' => 1])->assertSessionHasNoErrors();
    $this->post(route('admin.operations.ai.suggestions.undo', $suggestion), ['lock_version' => 2])->assertSessionHasNoErrors();
    expect($this->asset->tags()->count())->toBe(1);
});

it('processes only selected tags with one photo snapshot and reports stale items individually', function (): void {
    $first = aiSuggestion(AiSuggestion::TYPE_TAG, 'Plein');
    $second = aiSuggestion(AiSuggestion::TYPE_TAG, 'Huis');
    $unselected = aiSuggestion(AiSuggestion::TYPE_TAG, 'Boom');
    $stale = aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, 'Stale');
    $stale->update(['source_file_sha256' => str_repeat('e', 64)]);
    $payload = [
        'confirm' => 1, 'decision' => 'accept', 'selected' => [$first->id, $second->id, $stale->id],
        'versions' => [$first->id => 1, $second->id => 1, $stale->id => 1],
    ];
    $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.bulk'), $payload)
        ->assertSessionHas('review_results', fn ($results) => count($results) === 3);
    expect($this->asset->tags()->count())->toBe(2)
        ->and($unselected->fresh()->review_status)->toBe('pending')
        ->and($stale->fresh()->review_status)->toBe('superseded');
    unset($payload['confirm']);
    $this->post(route('admin.operations.ai.suggestions.bulk'), $payload)->assertSessionHasErrors('confirm');
});

it('requires the original bulk snapshot and rejects duplicate selections', function (): void {
    $suggestion = aiSuggestion(AiSuggestion::TYPE_TAG, 'Plein');
    $payload = ['confirm' => 1, 'decision' => 'accept', 'selected' => [$suggestion->id], 'versions' => [$suggestion->id => 1]];
    $this->asset->update(['lock_version' => 2]);
    $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.bulk'), $payload)
        ->assertSessionHas('review_results', fn ($results) => $results[0]['message'] === __('ai.errors.photo_changed'));
    expect($suggestion->fresh()->review_status)->toBe('pending');
    $payload['selected'][] = $suggestion->id;
    $this->post(route('admin.operations.ai.suggestions.bulk'), $payload)->assertSessionHasErrors('selected.0');
});

it('preauthorizes the entire bulk selection before making any changes', function (): void {
    $own = aiSuggestion(AiSuggestion::TYPE_TAG, 'Own tag');
    $other = aiSuggestion(AiSuggestion::TYPE_TAG, 'Other tag');
    $foreignAsset = Asset::query()->create(['accession_number' => 'FOREIGN', 'title' => 'Foreign']);
    $other->update(['asset_id' => $foreignAsset->id]);
    $this->user->roles()->sync([Role::where('key', 'volunteer')->firstOrFail()->id]);
    $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.bulk'), [
        'confirm' => 1, 'decision' => 'accept', 'selected' => [$own->id, $other->id],
        'versions' => [$own->id => 1, $other->id => 1],
    ])->assertForbidden();
    expect($own->fresh()->review_status)->toBe('pending');
});

it('supports an end-date-only filter and excludes deleted photos', function (): void {
    aiSuggestion(AiSuggestion::TYPE_TAG, 'Plein');
    $this->actingAs($this->user)->get(route('admin.operations.ai.suggestions.index', ['until' => now()->toDateString()]))
        ->assertOk()->assertViewHas('suggestions', fn ($rows) => $rows->total() === 1);
    $this->asset->delete();
    $this->get(route('admin.operations.ai.suggestions.index'))->assertOk()
        ->assertViewHas('suggestions', fn ($rows) => $rows->total() === 0);
});

it('undoes a newly added tag but refuses legacy acceptance without a receipt', function (): void {
    $suggestion = aiSuggestion(AiSuggestion::TYPE_TAG, 'Plein');
    $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.accept', $suggestion), ['lock_version' => 1])->assertSessionHasNoErrors();
    $this->post(route('admin.operations.ai.suggestions.undo', $suggestion), ['lock_version' => 2])->assertSessionHasNoErrors();
    expect($this->asset->tags()->count())->toBe(0);
    $suggestion->update(['review_status' => 'accepted', 'acceptance_receipt' => null]);
    $this->post(route('admin.operations.ai.suggestions.undo', $suggestion), ['lock_version' => 3])->assertSessionHasErrors('suggestion');
});

it('scopes the review index and review actions to the current owners', function (): void {
    $this->user->roles()->sync([Role::query()->where('key', 'volunteer')->firstOrFail()->id]);
    $otherUser = User::query()->create([
        'name' => 'Other Reviewer',
        'email' => 'other-reviewer@example.test',
        'password' => Hash::make('secret12345'),
    ]);
    $otherUser->roles()->attach(Role::query()->where('key', 'volunteer')->firstOrFail());
    $otherAsset = Asset::query()->create([
        'accession_number' => 'AI-OTHER-'.(string) str()->ulid(),
        'title' => 'Other review contract',
        'created_by_user_id' => $otherUser->id,
    ])->fresh();
    $otherFile = AssetFile::query()->create([
        'asset_id' => $otherAsset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/other-review.jpg',
        'sha256' => str_repeat('c', 64),
        'media_type' => 'image/jpeg',
        'byte_size' => 100,
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
        'is_primary' => true,
    ]);
    $otherSuggestion = AiSuggestion::query()->create([
        'ai_run_id' => $this->run->id,
        'asset_id' => $otherAsset->id,
        'asset_file_id' => $otherFile->id,
        'source_asset_lock_version' => $otherAsset->lock_version,
        'source_file_sha256' => $otherFile->sha256,
        'suggestion_type' => AiSuggestion::TYPE_DESCRIPTION,
        'value' => 'Andere eigenaar',
    ]);
    $ownSuggestion = aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, 'Eigenaar zichtbaar');

    $this->actingAs($this->user)->get(route('admin.operations.ai.suggestions.index'))
        ->assertOk()
        ->assertSee('Eigenaar zichtbaar', false)
        ->assertDontSee('Andere eigenaar', false)
        ->assertViewHas('suggestions', fn ($suggestions): bool => $suggestions->total() === 1);

    $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.reject', $otherSuggestion), [
        'review_note' => 'Niet mijn foto',
    ])->assertForbidden();
    expect($otherSuggestion->fresh()->review_status)->toBe(AiSuggestion::REVIEW_PENDING);

    $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.reject', $ownSuggestion), [
        'review_note' => 'Controleren',
    ])->assertRedirect(route('admin.operations.ai.suggestions.index'));
});

it('renders pending AI suggestions for reviewers', function (): void {
    aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, 'Een beschrijving ter controle.');

    $this->actingAs($this->user)->get('/admin/operations/ai/suggestions')
        ->assertOk()
        ->assertSee('AI-suggesties beoordelen', false)
        ->assertSee('Een beschrijving ter controle.', false);
});

it('accepts a description suggestion through metadata revision guards', function (): void {
    $suggestion = aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, 'Een gecontroleerde AI-beschrijving.');

    $this->actingAs($this->user)->post("/admin/operations/ai/suggestions/{$suggestion->id}/accept", [
        'lock_version' => $this->asset->lock_version,
    ])->assertRedirect('/admin/operations/ai/suggestions');

    $this->asset->refresh();
    expect($this->asset->description)->toBe('Een gecontroleerde AI-beschrijving.')
        ->and($this->asset->lock_version)->toBe(2)
        ->and($suggestion->fresh()->review_status)->toBe(AiSuggestion::REVIEW_ACCEPTED)
        ->and(AssetAuditEvent::query()->where('event_type', 'ai.suggestion.accepted')->count())->toBe(1);
});

it('accepts a tag suggestion without duplicating existing metadata', function (): void {
    $suggestion = aiSuggestion(AiSuggestion::TYPE_TAG, 'Dorpsplein');

    $this->actingAs($this->user)->post("/admin/operations/ai/suggestions/{$suggestion->id}/accept", [
        'lock_version' => $this->asset->lock_version,
    ])->assertSessionHasNoErrors();

    expect($this->asset->fresh()->tags()->where('slug', 'dorpsplein')->exists())->toBeTrue()
        ->and($suggestion->fresh()->review_status)->toBe(AiSuggestion::REVIEW_ACCEPTED);
});

it('attaches the existing tag when a suggested tag is one of its synonyms', function (): void {
    $tag = Tag::query()->create(['name' => 'Kerk', 'slug' => 'kerk']);
    $tag->synonyms()->create(['name' => 'Godshuis', 'slug' => 'godshuis']);
    $suggestion = aiSuggestion(AiSuggestion::TYPE_TAG, 'godshuis');

    $this->actingAs($this->user)->post("/admin/operations/ai/suggestions/{$suggestion->id}/accept", [
        'lock_version' => $this->asset->lock_version,
    ])->assertSessionHasNoErrors();

    expect(Tag::query()->where('slug', 'godshuis')->exists())->toBeFalse()
        ->and($this->asset->fresh()->tags()->pluck('tags.id')->all())->toBe([$tag->id]);
});

it('rejects suggestions without changing asset metadata', function (): void {
    $suggestion = aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, 'Niet gebruiken.');

    $this->actingAs($this->user)->post("/admin/operations/ai/suggestions/{$suggestion->id}/reject", [
        'review_note' => 'Te algemeen',
    ])->assertRedirect('/admin/operations/ai/suggestions');

    expect($this->asset->fresh()->description)->toBeNull()
        ->and($this->asset->fresh()->lock_version)->toBe(1)
        ->and($suggestion->fresh()->review_status)->toBe(AiSuggestion::REVIEW_REJECTED)
        ->and(AssetAuditEvent::query()->where('event_type', 'ai.suggestion.rejected')->count())->toBe(1);
});

it('supersedes stale suggestions instead of applying them', function (): void {
    $suggestion = aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, 'Oude bronversie.');
    $suggestion->update(['source_file_sha256' => str_repeat('c', 64)]);

    $this->actingAs($this->user)->from('/admin/operations/ai/suggestions')->post("/admin/operations/ai/suggestions/{$suggestion->id}/accept", [
        'lock_version' => 1,
    ])->assertRedirect('/admin/operations/ai/suggestions')
        ->assertSessionHasErrors('suggestion');

    expect($this->asset->fresh()->description)->toBeNull()
        ->and($suggestion->fresh()->review_status)->toBe(AiSuggestion::REVIEW_SUPERSEDED);
});

it('shows stored AI output and review history on the photo without calling a provider', function (): void {
    Http::preventStrayRequests();
    aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, '<script>alert("provider")</script>');
    aiSuggestion(AiSuggestion::TYPE_TAG, 'dorpsplein')->update(['review_status' => AiSuggestion::REVIEW_ACCEPTED]);
    aiSuggestion(AiSuggestion::TYPE_TAG, 'afgewezen tag')->update(['review_status' => AiSuggestion::REVIEW_REJECTED, 'review_note' => 'Onjuist']);
    aiSuggestion(AiSuggestion::TYPE_TAG, 'oud voorstel')->update(['review_status' => AiSuggestion::REVIEW_SUPERSEDED]);

    $this->actingAs($this->user)->get(route('admin.assets.show', $this->asset))
        ->assertOk()->assertSee('AI-resultaten')->assertSee('review-proof')
        ->assertSee('<script>alert("provider")</script>')
        ->assertDontSee('<script>alert("provider")</script>', false)
        ->assertSee('dorpsplein')->assertSee('Geaccepteerd')->assertSee('Afgewezen')
        ->assertSee('Verouderd')->assertSee('Onjuist');
    expect($this->asset->fresh()->description)->toBeNull()
        ->and($this->asset->fresh()->lock_version)->toBe(1);
});

it('returns photo reviews to the photo and preserves existing acceptance guards', function (string $action): void {
    $suggestion = aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, 'Een voorstel op de foto.');
    $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.'.$action, $suggestion), [
        'return_to' => 'asset', 'lock_version' => $this->asset->lock_version,
    ])->assertRedirect(route('admin.assets.show', $this->asset).'#ai-results')->assertSessionHasNoErrors();
    $this->get(route('admin.assets.show', $this->asset))->assertOk()
        ->assertSee($action === 'accept' ? 'Geaccepteerd' : 'Afgewezen');
    expect($suggestion->fresh()->review_status)->toBe($action === 'accept' ? 'accepted' : 'rejected');
})->with(['accept', 'reject']);

it('keeps stale photo review errors visible without applying metadata', function (): void {
    $suggestion = aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, 'Verouderde beschrijving');
    $this->file->update(['is_primary' => false]);
    $this->asset->update(['lock_version' => 2]);
    $this->actingAs($this->user)->from(route('admin.assets.show', $this->asset))
        ->post(route('admin.operations.ai.suggestions.accept', $suggestion), ['return_to' => 'asset', 'lock_version' => 2])
        ->assertSessionHasErrors('suggestion');
    $this->get(route('admin.assets.show', $this->asset))->assertOk()->assertSee('primaire fotobestand');
    expect($this->asset->fresh()->description)->toBeNull();
});

it('accepts sibling tags and description after unrelated metadata edits', function (): void {
    Http::preventStrayRequests();
    $suggestions = [
        aiSuggestion(AiSuggestion::TYPE_TAG, 'Dorpsplein'),
        aiSuggestion(AiSuggestion::TYPE_TAG, 'Molen'),
        aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, 'Een molen op het dorpsplein.'),
    ];
    $this->asset->update(['title' => 'Handmatig aangepast', 'lock_version' => 2]);
    foreach ($suggestions as $suggestion) {
        $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.accept', $suggestion), [
            'return_to' => 'asset', 'lock_version' => $this->asset->fresh()->lock_version,
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.assets.show', $this->asset).'#ai-results');
        expect($suggestion->fresh()->review_status)->toBe(AiSuggestion::REVIEW_ACCEPTED);
    }
    expect($this->asset->fresh()->lock_version)->toBe(5)
        ->and($this->asset->fresh()->title)->toBe('Handmatig aangepast')
        ->and($this->asset->fresh()->description)->toBe('Een molen op het dorpsplein.')
        ->and($this->asset->tags()->count())->toBe(2)
        ->and(AssetAuditEvent::query()->where('event_type', 'ai.suggestion.accepted')->count())->toBe(3);
});

it('recovers previously superseded suggestions on both review surfaces without reanalysis', function (): void {
    Http::preventStrayRequests();
    $suggestion = aiSuggestion(AiSuggestion::TYPE_TAG, 'Herstelbaar voorstel');
    $suggestion->update(['review_status' => AiSuggestion::REVIEW_SUPERSEDED]);
    $this->asset->update(['lock_version' => 3]);
    $url = route('admin.operations.ai.suggestions.accept', $suggestion);
    $this->actingAs($this->user)->get(route('admin.assets.show', $this->asset))->assertOk()->assertSee($url);
    $this->get(route('admin.operations.ai.suggestions.index'))->assertOk()->assertSee('Herstelbaar voorstel');
    $this->post($url, ['lock_version' => 3])->assertSessionHasNoErrors();
    expect($suggestion->fresh()->review_status)->toBe(AiSuggestion::REVIEW_ACCEPTED);
});

it('keeps stale form conflicts retryable without invalidating the suggestion', function (): void {
    $suggestion = aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, 'Voorstel');
    $this->asset->update(['description' => 'Gelijktijdige edit', 'lock_version' => 2]);
    $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.accept', $suggestion), [
        'lock_version' => 1,
    ])->assertSessionHasErrors('lock_version');
    expect($suggestion->fresh()->review_status)->toBe(AiSuggestion::REVIEW_PENDING)
        ->and($this->asset->fresh()->description)->toBe('Gelijktijdige edit');
});

it('refuses replacement primary files even when the old file checksum is unchanged', function (): void {
    $suggestion = aiSuggestion(AiSuggestion::TYPE_TAG, 'Oud beeld');
    $this->file->update(['is_primary' => false]);
    $replacement = $this->file->replicate();
    $replacement->is_primary = true;
    $replacement->storage_key = 'originals/replacement.jpg';
    $replacement->sha256 = str_repeat('c', 64);
    $replacement->save();
    $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.accept', $suggestion), [
        'lock_version' => 1,
    ])->assertSessionHasErrors('suggestion');
    expect($suggestion->fresh()->review_status)->toBe(AiSuggestion::REVIEW_SUPERSEDED)
        ->and($this->asset->tags()->count())->toBe(0);
    $this->get(route('admin.operations.ai.suggestions.index'))->assertOk()->assertDontSee('Oud beeld');
    $this->get(route('admin.assets.show', $this->asset))->assertOk()
        ->assertDontSee(route('admin.operations.ai.suggestions.accept', $suggestion));
});

it('does not reopen human reviewed suggestions', function (string $status): void {
    $suggestion = aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, 'Niet opnieuw toepassen');
    $suggestion->update(['review_status' => $status]);
    $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.accept', $suggestion), [
        'lock_version' => 1,
    ])->assertSessionHasErrors('suggestion');
    expect($suggestion->fresh()->review_status)->toBe($status)
        ->and($this->asset->fresh()->description)->toBeNull();
})->with([AiSuggestion::REVIEW_ACCEPTED, AiSuggestion::REVIEW_REJECTED]);

it('distinguishes missing analysis empty output and embedding output', function (): void {
    $this->run->delete();
    $this->actingAs($this->user)->get(route('admin.assets.show', $this->asset))
        ->assertOk()->assertSee('Nog geen opgeslagen AI-resultaten');
    $this->run->save();
    $this->get(route('admin.assets.show', $this->asset))
        ->assertOk()->assertSee('Geen beschrijving of tags teruggegeven');
    $this->run->update(['run_type' => AiRun::TYPE_EMBEDDING, 'model_space' => 'visual-space', 'result_summary' => ['dimensions' => 768]]);
    $this->get(route('admin.assets.show', $this->asset))
        ->assertOk()->assertSee('Zoekindex')->assertSee('visual-space')->assertSee('768')
        ->assertSee('geen beschrijving of tags')->assertDontSee('Geen beschrijving of tags teruggegeven');
});

it('links a task to accessible successfully processed photos without exposing other photos', function (): void {
    $operation = OperationRun::query()->create([
        'operation_type' => 'ai.analysis', 'status' => 'completed', 'processed_items' => 1,
    ]);
    $operation->auditEvents()->create([
        'asset_id' => $this->asset->id, 'event_type' => 'ai.analysis.item_succeeded', 'severity' => 'info',
    ]);
    $operation->auditEvents()->create([
        'asset_id' => $this->asset->id, 'event_type' => 'ai.analysis.item_succeeded', 'severity' => 'info',
    ]);
    $url = route('admin.operations.runs.ai-results', $operation);
    $this->actingAs($this->user)->get(route('admin.operations.runs.index'))
        ->assertOk()->assertSee($url);
    $this->get($url)->assertOk()
        ->assertSee(route('admin.assets.show', $this->asset).'#ai-results')
        ->assertViewHas('assets', fn ($assets): bool => $assets->total() === 1);

    $volunteer = User::query()->create(['name' => 'Volunteer', 'email' => 'results-volunteer@example.test', 'password' => 'test-password']);
    $volunteer->roles()->attach(Role::query()->where('key', 'volunteer')->firstOrFail());
    $this->actingAs($volunteer)->get($url)->assertOk()->assertDontSee($this->asset->accession_number);
    $this->get(route('admin.assets.show', $this->asset))->assertForbidden();
    $suggestion = aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, 'Privaat voorstel');
    $this->post(route('admin.operations.ai.suggestions.accept', $suggestion), ['return_to' => 'asset', 'lock_version' => 1])->assertForbidden();
    $this->asset->update(['created_by_user_id' => $volunteer->id]);
    $this->get($url)->assertOk()->assertSee($this->asset->accession_number);
    $this->get(route('admin.assets.show', $this->asset))->assertOk()->assertSee('Privaat voorstel');
    $this->asset->delete();
    $this->get($url)->assertOk()->assertDontSee($this->asset->accession_number);
});

it('paginates photo result history and keeps the AI panel anchor', function (): void {
    for ($i = 0; $i < 10; $i++) {
        $copy = $this->run->replicate();
        $copy->idempotency_key = 'history-'.$i;
        $copy->save();
    }
    $this->actingAs($this->user)->get(route('admin.assets.show', $this->asset))
        ->assertOk()->assertViewHas('aiRuns', fn ($runs): bool => $runs->total() === 11 && $runs->count() === 10)
        ->assertSee('ai_page=2#ai-results');
    $this->get(route('admin.assets.show', [$this->asset, 'ai_page' => 2]))
        ->assertOk()->assertViewHas('aiRuns', fn ($runs): bool => $runs->count() === 1);
});

it('does not offer AI results for unrelated operations or unauthenticated visitors', function (): void {
    $operation = OperationRun::query()->create(['operation_type' => 'integrity.verify']);
    $url = route('admin.operations.runs.ai-results', $operation);
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs($this->user)->get($url)->assertNotFound();
    $this->get(route('admin.operations.runs.index'))->assertOk()->assertDontSee($url);
});

it('paginates successful task photos and excludes failed-only assets', function (string $type): void {
    $operation = OperationRun::query()->create(['operation_type' => $type, 'status' => 'running']);
    for ($i = 0; $i < 26; $i++) {
        $asset = Asset::query()->create(['accession_number' => 'RESULT-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        $operation->auditEvents()->create(['asset_id' => $asset->id, 'event_type' => $type.'.item_succeeded', 'severity' => 'info']);
    }
    $operation->auditEvents()->create(['asset_id' => $this->asset->id, 'event_type' => $type.'.item_failed', 'severity' => 'error']);
    $url = route('admin.operations.runs.ai-results', $operation);
    $this->actingAs($this->user)->get(route('admin.operations.runs.index'))->assertSee($url);
    $this->get($url)->assertOk()->assertDontSee($this->asset->accession_number)
        ->assertViewHas('assets', fn ($assets): bool => $assets->total() === 26 && $assets->count() === 25);
    $this->get($url.'?page=2')->assertOk()->assertSee('RESULT-25')
        ->assertViewHas('assets', fn ($assets): bool => $assets->count() === 1);
})->with(['ai.analysis', 'ai.index']);

it('shows an honest empty task state when historical success events are unavailable', function (): void {
    $operation = OperationRun::query()->create(['operation_type' => 'ai.analysis', 'status' => 'completed', 'processed_items' => 1]);
    $this->actingAs($this->user)->get(route('admin.operations.runs.ai-results', $operation))
        ->assertOk()->assertSee('een oude taak heeft geen succeslog');
});

it('allows a read-only owner to see output but not review it or access task operations', function (): void {
    $viewer = User::query()->create(['name' => 'Viewer', 'email' => 'results-viewer@example.test', 'password' => 'test-password']);
    $viewer->roles()->attach(Role::query()->where('key', 'viewer')->firstOrFail());
    $this->asset->update(['created_by_user_id' => $viewer->id]);
    $suggestion = aiSuggestion(AiSuggestion::TYPE_TAG, 'Read-only result');
    $this->actingAs($viewer)->get(route('admin.assets.show', $this->asset))
        ->assertOk()->assertSee('Read-only result')->assertDontSee('Voorstel accepteren')->assertDontSee('Voorstel afwijzen');
    $this->post(route('admin.operations.ai.suggestions.reject', $suggestion), ['return_to' => 'asset'])->assertForbidden();
    $operation = OperationRun::query()->create(['operation_type' => 'ai.analysis']);
    $this->get(route('admin.operations.runs.ai-results', $operation))->assertForbidden();
});

it('rejects arbitrary review redirect targets without changing the suggestion', function (): void {
    $suggestion = aiSuggestion(AiSuggestion::TYPE_TAG, 'Niet wijzigen');
    $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.accept', $suggestion), [
        'lock_version' => 1, 'return_to' => 'https://example.test',
    ])->assertSessionHasErrors('return_to');
    expect($suggestion->fresh()->review_status)->toBe(AiSuggestion::REVIEW_PENDING);
});

function confidentTag(string $value, ?float $confidence): AiSuggestion
{
    $suggestion = aiSuggestion(AiSuggestion::TYPE_TAG, $value);
    $suggestion->update(['confidence' => $confidence]);

    return $suggestion;
}

it('filters the queue by type and minimum confidence and shows the most certain first', function (): void {
    confidentTag('fietsenrek', 0.7);
    confidentTag('havenkade', 0.95);
    confidentTag('mistbank', 0.4);
    confidentTag('onbekendtag', null);
    aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, 'Een beschrijving zonder zekerheid');

    $this->actingAs($this->user)
        ->get(route('admin.operations.ai.suggestions.index', ['type' => 'tag', 'min_confidence_filter' => 0.6, 'sort' => 'confidence']))
        ->assertOk()
        ->assertSeeInOrder(['havenkade', 'Zekerheid: 95%', 'fietsenrek', 'Zekerheid: 70%'])
        ->assertDontSee('mistbank')
        ->assertDontSee('onbekendtag')
        ->assertDontSee('Een beschrijving zonder zekerheid');
});

it('shows exactly which confident tags would be accepted and changes nothing', function (): void {
    $confident = confidentTag('havenkade', 0.95);
    $unsure = confidentTag('fietsenrek', 0.7);

    $this->actingAs($this->user)
        ->get(route('admin.operations.ai.suggestions.tags.confirm', ['min_confidence' => 0.8]))
        ->assertOk()
        ->assertSee('havenkade')
        ->assertDontSee('fietsenrek');

    expect($confident->fresh()->review_status)->toBe(AiSuggestion::REVIEW_PENDING)
        ->and($unsure->fresh()->review_status)->toBe(AiSuggestion::REVIEW_PENDING)
        ->and($this->asset->fresh()->tags()->count())->toBe(0);
});

it('accepts exactly the confirmed confident tags and records one bulk operation', function (): void {
    $confident = confidentTag('havenkade', 0.95);
    $unsure = confidentTag('fietsenrek', 0.7);

    $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.tags.accept'), [
        'confirm' => 1,
        'min_confidence' => 0.8,
        'selected' => [$confident->id],
        'versions' => [$confident->id => $this->asset->lock_version],
    ])->assertRedirect(route('admin.operations.ai.suggestions.index'))->assertSessionHasNoErrors();

    expect($confident->fresh()->review_status)->toBe(AiSuggestion::REVIEW_ACCEPTED)
        ->and($unsure->fresh()->review_status)->toBe(AiSuggestion::REVIEW_PENDING)
        ->and($this->asset->fresh()->tags()->where('slug', 'havenkade')->exists())->toBeTrue()
        ->and(DB::table('bulk_operations')->where('operation_type', 'ai_tag_bulk_accept')->value('affected_count'))->toBe(1);
});

it('refuses a tag below the threshold, a description and a missing confirmation', function (): void {
    $unsure = confidentTag('fietsenrek', 0.7);
    $description = aiSuggestion(AiSuggestion::TYPE_DESCRIPTION, 'Een beschrijving');
    $payload = fn (string $id): array => ['confirm' => 1, 'min_confidence' => 0.8, 'selected' => [$id], 'versions' => [$id => $this->asset->lock_version]];

    $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.tags.accept'), $payload($unsure->id))->assertSessionHasErrors('selected');
    $this->post(route('admin.operations.ai.suggestions.tags.accept'), $payload($description->id))->assertSessionHasErrors('selected');
    $this->post(route('admin.operations.ai.suggestions.tags.accept'), [...$payload($unsure->id), 'confirm' => 0])->assertSessionHasErrors('confirm');

    expect($unsure->fresh()->review_status)->toBe(AiSuggestion::REVIEW_PENDING)
        ->and($description->fresh()->review_status)->toBe(AiSuggestion::REVIEW_PENDING)
        ->and($this->asset->fresh()->tags()->count())->toBe(0);
});

it('reports a photo that changed after the preview instead of accepting its tag', function (): void {
    $confident = confidentTag('havenkade', 0.95);
    $this->asset->update(['lock_version' => 2]);

    $this->actingAs($this->user)->post(route('admin.operations.ai.suggestions.tags.accept'), [
        'confirm' => 1,
        'min_confidence' => 0.8,
        'selected' => [$confident->id],
        'versions' => [$confident->id => 1],
    ])->assertRedirect(route('admin.operations.ai.suggestions.index'));

    expect($confident->fresh()->review_status)->toBe(AiSuggestion::REVIEW_PENDING)
        ->and($this->asset->fresh()->tags()->count())->toBe(0);
});
