<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Location;
use App\Modules\Catalogue\Models\LocationAlias;
use App\Modules\Catalogue\Services\AssetReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LocationController extends Controller
{
    public function index(Request $request): View
    {
        $query = Location::query()->with(['parent'])->withCount(['children', 'aliases', 'assets'])->orderBy('name');

        if ($type = $request->query('location_type')) {
            $query->where('location_type', $type);
        }

        if ($search = $request->string('q')->trim()->value()) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhereHas('aliases', fn ($aq) => $aq->where('name', 'like', '%'.$search.'%'));
            });
        }

        $locations = $query->paginate(30)->withQueryString();

        return view('catalogue.locations.index', compact('locations'));
    }

    public function create(Request $request): View
    {
        $this->checkManagePermission($request);
        $allLocations = Location::query()->orderBy('name')->get();
        $parentId = $request->query('parent_id');

        return view('catalogue.locations.create', compact('allLocations', 'parentId'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->checkManagePermission($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'location_type' => ['required', Rule::in(['country', 'province', 'municipality', 'city', 'neighbourhood', 'street', 'building', 'landmark', 'place'])],
            'historical_period' => ['nullable', 'string', 'max:100'],
            'parent_id' => ['nullable', 'exists:locations,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:10000'],
            'aliases' => ['nullable', 'string', 'max:2000'],
        ]);

        $normalizedName = Str::lower(trim($data['name']));
        $parentId = ! empty($data['parent_id']) ? $data['parent_id'] : null;

        $exists = Location::query()
            ->where('parent_id', $parentId)
            ->where('normalized_name', $normalizedName)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => __('catalogue.generated.t_f244b3ce8162fd88'),
            ]);
        }

        $location = DB::transaction(function () use ($data, $normalizedName, $parentId): Location {
            $loc = Location::query()->create([
                'name' => $data['name'],
                'normalized_name' => $normalizedName,
                'location_type' => $data['location_type'],
                'historical_period' => $data['historical_period'] ?? null,
                'parent_id' => $parentId,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'description' => $data['description'] ?? null,
            ]);

            $this->syncAliases($loc, $data['aliases'] ?? '');

            return $loc;
        });

        return redirect()->route('catalogue.locations.show', $location)
            ->with('status', __('catalogue.generated.t_e3263e192f6daec1'));
    }

    public function show(Request $request, Location $location): View
    {
        $location->load(['parent', 'aliases', 'children' => function ($q) {
            $q->withCount('assets')->orderBy('name');
        }]);

        $assets = $location->assets()->with(['files'])->get();

        return view('catalogue.locations.show', compact('location', 'assets'));
    }

    public function edit(Request $request, Location $location): View
    {
        $this->checkManagePermission($request);
        $location->load('aliases');

        $invalidParentIds = array_merge([$location->id], $location->allDescendantIds());
        $availableParents = Location::query()
            ->whereNotIn('id', $invalidParentIds)
            ->orderBy('name')
            ->get();

        return view('catalogue.locations.edit', compact('location', 'availableParents'));
    }

    public function update(Request $request, Location $location): RedirectResponse
    {
        $this->checkManagePermission($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'location_type' => ['required', Rule::in(['country', 'province', 'municipality', 'city', 'neighbourhood', 'street', 'building', 'landmark', 'place'])],
            'historical_period' => ['nullable', 'string', 'max:100'],
            'parent_id' => ['nullable', 'exists:locations,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:10000'],
            'aliases' => ['nullable', 'string', 'max:2000'],
        ]);

        $parentId = ! empty($data['parent_id']) ? $data['parent_id'] : null;
        if ($parentId !== null) {
            $invalidParentIds = array_merge([$location->id], $location->allDescendantIds());
            if (in_array($parentId, $invalidParentIds, true)) {
                throw ValidationException::withMessages([
                    'parent_id' => __('catalogue.generated.t_e230ebfa26a95d0a'),
                ]);
            }
        }

        $normalizedName = Str::lower(trim($data['name']));
        $exists = Location::query()
            ->where('id', '!=', $location->id)
            ->where('parent_id', $parentId)
            ->where('normalized_name', $normalizedName)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => __('catalogue.generated.t_98fc690a3c9ae9fe'),
            ]);
        }

        DB::transaction(function () use ($location, $data, $normalizedName, $parentId): void {
            $location->update([
                'name' => $data['name'],
                'normalized_name' => $normalizedName,
                'location_type' => $data['location_type'],
                'historical_period' => $data['historical_period'] ?? null,
                'parent_id' => $parentId,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'description' => $data['description'] ?? null,
            ]);

            $this->syncAliases($location, $data['aliases'] ?? '');
        });

        return redirect()->route('catalogue.locations.show', $location)
            ->with('status', __('catalogue.generated.t_49dc77ac39655ebd'));
    }

    public function destroy(Request $request, Location $location): RedirectResponse
    {
        $this->checkManagePermission($request);

        DB::transaction(function () use ($location): void {
            Location::query()->where('parent_id', $location->id)->update([
                'parent_id' => $location->parent_id,
            ]);
            $location->assets()->detach();
            $location->aliases()->delete();
            $location->delete();
        });

        return redirect()->route('catalogue.locations.index')
            ->with('status', __('catalogue.generated.t_2955d8e616ad67ed'));
    }

    public function addAsset(Request $request, Location $location): RedirectResponse
    {
        $this->checkManagePermission($request);

        $data = $request->validate([
            'asset_id' => ['nullable', 'string'],
            'accession_number' => ['nullable', 'string'],
            'relationship_type' => ['required', Rule::in(['depicted_place', 'creation_place', 'subject_location', 'origin', 'destination', 'other'])],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'verification_status' => ['required', Rule::in(['unverified', 'verified', 'disputed'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $asset = AssetReference::resolve($data['asset_id'] ?? null, $data['accession_number'] ?? null);

        if ($asset === null) {
            throw ValidationException::withMessages([
                'asset_id' => __('catalogue.generated.t_535f3aaae9765ff2'),
            ]);
        }

        $user = $request->user();
        if ($user === null || ! $user->can('view', $asset) || ! $user->can('update', $asset)) {
            abort(403, __('catalogue.generated.t_2159d6168feb9347'));
        }

        $existing = DB::table('asset_locations')
            ->where('location_id', $location->id)
            ->where('asset_id', $asset->id)
            ->where('relationship_type', $data['relationship_type'])
            ->first();

        if ($existing !== null) {
            DB::table('asset_locations')->where('id', $existing->id)->update([
                'confidence' => $data['confidence'] ?? null,
                'verification_status' => $data['verification_status'],
                'note' => $data['note'] ?? null,
                'updated_at' => now(),
            ]);
        } else {
            $location->assets()->attach($asset->id, [
                'id' => (string) Str::ulid(),
                'relationship_type' => $data['relationship_type'],
                'confidence' => $data['confidence'] ?? null,
                'verification_status' => $data['verification_status'],
                'note' => $data['note'] ?? null,
            ]);
        }

        return redirect()->route('catalogue.locations.show', $location)
            ->with('status', __('catalogue.generated.t_30c0b3183e73f598'));
    }

    public function removeAsset(Request $request, Location $location, Asset $asset): RedirectResponse
    {
        $this->checkManagePermission($request);

        $user = $request->user();
        if ($user === null || ! $user->can('view', $asset) || ! $user->can('update', $asset)) {
            abort(403, __('catalogue.generated.t_01f6c1ce25102b10'));
        }

        $relationshipType = $request->query('relationship_type');
        if ($relationshipType) {
            DB::table('asset_locations')
                ->where('location_id', $location->id)
                ->where('asset_id', $asset->id)
                ->where('relationship_type', $relationshipType)
                ->delete();
        } else {
            $location->assets()->detach($asset->id);
        }

        return redirect()->route('catalogue.locations.show', $location)
            ->with('status', __('catalogue.generated.t_d2f077dd2df74867'));
    }

    private function syncAliases(Location $location, string $rawAliases): void
    {
        $aliasNames = collect(preg_split('/[\r\n,]+/', $rawAliases) ?: [])
            ->map(fn ($name) => trim((string) $name))
            ->filter(fn ($name) => $name !== '')
            ->unique()
            ->values();

        $location->aliases()->delete();

        foreach ($aliasNames as $alias) {
            LocationAlias::query()->create([
                'location_id' => $location->id,
                'name' => $alias,
                'normalized_name' => Str::lower($alias),
                'alias_type' => 'historical',
            ]);
        }
    }

    private function checkManagePermission(Request $request): void
    {
        $user = $request->user();
        if ($user === null || (! $user->hasPermission('catalogue.manage') && ! $user->hasPermission('assets.update'))) {
            abort(403, __('catalogue.generated.t_a451388402802289'));
        }
    }
}
