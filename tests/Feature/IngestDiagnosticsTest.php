<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Ingest\Jobs\ProcessUpload;
use App\Modules\Ingest\Models\QuarantineUpload;
use App\Modules\Ingest\Services\ImageProcessor;
use App\Modules\Ingest\Services\QuarantineUploadService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * An upload that ends "completed" without a file of its own for its photo must leave
 * a trace in the log, so a failure such as browser test 12 can be explained.
 */

function diagnosticsUpload(int $red): QuarantineUpload
{
    $user = User::query()->create(['name' => 'diagnose', 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'created_by_user_id' => $user->id, 'title' => 'Diagnose']);
    $image = imagecreatetruecolor(20, 10);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, $red, 10, 10));
    ob_start();
    imagejpeg($image);

    return app(QuarantineUploadService::class)->quarantine($asset, UploadedFile::fake()->createWithContent('foto.jpg', (string) ob_get_clean()), $user->id);
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    config(['filesystems.default' => 'local', 'ingest.scanner' => 'none']);
    Storage::fake('local');
});

it('logs nothing unusual for a normal upload', function (): void {
    $upload = diagnosticsUpload(200);
    Log::spy();

    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));

    expect($upload->fresh()->status)->toBe('completed');
    Log::shouldNotHaveReceived('warning');
});

it('logs an upload that completes without a file of its own for its photo', function (): void {
    $upload = diagnosticsUpload(200);
    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));
    // Simulate the unexplained case: the stored file now belongs to another photo, and
    // the upload is processed again (a retry). Storage keys are unique per upload, so
    // only a retry of the same upload reaches the early return.
    $other = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'title' => 'Ander',
        'created_by_user_id' => Asset::query()->findOrFail($upload->asset_id)->created_by_user_id]);
    AssetFile::query()->where('storage_key', $upload->storage_key)->update(['asset_id' => $other->id]);
    QuarantineUpload::query()->whereKey($upload->id)->update(['status' => 'queued']);
    Log::spy();

    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));

    expect($upload->fresh()->status)->toBe('completed');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === __('shared.ingest_log.already_stored')
        && $context['upload_id'] === $upload->id && $context['existing_asset_id'] === $other->id)->once();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === __('shared.ingest_log.completed_without_file')
        && $context['upload_id'] === $upload->id && $context['asset_id'] === $upload->asset_id)->once();
});
