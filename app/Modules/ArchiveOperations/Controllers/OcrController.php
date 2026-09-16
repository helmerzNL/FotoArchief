<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ArchiveOperations\Jobs\ProcessAssetOcrJob;
use App\Modules\ArchiveOperations\Models\AssetOcrText;
use App\Modules\ArchiveOperations\Services\TesseractOcrService;
use App\Modules\Catalogue\Models\Asset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\View\View;

class OcrController extends Controller
{
    public function __construct(
        private readonly TesseractOcrService $ocrService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('catalogue.manage') || $user->hasPermission('users.manage') || $user->hasPermission('assets.view')), 403);

        $diagnostics = $this->ocrService->getDiagnostics();
        $query = $request->query('q');
        $queryString = is_string($query) ? $query : null;
        $ocrRecords = $this->ocrService->searchOcrText($queryString);

        return view('operations.ocr.index', compact('diagnostics', 'ocrRecords', 'queryString'));
    }

    public function show(Request $request, AssetOcrText $ocr): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('catalogue.manage') || $user->hasPermission('users.manage') || $user->hasPermission('assets.view')), 403);

        $ocr->load(['asset', 'file']);

        return view('operations.ocr.show', compact('ocr'));
    }

    public function update(Request $request, AssetOcrText $ocr): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('catalogue.manage') || $user->hasPermission('users.manage')), 403);

        $validated = $request->validate([
            'edited_text' => ['required', 'string'],
        ]);

        $this->ocrService->updateEditedText($ocr, $validated['edited_text'], $user);

        return redirect()
            ->route('admin.operations.ocr.show', $ocr)
            ->with('status', 'Tekstcorrectie succesvol opgeslagen.');
    }

    public function dispatchOcr(Request $request, Asset $asset): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('catalogue.manage') || $user->hasPermission('users.manage')), 403);

        $primaryFile = $asset->files()->where('is_primary', true)->first() ?? $asset->files()->first();
        if (! $primaryFile) {
            return redirect()->back()->with('error', 'Geen archiefbestand gevonden voor deze asset.');
        }

        // Same connection and atomicity contract as the ingest pipeline: heavy work is
        // pushed onto the dedicated ingest database connection, not the default queue.
        Queue::connection('ingest')->push(new ProcessAssetOcrJob($primaryFile->id));

        return redirect()->back()->with('status', "OCR-taak geplaatst in de achtergrondwachtrij voor asset {$asset->accession_number}.");
    }
}
