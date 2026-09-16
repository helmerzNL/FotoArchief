<?php

declare(strict_types=1);

use App\Modules\Catalogue\Models\Asset;
use App\Modules\Ingest\Jobs\ProcessUpload;
use App\Modules\Ingest\Services\QuarantineUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('rejects an upload whose detected type is not on the ingest allowlist', function (): void {
    $asset = Asset::query()->create(['accession_number' => 'FA-000003']);
    $upload = UploadedFile::fake()->create('not-an-image.txt', 8, 'text/plain');

    expect(fn () => app(QuarantineUploadService::class)->quarantine($asset, $upload))
        ->toThrow(ValidationException::class);
});

it('streams an allowed upload to private quarantine and dispatches processing', function (string $disk): void {
    config(['filesystems.default' => $disk]);
    Storage::fake($disk);
    Queue::fake();
    $asset = Asset::query()->create(['accession_number' => 'FA-000004']);
    $upload = UploadedFile::fake()->image('scan.png', 50, 50);

    $quarantine = app(QuarantineUploadService::class)->quarantine($asset, $upload);

    expect($quarantine->status)->toBe('queued')
        ->and($quarantine->storage_disk)->toBe($disk)
        ->and($quarantine->storage_key)->toStartWith('quarantine/');

    Storage::disk($disk)->assertExists($quarantine->storage_key);
    Queue::assertPushed(ProcessUpload::class, fn (ProcessUpload $job): bool => $job->uploadId === $quarantine->id);
})->with(['s3', 'local']);
