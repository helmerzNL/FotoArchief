<?php

declare(strict_types=1);

use App\Http\Controllers\AdminAssetController;
use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Ingest\Jobs\ProcessUpload;
use App\Modules\Ingest\Services\ImageProcessor;
use App\Modules\Ingest\Services\QuarantineUploadService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

$root = dirname(__DIR__, 2);

if (getenv('FOTOARCHIEF_DISPOSABLE_AI_IMAGE_LOAD') !== '1') {
    refuse('Refusing image-load harness: set FOTOARCHIEF_DISPOSABLE_AI_IMAGE_LOAD=1 for an isolated disposable fixture.');
}

$arguments = getopt('', ['fixture-dir:', 'images:']);
if (in_array('--workers', $argv, true) || array_filter($argv, static fn (string $argument): bool => str_starts_with($argument, '--workers=')) !== []) {
    refuse('The --workers option was removed: this harness is intentionally single-process. Use the deployment queue runtime you want to prove for concurrency and keep this synthetic smoke as the local binary-ingest check.');
}
$fixture = is_string($arguments['fixture-dir'] ?? null) ? (string) $arguments['fixture-dir'] : '';
$images = filter_var($arguments['images'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 25]]);
if ($fixture === '' || $images === false) {
    refuse('Usage: FOTOARCHIEF_DISPOSABLE_AI_IMAGE_LOAD=1 php tests/Smoke/ai-image-load.php --fixture-dir=EMPTY_MARKED_DIR --images=1..25');
}

$real = realpath($fixture);
if ($real === false || ! is_dir($real)) {
    refuse('Refusing image-load harness: fixture directory must exist.');
}
$marker = $real.DIRECTORY_SEPARATOR.'.fotoarchief-disposable-ai-load';
$entries = array_values(array_diff(scandir($real) ?: [], ['.', '..']));
if (! is_file($marker) || $entries !== ['.fotoarchief-disposable-ai-load']) {
    refuse('Refusing image-load harness: fixture directory must be empty except for .fotoarchief-disposable-ai-load.');
}

foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'INSTALLATION_ENABLED' => 'false',
    'BCRYPT_ROUNDS' => '4',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'FILESYSTEM_DISK' => 'local',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'database',
    'SESSION_DRIVER' => 'array',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->useEnvironmentPath($root.'/tests/Fixtures');
$app->loadEnvironmentFrom('test-settings');
$app->make(Kernel::class)->bootstrap();

config([
    'app.env' => 'testing',
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => ':memory:',
    'filesystems.default' => 'local',
    'filesystems.disks.local.root' => $real.DIRECTORY_SEPARATOR.'storage',
    'ingest.scanner' => 'none',
    'queue.default' => 'database',
    'queue.connections.ingest.driver' => 'database',
    'queue.connections.ingest.connection' => 'sqlite',
    'queue.connections.ingest.queue' => 'ingest',
]);

Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);

$sourceDir = $real.DIRECTORY_SEPARATOR.'sources';
if (! mkdir($sourceDir, 0o700, true) && ! is_dir($sourceDir)) {
    fail('Unable to create synthetic source directory.');
}

$user = User::query()->create([
    'name' => 'Disposable AI Image Load',
    'email' => 'disposable-ai-image-load@example.test',
    'password' => Hash::make('secret12345'),
]);
$user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
Auth::login($user);

$started = hrtime(true);
$accepted = 0;
$processed = 0;
$failed = 0;
$previewed = 0;
$previewBytes = 0;
$failures = [];

