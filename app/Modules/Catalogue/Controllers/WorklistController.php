<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Worklist;
use App\Modules\Catalogue\Models\WorklistItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorklistController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->user($request);

        $worklists = Worklist::query()
            ->with(['createdBy', 'assignedTo'])
            ->withCount([
                'items',
                __('catalogue.generated.t_7f869f4b51edb31b') => fn ($q) => $q->where('status', 'pending'),
                'items as completed_items_count' => fn ($q) => $q->where('status', 'completed'),
            ])
            ->latest('id')
            ->get();

        // Smart queue statistics
        $baseAssetQuery = Asset::query();
        if ($user->cannot('assets.publish')) {
            $baseAssetQuery->where('created_by_user_id', $user->id);
        }

        $stats = [
            'missing_dates' => (clone $baseAssetQuery)->where(function ($q) {
                $q->where('date_precision', 'unknown')->orWhereNull('date_earliest');
            })->count(),
            'missing_rights' => (clone $baseAssetQuery)->whereDoesntHave('rights', function ($q) {
                $q->where('verification_status', 'verified');
            })->count(),
            'missing_identification' => (clone $baseAssetQuery)->doesntHave('people')->doesntHave('locations')->count(),
            'missing_provenance' => (clone $baseAssetQuery)->doesntHave('sources')->doesntHave('contributors')->count(),
        ];

        return view('catalogue.worklists.index', [
            'worklists' => $worklists,
            'stats' => $stats,
        ]);
    }

    public function create(Request $request): View
    {
        $users = User::query()->orderBy('name')->get();

        return view('catalogue.worklists.create', [
            'users' => $users,
            'defaultType' => $request->input('type', 'custom'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'worklist_type' => ['required', 'string', 'in:missing_date,missing_rights,missing_identification,missing_provenance,custom'],
            'description' => ['nullable', 'string', 'max:2000'],
            'assigned_to_user_id' => ['nullable', 'exists:users,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $limit = (int) ($validated['limit'] ?? 50);

        /** @var Worklist $worklist */
        $worklist = Worklist::query()->create([
            'title' => $validated['title'],
            'worklist_type' => $validated['worklist_type'],
            'description' => $validated['description'] ?? null,
            'created_by_user_id' => $user->id,
            'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
            'status' => 'active',
        ]);

        // Auto-populate based on criteria
        $assetsQuery = Asset::query();
        if ($user->cannot('assets.publish')) {
            $assetsQuery->where('created_by_user_id', $user->id);
        }

        switch ($validated['worklist_type']) {
            case 'missing_date':
                $assetsQuery->where(function ($q) {
                    $q->where('date_precision', 'unknown')->orWhereNull('date_earliest');
                });
                break;
            case 'missing_rights':
                $assetsQuery->whereDoesntHave('rights', function ($q) {
                    $q->where('verification_status', 'verified');
                });
                break;
            case 'missing_identification':
                $assetsQuery->doesntHave('people')->doesntHave('locations');
                break;
            case 'missing_provenance':
                $assetsQuery->doesntHave('sources')->doesntHave('contributors');
                break;
        }

        if ($validated['worklist_type'] !== 'custom') {
            $matchingAssetIds = $assetsQuery->latest('id')->limit($limit)->pluck('id');
            foreach ($matchingAssetIds as $assetId) {
                WorklistItem::query()->create([
                    'worklist_id' => $worklist->id,
                    'asset_id' => $assetId,
                    'status' => 'pending',
                ]);
            }
        }

        return redirect()->route('catalogue.worklists.show', $worklist)->with('status', __('catalogue.generated.t_9b8ff9622b99226f').$worklist->items()->count().' items.');
    }

    public function show(Request $request, Worklist $worklist): View
    {
        $user = $this->user($request);
        $worklist->load(['createdBy', 'assignedTo']);

        $statusFilter = $request->input('status');

        $itemsQuery = $worklist->items()->with(['asset.files', 'completedBy']);
        if ($statusFilter && in_array($statusFilter, ['pending', 'in_progress', 'completed', 'skipped'], true)) {
            $itemsQuery->where('status', $statusFilter);
        }

        // Hide items for assets the user cannot view
        if ($user->cannot('assets.publish')) {
            $itemsQuery->whereHas('asset', fn ($q) => $q->where('created_by_user_id', $user->id));
        }

        $items = $itemsQuery->latest('id')->get();

        return view('catalogue.worklists.show', [
            'worklist' => $worklist,
            'items' => $items,
            'statusFilter' => $statusFilter,
        ]);
    }

    public function edit(Worklist $worklist): View
    {
        $users = User::query()->orderBy('name')->get();

        return view('catalogue.worklists.edit', [
            'worklist' => $worklist,
            'users' => $users,
        ]);
    }

    public function update(Request $request, Worklist $worklist): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'string', 'in:active,in_progress,completed,archived'],
            'assigned_to_user_id' => ['nullable', 'exists:users,id'],
        ]);

        $worklist->update($validated);

        return redirect()->route('catalogue.worklists.show', $worklist)->with('status', __('catalogue.generated.t_ddf536ab7a1cdd9d'));
    }

    public function destroy(Worklist $worklist): RedirectResponse
    {
        $worklist->delete();

        return redirect()->route('catalogue.worklists.index')->with('status', __('catalogue.generated.t_97624768773aa55c'));
    }

    public function updateItem(Request $request, Worklist $worklist, WorklistItem $item): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($item->worklist_id === $worklist->id, 404);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:pending,in_progress,completed,skipped'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $isCompleted = $validated['status'] === 'completed';

        $item->update([
            'status' => $validated['status'],
            'note' => $validated['note'] ?? $item->note,
            'completed_at' => $isCompleted ? now() : null,
            'completed_by_user_id' => $isCompleted ? $user->id : null,
        ]);

        // Check if all items in worklist are now completed
        $remaining = $worklist->items()->whereNotIn('status', ['completed', 'skipped'])->count();
        if ($remaining === 0 && $worklist->items()->count() > 0) {
            $worklist->update(['status' => 'completed']);
        }

        return back()->with('status', __('catalogue.generated.t_b13263aff3fe29f1'));
    }

    public function addAssets(Request $request, Worklist $worklist): RedirectResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'asset_id' => ['required', 'string', 'exists:assets,id'],
        ]);

        /** @var Asset $asset */
        $asset = Asset::query()->whereKey($validated['asset_id'])->firstOrFail();
        if ($user->cannot('view', $asset)) {
            abort(403, __('catalogue.generated.t_33884812ebb5b3c7'));
        }

        if (! $worklist->items()->where('asset_id', $asset->id)->exists()) {
            WorklistItem::query()->create([
                'worklist_id' => $worklist->id,
                'asset_id' => $asset->id,
                'status' => 'pending',
            ]);
        }

        return back()->with('status', __('catalogue.generated.t_6f75fdb8e4c2b8fb'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
