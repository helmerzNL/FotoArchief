<?php

declare(strict_types=1);

namespace App\Modules\DataExchange\Http\Controllers;

use App\Modules\DataExchange\Models\DataExport;
use App\Modules\DataExchange\Services\DataExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataExportController extends ExchangeController
{
    public function store(Request $request, DataExportService $service): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('assets.view') && $user->hasPermission('exports.create'), 403);
        $data = $request->validate([
            'export_type' => ['required', Rule::in(DataExportService::TYPES)],
            'scope' => ['required', Rule::in(['selection', 'all'])],
            'asset_ids' => ['nullable', 'array', 'max:'.(int) config('exchange.max_export_assets')],
            'asset_ids.*' => ['ulid'],
        ]);
        $export = $service->request($user, $data['export_type'], $data['scope'], $data['asset_ids'] ?? []);

        return redirect()->route('exchange.exports.show', $export)
            ->with('status', __('exchange.generated.t_a7722f428c96275b'));
    }

    public function show(Request $request, DataExport $export): View
    {
        $this->owned($request, $export);

        return view('exchange.exports.show', compact('export'));
    }

    public function retry(Request $request, DataExport $export, DataExportService $service): RedirectResponse
    {
        $user = $this->user($request);
        $this->owned($request, $export);
        $service->retry($export, $user);

        return redirect()->route('exchange.exports.show', $export)->with('status', __('exchange.generated.t_c580c423eab6d125'));
    }

    public function link(Request $request, DataExport $export, DataExportService $service): RedirectResponse
    {
        $user = $this->user($request);
        $this->owned($request, $export);
        $token = $service->issueDownloadToken($export, $user);

        return redirect()->route('exchange.exports.download', ['export' => $export->id, 'token' => $token]);
    }

    public function download(Request $request, DataExport $export, string $token, DataExportService $service): StreamedResponse
    {
        $user = $this->user($request);
        $this->owned($request, $export);
        $target = $service->authorizeDownload($export, $user, $token);
        $stream = Storage::disk($target['disk'])->readStream($target['key']);
        abort_unless(is_resource($stream), 503, __('exchange.generated.t_d26dfbd13d3a0dab'));
        $contentType = match ($export->export_type) {
            'metadata_json' => 'application/json',
            'metadata_csv' => __('exchange.generated.t_e1db727f9cc3e542'),
            default => 'application/zip',
        };

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$target['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function owned(Request $request, DataExport $export): void
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('assets.view') && $export->created_by_user_id === $user->id, 404);
    }
}