for ($index = 1; $index <= $images; $index++) {
    $path = $sourceDir.DIRECTORY_SEPARATOR.sprintf('synthetic-%03d.%s', $index, $index % 2 === 0 ? 'png' : 'jpg');
    $bytes = syntheticImage($index, $index % 2 === 0 ? IMAGETYPE_PNG : IMAGETYPE_JPEG);
    if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
        fail("Unable to write synthetic image {$index}.");
    }

    $asset = Asset::query()->create([
        'accession_number' => 'AI-SMOKE-'.str_pad((string) $index, 5, '0', STR_PAD_LEFT),
        'title' => "Synthetic binary smoke {$index}",
        'created_by_user_id' => $user->id,
    ]);
    $upload = app(QuarantineUploadService::class)->quarantine(
        $asset,
        new UploadedFile($path, basename($path), null, null, true),
        $user->id,
    );
    $accepted++;

    try {
        (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));
        $upload->refresh();
        if ($upload->status !== 'completed') {
            $failed++;
            $failures[] = "{$asset->accession_number}: upload status {$upload->status}";

            continue;
        }
        $file = AssetFile::query()->where('asset_id', $asset->id)->where('is_primary', true)->first();
        if (! $file instanceof AssetFile) {
            $failed++;
            $failures[] = "{$asset->accession_number}: no primary asset file";

            continue;
        }

        $preview = privatePreview($asset->refresh(), $file, $user);
        $size = getimagesizefromstring($preview);
        if ($size === false || ($size['mime'] ?? null) !== 'image/jpeg') {
            $failed++;
            $failures[] = "{$asset->accession_number}: private preview was not a JPEG";

            continue;
        }
        $processed++;
        $previewed++;
        $previewBytes += strlen($preview);
    } catch (Throwable $exception) {
        $failed++;
        $failures[] = "{$asset->accession_number}: ".$exception->getMessage();
    }
}

$elapsedMs = round((hrtime(true) - $started) / 1_000_000, 2);
$metrics = [
    'scope' => 'single-process synthetic binary ingest smoke',
    'images_requested' => $images,
    'uploads_accepted' => $accepted,
    'processed_successfully' => $processed,
    'failed' => $failed,
    'private_previews_verified' => $previewed,
    'private_preview_bytes' => $previewBytes,
    'elapsed_ms' => $elapsedMs,
    'fixture_dir' => $real,
    'database' => 'sqlite::memory:',
    'storage' => 'unique disposable local fixture',
    'workers' => 'not exercised; use deployment queue runtime for concurrency proof',
    'production_50k_proof' => 'blocked: this synthetic smoke is not a representative 50k corpus or live proxy/concurrency proof',
];

echo json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL);
}

exit($failed === 0 && $accepted === $images && $processed === $images && $previewed === $images ? 0 : 1);

function refuse(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(2);
}

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}

function syntheticImage(int $index, int $type): string
{
    $width = 96 + ($index % 5) * 8;
    $height = 72 + ($index % 7) * 6;
    $image = imagecreatetruecolor($width, $height);
    if ($image === false) {
        fail('Unable to allocate synthetic image.');
    }

    $background = imagecolorallocate($image, 220 - ($index % 40), 230 - ($index % 50), 240 - ($index % 60));
    $accent = imagecolorallocate($image, 60 + ($index % 120), 40 + ($index % 80), 90 + ($index % 100));
    imagefill($image, 0, 0, $background);
    imagefilledrectangle($image, 8, 8, $width - 8, $height - 8, $accent);
    imagestring($image, 3, 14, 14, sprintf('FA%03d', $index), $background);

    ob_start();
    try {
        $ok = $type === IMAGETYPE_PNG ? imagepng($image) : imagejpeg($image, null, 88);
        $bytes = ob_get_contents();
    } finally {
        ob_end_clean();
        imagedestroy($image);
    }
    if (! $ok || ! is_string($bytes) || $bytes === '') {
        fail('Unable to encode synthetic image.');
    }

    return $bytes;
}

function privatePreview(Asset $asset, AssetFile $file, User $user): string
{
    $request = Request::create('/synthetic-private-preview');
    $request->setUserResolver(static fn (): User => $user);
    $response = app(AdminAssetController::class)->media($request, $asset, $file, 'preview1200');
    if ($response->getStatusCode() !== 200 || $response->headers->get('Content-Type') !== 'image/jpeg') {
        fail("Private preview response failed with status {$response->getStatusCode()}.");
    }

    ob_start();
    try {
        $response->sendContent();
        $bytes = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    if (! is_string($bytes) || $bytes === '') {
        fail('Private preview response was empty.');
    }
    if (! Storage::disk((string) $file->storage_disk)->exists((string) ($file->derivatives['preview1200'] ?? ''))) {
        fail('Private preview derivative was not stored in the disposable fixture.');
    }

    return $bytes;
}
