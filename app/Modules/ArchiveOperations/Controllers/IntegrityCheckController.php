<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ArchiveOperations\Services\IntegrityVerificationService;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IntegrityCheckController extends Controller
{
    public function __construct(
        private readonly IntegrityVerificationService $integrityService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('assets.update') || $user->hasPermission('catalogue.manage') || $user->hasPermission('users.manage')), 403);

        $summary = $this->integrityService->getIntegritySummary();
        $issues = $this->integrityService->getOpenIssues();

        return view('operations.integrity.index', compact('summary', 'issues'));
    }

    public function runCheck(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('assets.update') || $user->hasPermission('users.manage')), 403);

        $result = $this->integrityService->verifyAll();

        return redirect()
            ->route('admin.operations.integrity.index')
            ->with('status', "Integriteitscontrole voltooid. {$result['total_checked']} bestanden gecontroleerd: {$result['ok']} in orde, {$result['issues']} aandachtspunten.");
    }

    public function rebuild(Request $request, AssetFile $file): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('assets.update') || $user->hasPermission('users.manage')), 403);

        $this->integrityService->rebuildMissingDerivatives($file, $user);

        return redirect()
            ->back()
            ->with('status', "Weergaven voor bestand {$file->original_filename} succesvol herbouwd.");
    }

    public function rebuildAll(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('assets.update') || $user->hasPermission('users.manage')), 403);

        $count = $this->integrityService->rebuildAllMissingDerivatives($user);

        return redirect()
            ->route('admin.operations.integrity.index')
            ->with('status', "{$count} bestanden voorzien van nieuw gegenereerde weergaven.");
    }
}
