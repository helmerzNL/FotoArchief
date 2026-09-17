<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ArchiveOperations\Jobs\CleanupOrphanUploadsJob;
use App\Modules\ArchiveOperations\Jobs\PurgeAssetsJob;
use App\Modules\ArchiveOperations\Models\TrashPurgeLog;
use App\Modules\ArchiveOperations\Services\OperationRunService;
use App\Modules\ArchiveOperations\Services\TrashService;
use App\Modules\Catalogue\Models\Asset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrashController extends Controller
{
    public function __construct(
        private readonly TrashService $trashService,
        private readonly OperationRunService $runService,
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

        $runs = $this->runService->recentRuns(10);

        return view('operations.trash.index', compact('summary', 'trashedAssets', 'recentPurges', 'runs'));
    }

    public function trash(Request $request, Asset $asset): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('catalogue.manage') || $user->hasPermission('users.manage')), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        // Trashing and restoring only set a column; no file is read or written.
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
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'confirm_purge' => ['required', 'accepted'],
        ]);

        $asset = Asset::onlyTrashed()->where('id', $assetId)->firstOrFail();

        // Destroying archive bytes is queued so the operator keeps a status, an error
        // and a retry instead of losing the outcome to a dropped connection.
        $this->runService->dispatchRun(
            PurgeAssetsJob::class,
            PurgeAssetsJob::TYPE,
            $user,
            ['asset_id' => $asset->id, 'reason' => $validated['reason']],
            1,
        );

        return redirect()
            ->route('admin.operations.trash.index')
            ->with('status', "Definitieve verwijdering van {$asset->accession_number} is in de wachtrij geplaatst.");
    }

    public function purgeExpired(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);

        $validated = $request->validate([
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $days = (int) ($validated['retention_days'] ?? TrashService::DEFAULT_RETENTION_DAYS);

        $run = $this->runService->dispatchRun(
            PurgeAssetsJob::class,
            PurgeAssetsJob::TYPE,
            $user,
            [
                'retention_days' => $days,
                'reason' => "Bewaartermijn van {$days} dagen verlopen (automatische purge).",
            ],
        );

        return redirect()
            ->route('admin.operations.trash.index')
            ->with('status', "Opschoning van verlopen items ouder dan {$days} dagen is gestart (taak {$run->id}).");
    }

    public function cleanupOrphans(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);

        $this->runService->dispatchRun(
            CleanupOrphanUploadsJob::class,
            CleanupOrphanUploadsJob::TYPE,
            $user,
            ['retention_hours' => 48],
        );

        return redirect()
            ->route('admin.operations.trash.index')
            ->with('status', __('operations.generated.t_1bf0b5fe2963cf0f'));
    }
}
