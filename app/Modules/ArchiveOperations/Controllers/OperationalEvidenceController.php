<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Services\AcceptanceEvidenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

final class OperationalEvidenceController extends Controller
{
    public function notifications(Request $request): View
    {
        $user = $this->user($request);
        $runs = OperationRun::query()->where('requested_by_user_id', $user->id)
            ->whereIn('status', ['completed', 'failed', 'cancelled', 'paused'])
            ->orderByDesc('updated_at')->orderByDesc('id')->paginate(25);
        $read = DB::table('operation_notification_reads')->where('user_id', $user->id)
            ->whereIn('run_id', $runs->pluck('id'))->pluck('fingerprint', 'run_id');
        $fingerprints = $runs->getCollection()->mapWithKeys(fn (OperationRun $run): array => [$run->id => $this->fingerprint($run)]);

        return view('operations.notifications', compact('runs', 'read', 'fingerprints'));
    }

    public function read(Request $request, OperationRun $run): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['fingerprint' => ['required', 'string', 'size:64']]);
        DB::transaction(function () use ($run, $user, $data): void {
            $current = OperationRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->requested_by_user_id === $user->id, 403);
            abort_unless(in_array($current->status, ['completed', 'failed', 'cancelled', 'paused'], true)
                && hash_equals($this->fingerprint($current), $data['fingerprint']), 409);
            DB::table('operation_notification_reads')->updateOrInsert(['user_id' => $user->id, 'run_id' => $current->id],
                ['fingerprint' => $data['fingerprint'], 'read_at' => now()]);
        });

        return back()->with('status', __('evidence.read_saved'));
    }

    public function timeline(Request $request, string $incident): View
    {
        $this->user($request, true);
        $record = DB::table('operational_incidents')->where('id', $incident)->firstOrFail();
        $events = DB::table('operational_incident_events')->where('incident_id', $incident)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(25);

        return view('operations.incident-timeline', compact('record', 'events'));
    }

    public function index(Request $request): View
    {
        $this->user($request, true);
        $evidence = DB::table('acceptance_evidence')->orderByDesc('created_at')->orderByDesc('id')->paginate(25);
        $runs = OperationRun::query()->latest()->limit(25)->get();
        $incidents = DB::table('operational_incidents')->orderByDesc('updated_at')->limit(25)->get();
        $checks = DB::table('recovery_checks')->orderByDesc('created_at')->limit(10)->get();

        return view('operations.evidence', compact('evidence', 'runs', 'incidents', 'checks'));
    }

    public function store(Request $request, AcceptanceEvidenceService $evidence): RedirectResponse
    {
        $user = $this->user($request, true);
        $request->validate(['confirm' => ['required', 'accepted']]);
        $evidence->record($request->only(['version', 'environment', 'kind', 'result', 'reference']), 'human', $user);

        return back()->with('status', __('evidence.recorded'));
    }

    public function support(Request $request): Response
    {
        $this->user($request, true);
        $data = $request->validate([
            'confirm' => ['required', 'accepted'],
            'runs' => ['sometimes', 'array', 'max:25'], 'runs.*' => ['required', 'ulid', 'distinct', 'exists:operation_runs,id'],
            'incidents' => ['sometimes', 'array', 'max:25'], 'incidents.*' => ['required', 'ulid', 'distinct', 'exists:operational_incidents,id'],
            'checks' => ['sometimes', 'array', 'max:10'], 'checks.*' => ['required', 'ulid', 'distinct', 'exists:recovery_checks,id'],
        ]);
        $runs = OperationRun::query()->whereIn('id', $data['runs'] ?? [])->get()->map(fn (OperationRun $run): array => [
            'id' => $run->id, 'status' => $this->enum($run->status, ['queued', 'running', 'paused', 'completed', 'failed', 'cancelled']),
            'total' => $run->total_items, 'processed' => $run->processed_items, 'failed' => $run->failed_items, 'attempts' => $run->attempts,
        ]);
        $incidents = DB::table('operational_incidents')->whereIn('id', $data['incidents'] ?? [])->get()->map(fn ($row): array => [
            'id' => $row->id, 'status' => $this->enum($row->status, ['open', 'resolved']),
            'severity' => $this->enum($row->severity, ['info', 'warning', 'critical']),
            'observations' => (int) $row->observations, 'acknowledged' => $row->acknowledged_at !== null, 'delivered' => $row->notified_at !== null,
        ]);
        $checks = DB::table('recovery_checks')->whereIn('id', $data['checks'] ?? [])->get()->map(function ($row): array {
            $report = json_decode($row->report, true, 512, JSON_THROW_ON_ERROR);
            $safe = [];
            foreach ($report['checks'] as $check) {
                if (in_array($check['key'], ['php', 'extensions', 'storage', 'database', 'limits', 'scanner', 'worker', 'activity',
                    'target_version', 'free_space', 'migrations', 'active_tasks', 'backup_evidence'], true)) {
                    $safe[] = ['key' => $check['key'], 'status' => $this->enum($check['status'], ['ok', 'warning', 'critical', 'blocked'])];
                }
            }

            return ['id' => $row->id, 'checks' => $safe];
        });
        $json = json_encode(['schema' => 1, 'version' => trim(File::get(base_path('VERSION'))), 'generated_at' => now()->toIso8601String(),
            'runs' => $runs, 'incidents' => $incidents, 'checks' => $checks], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        return response($json, 200, ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="fotoarchief-support.json"',
            'Cache-Control' => 'no-store, private']);
    }

    private function fingerprint(OperationRun $run): string
    {
        return hash('sha256', json_encode([$run->status, $run->attempts, $run->finished_at, $run->updated_at], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $allowed */
    private function enum(string $value, array $allowed): string
    {
        abort_unless(in_array($value, $allowed, true), 422, __('evidence.invalid_diagnostic'));

        return $value;
    }

    private function user(Request $request, bool $admin = false): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active
            && ($admin ? $user->hasPermission('users.manage') : $user->hasPermission('assets.view')), 403);

        return $user;
    }
}
