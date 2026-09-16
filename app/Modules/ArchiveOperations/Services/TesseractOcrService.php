<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Models\User;
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
                'status_message' => 'OCR is uitgeschakeld via OCR_ENABLED=false.',
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
                    'status_message' => 'Tesseract binary kon niet worden uitgevoerd: '.$versionProcess->getErrorOutput(),
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
                'status_message' => 'Fout bij controleren van Tesseract: '.$e->getMessage(),
            ];
        }
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
                'error_message' => 'OCR is uitgeschakeld in de configuratie.',
                'processed_at' => now(),
            ])->save();

            return $ocrRecord;
        }

        if (! $diagnostics['available']) {
            $ocrRecord->fill([
                'status' => 'failed',
                'error_message' => 'Tesseract executable niet beschikbaar: '.$diagnostics['status_message'],
                'processed_at' => now(),
            ])->save();

            return $ocrRecord;
        }

        $ocrRecord->status = 'processing';
        $ocrRecord->save();

        // Read image file from storage to temporary local file
        $storage = Storage::disk('local');
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
                'error_message' => 'Aanmaken van tijdelijk bestand mislukt.',
                'processed_at' => now(),
            ])->save();

            return $ocrRecord;
        }

        try {
            file_put_contents($tempPath, $storage->get($file->storage_key));

            $binary = $diagnostics['binary'];
            $timeout = (int) config('services.tesseract.timeout', 60);

            $process = new Process([$binary, $tempPath, 'stdout', '-l', $language]);
            $process->setTimeout($timeout);
            $process->run();

            if (! $process->isSuccessful()) {
                $ocrRecord->fill([
                    'status' => 'failed',
                    'error_message' => 'Tesseract verwerking mislukt: '.$process->getErrorOutput(),
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
                'error_message' => 'Onverwachte OCR-fout: '.$e->getMessage(),
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
     * @return LengthAwarePaginator<int, AssetOcrText>
     */
    public function searchOcrText(?string $query, int $perPage = 15): LengthAwarePaginator
    {
        $builder = AssetOcrText::query()->with(['asset', 'file'])->latest('processed_at');

        if ($query !== null && trim($query) !== '') {
            $searchTerm = '%'.trim($query).'%';
            $builder->where(function ($q) use ($searchTerm): void {
                $q->where('extracted_text', 'like', $searchTerm)
                    ->orWhere('edited_text', 'like', $searchTerm);
            });
        }

        /** @var LengthAwarePaginator<int, AssetOcrText> $paginator */
        $paginator = $builder->paginate($perPage);

        return $paginator;
    }
}
