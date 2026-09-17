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
            $this->error(__('shared.generated.t_05b05660839441e7').($diagnostics['status_message'] ?? __('shared.generated.t_9d381c259dc2ae70')));
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
            $this->error(__('shared.generated.t_255127982b0da35b'));

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
                $this->error(__('shared.generated.t_a850180676b5b89c').$process->getErrorOutput());

                return self::FAILURE;
            }

            $text = trim($process->getOutput());
            $this->line(__('shared.generated.t_2c1435454a8f48bc').($text !== '' ? $text : '(leeg)'));

            if (! str_contains(strtoupper(preg_replace('/[^A-Z]/i', '', $text) ?? ''), 'ARCHIEF')) {
                $this->error(__('shared.generated.t_245bda6e1a2e852e'));

                return self::FAILURE;
            }
        } finally {
            @unlink($image);
        }

        $this->info(__('shared.generated.t_5540131742adb070').($diagnostics['version'] ?? 'onbekend').__('shared.generated.t_7c514222c41ad0eb').$language.'.');

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
                $this->error(__('shared.generated.t_255127982b0da35b'));

                return self::FAILURE;
            }

            $bytes = (string) file_get_contents($probe);
            @unlink($probe);
            Storage::disk('local')->put($storageKey, $bytes);

            $asset = Asset::query()->create([
                'accession_number' => $accession,
                'title' => __('shared.generated.t_17847da9a4d8bd04'),
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
            $this->line(__('shared.generated.t_166db3119a351487'));

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
                $this->error(__('shared.generated.t_9ffce31057a25368'));

                return self::FAILURE;
            }

            if ($record->status !== 'completed') {
                $this->error(__('shared.generated.t_2864171336098fb6').($record->error_message ?? $record->status));

                return self::FAILURE;
            }

            $recognised = strtoupper(preg_replace('/[^A-Z]/i', '', (string) $record->extracted_text) ?? '');
            if (! str_contains($recognised, 'ARCHIEF')) {
                $this->error(__('shared.generated.t_ae2177037bef5753'));

                return self::FAILURE;
            }

            $this->info(__('shared.generated.t_01903bed4dde40a7').$language.').');

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

        return __('shared.generated.t_6793c2e16197d2f6').$this->accountName($current).__('shared.generated.t_1a286321df1372ab')
            .$expected.__('shared.generated.t_8fb62a321799af41')
            .__('shared.generated.t_092768e2a6267fe6')
            .__('shared.generated.t_ad66c0086909c6a4').$expected.__('shared.generated.t_5946fe23f4dd5fd5');
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
