<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Contributor;
use App\Modules\Catalogue\Services\AssetReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ContributorController extends Controller
{
    public function index(Request $request): View
    {
        $query = Contributor::query()->withCount('assets')->orderBy('name');

        if ($type = $request->query('contributor_type')) {
            $query->where('contributor_type', $type);
        }

        if ($search = $request->string('q')->trim()->value()) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        $contributors = $query->paginate(30)->withQueryString();

        return view('catalogue.contributors.index', compact('contributors'));
    }

    public function create(Request $request): View
    {
        $this->checkManagePermission($request);

        return view('catalogue.contributors.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->checkManagePermission($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contributor_type' => ['required', Rule::in(['individual', 'organisation', 'donor', 'photographer', 'collector'])],
            'email' => ['nullable', 'email', 'max:320'],
            'contact_details' => ['nullable', 'string', 'max:10000'],
            'note' => ['nullable', 'string', 'max:10000'],
        ]);

        $contributor = Contributor::query()->create($data);

        return redirect()->route('catalogue.contributors.show', $contributor)
            ->with('status', __('catalogue.generated.t_2918018ee464b415'));
    }

    public function show(Request $request, Contributor $contributor): View
    {
        $assets = $contributor->assets()->with(['files'])->get();

        return view('catalogue.contributors.show', compact('contributor', 'assets'));
    }

    public function edit(Request $request, Contributor $contributor): View
    {
        $this->checkManagePermission($request);

        return view('catalogue.contributors.edit', compact('contributor'));
    }

    public function update(Request $request, Contributor $contributor): RedirectResponse
    {
        $this->checkManagePermission($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contributor_type' => ['required', Rule::in(['individual', 'organisation', 'donor', 'photographer', 'collector'])],
            'email' => ['nullable', 'email', 'max:320'],
            'contact_details' => ['nullable', 'string', 'max:10000'],
            'note' => ['nullable', 'string', 'max:10000'],
        ]);

        $contributor->update($data);

        return redirect()->route('catalogue.contributors.show', $contributor)
            ->with('status', __('catalogue.generated.t_8477f90e69ea3c1e'));
    }

    public function destroy(Request $request, Contributor $contributor): RedirectResponse
    {
        $this->checkManagePermission($request);

        DB::transaction(function () use ($contributor): void {
            $contributor->assets()->detach();
            $contributor->delete();
        });

        return redirect()->route('catalogue.contributors.index')
            ->with('status', __('catalogue.generated.t_43e14ec1b0897208'));
    }

    public function addAsset(Request $request, Contributor $contributor): RedirectResponse
    {
        $this->checkManagePermission($request);

        $data = $request->validate([
            'asset_id' => ['nullable', 'string'],
            'accession_number' => ['nullable', 'string'],
            'relationship_type' => ['required', Rule::in(['donor', 'photographer', 'creator', 'collector', 'contact', 'other'])],
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

        $existing = DB::table('asset_contributors')
            ->where('contributor_id', $contributor->id)
            ->where('asset_id', $asset->id)
            ->where('relationship_type', $data['relationship_type'])
            ->first();

        if ($existing !== null) {
            DB::table('asset_contributors')->where('id', $existing->id)->update([
                'confidence' => $data['confidence'] ?? null,
                'verification_status' => $data['verification_status'],
                'note' => $data['note'] ?? null,
                'updated_at' => now(),
            ]);
        } else {
            $contributor->assets()->attach($asset->id, [
                'id' => (string) Str::ulid(),
                'relationship_type' => $data['relationship_type'],
                'confidence' => $data['confidence'] ?? null,
                'verification_status' => $data['verification_status'],
                'note' => $data['note'] ?? null,
            ]);
        }

        return redirect()->route('catalogue.contributors.show', $contributor)
            ->with('status', __('catalogue.generated.t_5c5977d644e3cedd'));
    }

    public function removeAsset(Request $request, Contributor $contributor, Asset $asset): RedirectResponse
    {
        $this->checkManagePermission($request);

        $user = $request->user();
        if ($user === null || ! $user->can('view', $asset) || ! $user->can('update', $asset)) {
            abort(403, __('catalogue.generated.t_01f6c1ce25102b10'));
        }

        $relationshipType = $request->query('relationship_type');
        if ($relationshipType) {
            DB::table('asset_contributors')
                ->where('contributor_id', $contributor->id)
                ->where('asset_id', $asset->id)
                ->where('relationship_type', $relationshipType)
                ->delete();
        } else {
            $contributor->assets()->detach($asset->id);
        }

        return redirect()->route('catalogue.contributors.show', $contributor)
            ->with('status', __('catalogue.generated.t_2dfe5d4490877d08'));
    }

    private function checkManagePermission(Request $request): void
    {
        $user = $request->user();
        if ($user === null || (! $user->hasPermission('catalogue.manage') && ! $user->hasPermission('assets.update'))) {
            abort(403, __('catalogue.generated.t_68e4850343171653'));
        }
    }
}
