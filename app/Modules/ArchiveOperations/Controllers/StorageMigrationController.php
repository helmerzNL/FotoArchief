<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ArchiveOperations\Jobs\StorageCleanupJob;
use App\Modules\ArchiveOperations\Jobs\StorageCopyJob;
use App\Modules\ArchiveOperations\Models\StorageMigration;
use App\Modules\ArchiveOperations\Services\OperationRunService;
use App\Modules\ArchiveOperations\Services\StorageMigrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StorageMigrationController extends Controller
{
    public function __construct(
        private readonly StorageMigrationService $migrationService,
        private readonly OperationRunService $runService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('users.manage') || $user->hasPermission('catalogue.manage')), 403);

        $disks = $this->migrationService->getAvailableDisks();
        $migrations = StorageMigration::query()->with('initiatedBy')->latest('id')->limit(20)->get();
        $runs = $this->runService->recentRuns(10);

        return view('operations.storage.index', compact('disks', 'migrations', 'runs'));
    }

    public function start(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);

        $validated = $request->validate([
            'source_disk' => ['required', 'string'],
            'target_disk' => ['required', 'string', 'different:source_disk'],
        ]);

        // Only the intent is recorded here; copying and verifying bytes happens on the
        // ingest queue, so a large archive cannot outlive the request.
        $migration = $this->migrationService->prepareMigration($validated['source_disk'], $validated['target_disk'], $user);

        $this->runService->dispatchRun(
            StorageCopyJob::class,
            StorageCopyJob::TYPE,
            $user,
            ['migration_id' => $migration->id],
            $migration->total_files,
        );

        return redirect()
            ->route('admin.operations.storage.index')
            ->with('status', "Migratie {$migration->id} gestart op de achtergrond. {$migration->total_files} bestanden worden gekopieerd en geverifieerd.");
    }

    public function cutover(Request $request, StorageMigration $migration): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);

        // Cutover only flips verified database references; it touches no file bytes and
        // is therefore bounded work that may stay in the request.
        $this->migrationService->cutover($migration, $user);

        return redirect()
            ->route('admin.operations.storage.index')
            ->with('status', "Cutover voltooid. Alle geverifieerde archiefverwijzingen staan nu op [{$migration->target_disk}].");
    }

    public function cleanup(Request $request, StorageMigration $migration): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);

        $this->runService->dispatchRun(
            StorageCleanupJob::class,
            StorageCleanupJob::TYPE,
            $user,
            ['migration_id' => $migration->id],
            $migration->verified_files,
        );

        return redirect()
            ->route('admin.operations.storage.index')
            ->with('status', "Opschoning van bronbestanden op [{$migration->source_disk}] is in de wachtrij geplaatst.");
    }
}
