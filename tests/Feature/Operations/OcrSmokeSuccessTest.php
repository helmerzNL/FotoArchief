<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Modules\ArchiveOperations\Jobs\ProcessAssetOcrJob;
use App\Modules\ArchiveOperations\Models\AssetOcrText;
use App\Modules\ArchiveOperations\Services\TesseractOcrService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Every other OCR test proves a failure path, because no Tesseract binary exists
 * on a developer machine. That leaves the success path -- the one CI depends on --
 * completely unexercised: a mistake in how the output is read would surface as a
 * failing pipeline blamed on the image, not on this code.
 *
 * These tests install a stub executable that answers exactly like tesseract, so
 * the reading, parsing and persisting of a successful run is verified without
 * needing the real binary. The real binary remains the image's responsibility.
 */
/** @var list<string> Directories to remove after each test. */
$GLOBALS['fa_ocr_stub_dirs'] = [];

function installStubTesseract(string $recognisedText = 'ARCHIEF'): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fa-ocr-stub-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $GLOBALS['fa_ocr_stub_dirs'][] = $directory;

    if (PHP_OS_FAMILY === 'Windows') {
        $path = $directory.DIRECTORY_SEPARATOR.'tesseract.bat';
        $script = "@echo off\r\n"
            ."if \"%~1\"==\"--version\" goto version\r\n"
            ."if \"%~1\"==\"--list-langs\" goto langs\r\n"
            ."echo {$recognisedText}\r\n"
            ."exit /b 0\r\n"
            .":version\r\n"
            ."echo tesseract 5.3.4-stub\r\n"
            ."exit /b 0\r\n"
            .":langs\r\n"
            ."echo List of available languages:\r\n"
            ."echo eng\r\n"
            ."echo nld\r\n"
            ."exit /b 0\r\n";
    } else {
        $path = $directory.DIRECTORY_SEPARATOR.'tesseract';
        $script = "#!/bin/sh\n"
            ."case \"\$1\" in\n"
            ."  --version) echo 'tesseract 5.3.4-stub'; exit 0;;\n"
            ."  --list-langs) echo 'List of available languages:'; echo 'eng'; echo 'nld'; exit 0;;\n"
            ."esac\n"
            ."echo '{$recognisedText}'\n"
            ."exit 0\n";
    }

    file_put_contents($path, $script);
    if (PHP_OS_FAMILY !== 'Windows') {
        chmod($path, 0755);
    }

    config()->set('services.tesseract.enabled', true);
    config()->set('services.tesseract.binary', $path);

    return $path;
}

function ocrSmokeDossier(): AssetFile
{
    $image = imagecreatetruecolor(320, 120);
    $white = imagecolorallocate($image, 255, 255, 255);
    imagefilledrectangle($image, 0, 0, 320, 120, $white === false ? 0 : $white);
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    $asset = Asset::query()->create([
        'accession_number' => 'FA-OCR-OK-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)),
        'title' => 'Leesbaar document',
        'lock_version' => 1,
    ]);

    Storage::disk('local')->put('originals/ocr-ok.png', $bytes);

    return AssetFile::query()->create([
        'asset_id' => $asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/ocr-ok.png',
        'sha256' => hash('sha256', $bytes),
        'media_type' => 'image/png',
        'byte_size' => strlen($bytes),
        'original_filename' => 'ocr-ok.png',
        'derivatives' => [],
        'ingest_status' => 'ready_private',
        'is_primary' => true,
    ]);
}

afterEach(function (): void {
    foreach ($GLOBALS['fa_ocr_stub_dirs'] ?? [] as $directory) {
        foreach ((array) glob($directory.DIRECTORY_SEPARATOR.'*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }
        @rmdir($directory);
    }
    $GLOBALS['fa_ocr_stub_dirs'] = [];
});
it('reports success and the engine version when the binary really returns text', function (): void {
    installStubTesseract();

    $this->artisan('operations:ocr-smoke', ['--language' => 'eng'])
        ->expectsOutputToContain('Herkende tekst')
        ->expectsOutputToContain('Tesseract werkt')
        ->assertExitCode(0);
});

it('fails when the requested language is not one the binary can load', function (): void {
    installStubTesseract();

    $this->artisan('operations:ocr-smoke', ['--language' => 'fra'])
        ->expectsOutputToContain('ontbreekt')
        ->assertExitCode(1);
});

it('fails rather than passing when the binary runs but returns nothing useful', function (): void {
    installStubTesseract('');

    $this->artisan('operations:ocr-smoke', ['--language' => 'eng'])
        ->assertExitCode(1);
});

it('lets the queued job write real machine text back to the dossier', function (string $disk): void {
    Storage::fake('local');
    installStubTesseract();

    $file = ocrSmokeDossier();
    if ($disk !== 'local') {
        Storage::fake($disk);
        Storage::disk($disk)->put($file->storage_key, Storage::disk('local')->get($file->storage_key));
        Storage::disk('local')->delete($file->storage_key);
        $file->update(['storage_disk' => $disk]);
    }

    (new ProcessAssetOcrJob($file->id))->handle(app(TesseractOcrService::class));

    $record = AssetOcrText::query()->where('asset_file_id', $file->id)->firstOrFail();

    expect($record->status)->toBe('completed')
        ->and(strtoupper((string) $record->extracted_text))->toContain('ARCHIEF')
        ->and($record->is_edited)->toBeFalse()
        ->and($record->processed_at)->not->toBeNull();
})->with(['local', 'relocated']);

it('reports the binary as available with its version and languages', function (): void {
    installStubTesseract();

    $diagnostics = app(TesseractOcrService::class)->getDiagnostics();

    expect($diagnostics['enabled'])->toBeTrue()
        ->and($diagnostics['available'])->toBeTrue()
        ->and($diagnostics['version'])->toContain('5.3.4-stub')
        ->and($diagnostics['languages'])->toContain('eng')
        ->and($diagnostics['languages'])->toContain('nld');
});

it('writes a fixture the worker can actually read, and leaves none behind', function (): void {
    // Deliberately not Storage::fake: the defect this covers was a real directory
    // created 0700 by the wrong account, which a faked disk cannot reproduce.
    installStubTesseract();

    $before = Storage::disk('local')->directories();

    // No worker runs in the suite, so the job is never picked up. That is the point:
    // the run must get past writing and reading the fixture before it waits at all.
    $this->artisan('operations:ocr-smoke', ['--queued' => true, '--wait' => 5])
        ->assertExitCode(1);

    $output = Artisan::output();

    expect($output)->not->toContain('Bestand niet gevonden in opslag')
        ->and($output)->not->toContain('maar de opslag hoort bij');

    expect(Storage::disk('local')->directories())->toEqual($before)
        ->and(Asset::query()->where('accession_number', 'like', 'OCR-SMOKE-%')->count())->toBe(0);
});
