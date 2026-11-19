<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ArchiveOperations\Models\BackupJob;
use App\Modules\ArchiveOperations\Services\ArchiveBackupJobService;
use App\Modules\ArchiveOperations\Services\ArchiveBackupRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Operations > Backup: the archive as one file the administrator holds, and putting it back
 * (App-Guidance shared/guidelines/backup-restore.md). Administrators only; every route here.
 */
class ArchiveBackupController extends Controller
{
    /** Each upload request stays far below PHP's post_max_size (110 MB in the image). */
    public const int CHUNK_BYTES = 16_777_216;

    public function __construct(private readonly ArchiveBackupJobService $backups) {}

    public function show(Request $request): View
    {
        $this->authorizeAdministrator($request);

        return view('operations.backup', [
            'automatic' => $this->backups->automaticStatus(),
            'maxUploadBytes' => (int) config('operations.backup.max_upload_bytes'),
        ]);
    }

    public function export(Request $request): JsonResponse
    {
        $user = $this->authorizeAdministrator($request);
        $data = $request->validate(['with_files' => ['required', 'boolean']]);

        return $this->started(fn (): BackupJob => $this->backups->startExport($user, (bool) $data['with_files']));
    }

    public function latest(Request $request): JsonResponse
    {
        $this->authorizeAdministrator($request);
        $job = $this->backups->latest();

        return response()->json(['job' => $job instanceof BackupJob ? $this->backups->present($job) : null])
            ->header('Cache-Control', 'no-store, private');
    }

    public function dismiss(Request $request, BackupJob $job): JsonResponse
    {
        $this->authorizeAdministrator($request);
        $this->backups->dismiss($job);

        return response()->json(['ok' => true]);
    }

    public function download(Request $request, BackupJob $job): BinaryFileResponse
    {
        $this->authorizeAdministrator($request);
        abort_unless($job->kind === BackupJob::KIND_EXPORT && $job->status === BackupJob::STATUS_DONE, 404);
        $path = $this->backups->resultPath($job);
        abort_unless(is_file($path), 404);

        return response()->download($path, (string) $job->result_name, [
            'Content-Type' => $job->includes_files ? 'application/zip' : 'application/json',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Starts a chunked upload: {size} in, {token, chunkBytes} out. */
    public function stageStart(Request $request): JsonResponse
    {
        $this->authorizeAdministrator($request);
        $data = $request->validate(['size' => ['required', 'integer', 'min:1']]);
        try {
            return response()->json(['token' => $this->backups->createStage((int) $data['size']), 'chunkBytes' => self::CHUNK_BYTES]);
        } catch (ArchiveBackupRefused $refused) {
            return $this->refused($refused);
        }
    }

    /** One chunk as the raw body, at ?offset=. */
    public function stageChunk(Request $request, string $token): JsonResponse
    {
        $this->authorizeAdministrator($request);
        $offset = $request->query('offset');
        if (! is_string($offset) || ! ctype_digit($offset)) {
            abort(422);
        }
        // The body as a stream (php://input), not a string, so a chunk is read once.
        $body = $request->getContent(true);
        if (! is_resource($body)) {
            abort(400);
        }
        try {
            return response()->json(['received' => $this->backups->appendChunk($token, (int) $offset, $body)]);
        } catch (ArchiveBackupRefused $refused) {
            return $this->refused($refused);
        } finally {
            fclose($body);
        }
    }

    /** The upload is complete: check the whole file and answer with what it contains. */
    public function stageFinish(Request $request, string $token): JsonResponse
    {
        $this->authorizeAdministrator($request);
        try {
            return response()->json(['token' => $token, 'summary' => $this->backups->finishStage($token)]);
        } catch (ArchiveBackupRefused $refused) {
            return $this->refused($refused);
        }
    }

    public function stageAutomatic(Request $request, string $name): JsonResponse
    {
        $this->authorizeAdministrator($request);
        try {
            return response()->json($this->backups->stageAutomatic($name));
        } catch (ArchiveBackupRefused $refused) {
            return $this->refused($refused);
        }
    }

    public function discard(Request $request, string $token): JsonResponse
    {
        $this->authorizeAdministrator($request);
        $this->backups->discardStage($token);

        return response()->json(['ok' => true]);
    }

    public function restore(Request $request): JsonResponse
    {
        $user = $this->authorizeAdministrator($request);
        $data = $request->validate(['token' => ['required', 'string', 'regex:'.ArchiveBackupJobService::TOKEN_PATTERN]]);

        return $this->started(fn (): BackupJob => $this->backups->startRestore($user, $data['token']));
    }

    public function downloadAutomatic(Request $request, string $name): BinaryFileResponse
    {
        $this->authorizeAdministrator($request);
        try {
            $path = $this->backups->automaticPath($name);
        } catch (ArchiveBackupRefused) {
            abort(404);
        }

        return response()->download($path, $name, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * @param  callable(): BackupJob  $start
     */
    private function started(callable $start): JsonResponse
    {
        try {
            $job = $start();
        } catch (ArchiveBackupRefused $refused) {
            return $this->refused($refused, $refused->key === 'operations.backup.errors.busy' ? 409 : 422);
        }

        return response()->json(['job' => $this->backups->present($job->refresh())], 202);
    }

    private function refused(ArchiveBackupRefused $refused, int $status = 422): JsonResponse
    {
        return response()->json(['message' => __($refused->key, array_map('strval', $refused->params))], $status);
    }

    private function authorizeAdministrator(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);

        return $user;
    }
}
