<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Services\FileVersionService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Ingest\Jobs\ProcessUpload;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Services\ImageProcessor;
use App\Modules\Ingest\Services\OriginalImageDecoder;
use App\Modules\Ingest\Services\PdfPageRenderer;
use App\Modules\Ingest\Services\QuarantineUploadService;
use App\Modules\Ingest\Services\RasterImageConverter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function reprocessAdmin(): User
{
    $user = User::query()->create(['name' => 'reprocess', 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());

    return $user;
}

/** An orange 64x48 picture in the given format; TIFF and HEIC come from the raster fixtures. */
function reprocessContent(string $format): string
{
    if (in_array($format, ['tif', 'heic'], true)) {
        return (string) file_get_contents(__DIR__.'/../Fixtures/raster/oranje-64x48.'.$format);
    }
    if ($format === 'pdf') {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R/Size 4>>\n%%EOF\n";
    }
    $image = imagecreatetruecolor(64, 48);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
    ob_start();
    match ($format) {
        'jpg' => imagejpeg($image, null, 95),
        'png' => imagepng($image),
        'webp' => imagewebp($image, null, 95),
        'bmp' => imagebmp($image),
    };

    return (string) ob_get_clean();
}

/** Runs a file through the real ingest pipeline and returns the stored file. */
function reprocessIngest(string $content, string $filename): AssetFile
{
    $user = reprocessAdmin();
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'created_by_user_id' => $user->id, 'title' => 'Reprocess']);
    $upload = app(QuarantineUploadService::class)->quarantine(
        $asset, UploadedFile::fake()->createWithContent($filename, $content), $user->id, 'Scans/'.$filename, allowExtendedTypes: true,
    );
    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));
    expect($upload->fresh()->status)->toBe('completed');

    return AssetFile::query()->where('asset_id', $asset->id)->sole();
}

/** Stores bytes as an original without running ingest, for originals ingest would have rejected. */
function reprocessStoredFile(string $content, string $mediaType): AssetFile
{
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'title' => 'Beschadigd']);
    $key = 'originals/'.str()->uuid();
    Storage::disk('local')->put($key, $content);

    return AssetFile::query()->create([
        'asset_id' => $asset->id, 'storage_disk' => 'local', 'storage_key' => $key,
        'sha256' => hash('sha256', $content), 'media_type' => $mediaType, 'byte_size' => strlen($content),
        'original_filename' => 'beschadigd', 'pixel_width' => 64, 'pixel_height' => 48,
        'derivatives' => ['preview300' => 'derivatives/oud/preview-300.jpg'],
        'ingest_status' => 'ready_private', 'scanner_status' => 'clean', 'is_primary' => true,
    ]);
}

/** @return list<string> */
function reprocessTemporaryFiles(): array
{
    return glob(sys_get_temp_dir().'/fotoarchief-*') ?: [];
}

function popplerMissing(): bool
{
    return trim((string) shell_exec('command -v pdftoppm pdfinfo 2>/dev/null')) === '';
}

function libvipsMissing(): bool
{
    return trim((string) shell_exec('command -v vips vipsheader 2>/dev/null')) === '';
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    config(['filesystems.default' => 'local', 'ingest.scanner' => 'none']);
    Storage::fake('local');
});

it('regenerates the JPEG previews for every format the import accepts', function (string $format, string $mediaType): void {
    if ($format === 'pdf' && popplerMissing()) {
        $this->markTestSkipped('poppler-utils not installed');
    }
    if (in_array($format, ['tif', 'heic'], true) && libvipsMissing()) {
        $this->markTestSkipped('libvips-tools not installed');
    }
    $content = reprocessContent($format);
    $file = reprocessIngest($content, 'scan.'.$format);
    $before = $file->derivatives;
    $lockVersion = Asset::query()->findOrFail($file->asset_id)->lock_version;
    $temporaryFiles = reprocessTemporaryFiles();

    app(FileVersionService::class)->reprocessDerivatives($file, reprocessAdmin());

    $file->refresh();
    expect($file->media_type)->toBe($mediaType)
        ->and($file->sha256)->toBe(hash('sha256', $content))
        ->and(Storage::disk('local')->get($file->storage_key))->toBe($content)
        ->and(array_keys($file->derivatives))->toBe(['preview300', 'preview1200', 'preview2000'])
        ->and($file->derivatives)->not->toBe($before)
        ->and(Asset::query()->findOrFail($file->asset_id)->lock_version)->toBe($lockVersion + 1)
        ->and(AssetAuditEvent::query()->where('asset_id', $file->asset_id)->where('event_type', 'version.reprocessed')->count())->toBe(1)
        ->and(reprocessTemporaryFiles())->toBe($temporaryFiles);
    foreach ($file->derivatives as $key) {
        expect(getimagesizefromstring(Storage::disk('local')->get($key))['mime'])->toBe('image/jpeg');
    }
    if ($format !== 'pdf') {
        // The preview shows the picture, not a grey placeholder from a missing decoder.
        $rgb = imagecolorat(imagecreatefromstring(Storage::disk('local')->get($file->derivatives['preview300'])), 32, 24);
        expect(abs((($rgb >> 16) & 255) - 200))->toBeLessThan(20)
            ->and(abs((($rgb >> 8) & 255) - 120))->toBeLessThan(20)
            ->and(abs(($rgb & 255) - 40))->toBeLessThan(20);
    }
})->with([
    'JPEG' => ['jpg', 'image/jpeg'],
    'PNG' => ['png', 'image/png'],
    'WebP' => ['webp', 'image/webp'],
    'BMP' => ['bmp', 'image/bmp'],
    'PDF' => ['pdf', 'application/pdf'],
    'TIFF' => ['tif', 'image/tiff'],
    'HEIC' => ['heic', 'image/heic'],
]);

