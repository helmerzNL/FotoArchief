<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Services\OperationRunService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OperationRunController extends Controller
{
    public function __construct(
        private readonly OperationRunService $runService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('users.manage') || $user->hasPermission('catalogue.manage') || $user->hasPermission('assets.update')), 403);

        $runs = $this->runService->recentRuns();

        return view('operations.runs.index', compact('runs'));
    }

    public function retry(Request $request, OperationRun $run): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);

        abort_unless($run->status === OperationRun::STATUS_FAILED, 422);

        $jobClass = OperationRunService::jobMap()[$run->operation_type] ?? null;
        abort_if($jobClass === null, 422);

        $this->runService->retryRun($run, $jobClass);

        return redirect()
            ->route('admin.operations.runs.index')
            ->with('status', "Taak {$run->id} is opnieuw in de wachtrij geplaatst; de voortgang wordt hervat.");
    }

    public function cancel(Request $request, OperationRun $run): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);

        abort_unless($run->status === OperationRun::STATUS_QUEUED, 422);

        $this->runService->cancelRun($run);

        return redirect()
            ->route('admin.operations.runs.index')
            ->with('status', "Taak {$run->id} is geannuleerd voordat deze werd gestart.");
    }
}
