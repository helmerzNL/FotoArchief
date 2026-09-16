<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ArchiveOperations\Services\FileVersionService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;

class FileVersionController extends Controller
{
    public function __construct(
        private readonly FileVersionService $fileVersionService,
    ) {}

    public function index(Request $request, Asset $asset): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        // Versions expose the same private dossier as the detail page they are linked
        // from, so they follow the same ownership policy rather than a bare permission.
        $this->authorize('view', $asset);

        $versions = $this->fileVersionService->getAssetVersions($asset);

        return view('operations.versions.index', compact('asset', 'versions'));
    }

    public function store(Request $request, Asset $asset): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $this->authorize('upload', $asset);

        $validated = $request->validate([
            'file' => ['required', 'file'],
            'change_note' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var UploadedFile $uploadedFile */
        $uploadedFile = $request->file('file');
        $this->fileVersionService->uploadNewVersion($asset, $uploadedFile, $user, $validated['change_note'] ?? null);

        return redirect()
            ->route('admin.operations.versions.index', $asset)
            ->with('status', 'Nieuwe scanversie geüpload naar quarantaine. De achtergrondverwerking is gestart.');
    }

    public function reprocess(Request $request, Asset $asset, AssetFile $file): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $this->authorize('update', $asset);
        abort_unless($file->asset_id === $asset->id, 404);

        $this->fileVersionService->reprocessDerivatives($file, $user);

        return redirect()
            ->route('admin.operations.versions.index', $asset)
            ->with('status', 'Afgeleide weergaven (previews) succesvol opnieuw gegenereerd vanuit het ongewijzigde origineel.');
    }

    public function setActive(Request $request, Asset $asset, AssetFile $file): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $this->authorize('update', $asset);
        abort_unless($file->asset_id === $asset->id, 404);

        $this->fileVersionService->setActiveVersion($asset, $file, $user);

        return redirect()
            ->route('admin.operations.versions.index', $asset)
            ->with('status', 'Primaire weergaveversie bijgewerkt.');
    }
}
