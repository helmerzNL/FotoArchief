<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Person;
use App\Modules\Catalogue\Models\PersonAlias;
use App\Modules\Catalogue\Services\AssetReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PersonController extends Controller
{
    public function index(Request $request): View
    {
        $query = Person::query()->withCount(['aliases', 'assets'])->orderBy('sort_name')->orderBy('display_name');

        if ($type = $request->query('entity_type')) {
            if (in_array($type, ['person', 'organisation'], true)) {
                $query->where('entity_type', $type);
            }
        }

        if ($search = $request->string('q')->trim()->value()) {
            $query->where(function ($q) use ($search): void {
                $q->where('display_name', 'like', '%'.$search.'%')
                    ->orWhere('sort_name', 'like', '%'.$search.'%')
                    ->orWhereHas('aliases', fn ($aq) => $aq->where('name', 'like', '%'.$search.'%'));
            });
        }

        $people = $query->paginate(30)->withQueryString();

        return view('catalogue.people.index', compact('people'));
    }

    public function create(Request $request): View
    {
        $this->checkManagePermission($request);

        return view('catalogue.people.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->checkManagePermission($request);

        $data = $request->validate([
            'entity_type' => ['required', Rule::in(['person', 'organisation'])],
            'display_name' => ['required', 'string', 'max:255'],
            'sort_name' => ['nullable', 'string', 'max:255'],
            'birth_date_earliest' => ['nullable', 'date_format:Y-m-d'],
            'birth_date_latest' => ['nullable', 'date_format:Y-m-d'],
            'birth_date_precision' => ['required', Rule::in(['exact', 'circa', 'year', 'decade', 'range', 'before', 'after', 'unknown'])],
            'death_date_earliest' => ['nullable', 'date_format:Y-m-d'],
            'death_date_latest' => ['nullable', 'date_format:Y-m-d'],
            'death_date_precision' => ['required', Rule::in(['exact', 'circa', 'year', 'decade', 'range', 'before', 'after', 'unknown'])],
            'biographical_note' => ['nullable', 'string', 'max:10000'],
            'aliases' => ['nullable', 'string', 'max:2000'],
        ]);

        if (empty($data['sort_name'])) {
            $data['sort_name'] = $data['display_name'];
        }

        $person = DB::transaction(function () use ($data): Person {
            $person = Person::query()->create([
                'entity_type' => $data['entity_type'],
                'display_name' => $data['display_name'],
                'sort_name' => $data['sort_name'],
                'birth_date_earliest' => $data['birth_date_earliest'] ?? null,
                'birth_date_latest' => $data['birth_date_latest'] ?? null,
                'birth_date_precision' => $data['birth_date_precision'],
                'death_date_earliest' => $data['death_date_earliest'] ?? null,
                'death_date_latest' => $data['death_date_latest'] ?? null,
                'death_date_precision' => $data['death_date_precision'],
                'biographical_note' => $data['biographical_note'] ?? null,
            ]);

            $this->syncAliases($person, $data['aliases'] ?? '');

            return $person;
        });

        return redirect()->route('catalogue.people.show', $person)
            ->with('status', ($person->entity_type === 'organisation' ? 'Organisatie' : 'Persoon').__('catalogue.generated.t_1939139e9a090398'));
    }

    public function show(Request $request, Person $person): View
    {
        $person->load(['aliases']);
        $assets = $person->assets()->with(['files'])->get();

        return view('catalogue.people.show', compact('person', 'assets'));
    }

    public function edit(Request $request, Person $person): View
    {
        $this->checkManagePermission($request);
        $person->load('aliases');

        return view('catalogue.people.edit', compact('person'));
    }

    public function update(Request $request, Person $person): RedirectResponse
    {
        $this->checkManagePermission($request);

        $data = $request->validate([
            'entity_type' => ['required', Rule::in(['person', 'organisation'])],
            'display_name' => ['required', 'string', 'max:255'],
            'sort_name' => ['nullable', 'string', 'max:255'],
            'birth_date_earliest' => ['nullable', 'date_format:Y-m-d'],
            'birth_date_latest' => ['nullable', 'date_format:Y-m-d'],
            'birth_date_precision' => ['required', Rule::in(['exact', 'circa', 'year', 'decade', 'range', 'before', 'after', 'unknown'])],
            'death_date_earliest' => ['nullable', 'date_format:Y-m-d'],
            'death_date_latest' => ['nullable', 'date_format:Y-m-d'],
            'death_date_precision' => ['required', Rule::in(['exact', 'circa', 'year', 'decade', 'range', 'before', 'after', 'unknown'])],
            'biographical_note' => ['nullable', 'string', 'max:10000'],
            'aliases' => ['nullable', 'string', 'max:2000'],
        ]);

        if (empty($data['sort_name'])) {
            $data['sort_name'] = $data['display_name'];
        }

        DB::transaction(function () use ($person, $data): void {
            $person->update([
                'entity_type' => $data['entity_type'],
                'display_name' => $data['display_name'],
                'sort_name' => $data['sort_name'],
                'birth_date_earliest' => $data['birth_date_earliest'] ?? null,
                'birth_date_latest' => $data['birth_date_latest'] ?? null,
                'birth_date_precision' => $data['birth_date_precision'],
                'death_date_earliest' => $data['death_date_earliest'] ?? null,
                'death_date_latest' => $data['death_date_latest'] ?? null,
                'death_date_precision' => $data['death_date_precision'],
                'biographical_note' => $data['biographical_note'] ?? null,
            ]);

            $this->syncAliases($person, $data['aliases'] ?? '');
        });

        return redirect()->route('catalogue.people.show', $person)
            ->with('status', __('catalogue.generated.t_b0839a2ff05eb9f8'));
    }

    public function destroy(Request $request, Person $person): RedirectResponse
    {
        $this->checkManagePermission($request);

        DB::transaction(function () use ($person): void {
            $person->assets()->detach();
            $person->aliases()->delete();
            $person->delete();
        });

        return redirect()->route('catalogue.people.index')
            ->with('status', __('catalogue.generated.t_5ff3537e392e6949'));
    }

    public function addAsset(Request $request, Person $person): RedirectResponse
    {
        $this->checkManagePermission($request);

        $data = $request->validate([
            'asset_id' => ['nullable', 'string'],
            'accession_number' => ['nullable', 'string'],
            'relationship_type' => ['required', Rule::in(['depicted', 'photographer', 'subject', 'mentioned', 'creator', 'publisher', 'other'])],
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

        // Check if relationship already exists
        $existing = DB::table('asset_people')
            ->where('person_id', $person->id)
            ->where('asset_id', $asset->id)
            ->where('relationship_type', $data['relationship_type'])
            ->first();

        if ($existing !== null) {
            DB::table('asset_people')->where('id', $existing->id)->update([
                'confidence' => $data['confidence'] ?? null,
                'verification_status' => $data['verification_status'],
                'note' => $data['note'] ?? null,
                'updated_at' => now(),
            ]);
        } else {
            $person->assets()->attach($asset->id, [
                'id' => (string) Str::ulid(),
                'relationship_type' => $data['relationship_type'],
                'confidence' => $data['confidence'] ?? null,
                'verification_status' => $data['verification_status'],
                'note' => $data['note'] ?? null,
            ]);
        }

        return redirect()->route('catalogue.people.show', $person)
            ->with('status', __('catalogue.generated.t_255d48686deb8b92'));
    }

    public function removeAsset(Request $request, Person $person, Asset $asset): RedirectResponse
    {
        $this->checkManagePermission($request);

        $user = $request->user();
        if ($user === null || ! $user->can('view', $asset) || ! $user->can('update', $asset)) {
            abort(403, __('catalogue.generated.t_01f6c1ce25102b10'));
        }

        $relationshipType = $request->query('relationship_type');
        if ($relationshipType) {
            DB::table('asset_people')
                ->where('person_id', $person->id)
                ->where('asset_id', $asset->id)
                ->where('relationship_type', $relationshipType)
                ->delete();
        } else {
            $person->assets()->detach($asset->id);
        }

        return redirect()->route('catalogue.people.show', $person)
            ->with('status', __('catalogue.generated.t_2dfe5d4490877d08'));
    }

    private function syncAliases(Person $person, string $rawAliases): void
    {
        $aliasNames = collect(preg_split('/[\r\n,]+/', $rawAliases) ?: [])
            ->map(fn ($name) => trim((string) $name))
            ->filter(fn ($name) => $name !== '')
            ->unique()
            ->values();

        $person->aliases()->delete();

        foreach ($aliasNames as $alias) {
            PersonAlias::query()->create([
                'person_id' => $person->id,
                'name' => $alias,
                'normalized_name' => Str::lower($alias),
                'alias_type' => 'alternate',
            ]);
        }
    }

    private function checkManagePermission(Request $request): void
    {
        $user = $request->user();
        if ($user === null || (! $user->hasPermission('catalogue.manage') && ! $user->hasPermission('assets.update'))) {
            abort(403, __('catalogue.generated.t_3e661e37cc623c25'));
        }
    }
}
