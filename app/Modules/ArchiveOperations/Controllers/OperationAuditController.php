<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OperationAuditController extends Controller
{
    public function __invoke(Request $request): View|StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('audit.view'), 403);
        $filters = $request->validate([
            'asset' => ['nullable', 'string', 'max:255'], 'run' => ['nullable', 'string', 'max:26'],
            'actor' => ['nullable', 'string', 'max:26'], 'event' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'export' => ['nullable', 'in:jsonl'],
        ]);
        $assets = DB::table('asset_audit_events')->select(['id', 'created_at', 'asset_id', 'actor_user_id', 'event_type'])
            ->selectRaw('NULL AS operation_run_id');
        $operations = DB::table('operation_run_audit_events AS events')
            ->leftJoin('operation_runs AS runs', 'runs.id', '=', 'events.operation_run_id')
            ->select(['events.id', 'events.created_at', 'events.asset_id'])
            ->selectRaw("COALESCE(JSON_EXTRACT(events.context, '$.actor_user_id'), runs.requested_by_user_id) AS actor_user_id")
            ->addSelect(['events.event_type', 'events.operation_run_id']);
        if (DB::getDriverName() === 'pgsql') {
            $operations->select(['events.id', 'events.created_at', 'events.asset_id'])
                ->selectRaw("COALESCE(events.context->>'actor_user_id', runs.requested_by_user_id) AS actor_user_id")
                ->addSelect(['events.event_type', 'events.operation_run_id']);
        }
        $query = DB::query()->fromSub($assets->unionAll($operations), 'audit')
            ->when($filters['asset'] ?? null, fn ($q, $value) => $q->where(function ($q) use ($value): void {
                $q->where('asset_id', $value)->orWhereIn('asset_id', DB::table('assets')->select('id')->where('accession_number', $value));
            }))
            ->when($filters['run'] ?? null, fn ($q, $value) => $q->where('operation_run_id', $value))
            ->when($filters['actor'] ?? null, fn ($q, $value) => $q->where('actor_user_id', $value))
            ->when($filters['event'] ?? null, fn ($q, $value) => $q->where('event_type', $value))
            ->when($filters['from'] ?? null, fn ($q, $value) => $q->whereDate('created_at', '>=', $value))
            ->when($filters['until'] ?? null, fn ($q, $value) => $q->whereDate('created_at', '<=', $value))
            ->orderByDesc('created_at')->orderByDesc('id');
        if (($filters['export'] ?? null) === 'jsonl') {
            if ((clone $query)->count() > 10000) {
                throw ValidationException::withMessages(['export' => __('workbench.export_limit')]);
            }

            return response()->streamDownload(function () use ($query): void {
                foreach ($query->limit(10000)->cursor() as $event) {
                    echo json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
                }
            }, 'fotoarchief-audit.jsonl', ['Content-Type' => 'application/x-ndjson', 'Cache-Control' => 'private, no-store']);
        }
        $events = $query->paginate(50)->withQueryString();

        return view('operations.audit', compact('events', 'filters'));
    }
}
