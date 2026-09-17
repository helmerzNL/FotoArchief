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

/**
 * OCR text is a verbatim transcription of a private scan, so every action here is
 * authorised against the dossier the text belongs to, never against a bare
 * permission. A broad assets.view check would have let any signed-in viewer read
 * the contents of every other owner's private photographs.
 */
class OcrController extends Controller
{
    public function __construct(
        private readonly TesseractOcrService $ocrService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless($user->hasPermission('assets.view'), 403);

        $diagnostics = $this->ocrService->getDiagnostics();
        $query = $request->query('q');
        $queryString = is_string($query) ? $query : null;
        // Scoped to the dossiers this actor may view; trashed and orphan rows are out.
        $ocrRecords = $this->ocrService->searchOcrText($queryString, $user);

        return view('operations.ocr.index', compact('diagnostics', 'ocrRecords', 'queryString'));
    }

    public function show(Request $request, AssetOcrText $ocr): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $this->authorizeOcrRecord($ocr, 'view');
        $ocr->load(['asset', 'file']);

        return view('operations.ocr.show', compact('ocr'));
    }

    public function update(Request $request, AssetOcrText $ocr): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        // Correcting a transcription edits the dossier's description of itself.
        $this->authorizeOcrRecord($ocr, 'update');

        $validated = $request->validate([
            'edited_text' => ['required', 'string'],
        ]);

        $this->ocrService->updateEditedText($ocr, $validated['edited_text'], $user);

        return redirect()
            ->route('admin.operations.ocr.show', $ocr)
            ->with('status', __('operations.generated.t_5e186cd64ba3186e'));
    }

    public function dispatchOcr(Request $request, Asset $asset): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $this->authorize('update', $asset);

        $primaryFile = $asset->files()->where('is_primary', true)->first() ?? $asset->files()->first();
        if (! $primaryFile) {
            return redirect()->back()->with('error', __('operations.generated.t_5bcaa4bc2c8bafb9'));
        }

        // Same connection and atomicity contract as the ingest pipeline: heavy work is
        // pushed onto the dedicated ingest database connection, not the default queue.
        Queue::connection('ingest')->push(new ProcessAssetOcrJob($primaryFile->id));

        return redirect()->back()->with('status', "OCR-taak geplaatst in de achtergrondwachtrij voor asset {$asset->accession_number}.");
    }

    /**
     * Authorises an OCR row through the dossier it transcribes.
     *
     * A row whose dossier is trashed or already deleted has no dossier to authorise
     * against, so it is treated as gone rather than as world-readable.
     */
    private function authorizeOcrRecord(AssetOcrText $ocr, string $ability): void
    {
        $asset = Asset::query()->find($ocr->asset_id);
        abort_if($asset === null, 404);

        $this->authorize($ability, $asset);
    }
}
