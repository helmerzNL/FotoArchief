<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Source;
use App\Modules\Catalogue\Services\AssetReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SourceController extends Controller
{
    public function index(Request $request): View
    {
        $query = Source::query()->withCount('assets')->orderBy('name');

        if ($type = $request->query('source_type')) {
            $query->where('source_type', $type);
        }

        if ($search = $request->string('q')->trim()->value()) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('reference_code', 'like', '%'.$search.'%');
            });
        }

        $sources = $query->paginate(30)->withQueryString();

        return view('catalogue.sources.index', compact('sources'));
    }

    public function create(Request $request): View
    {
        $this->checkManagePermission($request);

        return view('catalogue.sources.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->checkManagePermission($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'source_type' => ['required', Rule::in(['archive', 'donor', 'institution', 'collection', 'family', 'other'])],
            'reference_code' => ['nullable', 'string', 'max:255'],
            'acquisition_date' => ['nullable', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:10000'],
            'custody_history' => ['nullable', 'string', 'max:10000'],
        ]);

        $source = Source::query()->create($data);

        return redirect()->route('catalogue.sources.show', $source)
            ->with('status', 'Herkomstbron succesvol geregistreerd.');
    }

    public function show(Request $request, Source $source): View
    {
        $assets = $source->assets()->with(['files'])->get();

        return view('catalogue.sources.show', compact('source', 'assets'));
    }

    public function edit(Request $request, Source $source): View
    {
        $this->checkManagePermission($request);

        return view('catalogue.sources.edit', compact('source'));
    }

    public function update(Request $request, Source $source): RedirectResponse
    {
        $this->checkManagePermission($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'source_type' => ['required', Rule::in(['archive', 'donor', 'institution', 'collection', 'family', 'other'])],
            'reference_code' => ['nullable', 'string', 'max:255'],
            'acquisition_date' => ['nullable', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:10000'],
            'custody_history' => ['nullable', 'string', 'max:10000'],
        ]);

        $source->update($data);

        return redirect()->route('catalogue.sources.show', $source)
            ->with('status', 'Herkomstbron bijgewerkt.');
    }

    public function destroy(Request $request, Source $source): RedirectResponse
    {
        $this->checkManagePermission($request);

        DB::transaction(function () use ($source): void {
            $source->assets()->detach();
            $source->delete();
        });

        return redirect()->route('catalogue.sources.index')
            ->with('status', 'Herkomstbron verwijderd.');
    }

    public function addAsset(Request $request, Source $source): RedirectResponse
    {
        $this->checkManagePermission($request);

        $data = $request->validate([
            'asset_id' => ['nullable', 'string'],
            'accession_number' => ['nullable', 'string'],
            'relationship_type' => ['required', Rule::in(['provenance', 'donor', 'custody', 'acquisition', 'deposit', 'other'])],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'verification_status' => ['required', Rule::in(['unverified', 'verified', 'disputed'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $asset = AssetReference::resolve($data['asset_id'] ?? null, $data['accession_number'] ?? null);

        if ($asset === null) {
            throw ValidationException::withMessages([
                'asset_id' => 'De opgegeven foto kon niet worden gevonden.',
            ]);
        }

        $user = $request->user();
        if ($user === null || ! $user->can('view', $asset) || ! $user->can('update', $asset)) {
            abort(403, 'Je hebt geen toestemming om deze foto te koppelen.');
        }

        $existing = DB::table('asset_sources')
            ->where('source_id', $source->id)
            ->where('asset_id', $asset->id)
            ->where('relationship_type', $data['relationship_type'])
            ->first();

        if ($existing !== null) {
            DB::table('asset_sources')->where('id', $existing->id)->update([
                'confidence' => $data['confidence'] ?? null,
                'verification_status' => $data['verification_status'],
                'note' => $data['note'] ?? null,
                'updated_at' => now(),
            ]);
        } else {
            $source->assets()->attach($asset->id, [
                'id' => (string) Str::ulid(),
                'relationship_type' => $data['relationship_type'],
                'confidence' => $data['confidence'] ?? null,
                'verification_status' => $data['verification_status'],
                'note' => $data['note'] ?? null,
            ]);
        }

        return redirect()->route('catalogue.sources.show', $source)
            ->with('status', 'Foto succesvol gekoppeld aan herkomstbron.');
    }

    public function removeAsset(Request $request, Source $source, Asset $asset): RedirectResponse
    {
        $this->checkManagePermission($request);

        $user = $request->user();
        if ($user === null || ! $user->can('view', $asset) || ! $user->can('update', $asset)) {
            abort(403, 'Je hebt geen toestemming om deze foto te ontkoppelen.');
        }

        $relationshipType = $request->query('relationship_type');
        if ($relationshipType) {
            DB::table('asset_sources')
                ->where('source_id', $source->id)
                ->where('asset_id', $asset->id)
                ->where('relationship_type', $relationshipType)
                ->delete();
        } else {
            $source->assets()->detach($asset->id);
        }

        return redirect()->route('catalogue.sources.show', $source)
            ->with('status', 'Fotokoppeling verwijderd.');
    }

    private function checkManagePermission(Request $request): void
    {
        $user = $request->user();
        if ($user === null || (! $user->hasPermission('catalogue.manage') && ! $user->hasPermission('assets.update'))) {
            abort(403, 'Onvoldoende rechten om herkomstbronnen te beheren.');
        }
    }
}
