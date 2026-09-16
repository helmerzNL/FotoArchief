<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Models\AiSuggestion;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Ingest\Models\AssetAuditEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

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
