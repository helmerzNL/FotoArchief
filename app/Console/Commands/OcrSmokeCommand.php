<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\ArchiveOperations\Jobs\ProcessAssetOcrJob;
use App\Modules\ArchiveOperations\Models\AssetOcrText;
use App\Modules\ArchiveOperations\Services\TesseractOcrService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Runs the configured Tesseract binary against a generated image and checks that
 * the text comes back.
 *
 * Configuration alone proves nothing: a container can carry the right environment
 * variables and still have no binary, or have one without the Dutch language data,
 * and the first sign of it would be an operator's OCR run failing. This command is
 * meant to run in the image build or in CI, where a missing binary should stop the
 * pipeline rather than reach a user.
 */
class OcrSmokeCommand extends Command
{
    protected $signature = 'operations:ocr-smoke
        {--language=eng : Language code the binary must be able to load}
        {--queued : Also prove the real ingest worker runs the job end to end}
        {--wait=90 : Seconds to wait for the worker to finish the queued job}';

    protected $description = 'Verify that the configured Tesseract binary really extracts text';

    public function handle(TesseractOcrService $service): int
    {
        // In a Compose deployment the binary belongs to the worker container, not
        // necessarily to this one. Probing it here would fail a perfectly healthy
        // installation, so the queued mode asks the worker to prove it instead.
        if ($this->option('queued')) {
            return $this->smokeQueuedJob((string) $this->option('language'));
        }

        $diagnostics = $service->getDiagnostics();

        if ($diagnostics['available'] !== true) {
            $this->error('Tesseract is niet beschikbaar: '.($diagnostics['status_message'] ?? 'onbekende reden'));
            $this->line('Binary: '.($diagnostics['binary'] ?? 'onbekend'));

            return self::FAILURE;
        }

        $language = (string) $this->option('language');
        $languages = $diagnostics['languages'] ?? [];
        if ($languages !== [] && ! in_array($language, $languages, true)) {
            $this->error("Taal [{$language}] ontbreekt. Beschikbaar: ".implode(', ', $languages));

            return self::FAILURE;
        }

        $image = $this->renderProbeImage();
        if ($image === null) {
            $this->error('Kon geen testafbeelding maken; de GD-extensie ontbreekt.');

            return self::FAILURE;
        }

        try {
            $process = new Process([
                (string) $diagnostics['binary'],
                $image,
                'stdout',
                '-l',
                $language,
            ]);
            $process->setTimeout((float) $service->resolveProcessTimeout());
            $process->run();

            if (! $process->isSuccessful()) {
                $this->error('Tesseract gaf een fout terug: '.$process->getErrorOutput());

                return self::FAILURE;
            }

            $text = trim($process->getOutput());
            $this->line('Herkende tekst: '.($text !== '' ? $text : '(leeg)'));

            if (! str_contains(strtoupper(preg_replace('/[^A-Z]/i', '', $text) ?? ''), 'ARCHIEF')) {
                $this->error('De herkende tekst bevat het verwachte woord niet; controleer de taalbestanden.');

                return self::FAILURE;
            }
        } finally {
            @unlink($image);
        }

        $this->info('Tesseract werkt: versie '.($diagnostics['version'] ?? 'onbekend').', taal '.$language.'.');

        return self::SUCCESS;
    }

