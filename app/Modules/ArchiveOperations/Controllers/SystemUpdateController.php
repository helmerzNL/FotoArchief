<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ArchiveOperations\Services\UpdateCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Settings > System: the installed and the latest version, the update check and the update steps
 * for the way this installation is deployed. Administrators only; nothing here updates the app.
 */
class SystemUpdateController extends Controller
{
    public const array DEPLOYMENTS = ['compose', 'komodo', 'dockhand', 'webhosting'];

    public function __construct(private readonly UpdateCheckService $updates) {}

    public function show(Request $request): View
    {
        $this->authorizeAdministrator($request);

        return view('operations.system', [
            'update' => $this->updates->cached(),
            'deployment' => $this->deployment(),
            'deployments' => self::DEPLOYMENTS,
            'channel' => config('app.channel'),
            'releaseTag' => config('app.release_tag'),
        ]);
    }

    public function check(Request $request): RedirectResponse
    {
        $this->authorizeAdministrator($request);
        $status = $this->updates->status(force: true);

        return redirect()->route('admin.operations.system')->with('status', match ($status['state']) {
            'available' => __('operations.update.checked_available', ['latest' => $status['latest']]),
            'disabled' => __('operations.update.state.disabled'),
            default => $status['error'] !== null
                ? __('operations.update.checked_failed', ['reason' => $status['error']])
                : __('operations.update.checked_current'),
        });
    }

    public function status(Request $request): JsonResponse
    {
        $this->authorizeAdministrator($request);

        return response()->json($this->updates->status());
    }

    /** The configured deployment method, or the likely one: a container means Compose. */
    private function deployment(): string
    {
        $configured = strtolower(trim((string) config('operations.updates.deployment')));
        if (in_array($configured, self::DEPLOYMENTS, true)) {
            return $configured;
        }

        return is_file('/.dockerenv') || getenv('FOTOARCHIEF_TAG') !== false ? 'compose' : 'webhosting';
    }

    private function authorizeAdministrator(Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);
    }
}
