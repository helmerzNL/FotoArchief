<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Services\OperationRunService;
use App\Modules\Ingest\Jobs\OneDriveImportJob;
use App\Modules\Ingest\Models\OneDriveConnection;
use App\Modules\Ingest\Services\OneDriveClient;
use App\Modules\Ingest\Services\OneDriveImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

/**
 * Admin → OneDrive: register the app, connect a personal OneDrive, choose a folder,
 * count and import (ADR-0022). Administrators only; secrets are write-only.
 */
class OneDriveController extends Controller
{
    private const SESSION_KEY = 'onedrive.oauth';

    private const ACTIVE = [OperationRun::STATUS_QUEUED, OperationRun::STATUS_RUNNING, OperationRun::STATUS_PAUSED];

    public function __construct(private readonly OneDriveClient $client) {}

    public function show(Request $request): View
    {
        $this->authorizeAdmin($request);
        $connection = OneDriveConnection::current();
        $browse = null;
        $browseError = null;
        if ($connection->isConnected() && $request->filled('browse')) {
            $item = (string) $request->query('browse');
            try {
                $browse = $this->client->folder($connection, $item === 'root' ? null : $item);
            } catch (RuntimeException $exception) {
                $browseError = $exception->getMessage();
            }
        }
        $runs = OperationRun::query()->where('operation_type', OneDriveImportJob::TYPE)->latest('created_at')->limit(5)->get();

        return view('operations.onedrive', [
            'connection' => $connection,
            'redirectUri' => $this->redirectUri(),
            'browse' => $browse,
            'browseError' => $browseError,
            'runs' => $runs,
            'activeRun' => $runs->first(fn (OperationRun $run): bool => in_array($run->status, self::ACTIVE, true)),
            'importLabel' => match (true) {
                $connection->resume_link !== null => 'continue',
                $connection->delta_link !== null => 'top_up',
                default => 'start',
            },
        ]);
    }

    public function saveApp(Request $request): RedirectResponse
    {
        $user = $this->authorizeAdmin($request);
        $connection = OneDriveConnection::current();
        $validated = $request->validate([
            'client_id' => ['required', 'uuid'],
            'client_secret' => [$connection->hasApp() ? 'nullable' : 'required', 'string', 'max:255'],
        ]);
        $clientId = strtolower((string) $validated['client_id']);
        $changes = ['client_id' => $clientId];
        $secret = trim((string) ($validated['client_secret'] ?? ''));
        if ($secret !== '') {
            $changes['client_secret'] = $secret;
        }
        if ($clientId !== $connection->client_id) {
            // Tokens belong to the old app; they cannot be used with a new one.
            $changes += $this->disconnectedState();
        }
        DB::transaction(function () use ($connection, $changes, $user): void {
            $connection->forceFill($changes)->save();
            $this->audit($user, 'app_saved');
        });

        return redirect()->route('admin.operations.onedrive')->with('status', __('operations.onedrive.flash.app_saved'));
    }

    public function deleteApp(Request $request): RedirectResponse
    {
        $user = $this->authorizeAdmin($request);
        $request->validate(['confirm_delete' => ['accepted']]);
        $this->refuseWhileRunning();
        DB::transaction(function () use ($user): void {
            OneDriveConnection::current()->forceFill(['client_id' => null, 'client_secret' => null] + $this->disconnectedState())->save();
            $this->audit($user, 'app_deleted');
        });

        return redirect()->route('admin.operations.onedrive')->with('status', __('operations.onedrive.flash.app_deleted'));
    }

    public function connect(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $connection = OneDriveConnection::current();
        if (! $connection->hasApp()) {
            return redirect()->route('admin.operations.onedrive')->withErrors(['onedrive' => __('operations.onedrive.errors.no_app')]);
        }
        $state = Str::random(40);
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $request->session()->put(self::SESSION_KEY, ['state' => $state, 'verifier' => $verifier, 'expires' => now()->addMinutes(10)->getTimestamp()]);

        return redirect()->away($this->client->authorizationUrl($connection, $this->redirectUri(), $state, $challenge));
    }

