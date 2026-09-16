<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ArchiveOperations\Models\StorageMigration;
use App\Modules\ArchiveOperations\Services\StorageMigrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StorageMigrationController extends Controller
{
    public function __construct(
        private readonly StorageMigrationService $migrationService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('users.manage') || $user->hasPermission('catalogue.manage')), 403);

        $disks = $this->migrationService->getAvailableDisks();
        $migrations = StorageMigration::query()->with('initiatedBy')->latest('id')->limit(20)->get();

        return view('operations.storage.index', compact('disks', 'migrations'));
    }

    public function start(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('users.manage')), 403);

        $validated = $request->validate([
            'source_disk' => ['required', 'string'],
            'target_disk' => ['required', 'string'],
        ]);

        $migration = $this->migrationService->startMigration($validated['source_disk'], $validated['target_disk'], $user);

        return redirect()
            ->route('admin.operations.storage.index')
            ->with('status', "Migratie {$migration->id} gestart en geverifieerd: {$migration->verified_files}/{$migration->total_files} bestanden geverifieerd.");
    }

    public function cutover(Request $request, StorageMigration $migration): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('users.manage')), 403);

        $this->migrationService->cutover($migration, $user);

        return redirect()
            ->route('admin.operations.storage.index')
            ->with('status', "Cutover succesvol voltooid! Alle actieve archiefverwijzingen zijn omgezet naar [{$migration->target_disk}].");
    }

    public function cleanup(Request $request, StorageMigration $migration): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('users.manage')), 403);

        $deleted = $this->migrationService->cleanupSourceFiles($migration, $user);

        return redirect()
            ->route('admin.operations.storage.index')
            ->with('status', "Opschoning voltooid. {$deleted} bronbestanden veilig verwijderd van [{$migration->source_disk}].");
    }
}
