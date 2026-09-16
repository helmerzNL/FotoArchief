<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ArchiveOperations\Models\TrashPurgeLog;
use App\Modules\ArchiveOperations\Services\TrashService;
use App\Modules\Catalogue\Models\Asset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrashController extends Controller
{
    public function __construct(
        private readonly TrashService $trashService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('catalogue.manage') || $user->hasPermission('users.manage')), 403);

        $summary = $this->trashService->getTrashSummary();
        $trashedAssets = Asset::onlyTrashed()
            ->with(['deletedBy', 'files'])
            ->latest('deleted_at')
            ->paginate(15);

        $recentPurges = TrashPurgeLog::query()
            ->with('purgedBy')
            ->latest('created_at')
            ->limit(10)
            ->get();

        return view('operations.trash.index', compact('summary', 'trashedAssets', 'recentPurges'));
    }

    public function trash(Request $request, Asset $asset): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('catalogue.manage') || $user->hasPermission('users.manage')), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->trashService->moveToTrash($asset, $validated['reason'], $user);

        return redirect()
            ->back()
            ->with('status', "Asset {$asset->accession_number} is verplaatst naar de prullenbak.");
    }

    public function restore(Request $request, string $assetId): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('catalogue.manage') || $user->hasPermission('users.manage')), 403);

        $asset = Asset::onlyTrashed()->where('id', $assetId)->firstOrFail();
        $this->trashService->restoreFromTrash($asset, $user);

        return redirect()
            ->route('admin.operations.trash.index')
            ->with('status', "Asset {$asset->accession_number} is succesvol hersteld.");
    }

    public function purge(Request $request, string $assetId): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('users.manage')), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'confirm_purge' => ['required', 'accepted'],
        ]);

        $asset = Asset::onlyTrashed()->where('id', $assetId)->firstOrFail();
        $accession = $asset->accession_number;

        $this->trashService->purgeAsset($asset, $validated['reason'], $user);

        return redirect()
            ->route('admin.operations.trash.index')
            ->with('status', "Asset {$accession} is definitief en onherroepelijk verwijderd (gepurged).");
    }

    public function purgeExpired(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('users.manage')), 403);

        $validated = $request->validate([
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $days = (int) ($validated['retention_days'] ?? TrashService::DEFAULT_RETENTION_DAYS);
        $count = $this->trashService->purgeExpired($days, $user);

        return redirect()
            ->route('admin.operations.trash.index')
            ->with('status', "{$count} verlopen items ouder dan {$days} dagen zijn definitief verwijderd.");
    }

    public function cleanupOrphans(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('users.manage')), 403);

        $count = $this->trashService->cleanupOrphanQuarantineUploads(48, $user);

        return redirect()
            ->route('admin.operations.trash.index')
            ->with('status', "{$count} wees-quarantaine bestanden zijn opgeruimd.");
    }
}
