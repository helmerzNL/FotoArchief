<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Collection;
use App\Modules\Catalogue\Models\Tag;
use App\Modules\Ingest\Models\AssetAuditEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BulkAssetController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $user = $this->user($request);
        $assetIds = $request->input('asset_ids');
        if (! is_array($assetIds) || empty($assetIds)) {
            return redirect()->route('admin.assets.index')->with('error', 'Selecteer eerst minimaal één foto.');
        }

        $assets = Asset::query()->whereIn('id', $assetIds)->with(['tags', 'collections', 'rights'])->get();

        if ($assets->isEmpty()) {
            return redirect()->route('admin.assets.index')->with('error', 'Geen geldige foto’s geselecteerd.');
        }

        // Per-photo ownership & permission check
        foreach ($assets as $asset) {
            if ($user->cannot('update', $asset)) {
                abort(403, 'Geen toestemming om foto '.$asset->accession_number.' te bewerken.');
            }
        }

        $collections = Collection::query()->orderBy('title')->get();
        $tags = Tag::query()->orderBy('name')->get();

        return view('catalogue.bulk.confirm', [
            'assets' => $assets,
            'collections' => $collections,
            'tags' => $tags,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['required', 'string', 'exists:assets,id'],
            'lock_versions' => ['required', 'array'],
            'lock_versions.*' => ['required', 'integer', 'min:1'],

            'tags_to_add' => ['nullable', 'string', 'max:500'],
            'tags_to_remove' => ['nullable', 'array'],
            'tags_to_remove.*' => ['string', 'exists:tags,id'],

            'collection_id_to_add' => ['nullable', 'string', 'exists:collections,id'],
            'collection_id_to_remove' => ['nullable', 'string', 'exists:collections,id'],

            'update_rights' => ['nullable', 'boolean'],
            'rights_status' => ['nullable', 'string', 'in:unverified,verified,disputed'],
            'rights_holder' => ['nullable', 'string', 'max:255'],
            'rights_note' => ['nullable', 'string', 'max:2000'],

            'update_dates' => ['nullable', 'boolean'],
            'date_precision' => ['nullable', 'string', 'in:exact,year,decade,unknown'],
            'date_earliest' => ['nullable', 'date'],
            'date_latest' => ['nullable', 'date', 'after_or_equal:date_earliest'],
            'date_display' => ['nullable', 'string', 'max:100'],

            'update_status' => ['nullable', 'boolean'],
            'catalogue_status' => ['nullable', 'string', 'in:draft,under_review,catalogued'],
        ]);

        $tagsToAdd = collect(explode(',', (string) ($validated['tags_to_add'] ?? '')))
            ->map(fn (string $tag) => trim($tag))
            ->filter()
            ->unique();

        $tagIdsToAdd = $tagsToAdd->map(fn (string $name) => Tag::query()->firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name],
        )->id);

        $tagIdsToRemove = $validated['tags_to_remove'] ?? [];

        $updatedCount = 0;

        DB::transaction(function () use ($validated, $user, $tagIdsToAdd, $tagIdsToRemove, &$updatedCount) {
            foreach ($validated['asset_ids'] as $assetId) {
                /** @var Asset $asset */
                $asset = Asset::query()->lockForUpdate()->findOrFail($assetId);

                // Per-photo authorization check
                if ($user->cannot('update', $asset)) {
                    abort(403, 'Geen toestemming voor foto '.$asset->accession_number);
                }

                // Optimistic concurrency check
                $expectedLockVersion = (int) ($validated['lock_versions'][$assetId] ?? 0);
                if ($asset->lock_version !== $expectedLockVersion) {
                    throw ValidationException::withMessages([
                        'lock_versions' => 'Foto '.$asset->accession_number.' is tussentijds gewijzigd door een andere gebruiker. Vernieuw de selectie.',
                    ]);
                }

                $firstRight = $asset->rights->first();
                $before = [
                    'metadata' => Arr::only($asset->attributesToArray(), ['title', 'date_precision', 'date_earliest', 'date_latest', 'date_display', 'catalogue_status']),
                    'tags' => $asset->tags->pluck('name')->all(),
                    'rights' => $firstRight ? Arr::only($firstRight->attributesToArray(), ['verification_status', 'rights_holder', 'note']) : null,
                    'collections' => $asset->collections->pluck('title')->all(),
                ];

                // 1. Tags
                if ($tagIdsToAdd->isNotEmpty()) {
                    foreach ($tagIdsToAdd as $tId) {
                        if (! $asset->tags()->where('tags.id', $tId)->exists()) {
                            $asset->tags()->attach($tId, ['id' => (string) Str::ulid()]);
                        }
                    }
                }
                if (! empty($tagIdsToRemove)) {
                    $asset->tags()->detach($tagIdsToRemove);
                }

                // 2. Collections
                if (! empty($validated['collection_id_to_add'])) {
                    if (! $asset->collections()->where('collections.id', $validated['collection_id_to_add'])->exists()) {
                        $maxPos = (int) DB::table('collection_assets')->where('collection_id', $validated['collection_id_to_add'])->max('position');
                        $asset->collections()->attach($validated['collection_id_to_add'], [
                            'id' => (string) Str::ulid(),
                            'position' => $maxPos + 1,
                        ]);
                    }
                }
                if (! empty($validated['collection_id_to_remove'])) {
                    $asset->collections()->detach($validated['collection_id_to_remove']);
                }

                // 3. Rights
                if (! empty($validated['update_rights']) && ! empty($validated['rights_status'])) {
                    $rightsData = [
                        'verification_status' => $validated['rights_status'],
                        'rights_holder' => $validated['rights_holder'] ?? null,
                        'note' => $validated['rights_note'] ?? null,
                    ];
                    if ($firstRight) {
                        $firstRight->update($rightsData);
                    } else {
                        $asset->rights()->create($rightsData);
                    }
                }

                // 4. Dates
                if (! empty($validated['update_dates'])) {
                    if (! empty($validated['date_precision'])) {
                        $asset->date_precision = $validated['date_precision'];
                    }
                    $asset->date_earliest = $validated['date_earliest'] ?? null;
                    $asset->date_latest = $validated['date_latest'] ?? null;
                    $asset->date_display = $validated['date_display'] ?? null;
                }

                // 5. Catalogue status
                if (! empty($validated['update_status']) && ! empty($validated['catalogue_status'])) {
                    $asset->catalogue_status = $validated['catalogue_status'];
                }

                $asset->lock_version++;
                $asset->save();

                $asset->load(['tags', 'rights', 'collections']);
                $newFirstRight = $asset->rights->first();

                $after = [
                    'metadata' => Arr::only($asset->attributesToArray(), ['title', 'date_precision', 'date_earliest', 'date_latest', 'date_display', 'catalogue_status']),
                    'tags' => $asset->tags->pluck('name')->all(),
                    'rights' => $newFirstRight ? Arr::only($newFirstRight->attributesToArray(), ['verification_status', 'rights_holder', 'note']) : null,
                    'collections' => $asset->collections->pluck('title')->all(),
                ];

                AssetAuditEvent::query()->create([
                    'asset_id' => $asset->id,
                    'actor_user_id' => $user->id,
                    'event_type' => 'metadata.bulk_updated',
                    'details' => [
                        'revision' => $asset->lock_version,
                        'before' => $before,
                        'after' => $after,
                    ],
                ]);

                $updatedCount++;
            }

            DB::table('bulk_operations')->insert([
                'id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'operation_type' => 'bulk_metadata_update',
                'affected_count' => $updatedCount,
                'payload' => json_encode($validated),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return redirect()->route('admin.assets.index')->with('status', sprintf('%d foto’s succesvol bijgewerkt in batch.', $updatedCount));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
