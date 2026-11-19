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
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Admin → OneDrive: connect a personal OneDrive with a code, choose a folder, count and
 * import (ADR-0022, ADR-0023). Administrators only. The app signs in through the shared,
 * public Vistora app registration unless an administrator entered an own client ID.
 */
class OneDriveController extends Controller
{
    private const SESSION_KEY = 'onedrive.device';

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
            'device' => $this->pendingDevice($request),
            'defaultClientId' => (string) config('ingest.onedrive.client_id', ''),
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

    /** Saves an own client ID; an empty field returns to the shared Vistora app. */
    public function saveApp(Request $request): RedirectResponse
    {
        $user = $this->authorizeAdmin($request);
        $validated = $request->validate(['client_id' => ['nullable', 'uuid']]);
        $this->refuseWhileRunning();
        $connection = OneDriveConnection::current();
        $clientId = strtolower(trim((string) ($validated['client_id'] ?? '')));
        $clientId = $clientId === '' ? null : $clientId;
        if ($clientId === $connection->client_id) {
            return redirect()->route('admin.operations.onedrive');
        }
        $request->session()->forget(self::SESSION_KEY);
        DB::transaction(function () use ($connection, $clientId, $user): void {
            // Tokens belong to the old app; they cannot be used with another one.
            $connection->forceFill(['client_id' => $clientId] + $this->disconnectedState())->save();
            $this->audit($user, $clientId === null ? 'custom_app_reset' : 'custom_app_saved');
        });

        return redirect()->route('admin.operations.onedrive')
            ->with('status', $clientId === null ? __('operations.onedrive.flash.custom_app_reset') : __('operations.onedrive.flash.custom_app_saved'));
    }

    /** Asks Microsoft for a code and shows it; the page then waits for the sign-in. */
    public function connect(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->refuseWhileRunning();
        $connection = OneDriveConnection::current();
        if (! $connection->hasApp()) {
            return redirect()->route('admin.operations.onedrive')->withErrors(['onedrive' => __('operations.onedrive.errors.not_available')]);
        }
        try {
            $login = $this->client->startDeviceLogin($connection);
        } catch (RuntimeException $exception) {
            return redirect()->route('admin.operations.onedrive')->withErrors(['onedrive' => $exception->getMessage()]);
        }
        $request->session()->put(self::SESSION_KEY, [
            // The device code lets whoever holds it finish the sign-in; keep it unreadable.
            'device_code' => Crypt::encryptString($login['device_code']),
            'client_id' => $connection->clientId(),
            'user_code' => $login['user_code'],
            'verification_uri' => $login['verification_uri'],
            'expires' => now()->addSeconds($login['expires_in'])->getTimestamp(),
            'interval' => $login['interval'],
            'next_poll' => now()->addSeconds($login['interval'])->getTimestamp(),
        ]);

        return redirect()->route('admin.operations.onedrive');
    }

    /**
     * Asks Microsoft once whether the sign-in is finished, never faster than the interval
     * Microsoft asked for. Answers JSON for the waiting page, or redirects without script.
     */
    public function poll(Request $request): JsonResponse|RedirectResponse
    {
        $user = $this->authorizeAdmin($request);
        $connection = OneDriveConnection::current();
        $pending = $this->pendingDevice($request);
        if ($pending === null || $pending['client_id'] !== $connection->clientId()) {
            $request->session()->forget(self::SESSION_KEY);

            return $this->pollAnswer($request, 'expired', __('operations.onedrive.errors.expired'));
        }
        if ($pending['next_poll'] > now()->getTimestamp()) {
            return $this->pollAnswer($request, 'pending', null, $pending['next_poll'] - now()->getTimestamp());
        }
        try {
            $deviceCode = Crypt::decryptString($pending['device_code']);
            $status = $this->client->pollDeviceLogin($connection, $deviceCode, $user);
        } catch (DecryptException) {
            $request->session()->forget(self::SESSION_KEY);

            return $this->pollAnswer($request, 'expired', __('operations.onedrive.errors.expired'));
        } catch (RuntimeException $exception) {
            $request->session()->forget(self::SESSION_KEY);

            return $this->pollAnswer($request, 'error', $exception->getMessage());
        }
        if ($status === 'connected') {
            $request->session()->forget(self::SESSION_KEY);
            $this->audit($user, 'connected');

            return $this->pollAnswer($request, 'connected');
        }
        $interval = $pending['interval'] + ($status === 'slow_down' ? 5 : 0);
        $request->session()->put(self::SESSION_KEY, array_merge($pending, [
            'interval' => min($interval, 60),
            'next_poll' => now()->addSeconds(min($interval, 60))->getTimestamp(),
        ]));

        return $this->pollAnswer($request, 'pending', null, min($interval, 60));
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('admin.operations.onedrive');
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

    /**
     * The sign-in that waits for the administrator, when it has not expired.
     *
     * @return array{device_code: string, client_id: string, user_code: string, verification_uri: string, expires: int, interval: int, next_poll: int}|null
     */
    private function pendingDevice(Request $request): ?array
    {
        $pending = $request->session()->get(self::SESSION_KEY);
        if (! is_array($pending) || ! is_string($pending['device_code'] ?? null) || ! is_string($pending['user_code'] ?? null)
            || ! is_string($pending['client_id'] ?? null) || (int) ($pending['expires'] ?? 0) <= now()->getTimestamp()) {
            return null;
        }

        return [
            'device_code' => $pending['device_code'],
            'client_id' => $pending['client_id'],
            'user_code' => $pending['user_code'],
            'verification_uri' => OneDriveClient::safeVerificationUri((string) ($pending['verification_uri'] ?? '')),
            'expires' => (int) $pending['expires'],
            'interval' => max(1, (int) ($pending['interval'] ?? 5)),
            'next_poll' => (int) ($pending['next_poll'] ?? 0),
        ];
    }

    private function pollAnswer(Request $request, string $status, ?string $message = null, int $retryAfter = 0): JsonResponse|RedirectResponse
    {
        $target = $status === 'connected'
            ? route('admin.operations.onedrive', ['browse' => 'root'], false)
            : route('admin.operations.onedrive', [], false);
        if ($request->expectsJson()) {
            return response()->json(array_filter([
                'status' => $status,
                'message' => $message,
                'retry_after' => $status === 'pending' ? max(1, $retryAfter) : null,
                'redirect' => $status === 'pending' ? null : $target,
            ], fn (mixed $value): bool => $value !== null));
        }
        $redirect = redirect()->to($target);

        return match ($status) {
            'connected' => $redirect->with('status', __('operations.onedrive.flash.connected')),
            'pending' => $redirect->with('status', __('operations.onedrive.device.still_waiting')),
            default => $redirect->withErrors(['onedrive' => (string) $message]),
        };
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
