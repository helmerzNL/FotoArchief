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
use App\Modules\Ingest\Services\PdfPageRenderer;
use App\Modules\Ingest\Services\QuarantineUploadService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function pdfFixture(int $pages = 2): string
{
    $kids = implode(' ', array_map(fn (int $page): string => ($page + 2).' 0 R', range(1, $pages)));
    $objects = "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[{$kids}]/Count {$pages}>>endobj\n";
    foreach (range(1, $pages) as $page) {
        $objects .= ($page + 2)." 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\n";
    }

    return "%PDF-1.4\n{$objects}trailer<</Root 1 0 R/Size ".($pages + 3).">>\n%%EOF\n";
}

function pdfUpload(string $content, string $filename = 'document.pdf'): QuarantineUpload
{
    $user = User::query()->create(['name' => 'pdf', 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'created_by_user_id' => $user->id, 'title' => 'PDF']);

    return app(QuarantineUploadService::class)->quarantine(
        $asset, UploadedFile::fake()->createWithContent($filename, $content), $user->id, 'Documenten/'.$filename, allowExtendedTypes: true,
    );
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    config(['filesystems.default' => 'local', 'ingest.scanner' => 'none']);
    Storage::fake('local');
});

it('keeps the PDF original unchanged and builds JPEG previews from its first page', function (): void {
    $content = pdfFixture(2);
    $upload = pdfUpload($content);

    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));

    $file = AssetFile::query()->sole();
    expect($upload->fresh()->status)->toBe('completed')
        ->and($file->media_type)->toBe('application/pdf')
        ->and($file->sha256)->toBe(hash('sha256', $content))
        ->and(Storage::disk('local')->get($file->storage_key))->toBe($content)
        ->and($file->technical_metadata['page_count'])->toBe(2)
        ->and($file->technical_metadata['preview_page'])->toBe(1)
        ->and($file->original_path)->toBe('Documenten/document.pdf');
    foreach ([300, 1200, 2000] as $size) {
        expect(getimagesizefromstring(Storage::disk('local')->get($file->derivatives['preview'.$size]))['mime'])->toBe('image/jpeg');
    }
})->skip(fn () => trim((string) shell_exec('command -v pdftoppm pdfinfo 2>/dev/null')) === '', 'poppler-utils not installed');

it('rejects a corrupt PDF permanently without creating a stored file', function (): void {
    $upload = pdfUpload("%PDF-1.4\nthis is not a pdf");

    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));

    expect($upload->fresh()->status)->toBe('rejected')->and(AssetFile::count())->toBe(0);
})->skip(fn () => trim((string) shell_exec('command -v pdftoppm pdfinfo 2>/dev/null')) === '', 'poppler-utils not installed');

it('treats missing PDF tools as an installation problem that can be retried, not a rejection', function (): void {
    $upload = pdfUpload(pdfFixture(1));
    $processor = new ImageProcessor(new MalwareScanner, new PdfPageRenderer('/nonexistent/pdfinfo', '/nonexistent/pdftoppm'));

    expect(fn () => (new ProcessUpload($upload->id))->handle($processor))->toThrow(RuntimeException::class);
    expect(AssetFile::count())->toBe(0)->and($upload->fresh()->status)->not->toBe('rejected');
});

it('keeps PDFs and BMPs out of the browser upload paths', function (): void {
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid()]);

    expect(fn () => app(QuarantineUploadService::class)->quarantine($asset, UploadedFile::fake()->createWithContent('a.pdf', pdfFixture(1))))
        ->toThrow(ValidationException::class);
});
