<?php

declare(strict_types=1);

namespace App\Modules\DataExchange\Http\Controllers;

use App\Modules\DataExchange\Models\MetadataImport;
use App\Modules\DataExchange\Services\MetadataImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MetadataImportController extends ExchangeController
{
    public function store(Request $request, MetadataImportService $service): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('assets.view') && $user->hasPermission('assets.update'), 403);
        $data = $request->validate([
            'file' => ['required', 'file'],
            'write_mode' => ['required', Rule::in(['fill_empty', 'overwrite'])],
        ]);
        $import = $service->accept($data['file'], $user, $data['write_mode']);

        return redirect()->route('exchange.imports.show', $import)
            ->with('status', 'Bestand ontvangen. Er is nog niets gewijzigd: controleer eerst het voorbeeld.');
    }

    public function show(Request $request, MetadataImport $import): View
    {
        $this->owned($request, $import);
        $rows = $import->rows()->orderBy('row_number')->limit((int) config('exchange.preview_rows'))->get();

        return view('exchange.imports.show', compact('import', 'rows'));
    }

    public function analyse(Request $request, MetadataImport $import, MetadataImportService $service): RedirectResponse
    {
        $this->owned($request, $import);
        $data = $request->validate(['write_mode' => ['required', Rule::in(['fill_empty', 'overwrite'])]]);
        $service->reanalyse($import, $data['write_mode']);

        return redirect()->route('exchange.imports.show', $import)->with('status', 'Controle opnieuw uitgevoerd. Er is nog niets gewijzigd.');
    }

    public function confirm(Request $request, MetadataImport $import, MetadataImportService $service): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('assets.update'), 403);
        $this->owned($request, $import);
        $data = $request->validate(['checksum' => ['required', 'string', 'size:64']]);
        $service->confirm($import, $user, $data['checksum']);

        return redirect()->route('exchange.imports.show', $import)
            ->with('status', 'Import bevestigd en ingepland. Een actieve worker voert de wijzigingen uit.');
    }

    private function owned(Request $request, MetadataImport $import): void
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('assets.view') && $import->created_by_user_id === $user->id, 404);
    }
}
