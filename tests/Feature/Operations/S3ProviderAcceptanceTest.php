<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\License;
use App\Modules\Ingest\Jobs\ProcessUpload;
use App\Modules\Ingest\Services\ImageProcessor;
use App\Modules\Ingest\Services\QuarantineUploadService;
use App\Modules\Installation\InstallationSettings;
use App\Modules\Installation\InstallationStorage;
use App\Modules\Publication\Models\Publication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function s3AcceptanceSettings(): ?InstallationSettings
{
    $required = [
        'FOTOARCHIEF_TEST_S3_ENDPOINT',
        'FOTOARCHIEF_TEST_S3_REGION',
        'FOTOARCHIEF_TEST_S3_BUCKET',
        'FOTOARCHIEF_TEST_S3_ACCESS_KEY',
        'FOTOARCHIEF_TEST_S3_SECRET_KEY',
    ];

    foreach ($required as $key) {
        if ((string) getenv($key) === '') {
            return null;
        }
    }

    return new InstallationSettings(
        'unused-db-host',
        5432,
        'unused-db',
        'unused-db-user',
        'unused-db-password',
        'prefer',
        's3',
        (string) getenv('FOTOARCHIEF_TEST_S3_ENDPOINT'),
        (string) getenv('FOTOARCHIEF_TEST_S3_REGION'),
        (string) getenv('FOTOARCHIEF_TEST_S3_BUCKET'),
        (string) getenv('FOTOARCHIEF_TEST_S3_ACCESS_KEY'),
        (string) getenv('FOTOARCHIEF_TEST_S3_SECRET_KEY'),
        filter_var(getenv('FOTOARCHIEF_TEST_S3_PATH_STYLE') ?: true, FILTER_VALIDATE_BOOL),
    );
}

function configureS3AcceptanceDisk(InstallationSettings $settings): void
{
    $settings->apply(config());
    config([
        'filesystems.default' => 's3',
        'filesystems.disks.s3.throw' => true,
        'ingest.scanner' => 'none',
    ]);
    Storage::forgetDisk('s3');
}

function s3AcceptanceUser(): User
{
    app(DatabaseSeeder::class)->run();
    $user = User::query()->create([
        'name' => 'S3 Acceptance',
        'email' => 's3-acceptance@example.test',
        'password' => Hash::make('disposable-s3-password'),
    ]);
    $user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());

    return $user;
}

function s3AnonymousUrl(InstallationSettings $settings, string $key): string
{
    $endpoint = rtrim($settings->endpoint, '/');
    $path = implode('/', array_map('rawurlencode', explode('/', $key)));

    if ($settings->pathStyle) {
        return $endpoint.'/'.rawurlencode($settings->bucket).'/'.$path;
    }

    $parts = parse_url($endpoint);
    if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
        throw new RuntimeException('FOTOARCHIEF_TEST_S3_ENDPOINT must be an absolute HTTP(S) endpoint.');
    }
    $authority = $settings->bucket.'.'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

    return $parts['scheme'].'://'.$authority.'/'.$path;
}

function s3AnonymousStatus(string $url): int
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Cannot initialize anonymous S3 request.');
    }
    curl_setopt_array($curl, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $result = curl_exec($curl);
    if ($result === false) {
        $message = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('Anonymous S3 request failed: '.$message);
    }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    return (int) $status;
}

it('proves installation probe and ingest against a real private S3 compatible bucket', function (): void {
    $settings = s3AcceptanceSettings();
    if (! $settings instanceof InstallationSettings) {
        $this->markTestSkipped('Set FOTOARCHIEF_TEST_S3_* variables for real S3/Hetzner acceptance.');
    }

    configureS3AcceptanceDisk($settings);
    app(InstallationStorage::class)->check($settings);

    $createdKeys = [];
    $user = s3AcceptanceUser();
    $asset = Asset::query()->create([
        'accession_number' => 'S3-'.(string) str()->ulid(),
        'title' => 'S3 provider acceptance',
        'created_by_user_id' => $user->id,
    ]);

    try {
        $upload = app(QuarantineUploadService::class)->quarantine(
            $asset,
            UploadedFile::fake()->image('s3-acceptance.png', 320, 160),
            $user->id,
        );
        $createdKeys[] = $upload->storage_key;

        (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));

        $file = AssetFile::query()->where('asset_id', $asset->id)->sole();
        $createdKeys[] = $file->storage_key;
        foreach ($file->derivatives as $derivative) {
            $createdKeys[] = $derivative;
        }

        expect($upload->fresh()->status)->toBe('completed')
            ->and($file->storage_disk)->toBe('s3')
            ->and($file->scanner_status)->toBe('unscanned')
            ->and(Storage::disk('s3')->exists($file->storage_key))->toBeTrue();
        expect(Storage::disk('s3')->visibility($file->storage_key))->toBe('private');

        foreach ($file->derivatives as $derivative) {
            expect(Storage::disk('s3')->exists($derivative))->toBeTrue()
                ->and(Storage::disk('s3')->visibility($derivative))->toBe('private')
                ->and(s3AnonymousStatus(s3AnonymousUrl($settings, $derivative)))->toBeIn([401, 403, 404]);
        }

        expect(s3AnonymousStatus(s3AnonymousUrl($settings, $file->storage_key)))->toBeIn([401, 403, 404]);

        $file->forceFill(['scanner_status' => 'clean', 'scanned_at' => now()])->save();
        $license = License::query()->firstOrCreate(['code' => 's3-acceptance'], ['name' => 'S3 acceptance', 'url' => 'https://example.test/s3-acceptance']);
        $asset->rights()->create(['verification_status' => 'verified', 'rights_holder' => 'S3 acceptance', 'license_id' => $license->id]);
        $publication = Publication::query()->create([
            'asset_id' => $asset->id,
            'status' => 'published',
            'privacy_cleared' => true,
            'published_lock_version' => $asset->lock_version,
            'permalink_slug' => 's3-'.str()->slug((string) str()->ulid()),
            'download_policy' => 'preview_only',
        ]);
        $this->get(route('public.photo.media', [$publication, 'preview300']))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $publication->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_reason' => 'S3 denial check']);
        $this->get('/foto/'.$publication->permalink_slug.'/media/preview300')->assertNotFound();
    } finally {
        if ($createdKeys !== []) {
            Storage::disk('s3')->delete(array_values(array_unique($createdKeys)));
        }
    }
});

it('keeps failed S3 reads retryable instead of creating clean metadata', function (): void {
    $settings = s3AcceptanceSettings();
    if (! $settings instanceof InstallationSettings) {
        $this->markTestSkipped('Set FOTOARCHIEF_TEST_S3_* variables for real S3/Hetzner acceptance.');
    }

    configureS3AcceptanceDisk($settings);
    $user = s3AcceptanceUser();
    $asset = Asset::query()->create([
        'accession_number' => 'S3-FAIL-'.(string) str()->ulid(),
        'title' => 'S3 missing object acceptance',
        'created_by_user_id' => $user->id,
    ]);
    $upload = app(QuarantineUploadService::class)->quarantine(
        $asset,
        UploadedFile::fake()->image('missing-object.png', 120, 60),
        $user->id,
    );
    Storage::disk('s3')->delete($upload->storage_key);

    expect(fn () => (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class)))
        ->toThrow(Throwable::class);
    expect($upload->fresh()->status)->toBe('queued')
        ->and(AssetFile::query()->where('asset_id', $asset->id)->count())->toBe(0);
});