    /**
     * Proves the whole path an operator actually depends on: a job pushed onto the
     * ingest connection, picked up by the real external worker, writing OCR text
     * back to the live database.
     *
     * It runs against the onboarded installation on purpose -- a separate test
     * database would prove the schema and not the deployment -- so it must leave no
     * trace. Everything it creates is synthetic, carries a recognisable accession
     * number, and is removed again in a finally block. It never touches
     * installation state, existing dossiers, or the queue beyond its own message.
     */
    private function smokeQueuedJob(string $language): int
    {
        $accession = 'OCR-SMOKE-'.strtoupper(Str::random(8));
        $storageKey = 'ocr-smoke/'.$accession.'.png';
        $asset = null;

        $wrongAccount = $this->refuseWrongRuntimeAccount();
        if ($wrongAccount !== null) {
            $this->error($wrongAccount);

            return self::FAILURE;
        }

        try {
            $probe = $this->renderProbeImage();
            if ($probe === null) {
                $this->error('Kon geen testafbeelding maken; de GD-extensie ontbreekt.');

                return self::FAILURE;
            }

            $bytes = (string) file_get_contents($probe);
            @unlink($probe);
            Storage::disk('local')->put($storageKey, $bytes);

            $asset = Asset::query()->create([
                'accession_number' => $accession,
                'title' => 'OCR-rookproef (tijdelijk)',
            ]);

            $file = AssetFile::query()->create([
                'asset_id' => $asset->id,
                'storage_disk' => 'local',
                'storage_key' => $storageKey,
                'sha256' => hash('sha256', $bytes),
                'media_type' => 'image/png',
                'byte_size' => strlen($bytes),
                'original_filename' => $accession.'.png',
                'derivatives' => [],
                'ingest_status' => 'ready_private',
                'is_primary' => true,
            ]);

            Queue::connection('ingest')->push(new ProcessAssetOcrJob($file->id));
            $this->line('Taak op de ingest-wachtrij geplaatst; wachten op de worker...');

            $deadline = time() + max(5, (int) $this->option('wait'));
            $record = null;

            while (time() < $deadline) {
                $record = AssetOcrText::query()->where('asset_file_id', $file->id)->first();
                if ($record !== null && in_array($record->status, ['completed', 'failed', 'disabled'], true)) {
                    break;
                }
                sleep(2);
            }

            if ($record === null) {
                $this->error('De worker heeft de taak niet opgepakt. Draait de ingest-worker en staat OCR_ENABLED ook voor die container aan?');

                return self::FAILURE;
            }

            if ($record->status !== 'completed') {
                $this->error('De worker rondde de taak niet af: '.($record->error_message ?? $record->status));

                return self::FAILURE;
            }

            $recognised = strtoupper(preg_replace('/[^A-Z]/i', '', (string) $record->extracted_text) ?? '');
            if (! str_contains($recognised, 'ARCHIEF')) {
                $this->error('De worker leverde geen bruikbare tekst op; controleer de taalbestanden in de worker-container.');

                return self::FAILURE;
            }

            $this->info('De ingest-worker heeft de OCR-taak uitgevoerd en tekst opgeslagen (taal '.$language.').');

            return self::SUCCESS;
        } finally {
            // The smoke test must not leave a dossier behind in a real archive.
            if ($asset !== null) {
                AssetOcrText::query()->where('asset_id', $asset->id)->delete();
                AssetFile::query()->where('asset_id', $asset->id)->delete();
                $asset->forceDelete();
            }
            Storage::disk('local')->delete($storageKey);
            Storage::disk('local')->deleteDirectory('ocr-smoke');
        }
    }

    /**
     * Refuses to run as an account whose fixture the worker could not read.
     *
     * The local disk stores privately, so Laravel creates directories 0700 and files
     * 0600 owned by whoever wrote them. `docker compose exec` bypasses the entrypoint
     * and uses the image default, root, while deploy/entrypoint.sh runs the worker and
     * artisan under gosu www-data. A fixture written by root therefore lands in a
     * directory the worker cannot traverse: the job reports the file as missing though
     * it is plainly there, and the failure reads like a broken engine.
     *
     * The command refuses rather than repairing it. Taking ownership afterwards would
     * let the acceptance run under an account no real request ever uses, so it would
     * pass while telling an operator nothing about the runtime that actually serves
     * files. Matching the runtime is the property under test.
     *
     * @return string|null an actionable refusal, or null when this account is right
     */
    private function refuseWrongRuntimeAccount(): ?string
    {
        // Windows and any system without POSIX ownership: nothing to compare.
        if (! function_exists('posix_geteuid') || ! function_exists('fileowner')) {
            return null;
        }

        clearstatcache();
        $owner = @fileowner(Storage::disk('local')->path(''));
        if ($owner === false) {
            return null;
        }

        $current = posix_geteuid();
        if ($current === $owner) {
            return null;
        }

        $expected = $this->accountName($owner);

        return 'Dit commando draait als "'.$this->accountName($current).'" maar de opslag hoort bij "'
            .$expected.'". Een testafbeelding van dit account is onleesbaar voor de worker, '
            .'dus de proef zou een fout melden die er niet is. Draai hem als het runtime-account, '
            .'bijvoorbeeld met "docker compose exec --user '.$expected.' app php artisan operations:ocr-smoke --queued".';
    }

    /** Resolves a uid to its account name, falling back to the number. */
    private function accountName(int $uid): string
    {
        if (function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid($uid);
            if (is_array($entry) && $entry['name'] !== '') {
                return $entry['name'];
            }
        }

        return (string) $uid;
    }

    /** Writes a high-contrast probe image and returns its path, or null without GD. */
    private function renderProbeImage(): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $image = imagecreatetruecolor(720, 220);
        if ($image === false) {
            return null;
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 720, 220, $white === false ? 0 : $white);
        imagestring($image, 5, 40, 90, 'ARCHIEF', $black === false ? 0 : $black);

        $path = tempnam(sys_get_temp_dir(), 'ocr-smoke-').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }
}
