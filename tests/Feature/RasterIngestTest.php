<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Ingest\Jobs\ProcessUpload;
use App\Modules\Ingest\Models\QuarantineUpload;
use App\Modules\Ingest\Services\ImageProcessor;
use App\Modules\Ingest\Services\MalwareScanner;
use App\Modules\Ingest\Services\QuarantineUploadService;
use App\Modules\Ingest\Services\RasterImageConverter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function rasterFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../Fixtures/raster/'.$name);
}

function rasterUpload(string $content, string $filename): QuarantineUpload
{
    $user = User::query()->create(['name' => 'raster', 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'created_by_user_id' => $user->id, 'title' => 'Raster']);

    return app(QuarantineUploadService::class)->quarantine(
        $asset, UploadedFile::fake()->createWithContent($filename, $content), $user->id, 'Scans/'.$filename, allowExtendedTypes: true,
    );
}

function vipsMissing(): bool
{
    return trim((string) shell_exec('command -v vips vipsheader 2>/dev/null')) === '';
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    config(['filesystems.default' => 'local', 'ingest.scanner' => 'none']);
    Storage::fake('local');
});

it('keeps a TIFF or HEIC original unchanged and builds JPEG previews from it', function (string $fixture, string $mediaType): void {
    $content = rasterFixture($fixture);
    $upload = rasterUpload($content, $fixture);

    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));

    $file = AssetFile::query()->sole();
    expect($upload->fresh()->status)->toBe('completed')
        ->and($file->media_type)->toBe($mediaType)
        ->and($file->sha256)->toBe(hash('sha256', $content))
        ->and(Storage::disk('local')->get($file->storage_key))->toBe($content)
        ->and($file->pixel_width)->toBe(64)
        ->and($file->pixel_height)->toBe(48)
        ->and($file->original_path)->toBe('Scans/'.$fixture);
    foreach ([300, 1200, 2000] as $size) {
        $preview = Storage::disk('local')->get($file->derivatives['preview'.$size]);
        expect(getimagesizefromstring($preview)['mime'])->toBe('image/jpeg');
    }
    // The preview shows the picture, not a grey placeholder from a missing decoder.
    $rgb = imagecolorat(imagecreatefromstring(Storage::disk('local')->get($file->derivatives['preview300'])), 32, 24);
    expect(abs((($rgb >> 16) & 255) - 200))->toBeLessThan(20)
        ->and(abs((($rgb >> 8) & 255) - 120))->toBeLessThan(20)
        ->and(abs(($rgb & 255) - 40))->toBeLessThan(20);
})->with([
    'TIFF' => ['oranje-64x48.tif', 'image/tiff'],
    'HEIC' => ['oranje-64x48.heic', 'image/heic'],
])->skip(fn () => vipsMissing(), 'libvips-tools not installed');

it('rejects a damaged TIFF or HEIC permanently without creating a stored file', function (string $content, string $filename): void {
    $upload = rasterUpload($content, $filename);

    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));

    expect($upload->fresh()->status)->toBe('rejected')
        ->and($upload->fresh()->failure_reason)->toContain('TIFF- of HEIC-bestand')
        ->and(AssetFile::count())->toBe(0);
})->with([
    'truncated TIFF' => [substr(rasterFixture('oranje-64x48.tif'), 0, 40), 'kapot.tif'],
    'truncated HEIC' => [substr(rasterFixture('oranje-64x48.heic'), 0, 100), 'kapot.heic'],
])->skip(fn () => vipsMissing(), 'libvips-tools not installed');

it('applies the pixel limit to the original before decoding it', function (): void {
    config(['ingest.max_image_pixels' => 1000]);
    $upload = rasterUpload(rasterFixture('oranje-64x48.tif'), 'groot.tif');

    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));

    expect($upload->fresh()->status)->toBe('rejected')->and(AssetFile::count())->toBe(0);
})->skip(fn () => vipsMissing(), 'libvips-tools not installed');

it('treats missing libvips tools as an installation problem that can be retried, not a rejection', function (): void {
    $upload = rasterUpload(rasterFixture('oranje-64x48.heic'), 'telefoon.heic');
    $processor = new ImageProcessor(new MalwareScanner, rasters: new RasterImageConverter('/nonexistent/vips', '/nonexistent/vipsheader'));

    expect(fn () => (new ProcessUpload($upload->id))->handle($processor))->toThrow(RuntimeException::class);
    expect(AssetFile::count())->toBe(0)->and($upload->fresh()->status)->not->toBe('rejected');
});

it('recognises TIFF and HEIC by their first bytes and leaves AVIF and JPEG alone', function (string $head, ?string $expected): void {
    $path = (string) tempnam(sys_get_temp_dir(), 'raster-detect-');
    file_put_contents($path, $head.str_repeat("\0", 64));

    try {
        expect((new RasterImageConverter)->detect($path))->toBe($expected);
    } finally {
        unlink($path);
    }
})->with([
    'little-endian TIFF' => ["II*\0\x08\0\0\0", 'image/tiff'],
    'big-endian TIFF' => ["MM\0*\0\0\0\x08", 'image/tiff'],
    'BigTIFF' => ["II+\0\x08\0\0\0", 'image/tiff'],
    'HEIC' => ["\0\0\0\x18ftypheic\0\0\0\0mif1heic", 'image/heic'],
    'HEIF container with HEIC inside' => ["\0\0\0\x18ftypmif1\0\0\0\0mif1heic", 'image/heic'],
    'AVIF' => ["\0\0\0\x1cftypavif\0\0\0\0avifmif1miaf", null],
    'AVIF that also lists heic' => ["\0\0\0\x1cftypmif1\0\0\0\0mif1avifheic", null],
    'JPEG' => ["\xFF\xD8\xFF\xE0\0\x10JFIF", null],
]);

it('keeps TIFF and HEIC out of the browser upload paths', function (string $fixture): void {
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid()]);

    expect(fn () => app(QuarantineUploadService::class)->quarantine($asset, UploadedFile::fake()->createWithContent($fixture, rasterFixture($fixture))))
        ->toThrow(ValidationException::class);
})->with(['oranje-64x48.tif', 'oranje-64x48.heic']);