it('shows a damaged original as a validation error and leaves the previews alone', function (string $content, string $mediaType, string $tool): void {
    if ($tool === 'poppler' && popplerMissing()) {
        $this->markTestSkipped('poppler-utils not installed');
    }
    if ($tool === 'libvips' && libvipsMissing()) {
        $this->markTestSkipped('libvips-tools not installed');
    }
    $file = reprocessStoredFile($content, $mediaType);
    $temporaryFiles = reprocessTemporaryFiles();

    try {
        app(FileVersionService::class)->reprocessDerivatives($file, reprocessAdmin());
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('reprocess');
    }

    expect($file->fresh()->derivatives)->toBe(['preview300' => 'derivatives/oud/preview-300.jpg'])
        ->and(Storage::disk('local')->allFiles('derivatives/'.$file->id))->toBe([])
        ->and(AssetAuditEvent::query()->where('event_type', 'version.reprocessed')->count())->toBe(0)
        ->and(reprocessTemporaryFiles())->toBe($temporaryFiles);
})->with([
    'corrupt PDF' => ["%PDF-1.4\nthis is not a pdf", 'application/pdf', 'poppler'],
    'truncated TIFF' => fn (): array => [substr(reprocessContent('tif'), 0, 40), 'image/tiff', 'libvips'],
    'truncated HEIC' => fn (): array => [substr(reprocessContent('heic'), 0, 100), 'image/heic', 'libvips'],
    'not an image' => ['dit is geen afbeelding', 'image/jpeg', 'none'],
    'truncated PNG' => fn (): array => [substr(reprocessContent('png'), 0, 60), 'image/png', 'none'],
]);

it('returns to the versions page with the error instead of failing the request', function (): void {
    $file = reprocessStoredFile('dit is geen afbeelding', 'image/jpeg');
    $asset = Asset::query()->findOrFail($file->asset_id);

    $this->actingAs(reprocessAdmin())
        ->from(route('admin.operations.versions.index', $asset))
        ->post(route('admin.operations.versions.reprocess', [$asset, $file]))
        ->assertRedirect(route('admin.operations.versions.index', $asset))
        ->assertSessionHasErrors('reprocess');
});

it('treats missing PDF or libvips tools as an installation problem, not a validation error', function (string $format, string $mediaType): void {
    $file = reprocessStoredFile(reprocessContent($format), $mediaType);
    $service = new FileVersionService(app(QuarantineUploadService::class), new OriginalImageDecoder(
        new PdfPageRenderer('/nonexistent/pdfinfo', '/nonexistent/pdftoppm'),
        new RasterImageConverter('/nonexistent/vips', '/nonexistent/vipsheader'),
    ));

    expect(fn () => $service->reprocessDerivatives($file, reprocessAdmin()))->toThrow(RuntimeException::class);
    expect($file->fresh()->derivatives)->toBe(['preview300' => 'derivatives/oud/preview-300.jpg'])
        ->and(Storage::disk('local')->allFiles('derivatives/'.$file->id))->toBe([]);
})->with([
    'PDF' => ['pdf', 'application/pdf'],
    'TIFF' => ['tif', 'image/tiff'],
    'HEIC' => ['heic', 'image/heic'],
]);

it('refuses to rebuild from an original whose checksum no longer matches', function (): void {
    $file = reprocessIngest(reprocessContent('png'), 'scan.png');
    $before = $file->derivatives;
    Storage::disk('local')->put($file->storage_key, reprocessContent('jpg'));

    expect(fn () => app(FileVersionService::class)->reprocessDerivatives($file, reprocessAdmin()))
        ->toThrow(RuntimeException::class);
    expect($file->fresh()->derivatives)->toBe($before)
        ->and(Storage::disk('local')->allFiles('derivatives/'.$file->id))->toBe([]);
});

it('applies the pixel limit when rebuilding, also to a TIFF original before decoding it', function (string $format): void {
    if ($format === 'tif' && libvipsMissing()) {
        $this->markTestSkipped('libvips-tools not installed');
    }
    $file = reprocessIngest(reprocessContent($format), 'scan.'.$format);
    config(['ingest.max_image_pixels' => 1000]);

    expect(fn () => app(FileVersionService::class)->reprocessDerivatives($file, reprocessAdmin()))
        ->toThrow(ValidationException::class);
    expect(Storage::disk('local')->allFiles('derivatives/'.$file->id))->toBe([]);
})->with(['PNG' => 'png', 'TIFF' => 'tif']);

it('applies the EXIF orientation of a JPEG original when rebuilding', function (): void {
    $image = imagecreatetruecolor(80, 40);
    ob_start();
    imagejpeg($image);
    $bytes = (string) ob_get_clean();
    // Little-endian TIFF IFD0 with orientation 6 (90 degrees clockwise).
    $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVvvV', 0x0112, 3, 1, 6, 0, 0);
    $oriented = substr($bytes, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2);
    $file = reprocessIngest($oriented, 'staand.jpg');

    app(FileVersionService::class)->reprocessDerivatives($file, reprocessAdmin());

    $preview = Storage::disk('local')->get($file->fresh()->derivatives['preview300']);
    $info = getimagesizefromstring($preview);
    expect($info[0])->toBe(40)->and($info[1])->toBe(80)->and($preview)->not->toContain("Exif\0\0");
});
