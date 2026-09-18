<?php

declare(strict_types=1);

namespace App\Modules\Publication\Services;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Publication\Models\Publication;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicationReviewService
{
    /** @return array<string, bool> */
    public function checklist(Asset $asset): array
    {
        $publication = $asset->publication;
        $primary = $asset->files()->where('is_primary', true)->first();

        return [
            'primary' => $primary !== null,
            'scan' => $primary !== null && $primary->scanner_status === 'clean' && $primary->ingest_status === 'ready_private',
            'rights' => $asset->rights()->where('verification_status', 'verified')->exists(),
            'privacy' => $publication?->privacy_cleared === true,
            'embargo' => $publication?->embargo_until === null || Publication::query()->whereKey($publication->id)->whereDate('embargo_until', '<=', now()->toDateString())->exists(),
            'approval' => $publication?->status === 'published',
            'revision' => $publication !== null && $publication->published_lock_version === $asset->lock_version,
            'visible' => Publication::query()->where('asset_id', $asset->id)->publiclyVisible()->exists(),
        ];
    }

    /** @return array<string, mixed> */
    public function snapshot(Asset $asset): array
    {
        $publication = $asset->publication;

        return [
            'metadata' => Arr::only($asset->attributesToArray(), ['title', 'description', 'date_precision', 'date_earliest', 'date_latest', 'date_display']),
            'tags' => $asset->tags()->orderBy('name')->pluck('name')->all(),
            'rights' => $asset->rights()->orderBy('id')->get()->map(fn ($right) => $right->only(['verification_status', 'rights_holder', 'license_id', 'rights_statement_id']))->all(),
            'primary' => $asset->files()->where('is_primary', true)->first()?->only(['id', 'sha256', 'scanner_status', 'ingest_status']),
            'publication' => $publication === null ? null : Arr::only($publication->attributesToArray(), ['privacy_cleared', 'credit_line', 'download_policy', 'embargo_until']),
        ];
    }

    public function fingerprint(Asset $asset): string
    {
        return hash('sha256', json_encode([$asset->lock_version, $asset->publication?->only(['id', 'status', 'updated_at']), $this->snapshot($asset)], JSON_THROW_ON_ERROR));
    }

    public function snapshotMatches(mixed $current, mixed $approved): bool
    {
        if (! is_array($current) || ! is_array($approved)) {
            return $current === $approved;
        }
        if (count($current) !== count($approved)) {
            return false;
        }
        // PostgreSQL jsonb reorders object keys, but scalar types and list positions still matter.
        foreach ($current as $key => $value) {
            if (! array_key_exists($key, $approved) || ! $this->snapshotMatches($value, $approved[$key])) {
                return false;
            }
        }

        return true;
    }

    public function decide(User $user, string $assetId, string $decision, ?string $reason = null, ?string $fingerprint = null): Asset
    {
        return DB::transaction(function () use ($user, $assetId, $decision, $reason, $fingerprint): Asset {
            abort_unless($user->hasPermission('assets.publish'), 403);
            $asset = Asset::query()->whereKey($assetId)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('view', $asset);
            $publication = Publication::query()->where('asset_id', $asset->id)->lockForUpdate()->first();
            abort_unless($publication !== null && $publication->status === 'in_review', 409, $decision === 'publish' ? __('publication.generated.t_4d8f1538488bec0e') : __('publication.generated.t_b7de418a87e289e8'));
            $asset->setRelation('publication', $publication);
            if ($fingerprint !== null && ! hash_equals($fingerprint, $this->fingerprint($asset))) {
                throw ValidationException::withMessages(['receipt' => __('publishwork.changed')]);
            }
            if ($decision === 'publish') {
                $checks = $this->checklist($asset);
                abort_unless($checks['privacy'], 409, __('publication.generated.t_e7c0bd249bb1c8f3'));
                abort_unless($checks['rights'], 409, __('publication.generated.t_65b44ea637cca0bd'));
                abort_unless($checks['scan'] && $checks['primary'], 409, __('publication.generated.t_a3e8eaf0d5f878d8'));
                $publication->update([
                    'permalink_slug' => $publication->permalink_slug ?? $this->uniqueSlug($asset),
                    'status' => 'published', 'reviewed_by_user_id' => $user->id, 'reviewed_at' => now(), 'published_at' => now(),
                    'revoked_at' => null, 'revoked_reason' => null, 'published_lock_version' => $asset->lock_version,
                    'approval_snapshot' => $this->snapshot($asset),
                ]);
            } else {
                abort_unless($decision === 'reject' && is_string($reason) && trim($reason) !== '', 422);
                $publication->update(['status' => 'draft', 'reviewed_by_user_id' => $user->id, 'reviewed_at' => now(), 'reject_reason' => $reason]);
            }
            AssetAuditEvent::query()->create([
                'asset_id' => $asset->id, 'actor_user_id' => $user->id,
                'event_type' => $decision === 'publish' ? 'publication.published' : 'publication.rejected',
                'details' => ['publication_id' => $publication->id, 'lock_version' => $asset->lock_version, 'reason' => $reason],
            ]);

            return $asset;
        });
    }

    private function uniqueSlug(Asset $asset): string
    {
        $base = Str::slug($asset->accession_number);
        $slug = $base;
        $suffix = 1;
        while (Publication::query()->where('permalink_slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
