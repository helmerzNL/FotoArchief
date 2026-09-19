<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Models\AiSuggestion;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Publication\Models\Publication;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

$app = require __DIR__.'/bootstrap.php';
$app->make(Kernel::class)->bootstrap();
$root = (string) getenv('FOTOARCHIEF_BROWSER_ROOT');
if (Asset::query()->where('accession_number', 'like', 'FA-BROWSER-%')->exists()) {
    throw new RuntimeException('Browser fixture has already been seeded.');
}
$admin = User::query()->where('email', 'release@example.test')->sole();
$users = ['administrator' => $admin];
foreach (['archivist', 'editor', 'volunteer', 'viewer'] as $role) {
    $user = User::query()->create([
        'name' => 'Browser '.$role, 'email' => $role.'@browser.example.test',
        'password' => Hash::make('disposable-smoke-password'),
    ]);
    $user->roles()->attach(Role::query()->where('key', $role)->sole());
    $users[$role] = $user;
}
$manifest = ['url' => (string) getenv('SMOKE_URL'), 'assets' => [], 'suggestions' => [], 'publications' => []];
foreach (['review', 'stale', 'revoke', 'embargo', 'trash', 'volunteer', 'public'] as $index => $name) {
    $asset = Asset::query()->create([
        'accession_number' => 'FA-BROWSER-'.strtoupper($name),
        'title' => 'Browser '.$name, 'lock_version' => 1,
        'created_by_user_id' => $users[$name === 'volunteer' ? 'volunteer' : 'administrator']->id,
    ])->fresh();
    $bitmap = imagecreatetruecolor(180, 120);
    imagefill($bitmap, 0, 0, imagecolorallocate($bitmap, 30 + $index * 20, 96, 148));
    ob_start();
    imagejpeg($bitmap);
    $bytes = ob_get_clean();
    unset($bitmap);
    $key = 'browser/'.$asset->id.'/original.jpg';
    $derivatives = [];
    Storage::disk('local')->put($key, $bytes);
    foreach (['preview300', 'preview1200', 'preview2000'] as $size) {
        $derivatives[$size] = 'browser/'.$asset->id.'/'.$size.'.jpg';
        Storage::disk('local')->put($derivatives[$size], $bytes);
    }
    $file = AssetFile::query()->create([
        'asset_id' => $asset->id, 'storage_disk' => 'local', 'storage_key' => $key,
        'sha256' => hash('sha256', $bytes), 'media_type' => 'image/jpeg', 'byte_size' => strlen($bytes),
        'original_filename' => $name.'.jpg', 'pixel_width' => 180, 'pixel_height' => 120,
        'derivatives' => $derivatives, 'ingest_status' => 'ready_private', 'scanner_status' => 'clean',
        'is_primary' => true, 'validated_at' => now(), 'processed_at' => now(), 'scanned_at' => now(),
        'technical_metadata' => ['synthetic_browser_fixture' => true, 'real_scanner_executed' => false],
    ]);
    $manifest['assets'][$name] = ['id' => $asset->id, 'file_id' => $file->id, 'title' => $asset->title];
    if (in_array($name, ['review', 'stale', 'volunteer'], true)) {
        $run = AiRun::query()->create([
            'run_type' => AiRun::TYPE_IMAGE_ANALYSIS, 'status' => AiRun::STATUS_SUCCEEDED,
            'asset_id' => $asset->id, 'asset_file_id' => $file->id,
            'source_asset_lock_version' => 1, 'source_file_sha256' => $file->sha256,
            'provider_kind' => 'local', 'provider_name' => 'synthetic-browser-fixture',
            'model_id' => 'no-provider-request', 'idempotency_key' => 'browser-'.$file->id,
            'input_contract' => ['synthetic_browser_fixture' => true, 'provider_called' => false],
        ]);
        foreach ([
            'description' => [AiSuggestion::TYPE_DESCRIPTION, 'Gecontroleerd marktplein uit de browserproef.'],
            'tag' => [AiSuggestion::TYPE_TAG, 'browser-marktplein'],
            'reject' => [AiSuggestion::TYPE_TAG, 'browser-onjuist'],
        ] as $label => [$type, $value]) {
            $suggestion = AiSuggestion::query()->create([
                'ai_run_id' => $run->id, 'asset_id' => $asset->id, 'asset_file_id' => $file->id,
                'source_asset_lock_version' => 1,
                'source_file_sha256' => $name === 'stale' ? str_repeat('0', 64) : $file->sha256,
                'suggestion_type' => $type, 'value' => $value,
            ]);
            $manifest['suggestions'][$name][$label] = $suggestion->id;
        }
    }
    if (in_array($name, ['revoke', 'embargo', 'trash', 'public'], true)) {
        $asset->rights()->create(['verification_status' => 'verified', 'rights_holder' => 'Synthetic browser fixture']);
        $publication = Publication::query()->create([
            'asset_id' => $asset->id, 'status' => 'published', 'privacy_cleared' => true,
            'published_lock_version' => 1, 'permalink_slug' => 'browser-'.$name,
            'download_policy' => 'preview_only', 'published_at' => now(),
            'embargo_until' => $name === 'embargo' ? now()->addDay()->toDateString() : null,
        ]);
        $manifest['publications'][$name] = $publication->id;
    }
}
$operation = OperationRun::query()->create([
    'operation_type' => 'ai.analysis', 'status' => 'failed', 'requested_by_user_id' => $admin->id,
    'total_items' => 1, 'payload' => ['asset_ids' => [$manifest['assets']['review']['id']], 'provider' => 'synthetic-browser-fixture', 'cursor' => 0],
]);
$operation->auditEvents()->create([
    'asset_id' => $manifest['assets']['review']['id'], 'event_type' => 'ai.analysis.item_failed',
    'severity' => 'error', 'message' => 'Synthetic workbench failure',
    'context' => ['secret' => 'not-for-export'],
]);
$manifest['operation_id'] = $operation->id;
$upload = imagecreatetruecolor(151, 101);
imagefill($upload, 0, 0, imagecolorallocate($upload, 119, 44, 201));
imagepng($upload, $root.'/browser-upload.png');
unset($upload);
file_put_contents($root.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Prepared synthetic browser users, AI review records, publication guards and upload image; no AI provider or scanner was called.\n";
