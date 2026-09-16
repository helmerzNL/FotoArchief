<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\ArchiveOperations\Services\TesseractOcrService;
use Illuminate\Console\Command;
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
    protected $signature = 'operations:ocr-smoke {--language=eng : Language code the binary must be able to load}';

    protected $description = 'Verify that the configured Tesseract binary really extracts text';

    public function handle(TesseractOcrService $service): int
    {
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
