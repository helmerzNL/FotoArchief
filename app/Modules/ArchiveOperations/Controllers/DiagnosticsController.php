<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ArchiveOperations\Services\SystemDiagnosticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DiagnosticsController extends Controller
{
    public function __construct(
        private readonly SystemDiagnosticsService $diagnosticsService,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->hasPermission('users.manage') || $user->hasPermission('audit.view')), 403);

        $diagnostics = $this->diagnosticsService->getAllDiagnostics();

        if ($request->wantsJson()) {
            return response()->json($diagnostics);
        }

        return view('operations.diagnostics', compact('diagnostics'));
    }
}
