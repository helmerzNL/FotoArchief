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

        try {
            $probe = $this->renderProbeImage();
            if ($probe === null) {
                $this->error('Kon geen testafbeelding maken; de GD-extensie ontbreekt.');

                return self::FAILURE;
            }

            $bytes = (string) file_get_contents($probe);
            @unlink($probe);
            Storage::disk('local')->put($storageKey, $bytes);

            $unreadable = $this->alignFixtureWithRuntimeUser($storageKey);
            if ($unreadable !== null) {
                $this->error($unreadable);

                return self::FAILURE;
            }

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
     * Makes the synthetic fixture readable by the account the worker runs as.
     *
     * The local disk stores privately, so Laravel creates directories 0700 and files
     * 0600 owned by whoever wrote them. `docker compose exec` defaults to root while
     * the worker runs as the web account, so a fixture written by root lands in a
     * directory the worker cannot traverse: the job then reports the file as missing
     * although it is plainly there, and the failure reads like a broken engine.
     *
     * Ownership is aligned with the disk root rather than permissions widened, so the
     * fixture stays as private as every other stored file. When alignment is not
     * possible the command says which account to use instead of dispatching a job
     * that is certain to fail.
     *
     * @return string|null an actionable problem, or null when the fixture is readable
     */
    private function alignFixtureWithRuntimeUser(string $storageKey): ?string
    {
        // Windows and any system without POSIX ownership: nothing to align.
        if (! function_exists('posix_geteuid') || ! function_exists('fileowner')) {
            return null;
        }

        $disk = Storage::disk('local');
        $filePath = $disk->path($storageKey);
        $directoryPath = dirname($filePath);
        $rootPath = $disk->path('');

        clearstatcache();
        $owner = @fileowner($rootPath);
        if ($owner === false) {
            return null;
        }

        $group = @filegroup($rootPath);
        $isRoot = posix_geteuid() === 0;

        foreach ([$directoryPath, $filePath] as $path) {
            if (@fileowner($path) === $owner) {
                continue;
            }

            if (! $isRoot) {
                return $this->wrongOwnerMessage($owner);
            }

            @chown($path, $owner);
            if ($group !== false) {
                @chgrp($path, $group);
            }
        }

        clearstatcache();
        if (@fileowner($filePath) !== $owner || @fileowner($directoryPath) !== $owner) {
            return $this->wrongOwnerMessage($owner);
        }

        return null;
    }

    /** Names the account the fixture must belong to, so the operator can act on it. */
    private function wrongOwnerMessage(int $owner): string
    {
        $name = (string) $owner;
        if (function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid($owner);
            if (is_array($entry) && $entry['name'] !== '') {
                $name = $entry['name'];
            }
        }

        return 'De testafbeelding is niet leesbaar voor de worker: opslag hoort bij "'.$name
            .'" maar het bestand niet. Draai dit commando als dat account, bijvoorbeeld met '
            .'"docker compose exec --user '.$name.' app php artisan operations:ocr-smoke --queued".';
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
