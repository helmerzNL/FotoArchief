<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Models\AiSuggestion;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Ingest\Models\AssetAuditEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    $this->asset->forceFill(['lock_version' => 2])->save();

    $this->actingAs($this->user)->from('/admin/operations/ai/suggestions')->post("/admin/operations/ai/suggestions/{$suggestion->id}/accept", [
        'lock_version' => 2,
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
    $this->asset->update(['lock_version' => 2]);
    $this->actingAs($this->user)->from(route('admin.assets.show', $this->asset))
        ->post(route('admin.operations.ai.suggestions.accept', $suggestion), ['return_to' => 'asset', 'lock_version' => 2])
        ->assertSessionHasErrors('suggestion');
    $this->get(route('admin.assets.show', $this->asset))->assertOk()->assertSee('oudere bronversie');
    expect($this->asset->fresh()->description)->toBeNull();
});

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
