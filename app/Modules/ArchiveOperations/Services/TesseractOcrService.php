<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Models\User;
use App\Modules\ArchiveOperations\Jobs\ProcessAssetOcrJob;
use App\Modules\ArchiveOperations\Models\AssetOcrText;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Ingest\Models\AssetAuditEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class TesseractOcrService
{
    /**
     * @return array{
     *     enabled: bool,
     *     available: bool,
     *     binary: string,
     *     version: string|null,
     *     languages: list<string>,
     *     status_message: string,
     * }
     */
    public function getDiagnostics(): array
    {
        $enabled = (bool) config('services.tesseract.enabled', false);
        $binary = (string) config('services.tesseract.binary', 'tesseract');

        if (! $enabled) {
            return [
                'enabled' => false,
                'available' => false,
                'binary' => $binary,
                'version' => null,
                'languages' => [],
                'status_message' => __('operations.generated.t_5f3f4462187251d4'),
            ];
        }

        try {
            $versionProcess = new Process([$binary, '--version']);
            $versionProcess->setTimeout(5);
            $versionProcess->run();

            if (! $versionProcess->isSuccessful()) {
                return [
                    'enabled' => true,
                    'available' => false,
                    'binary' => $binary,
                    'version' => null,
                    'languages' => [],
                    'status_message' => __('operations.generated.t_ea64f6e299c2df4c').$versionProcess->getErrorOutput(),
                ];
            }

            $versionOutput = trim($versionProcess->getOutput());
            $firstLine = explode("\n", $versionOutput)[0] ?? 'tesseract unknown';

            $langsProcess = new Process([$binary, '--list-langs']);
            $langsProcess->setTimeout(5);
            $langsProcess->run();

            $langs = [];
            if ($langsProcess->isSuccessful()) {
                $lines = array_filter(array_map('trim', explode("\n", $langsProcess->getOutput())));
                // Skip the "List of available languages" header
                $langs = array_values(array_filter($lines, fn ($l) => ! str_contains(strtolower($l), 'languages')));
            }

            return [
                'enabled' => true,
                'available' => true,
                'binary' => $binary,
                'version' => $firstLine,
                'languages' => $langs,
                'status_message' => "Tesseract OCR operationeel ({$firstLine}).",
            ];
        } catch (Throwable $e) {
            return [
                'enabled' => true,
                'available' => false,
                'binary' => $binary,
                'version' => null,
                'languages' => [],
                'status_message' => __('operations.generated.t_7b3b6dc3bec2e0cb').$e->getMessage(),
            ];
        }
    }

    /**
     * Tesseract must always finish, be killed and be recorded inside the job timeout,
     * so the configured process timeout is clamped below ProcessAssetOcrJob::$timeout.
     */
    public function resolveProcessTimeout(): int
    {
        $configured = (int) config('services.tesseract.timeout', 60);
        $ceiling = ProcessAssetOcrJob::MAX_JOB_TIMEOUT_SECONDS - ProcessAssetOcrJob::PROCESS_TIMEOUT_HEADROOM_SECONDS;

        return max(1, min($configured, $ceiling));
    }

    public function processAssetFile(AssetFile $file): AssetOcrText
    {
        $diagnostics = $this->getDiagnostics();
        $language = (string) config('services.tesseract.languages', 'nld+eng');

        $ocrRecord = AssetOcrText::query()->firstOrNew([
            'asset_file_id' => $file->id,
        ], [
            'id' => (string) Str::ulid(),
            'asset_id' => $file->asset_id,
            'language' => $language,
        ]);

        if (! $diagnostics['enabled']) {
            $ocrRecord->fill([
                'status' => 'disabled',
                'error_message' => __('operations.generated.t_2d7cfd72f8feff1a'),
                'processed_at' => now(),
            ])->save();

            return $ocrRecord;
        }

        if (! $diagnostics['available']) {
            $ocrRecord->fill([
                'status' => 'failed',
                'error_message' => __('operations.generated.t_076b96c01c122a08').$diagnostics['status_message'],
                'processed_at' => now(),
            ])->save();

            return $ocrRecord;
        }

        $ocrRecord->status = 'processing';
        $ocrRecord->save();

        // Read image file from storage to temporary local file
        $storage = Storage::disk($file->storage_disk ?? 'local');
        if (! $storage->exists($file->storage_key)) {
            $ocrRecord->fill([
                'status' => 'failed',
                'error_message' => "Bestand niet gevonden in opslag: {$file->storage_key}",
                'processed_at' => now(),
            ])->save();

            return $ocrRecord;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'fa_ocr_');
        if (! is_string($tempPath)) {
            $ocrRecord->fill([
                'status' => 'failed',
                'error_message' => __('operations.generated.t_3940232747566ace'),
                'processed_at' => now(),
            ])->save();

            return $ocrRecord;
        }

        try {
            file_put_contents($tempPath, $storage->get($file->storage_key));

            $binary = $diagnostics['binary'];
            $timeout = $this->resolveProcessTimeout();

            $process = new Process([$binary, $tempPath, 'stdout', '-l', $language]);
            $process->setTimeout($timeout);
            $process->run();

            if (! $process->isSuccessful()) {
                $ocrRecord->fill([
                    'status' => 'failed',
                    'error_message' => __('operations.generated.t_9e2f31d2e7b73be9').$process->getErrorOutput(),
                    'processed_at' => now(),
                ])->save();

                return $ocrRecord;
            }

            $extractedText = trim($process->getOutput());

            $ocrRecord->fill([
                'extracted_text' => $extractedText,
                'status' => 'completed',
                'engine_version' => $diagnostics['version'],
                'error_message' => null,
                'processed_at' => now(),
            ])->save();

            return $ocrRecord;
        } catch (Throwable $e) {
            $ocrRecord->fill([
                'status' => 'failed',
                'error_message' => __('operations.generated.t_c83cb019b6e6608f').$e->getMessage(),
                'processed_at' => now(),
            ])->save();

            return $ocrRecord;
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    public function updateEditedText(AssetOcrText $ocr, string $editedText, User $user): void
    {
        $ocr->update([
            'edited_text' => $editedText,
            'is_edited' => true,
        ]);

        AssetAuditEvent::query()->create([
            'asset_id' => $ocr->asset_id,
            'actor_user_id' => $user->id,
            'event_type' => 'ocr.edited',
            'details' => [
                'ocr_id' => $ocr->id,
                'edited_length' => strlen($editedText),
            ],
        ]);
    }

    /**
     * Searches OCR text within the dossiers the actor is actually allowed to see.
     *
     * OCR text is a verbatim transcription of a private scan, so reading it is reading
     * the dossier. The boundary is therefore the same one AssetPolicy::view applies:
     * the actor must hold assets.view, and may then see its own dossiers, or every
     * dossier only when it also holds assets.publish. assets.publish is a permission
     * granted to a role, not a statement that a photo is published, so it never makes
     * a dossier public - it only widens which private dossiers this actor may read.
     *
     * Rows whose dossier is trashed or already gone are excluded: a deleted dossier
     * must not keep leaking its transcribed text through a search box.
     *
     * @return LengthAwarePaginator<int, AssetOcrText>
     */
    public function searchOcrText(?string $query, User $actor, int $perPage = 15): LengthAwarePaginator
    {
        $builder = AssetOcrText::query()->with(['asset', 'file'])->latest('processed_at');

        if (! $actor->hasPermission('assets.view')) {
            // No read right at all: return an empty page rather than a filtered one.
            $builder->whereRaw('1 = 0');
        } else {
            $builder->whereHas('asset', function ($assetQuery) use ($actor): void {
                // whereHas on a SoftDeletes relation already drops trashed dossiers,
                // and the join itself drops orphan rows whose dossier is gone.
                if (! $actor->hasPermission('assets.publish')) {
                    $assetQuery->where('created_by_user_id', $actor->id);
                }
            });
        }

        if ($query !== null && trim($query) !== '') {
            $searchTerm = '%'.trim($query).'%';
            $builder->where(function ($q) use ($searchTerm): void {
                $q->where('extracted_text', 'like', $searchTerm)
                    ->orWhere('edited_text', 'like', $searchTerm)
                    // Also match the dossier itself, so an operator can reach the OCR
                    // result for one photo from its detail page by accession number.
                    ->orWhereHas('asset', function ($assetQuery) use ($searchTerm): void {
                        $assetQuery->where('accession_number', 'like', $searchTerm)
                            ->orWhere('title', 'like', $searchTerm);
                    });
            });
        }

        /** @var LengthAwarePaginator<int, AssetOcrText> $paginator */
        $paginator = $builder->paginate($perPage);

        return $paginator;
    }
}
