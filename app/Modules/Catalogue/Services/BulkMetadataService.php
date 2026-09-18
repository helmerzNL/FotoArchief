<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Services;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Collection;
use App\Modules\Catalogue\Models\Tag;
use App\Modules\DataExchange\Services\MetadataRowValidator;
use App\Modules\Ingest\Models\AssetAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BulkMetadataService
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $data = Validator::make($input, [
            'asset_ids' => ['required', 'array', 'min:1', 'max:25'], 'asset_ids.*' => ['required', 'ulid', 'distinct'],
            'lock_versions' => ['required', 'array'], 'lock_versions.*' => ['required', 'integer', 'min:1'],
            'tags_to_add' => ['nullable', 'string', 'max:2000'], 'tags_to_remove' => ['nullable', 'array', 'max:20'], 'tags_to_remove.*' => ['ulid', 'distinct', 'exists:tags,id'],
            'collection_id_to_add' => ['nullable', 'ulid', 'exists:collections,id'], 'collection_id_to_remove' => ['nullable', 'ulid', 'exists:collections,id'],
            'update_dates' => ['nullable', 'boolean'], 'date_precision' => ['required_if:update_dates,1', 'nullable', 'string'],
            'date_earliest' => ['nullable', 'date_format:Y-m-d'], 'date_latest' => ['nullable', 'date_format:Y-m-d'], 'date_display' => ['nullable', 'string', 'max:255'],
            'update_rights' => ['nullable', 'boolean'], 'rights_status' => ['required_if:update_rights,1', 'nullable', 'in:unverified,verified,disputed'],
            'rights_holder' => ['nullable', 'string', 'max:255'], 'rights_note' => ['nullable', 'string', 'max:2000'],
            'update_status' => ['nullable', 'boolean'], 'catalogue_status' => ['required_if:update_status,1', 'nullable', 'in:draft,under_review,catalogued'],
        ])->validate();
        foreach ($data['asset_ids'] as $id) {
            if (! isset($data['lock_versions'][$id])) {
                throw ValidationException::withMessages(['lock_versions' => __('daily.conflict')]);
            }
        }
        $values = ['tags' => (string) ($data['tags_to_add'] ?? '')];
        if (! empty($data['update_dates'])) {
            foreach (['date_precision', 'date_earliest', 'date_latest', 'date_display'] as $key) {
                $values[$key] = (string) ($data[$key] ?? '');
            }
        }
        $checked = app(MetadataRowValidator::class)->validate($values);
        if ($checked['errors'] !== []) {
            throw ValidationException::withMessages(['changes' => $checked['errors']]);
        }
        $data['normalized'] = $checked['values'];

        return $data;
    }

    /** @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public function preview(User $user, array $data): array
    {
        $rows = [];
        foreach ($data['asset_ids'] as $id) {
            $asset = Asset::query()->whereKey($id)->firstOrFail();
            Gate::forUser($user)->authorize('update', $asset);
            if ($asset->lock_version !== (int) $data['lock_versions'][$id]) {
                throw ValidationException::withMessages(['lock_versions' => __('daily.conflict')]);
            }
            $before = $this->snapshot($asset);
            $rows[] = ['asset' => $asset, 'before' => $before, 'after' => $this->plan($before, $data)];
        }

        return $rows;
    }

    /** @param array<string, mixed> $data
     * @return list<array{id: string, label: string, ok: bool, message: string}>
     */
    public function apply(User $user, array $data): array
    {
        $results = [];
        foreach ($data['asset_ids'] as $id) {
            try {
                $applied = DB::transaction(function () use ($user, $data, $id): Asset {
                    $asset = Asset::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                    Gate::forUser($user)->authorize('update', $asset);
                    if ($asset->lock_version !== (int) $data['lock_versions'][$id]) {
                        throw ValidationException::withMessages(['revision' => __('daily.conflict')]);
                    }
                    $before = $this->snapshot($asset);
                    $after = $this->plan($before, $data);
                    $asset->fill($after['metadata']);
                    $asset->lock_version++;
                    $asset->save();
                    $tagIds = [];
                    foreach ($after['tags'] as $name) {
                        $tagIds[] = Tag::query()->firstOrCreate(['name' => $name], ['slug' => hash('sha256', $name)])->id;
                    }
                    $asset->tags()->sync($tagIds);
                    if (! empty($data['collection_id_to_add'])) {
                        $collection = Collection::query()->whereKey($data['collection_id_to_add'])->lockForUpdate()->firstOrFail();
                        if (! $asset->collections()->whereKey($collection->id)->exists()) {
                            $asset->collections()->attach($collection->id, ['position' => (int) DB::table('collection_assets')->where('collection_id', $collection->id)->max('position') + 1]);
                        }
                    }
                    if (! empty($data['collection_id_to_remove'])) {
                        $asset->collections()->detach($data['collection_id_to_remove']);
                    }
                    if (! empty($data['update_rights'])) {
                        $right = $asset->rights()->latest('id')->first();
                        $right === null ? $asset->rights()->create($after['rights']) : $right->update($after['rights']);
                    }
                    AssetAuditEvent::query()->create(['asset_id' => $id, 'actor_user_id' => $user->id, 'event_type' => 'metadata.bulk_updated', 'details' => ['revision' => $asset->lock_version, 'before' => $before, 'after' => $this->snapshot($asset->refresh())]]);
                    DB::table('bulk_operations')->insert(['id' => (string) str()->ulid(), 'user_id' => $user->id, 'operation_type' => 'bulk_metadata_update', 'affected_count' => 1, 'payload' => json_encode(['asset_id' => $id], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

                    return $asset;
                });
                $results[] = ['id' => $id, 'label' => $applied->accession_number, 'ok' => true, 'message' => __('daily.saved')];
            } catch (ValidationException|AuthorizationException|ModelNotFoundException) {
                $visible = Asset::query()->whereKey($id)->first();
                $label = $visible !== null && Gate::forUser($user)->allows('view', $visible) ? $visible->accession_number : $id;
                $results[] = ['id' => $id, 'label' => $label, 'ok' => false, 'message' => __('daily.conflict')];
            }
        }

        return $results;
    }

    /** @return array<string, mixed> */
    private function snapshot(Asset $asset): array
    {
        return [
            'metadata' => Arr::only($asset->attributesToArray(), ['title', 'description', 'date_precision', 'date_earliest', 'date_latest', 'date_display', 'catalogue_status']),
            'tags' => $asset->tags()->orderBy('name')->pluck('name')->all(),
            'collections' => $asset->collections()->orderBy('title')->pluck('title', 'collections.id')->all(),
            'rights' => $asset->rights()->latest('id')->first()?->only(['verification_status', 'rights_holder', 'note']),
        ];
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function plan(array $before, array $data): array
    {
        $after = $before;
        $remove = Tag::query()->whereIn('id', $data['tags_to_remove'] ?? [])->pluck('name')->all();
        $after['tags'] = array_values(array_unique(array_diff(array_merge($before['tags'], $data['normalized']['tags']), $remove)));
        if (count($after['tags']) > 20) {
            throw ValidationException::withMessages(['tags' => __('catalogue.generated.t_40b8e381d73aa3d2')]);
        }
        if (! empty($data['update_dates'])) {
            $after['metadata'] = array_replace($after['metadata'], Arr::except($data['normalized'], ['tags']));
        }
        if (! empty($data['update_status'])) {
            $after['metadata']['catalogue_status'] = $data['catalogue_status'];
        }
        if (! empty($data['update_rights'])) {
            $after['rights'] = ['verification_status' => $data['rights_status'], 'rights_holder' => $data['rights_holder'] ?? null, 'note' => $data['rights_note'] ?? null];
        }
        if (! empty($data['collection_id_to_add'])) {
            $after['collections'][$data['collection_id_to_add']] = Collection::query()->whereKey($data['collection_id_to_add'])->firstOrFail()->title;
        }
        if (! empty($data['collection_id_to_remove'])) {
            unset($after['collections'][$data['collection_id_to_remove']]);
        }

        return $after;
    }
}
