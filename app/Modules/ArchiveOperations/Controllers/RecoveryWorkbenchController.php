<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ArchiveOperations\Models\BackupRecord;
use App\Modules\ArchiveOperations\Models\RestoreDrill;
use App\Modules\ArchiveOperations\Services\OperationalIncidentService;
use App\Modules\ArchiveOperations\Services\RecoveryReadinessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class RecoveryWorkbenchController extends Controller
{
    public function index(Request $request): View
    {
        $this->user($request);
        $checks = DB::table('recovery_checks')->latest('created_at')->limit(10)->get()
            ->map(fn ($check): array => ['id' => $check->id, 'kind' => $check->kind, 'created_at' => $check->created_at, 'report' => json_decode($check->report, true, 512, JSON_THROW_ON_ERROR)]);
        $backups = BackupRecord::query()->with(['drills' => fn ($query) => $query->where('status', 'verified')->latest('finished_at')])->latest()->paginate(20);
        $drills = RestoreDrill::query()->latest()->paginate(20, ['*'], 'drill_page');
        $incidents = DB::table('operational_incidents')->orderByDesc('updated_at')->get();

        return view('operations.recovery', compact('checks', 'backups', 'drills', 'incidents'));
    }

    public function check(Request $request, RecoveryReadinessService $readiness): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'kind' => ['required', 'in:installation,upgrade'], 'target_version' => ['required_if:kind,upgrade', 'nullable', 'regex:/^\d+\.\d+\.\d+$/D', 'max:32'],
            'required_free_bytes' => ['required_if:kind,upgrade', 'nullable', 'integer', 'min:1', 'max:1000000000000000'], 'confirm' => ['accepted'],
        ]);
        $report = $readiness->check($data['kind'], $data['target_version'] ?? null, (int) ($data['required_free_bytes'] ?? 1));
        DB::table('recovery_checks')->insert(['id' => (string) Str::ulid(), 'actor_user_id' => $user->id,
            'kind' => $data['kind'], 'report' => json_encode($report, JSON_THROW_ON_ERROR), 'created_at' => now()]);

        return back()->with('status', __('recovery.checked'));
    }

    public function acknowledge(Request $request, string $incident, OperationalIncidentService $incidents): RedirectResponse
    {
        $user = $this->user($request);
        $request->validate(['confirm' => ['accepted']]);
        $incidents->acknowledge($incident, $user);

        return back()->with('status', __('recovery.acknowledged'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);

        return $user;
    }
}
