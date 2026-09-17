<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ArchiveOperations\Services\ProcessingCentreService;
use App\Modules\Ingest\Models\QuarantineUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProcessingCentreController extends Controller
{
    public function __construct(
        private readonly ProcessingCentreService $processingService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('assets.update') || $user->hasPermission('catalogue.manage') || $user->hasPermission('users.manage')), 403);

        $status = $request->string('status', 'all')->value();
        $stats = $this->processingService->getStatistics();
        $uploads = $this->processingService->getUploads($status);

        return view('operations.processing.index', compact('stats', 'uploads', 'status'));
    }

    public function show(Request $request, QuarantineUpload $upload): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('assets.update') || $user->hasPermission('catalogue.manage') || $user->hasPermission('users.manage')), 403);

        $upload->load(['asset.auditEvents', 'uploadedBy']);

        return view('operations.processing.show', compact('upload'));
    }

    public function retry(Request $request, QuarantineUpload $upload): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('assets.update') || $user->hasPermission('users.manage')), 403);

        $this->processingService->retryUpload($upload, $user);

        return redirect()
            ->back()
            ->with('status', __('operations.generated.t_afe580e11be4225b'));
    }

    public function retryAll(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('assets.update') || $user->hasPermission('users.manage')), 403);

        $count = $this->processingService->retryAllFailed($user);

        return redirect()
            ->route('admin.operations.processing.index')
            ->with('status', "{$count} mislukte taken opnieuw klaargezet in de wachtrij.");
    }

    public function cancel(Request $request, QuarantineUpload $upload): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('assets.update') || $user->hasPermission('users.manage')), 403);

        $this->processingService->cancelUpload($upload, $user);

        return redirect()
            ->back()
            ->with('status', __('operations.generated.t_608e14856421fb0f'));
    }
}
