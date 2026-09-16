<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Models\User;
use App\Modules\Ai\Models\AiSuggestion;
use App\Modules\Catalogue\Models\Tag;
use App\Modules\Ingest\Models\AssetAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiSuggestionReviewService
{
    public function accept(AiSuggestion $suggestion, User $user, int $expectedLockVersion): AiSuggestion
    {
        $this->supersedeIfStale($suggestion);

        return DB::transaction(function () use ($suggestion, $user, $expectedLockVersion): AiSuggestion {
            $suggestion = AiSuggestion::query()->lockForUpdate()->with(['asset', 'assetFile'])->findOrFail($suggestion->id);
            $asset = $suggestion->asset()->lockForUpdate()->firstOrFail();
            $file = $suggestion->assetFile()->firstOrFail();
            $this->assertReviewable($suggestion, $expectedLockVersion, (int) $asset->lock_version, (string) $file->sha256);

            if ($suggestion->suggestion_type === AiSuggestion::TYPE_DESCRIPTION) {
                $asset->description = $suggestion->value;
            } elseif ($suggestion->suggestion_type === AiSuggestion::TYPE_TAG) {
                $tag = Tag::query()->firstOrCreate(
                    ['slug' => Str::slug($suggestion->value)],
                    ['name' => $suggestion->value],
                );
                if (! $asset->tags()->whereKey($tag->id)->exists()) {
                    $asset->tags()->attach($tag->id, ['id' => (string) Str::ulid()]);
                }
            } else {
                throw ValidationException::withMessages(['suggestion' => 'Dit type AI-suggestie kan nog niet automatisch worden toegepast.']);
            }

            $asset->lock_version = (int) $asset->lock_version + 1;
            $asset->save();
            $suggestion->forceFill([
                'review_status' => AiSuggestion::REVIEW_ACCEPTED,
                'reviewed_by_user_id' => $user->id,
                'reviewed_at' => now(),
            ])->save();
            AssetAuditEvent::query()->create([
                'asset_id' => $asset->id,
                'actor_user_id' => $user->id,
                'event_type' => 'ai.suggestion.accepted',
                'details' => [
                    'suggestion_id' => $suggestion->id,
                    'suggestion_type' => $suggestion->suggestion_type,
                    'revision' => $asset->lock_version,
                ],
            ]);

            return $suggestion->refresh();
        });
    }

    public function reject(AiSuggestion $suggestion, User $user, ?string $note = null): AiSuggestion
    {
        return DB::transaction(function () use ($suggestion, $user, $note): AiSuggestion {
            $suggestion = AiSuggestion::query()->lockForUpdate()->with('asset')->findOrFail($suggestion->id);
            if ($suggestion->review_status !== AiSuggestion::REVIEW_PENDING) {
                throw ValidationException::withMessages(['suggestion' => 'Deze AI-suggestie is al beoordeeld.']);
            }
            $suggestion->forceFill([
                'review_status' => AiSuggestion::REVIEW_REJECTED,
                'reviewed_by_user_id' => $user->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();
            AssetAuditEvent::query()->create([
                'asset_id' => $suggestion->asset_id,
                'actor_user_id' => $user->id,
                'event_type' => 'ai.suggestion.rejected',
                'details' => [
                    'suggestion_id' => $suggestion->id,
                    'suggestion_type' => $suggestion->suggestion_type,
                ],
            ]);

            return $suggestion->refresh();
        });
    }

    private function assertReviewable(AiSuggestion $suggestion, int $expectedLockVersion, int $currentLockVersion, string $currentSha): void
    {
        $errors = [];
        if ($suggestion->review_status !== AiSuggestion::REVIEW_PENDING) {
            $errors['suggestion'] = 'Deze AI-suggestie is al beoordeeld.';
        }
        if ($expectedLockVersion !== $currentLockVersion) {
            $errors['lock_version'] = 'Deze foto is tussentijds gewijzigd. Vernieuw de pagina en controleer de suggestie opnieuw.';
        }
        if ((int) $suggestion->source_asset_lock_version !== $currentLockVersion || $suggestion->source_file_sha256 !== $currentSha) {
            $errors['suggestion'] = 'Deze AI-suggestie hoort bij een oudere bronversie en is ongeldig gemaakt.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function supersedeIfStale(AiSuggestion $suggestion): void
    {
        $suggestion->loadMissing(['asset', 'assetFile']);
        if (
            $suggestion->review_status === AiSuggestion::REVIEW_PENDING
            && $suggestion->asset !== null
            && $suggestion->assetFile !== null
            && ((int) $suggestion->source_asset_lock_version !== (int) $suggestion->asset->lock_version
                || $suggestion->source_file_sha256 !== $suggestion->assetFile->sha256)
        ) {
            $suggestion->forceFill(['review_status' => AiSuggestion::REVIEW_SUPERSEDED])->save();
            throw ValidationException::withMessages([
                'suggestion' => 'Deze AI-suggestie hoort bij een oudere bronversie en is ongeldig gemaakt.',
            ]);
        }
    }
}
