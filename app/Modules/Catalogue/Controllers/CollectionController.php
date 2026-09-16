<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CollectionController extends Controller
{
    public function index(Request $request): View
    {
        $collections = Collection::query()
            ->with(['parent'])
            ->withCount(['children', 'assets'])
            ->orderBy('parent_id')
            ->orderBy('position')
            ->orderBy('title')
            ->get();

        $rootCollections = $collections->whereNull('parent_id');

        return view('catalogue.collections.index', compact('collections', 'rootCollections'));
    }

    public function create(Request $request): View
    {
        $this->checkManagePermission($request);
        $allCollections = Collection::query()->orderBy('title')->get();
        $parentId = $request->query('parent_id');

        return view('catalogue.collections.create', compact('allCollections', 'parentId'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->checkManagePermission($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:collections,slug'],
            'collection_type' => ['required', Rule::in(['collection', 'album', 'series', 'theme'])],
            'description' => ['nullable', 'string', 'max:10000'],
            'parent_id' => ['nullable', 'exists:collections,id'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        if (empty($data['slug'])) {
            $baseSlug = Str::slug($data['title']);
            $slug = $baseSlug !== '' ? $baseSlug : 'collectie';
            $counter = 1;
            while (Collection::query()->where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$counter;
                $counter++;
            }
            $data['slug'] = $slug;
        }

        $collection = Collection::query()->create($data);

        return redirect()->route('catalogue.collections.show', $collection)
            ->with('status', 'Collectie succesvol aangemaakt.');
    }

    public function show(Request $request, Collection $collection): View
    {
        $collection->load(['parent', 'children' => function ($q) {
            $q->withCount('assets')->orderBy('position')->orderBy('title');
        }]);

        $assets = $collection->assets()
            ->with(['files'])
            ->get();

        $allOtherCollections = Collection::query()
            ->where('id', '!=', $collection->id)
            ->orderBy('title')
            ->get();

        return view('catalogue.collections.show', compact('collection', 'assets', 'allOtherCollections'));
    }

    public function edit(Request $request, Collection $collection): View
    {
        $this->checkManagePermission($request);

        $invalidParentIds = array_merge([$collection->id], $collection->allDescendantIds());
        $availableParents = Collection::query()
            ->whereNotIn('id', $invalidParentIds)
            ->orderBy('title')
            ->get();

        return view('catalogue.collections.edit', compact('collection', 'availableParents'));
    }

    public function update(Request $request, Collection $collection): RedirectResponse
    {
        $this->checkManagePermission($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('collections', 'slug')->ignore($collection->id)],
            'collection_type' => ['required', Rule::in(['collection', 'album', 'series', 'theme'])],
            'description' => ['nullable', 'string', 'max:10000'],
            'parent_id' => ['nullable', 'exists:collections,id'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        if (! empty($data['parent_id'])) {
            $invalidParentIds = array_merge([$collection->id], $collection->allDescendantIds());
            if (in_array($data['parent_id'], $invalidParentIds, true)) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Een collectie kan niet onder zichzelf of een subcollectie worden geplaatst.',
                ]);
            }
        }

        $collection->update($data);

        return redirect()->route('catalogue.collections.show', $collection)
            ->with('status', 'Collectie succesvol bijgewerkt.');
    }

    public function destroy(Request $request, Collection $collection): RedirectResponse
    {
        $this->checkManagePermission($request);

        DB::transaction(function () use ($collection): void {
            // Reassign child collections to the deleted collection's parent
            Collection::query()->where('parent_id', $collection->id)->update([
                'parent_id' => $collection->parent_id,
            ]);
            $collection->assets()->detach();
            $collection->delete();
        });

        return redirect()->route('catalogue.collections.index')
            ->with('status', 'Collectie verwijderd.');
    }

    public function addAsset(Request $request, Collection $collection): RedirectResponse
    {
        $this->checkManagePermission($request);

        $data = $request->validate([
            'asset_id' => ['nullable', 'string'],
            'accession_number' => ['nullable', 'string'],
            'position' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $asset = null;
        if (! empty($data['asset_id'])) {
            $asset = Asset::query()->whereKey($data['asset_id'])->first();
        } elseif (! empty($data['accession_number'])) {
            $asset = Asset::query()->where('accession_number', trim((string) $data['accession_number']))->first();
        }

        if ($asset === null) {
            throw ValidationException::withMessages([
                'asset_id' => 'De opgegeven foto kon niet worden gevonden.',
            ]);
        }

        $user = $request->user();
        if ($user === null || ! $user->can('view', $asset) || ! $user->can('update', $asset)) {
            abort(403, 'Je hebt geen toestemming om deze foto te koppelen.');
        }

        $nextPosition = $data['position'] ?? (($collection->assets()->max('collection_assets.position') ?? 0) + 1);

        // Check if already in collection
        if ($collection->assets()->where('assets.id', $asset->id)->exists()) {
            $collection->assets()->updateExistingPivot($asset->id, [
                'position' => $nextPosition,
                'note' => $data['note'] ?? null,
            ]);
        } else {
            $collection->assets()->attach($asset->id, [
                'id' => (string) Str::ulid(),
                'position' => $nextPosition,
                'note' => $data['note'] ?? null,
            ]);
        }

        return redirect()->route('catalogue.collections.show', $collection)
            ->with('status', 'Foto toegevoegd aan collectie.');
    }

    public function removeAsset(Request $request, Collection $collection, Asset $asset): RedirectResponse
    {
        $this->checkManagePermission($request);

        $user = $request->user();
        if ($user === null || ! $user->can('view', $asset) || ! $user->can('update', $asset)) {
            abort(403, 'Je hebt geen toestemming om deze foto te ontkoppelen.');
        }

        $collection->assets()->detach($asset->id);

        return redirect()->route('catalogue.collections.show', $collection)
            ->with('status', 'Foto verwijderd uit collectie.');
    }

    public function moveAsset(Request $request, Collection $collection, Asset $asset): RedirectResponse
    {
        $this->checkManagePermission($request);

        $user = $request->user();
        if ($user === null || ! $user->can('view', $asset) || ! $user->can('update', $asset)) {
            abort(403, 'Je hebt geen toestemming om deze foto te verplaatsen.');
        }

        $data = $request->validate([
            'target_collection_id' => ['required', 'string'],
        ]);

        $targetCollection = Collection::query()->whereKey($data['target_collection_id'])->firstOrFail();

        DB::transaction(function () use ($collection, $targetCollection, $asset): void {
            $existingPivot = DB::table('collection_assets')
                ->where('collection_id', $collection->id)
                ->where('asset_id', $asset->id)
                ->first();

            $collection->assets()->detach($asset->id);

            $targetPosition = ($targetCollection->assets()->max('collection_assets.position') ?? 0) + 1;

            if (! $targetCollection->assets()->where('assets.id', $asset->id)->exists()) {
                $targetCollection->assets()->attach($asset->id, [
                    'id' => (string) Str::ulid(),
                    'position' => $targetPosition,
                    'note' => $existingPivot?->note,
                ]);
            }
        });

        return redirect()->route('catalogue.collections.show', $collection)
            ->with('status', 'Foto verplaatst naar '.$targetCollection->title.'.');
    }

    public function reorder(Request $request, Collection $collection): RedirectResponse
    {
        $this->checkManagePermission($request);

        $data = $request->validate([
            'ordered_asset_ids' => ['required', 'array'],
            'ordered_asset_ids.*' => ['required', 'exists:assets,id'],
        ]);

        DB::transaction(function () use ($collection, $data): void {
            // First assign temporary high numbers to avoid unique constraint collisions
            $pos = 1;
            foreach ($data['ordered_asset_ids'] as $assetId) {
                DB::table('collection_assets')
                    ->where('collection_id', $collection->id)
                    ->where('asset_id', $assetId)
                    ->update(['position' => 10000 + $pos]);
                $pos++;
            }

            $pos = 1;
            foreach ($data['ordered_asset_ids'] as $assetId) {
                DB::table('collection_assets')
                    ->where('collection_id', $collection->id)
                    ->where('asset_id', $assetId)
                    ->update(['position' => $pos]);
                $pos++;
            }
        });

        return redirect()->route('catalogue.collections.show', $collection)
            ->with('status', 'Volgorde van foto’s succesvol bijgewerkt.');
    }

    private function checkManagePermission(Request $request): void
    {
        $user = $request->user();
        if ($user === null || (! $user->hasPermission('collections.manage') && ! $user->hasPermission('assets.update') && ! $user->hasPermission('catalogue.manage'))) {
            abort(403, 'Onvoldoende rechten om collecties te beheren.');
        }
    }
}
