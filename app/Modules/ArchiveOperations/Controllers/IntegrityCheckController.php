<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ArchiveOperations\Jobs\RebuildDerivativesJob;
use App\Modules\ArchiveOperations\Jobs\VerifyIntegrityJob;
use App\Modules\ArchiveOperations\Services\IntegrityVerificationService;
use App\Modules\ArchiveOperations\Services\OperationRunService;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IntegrityCheckController extends Controller
{
    public function __construct(
        private readonly IntegrityVerificationService $integrityService,
        private readonly OperationRunService $runService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('assets.update') || $user->hasPermission('catalogue.manage') || $user->hasPermission('users.manage')), 403);

        $summary = $this->integrityService->getIntegritySummary();
        $issues = $this->integrityService->getOpenIssues();
        $runs = $this->runService->recentRuns(10);

        return view('operations.integrity.index', compact('summary', 'issues', 'runs'));
    }

    public function runCheck(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('assets.update') || $user->hasPermission('users.manage')), 403);

        // Re-hashing every original is unbounded work; the request only queues it.
        $run = $this->runService->dispatchRun(
            VerifyIntegrityJob::class,
            VerifyIntegrityJob::TYPE,
            $user,
            [],
            AssetFile::query()->count(),
        );

        return redirect()
            ->route('admin.operations.integrity.index')
            ->with('status', "Integriteitscontrole gestart op de achtergrond (taak {$run->id}). Volg de voortgang in het overzicht.");
    }

    public function rebuild(Request $request, AssetFile $file): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('assets.update') || $user->hasPermission('users.manage')), 403);

        $this->runService->dispatchRun(
            RebuildDerivativesJob::class,
            RebuildDerivativesJob::TYPE,
            $user,
            ['asset_file_id' => $file->id],
            1,
        );

        return redirect()
            ->back()
            ->with('status', "Herbouw van weergaven voor {$file->original_filename} is in de wachtrij geplaatst.");
    }

    public function rebuildAll(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('assets.update') || $user->hasPermission('users.manage')), 403);

        $run = $this->runService->dispatchRun(
            RebuildDerivativesJob::class,
            RebuildDerivativesJob::TYPE,
            $user,
        );

        return redirect()
            ->route('admin.operations.integrity.index')
            ->with('status', "Herbouw van ontbrekende weergaven gestart op de achtergrond (taak {$run->id}).");
    }
}
