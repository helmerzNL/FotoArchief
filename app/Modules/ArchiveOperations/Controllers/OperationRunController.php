<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Ai\Jobs\ProcessAiAnalysisJob;
use App\Modules\Ai\Jobs\ProcessAiIndexJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Services\OperationRunService;
use App\Modules\ArchiveOperations\Services\OperationWorkbenchService;
use App\Modules\Catalogue\Models\Asset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OperationRunController extends Controller
{
    public function __construct(
        private readonly OperationRunService $runService,
    ) {}

    public function show(Request $request, OperationRun $run, OperationWorkbenchService $workbench): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('users.manage') || $run->requested_by_user_id === $user->id), 403);
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $items = $workbench->items($run, (int) ($data['page'] ?? 1));
        $settings = Arr::only($run->payload ?? [], ['provider', 'model', 'cursor', 'generation_id', 'model_space', 'parent_run_id', 'migration_id', 'asset_file_id', 'retention_days', 'retention_hours']);
        $events = $run->auditEvents()->latest('created_at')->orderByDesc('id')->paginate(25, ['*'], 'audit_page');

        return view('operations.runs.show', compact('run', 'items', 'settings', 'events'));
    }

    public function control(Request $request, OperationRun $run, OperationWorkbenchService $workbench): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $data = $request->validate(['action' => ['required', Rule::in(['pause', 'resume'])]]);
        $workbench->control($run, $user, $data['action']);

        return back()->with('status', __('workbench.control_saved'));
    }

    public function retrySelected(Request $request, OperationRun $run, OperationWorkbenchService $workbench): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $data = $request->validate([
            'confirm' => ['accepted'],
            'selected' => ['required', 'array', 'min:1', 'max:25'],
            'selected.*' => ['required', 'string', 'size:26', 'distinct'],
        ]);
        $child = $workbench->retrySelected($run, $user, $data['selected']);

        return redirect()->route('admin.operations.runs.show', $child)->with('status', __('workbench.retry_saved'));
    }

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

        $this->runService->retryRun($run, $jobClass, $user);
        $message = in_array($run->operation_type, [ProcessAiAnalysisJob::TYPE, ProcessAiIndexJob::TYPE], true)
            ? "AI-taak {$run->id} is opnieuw in de wachtrij geplaatst met gecontroleerde foto's en schone tellers."
            : "Taak {$run->id} is opnieuw in de wachtrij geplaatst; de voortgang wordt hervat.";

        return redirect()
            ->route('admin.operations.runs.index')
            ->with('status', $message);
    }

    public function aiResults(Request $request, OperationRun $run): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('assets.view')
            && ($user->hasPermission('users.manage') || $user->hasPermission('catalogue.manage') || $user->hasPermission('assets.update')), 403);
        abort_unless(in_array($run->operation_type, [ProcessAiAnalysisJob::TYPE, ProcessAiIndexJob::TYPE], true), 404);

        $query = Asset::query()->whereIn('id', $run->auditEvents()
            ->select('asset_id')->where('event_type', $run->operation_type.'.item_succeeded'));
        if (! $user->hasPermission('assets.publish')) {
            $query->where('created_by_user_id', $user->id);
        }
        $assets = $query->orderBy('accession_number')->orderBy('id')->paginate(25);

        return view('ai.results.operation', compact('run', 'assets'));
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