    public function callback(Request $request): RedirectResponse
    {
        $user = $this->authorizeAdmin($request);
        $pending = $request->session()->pull(self::SESSION_KEY);
        $state = (string) $request->query('state', '');
        if (! is_array($pending) || ! is_string($pending['state'] ?? null) || ! is_string($pending['verifier'] ?? null)
            || (int) ($pending['expires'] ?? 0) < now()->getTimestamp() || $state === '' || ! hash_equals($pending['state'], $state)) {
            return redirect()->route('admin.operations.onedrive')->withErrors(['onedrive' => __('operations.onedrive.errors.state')]);
        }
        if ($request->filled('error')) {
            return redirect()->route('admin.operations.onedrive')->withErrors(['onedrive' => __('operations.onedrive.errors.denied', ['code' => Str::limit((string) $request->query('error'), 60)])]);
        }
        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect()->route('admin.operations.onedrive')->withErrors(['onedrive' => __('operations.onedrive.errors.state')]);
        }
        try {
            $this->client->connect(OneDriveConnection::current(), $code, $pending['verifier'], $this->redirectUri(), $user);
        } catch (RuntimeException $exception) {
            return redirect()->route('admin.operations.onedrive')->withErrors(['onedrive' => $exception->getMessage()]);
        }
        $this->audit($user, 'connected');

        return redirect()->route('admin.operations.onedrive', ['browse' => 'root'])->with('status', __('operations.onedrive.flash.connected'));
    }

    public function chooseFolder(Request $request): RedirectResponse
    {
        $user = $this->authorizeAdmin($request);
        $validated = $request->validate(['item_id' => ['required', 'string', 'max:200']]);
        $this->refuseWhileRunning();
        $connection = OneDriveConnection::current();
        $item = (string) $validated['item_id'];
        try {
            $folder = $this->client->folder($connection, $item === 'root' ? null : $item)['folder'];
        } catch (RuntimeException $exception) {
            return redirect()->route('admin.operations.onedrive')->withErrors(['onedrive' => $exception->getMessage()]);
        }
        DB::transaction(function () use ($connection, $folder, $user): void {
            $connection->forceFill([
                'folder_item_id' => $folder['id'],
                'folder_name' => $folder['name'] !== '' ? mb_substr($folder['name'], 0, 255) : null,
                'folder_path' => mb_substr($folder['path'], 0, 1000),
                'delta_link' => null,
                'resume_link' => null,
                'resume_offset' => 0,
            ])->save();
            $this->audit($user, 'folder_chosen');
        });

        return redirect()->route('admin.operations.onedrive')->with('status', __('operations.onedrive.flash.folder_chosen', ['folder' => $folder['path']]));
    }

    public function start(Request $request, OperationRunService $runs): RedirectResponse
    {
        $user = $this->authorizeAdmin($request);
        $validated = $request->validate(['mode' => ['required', 'in:'.OneDriveImportService::MODE_IMPORT.','.OneDriveImportService::MODE_SURVEY]]);
        $connection = OneDriveConnection::current();
        if (! $connection->isConnected() || ! $connection->hasFolder()) {
            return redirect()->route('admin.operations.onedrive')->withErrors(['onedrive' => __('operations.onedrive.errors.not_ready')]);
        }
        $this->refuseWhileRunning();
        $run = $runs->dispatchRun(OneDriveImportJob::class, OneDriveImportJob::TYPE, $user, ['mode' => $validated['mode'], 'stats' => []]);

        return redirect()->route('admin.operations.runs.show', $run)->with('status', __('operations.onedrive.flash.started'));
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $user = $this->authorizeAdmin($request);
        $request->validate(['confirm_disconnect' => ['accepted']]);
        $this->refuseWhileRunning();
        DB::transaction(function () use ($user): void {
            OneDriveConnection::current()->forceFill($this->disconnectedState())->save();
            $this->audit($user, 'disconnected');
        });

        return redirect()->route('admin.operations.onedrive')->with('status', __('operations.onedrive.flash.disconnected'));
    }

    /** @return array<string, mixed> */
    private function disconnectedState(): array
    {
        return [
            'refresh_token' => null, 'access_token' => null, 'access_token_expires_at' => null,
            'account_name' => null, 'drive_id' => null, 'connected_by_user_id' => null, 'connected_at' => null,
            'folder_item_id' => null, 'folder_name' => null, 'folder_path' => null,
            'delta_link' => null, 'resume_link' => null, 'resume_offset' => 0,
        ];
    }

    private function refuseWhileRunning(): void
    {
        if (OperationRun::query()->where('operation_type', OneDriveImportJob::TYPE)->whereIn('status', self::ACTIVE)->exists()) {
            abort(redirect()->route('admin.operations.onedrive')->withErrors(['onedrive' => __('operations.onedrive.errors.busy')]));
        }
    }

    private function redirectUri(): string
    {
        return route('admin.operations.onedrive.callback');
    }

    private function audit(User $user, string $action): void
    {
        DB::table('onedrive_connection_audits')->insert(['user_id' => $user->id, 'action' => $action, 'created_at' => now()]);
    }

    private function authorizeAdmin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);

        return $user;
    }
}
