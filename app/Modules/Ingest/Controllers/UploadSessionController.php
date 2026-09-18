<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Ingest\Models\UploadSession;
use App\Modules\Ingest\Models\UploadSessionItem;
use App\Modules\Ingest\Services\UploadRetryService;
use App\Modules\Ingest\Services\UploadSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class UploadSessionController extends Controller
{
    public function index(Request $request): View
    {
        $sessions = UploadSession::query()->where('user_id', $this->user($request)->id)->latest('id')->paginate(25);

        return view('admin.uploads.index', compact('sessions'));
    }

    public function store(Request $request, UploadSessionService $service): JsonResponse
    {
        $data = $request->validate([
            'client_key' => ['required', 'uuid'],
            'files' => ['required', 'array', 'min:1', 'max:'.min(250, (int) config('ingest.max_batch_upload_files'))],
            'files.*.filename' => ['required', 'string', 'max:255', 'regex:~^[^/\\\\\x00-\x1F]+\\.(?:jpe?g|png|webp)$~i'],
            'files.*.byte_size' => ['required', 'integer', 'min:1', 'max:'.min(104857600, (int) config('ingest.max_upload_bytes'))],
            'files.*.sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/', 'distinct'],
        ]);
        $session = $service->create($this->user($request), $data['client_key'], $data['files']);

        return response()->json(['url' => route('admin.uploads.show', $session)], 201)->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, UploadSession $session, UploadSessionService $service): View|JsonResponse
    {
        $service->authorize($this->user($request), $session);
        $session->load('items.upload.asset');
        if ($request->expectsJson()) {
            return response()->json([
                'closed' => $session->closed_at !== null || $session->expires_at->isPast() || $session->purged_at !== null,
                'chunk_bytes' => UploadSessionService::CHUNK_BYTES,
                'items' => $session->items->map(fn (UploadSessionItem $item) => [
                    'id' => $item->id, 'filename' => $item->filename, 'byte_size' => $item->byte_size, 'sha256' => $item->sha256,
                    'status' => $item->upload->status ?? $item->status, 'transfer_status' => $item->status,
                    'received' => DB::table('upload_session_chunks')->where('upload_session_item_id', $item->id)->orderBy('position')->pluck('position'),
                    'url' => $item->upload?->asset === null ? null : route('admin.assets.show', $item->upload->asset),
                ]),
            ])->header('Cache-Control', 'no-store, private');
        }
        $counts = $session->items->countBy(fn (UploadSessionItem $item) => $item->upload->status ?? $item->status);

        return view('admin.uploads.show', compact('session', 'counts'));
    }

    public function chunk(Request $request, UploadSession $session, UploadSessionItem $item, UploadSessionService $service): JsonResponse
    {
        $data = $request->validate(['position' => ['required', 'integer', 'min:0', 'max:24'], 'chunk' => ['required', 'file', 'max:4096']]);
        $chunk = $request->file('chunk');
        abort_unless($chunk instanceof UploadedFile, 422);
        $service->chunk($this->user($request), $session, $item, (int) $data['position'], $chunk);

        return response()->json(['received' => (int) $data['position']]);
    }

    public function finalize(Request $request, UploadSession $session, UploadSessionItem $item, UploadSessionService $service): JsonResponse
    {
        $service->finalize($this->user($request), $session, $item);

        return response()->json(['queued' => true], 202);
    }

    public function retry(Request $request, UploadSession $session, UploadSessionItem $item, UploadSessionService $sessions, UploadRetryService $retry): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);
        $sessions->authorize($this->user($request), $session);
        abort_unless($item->upload_session_id === $session->id, 404);
        if ($item->upload !== null) {
            $retry->retry($this->user($request), $item->upload);
        } else {
            $sessions->finalize($this->user($request), $session, $item);
        }

        return back()->with('status', __('uploads.retry_queued'));
    }

    public function close(Request $request, UploadSession $session, UploadSessionService $service): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);
        $service->close($this->user($request), $session);

        return back()->with('status', __('uploads.finished'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
