<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ArchiveOperations\Services\DuplicateDossierService;
use App\Modules\Ingest\Models\QuarantineUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DuplicateDossierController extends Controller
{
    public function __construct(
        private readonly DuplicateDossierService $duplicateService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('assets.update') || $user->hasPermission('catalogue.manage')), 403);

        $duplicates = $this->duplicateService->getPendingDuplicates();

        return view('operations.duplicates.index', compact('duplicates'));
    }

    public function show(Request $request, QuarantineUpload $upload): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('assets.update') || $user->hasPermission('catalogue.manage')), 403);

        $comparison = $this->duplicateService->getDuplicateComparison($upload);

        return view('operations.duplicates.show', [
            'upload' => $comparison['upload'],
            'targetAsset' => $comparison['targetAsset'],
            'targetFile' => $comparison['targetFile'],
        ]);
    }

    public function link(Request $request, QuarantineUpload $upload): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('assets.update') || $user->hasPermission('catalogue.manage')), 403);

        $validated = $request->validate([
            'provenance_note' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'string', 'max:1000'],
        ]);

        $targetAsset = $this->duplicateService->linkAndEnrich($upload, $user, $validated);

        return redirect()
            ->route('admin.assets.show', $targetAsset)
            ->with('status', 'Duplicaat succesvol gekoppeld en herkomst verrijkt. Er is geen tweede origineel aangemaakt.');
    }
}
