<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Models\AssetOcrText;
use App\Modules\ArchiveOperations\Services\TesseractOcrService;
use App\Modules\ArchiveOperations\Services\TrashService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\AssetVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Two owners, one archive.
 *
 * Both OCR text and file versions expose the contents of a private dossier. A broad
 * permission check would let any signed-in volunteer read and rewrite photographs
 * belonging to someone else, which is exactly what these cases deny. The boundary is
 * AssetPolicy: assets.view plus either ownership or assets.publish, where
 * assets.publish is a permission held by a role and never a statement that a photo
 * has been published.
 */
/**
 * Real JPEG bytes: reprocessing decodes the original, so a placeholder string would
 * fail for the wrong reason and hide whether authorization actually passed.
 */
function ownershipBoundaryJpeg(string $seed): string
{
    $image = imagecreatetruecolor(48, 32);
    if ($image === false) {
        throw new RuntimeException('Kon geen testafbeelding aanmaken.');
    }
    // asset_files.sha256 is unique, so each dossier needs distinct pixels.
    $hash = crc32($seed);
    $ink = imagecolorallocate($image, $hash % 255, ($hash >> 8) % 255, ($hash >> 16) % 255);
    imagefilledrectangle($image, 0, 0, 24, 32, $ink === false ? 0 : $ink);

    ob_start();
    imagejpeg($image, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}
beforeEach(function (): void {
    $this->artisan('migrate');

    $permissions = [];
    foreach (['assets.view', 'assets.update', 'assets.create', 'assets.publish', 'catalogue.manage', 'users.manage'] as $key) {
        $permissions[$key] = Permission::query()->firstOrCreate(['key' => $key], ['name' => $key]);
    }

    // A volunteer may work fully, but only on its own dossiers.
    $volunteerRole = Role::query()->firstOrCreate(['key' => 'volunteer'], ['name' => 'Volunteer']);
    $volunteerRole->permissions()->syncWithoutDetaching([
        $permissions['assets.view']->id,
        $permissions['assets.update']->id,
        $permissions['assets.create']->id,
        $permissions['catalogue.manage']->id,
    ]);

    // A viewer may only read its own dossiers.
    $viewerRole = Role::query()->firstOrCreate(['key' => 'viewer'], ['name' => 'Viewer']);
    $viewerRole->permissions()->syncWithoutDetaching([$permissions['assets.view']->id]);

    // A curator holds assets.publish and may therefore read across owners.
    $curatorRole = Role::query()->firstOrCreate(['key' => 'curator'], ['name' => 'Curator']);
    $curatorRole->permissions()->syncWithoutDetaching([
        $permissions['assets.view']->id,
        $permissions['assets.update']->id,
        $permissions['assets.publish']->id,
    ]);

    $makeUser = function (string $name, string $email, Role $role): User {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('secret12345'),
        ]);
        $user->roles()->attach($role);

        return $user;
    };

    $this->ownerA = $makeUser('Vrijwilliger A', 'volunteer-a@example.org', $volunteerRole);
    $this->ownerB = $makeUser('Vrijwilliger B', 'volunteer-b@example.org', $volunteerRole);
    $this->viewerB = $makeUser('Lezer B', 'viewer-b@example.org', $viewerRole);
    $this->curator = $makeUser('Curator', 'curator@example.org', $curatorRole);

    Storage::fake('local');

    $this->makeDossier = function (User $owner, string $accession, string $text): array {
        $asset = Asset::query()->create([
            'title' => 'Dossier '.$accession,
            'accession_number' => $accession,
            'created_by_user_id' => $owner->id,
        ]);

        $key = 'originals/'.$accession.'.jpg';
        $bytes = ownershipBoundaryJpeg($accession);
        Storage::disk('local')->put($key, $bytes);

        $file = AssetFile::query()->create([
            'asset_id' => $asset->id,
            'storage_disk' => 'local',
            'storage_key' => $key,
            'sha256' => hash('sha256', $bytes),
            'media_type' => 'image/jpeg',
            'byte_size' => strlen($bytes),
            'original_filename' => $accession.'.jpg',
            'derivatives' => [],
            'ingest_status' => 'ready_private',
            'is_primary' => true,
        ]);

        AssetVersion::query()->create([
            'asset_id' => $asset->id,
            'asset_file_id' => $file->id,
            'version_number' => 1,
            'change_type' => 'scan',
            'change_note' => 'Eerste scan van '.$accession,
        ]);

        $ocr = AssetOcrText::query()->create([
            'asset_id' => $asset->id,
            'asset_file_id' => $file->id,
            'extracted_text' => $text,
            'status' => 'completed',
            'language' => 'nld',
            'processed_at' => now(),
        ]);

        return ['asset' => $asset, 'file' => $file, 'ocr' => $ocr];
    };

    $this->dossierA = ($this->makeDossier)($this->ownerA, 'FA-OWN-A01', 'Geheime notariele akte van eigenaar A');
    $this->dossierB = ($this->makeDossier)($this->ownerB, 'FA-OWN-B01', 'Vertrouwelijke brief van eigenaar B');
});

it('never lists another owner private OCR text in the overview or the search', function (): void {
    $response = $this->actingAs($this->ownerA)->get('/admin/operations/ocr');
    $response->assertOk();
    $response->assertSee('FA-OWN-A01');
    $response->assertDontSee('FA-OWN-B01');
    $response->assertDontSee('Vertrouwelijke brief van eigenaar B');

    // Searching is the same boundary: a matching term must not reveal the row.
    $search = $this->actingAs($this->ownerA)->get('/admin/operations/ocr?q=Vertrouwelijke');
    $search->assertOk();
    $search->assertDontSee('Vertrouwelijke brief van eigenaar B');
    $search->assertDontSee('FA-OWN-B01');

    // Nor by guessing the other owner's accession number.
    $byNumber = $this->actingAs($this->ownerA)->get('/admin/operations/ocr?q=FA-OWN-B01');
    $byNumber->assertOk();
    $byNumber->assertDontSee('Vertrouwelijke brief van eigenaar B');
});

it('denies opening and correcting another owner OCR record by direct id', function (): void {
    $foreign = $this->dossierB['ocr'];

    $this->actingAs($this->ownerA)
        ->get('/admin/operations/ocr/'.$foreign->id)
        ->assertForbidden();

    $this->actingAs($this->ownerA)
        ->post('/admin/operations/ocr/'.$foreign->id, ['edited_text' => 'Overschreven door vreemde'])
        ->assertForbidden();

    // A viewer may not rewrite even its own dossier's transcription.
    $viewerDossier = ($this->makeDossier)($this->viewerB, 'FA-OWN-V01', 'Eigen tekst van lezer');
    $this->actingAs($this->viewerB)
        ->post('/admin/operations/ocr/'.$viewerDossier['ocr']->id, ['edited_text' => 'Correctie zonder recht'])
        ->assertForbidden();

    // The text really is unchanged in the database, not merely hidden.
    expect($foreign->fresh()->getEffectiveText())->toBe('Vertrouwelijke brief van eigenaar B');
    expect($foreign->fresh()->is_edited)->toBeFalse();
});

it('allows an owner to open and correct its own OCR record', function (): void {
    $own = $this->dossierA['ocr'];

    $this->actingAs($this->ownerA)
        ->get('/admin/operations/ocr/'.$own->id)
        ->assertOk()
        ->assertSee('Geheime notariele akte van eigenaar A');

    $this->actingAs($this->ownerA)
        ->post('/admin/operations/ocr/'.$own->id, ['edited_text' => 'Gecorrigeerde transcriptie'])
        ->assertRedirect();

    expect($own->fresh()->getEffectiveText())->toBe('Gecorrigeerde transcriptie');
    expect($own->fresh()->is_edited)->toBeTrue();
});

it('lets a curator holding assets.publish read across owners without that making anything public', function (): void {
    // assets.publish is a permission on the curator role, not a published status on
    // the dossier: it widens who may read privately, and nothing else.
    $response = $this->actingAs($this->curator)->get('/admin/operations/ocr');
    $response->assertOk();
    $response->assertSee('FA-OWN-A01');
    $response->assertSee('FA-OWN-B01');

    $this->actingAs($this->curator)
        ->get('/admin/operations/ocr/'.$this->dossierB['ocr']->id)
        ->assertOk();

    // The dossiers themselves remain private drafts throughout.
    expect($this->dossierA['asset']->fresh()->catalogue_status)->toBe('draft');
    expect($this->dossierB['asset']->fresh()->catalogue_status)->toBe('draft');
});

it('drops OCR rows whose dossier is trashed or gone from every listing and lookup', function (): void {
    $ocr = $this->dossierA['ocr'];

    app(TrashService::class)->moveToTrash($this->dossierA['asset'], 'Dubbel ingevoerd', $this->ownerA);

    // Trashed: the transcription must stop being readable and searchable at once.
    $response = $this->actingAs($this->ownerA)->get('/admin/operations/ocr');
    $response->assertOk();
    $response->assertDontSee('Geheime notariele akte van eigenaar A');

    $search = $this->actingAs($this->ownerA)->get('/admin/operations/ocr?q=Geheime');
    $search->assertOk();
    $search->assertDontSee('Geheime notariele akte van eigenaar A');

    $this->actingAs($this->ownerA)
        ->get('/admin/operations/ocr/'.$ocr->id)
        ->assertNotFound();

    // Even the curator, who may read across owners, sees a trashed dossier as gone.
    $this->actingAs($this->curator)
        ->get('/admin/operations/ocr/'.$ocr->id)
        ->assertNotFound();

    // The service boundary agrees, independent of the HTTP layer.
    $results = app(TesseractOcrService::class)->searchOcrText('Geheime', $this->ownerA);
    expect($results->total())->toBe(0);
});

it('denies listing, uploading, reprocessing and activating versions of another owner dossier', function (): void {
    $foreignAsset = $this->dossierB['asset'];
    $foreignFile = $this->dossierB['file'];

    // Reading the version history of a foreign private dossier.
    $this->actingAs($this->ownerA)
        ->get('/admin/operations/assets/'.$foreignAsset->id.'/versions')
        ->assertForbidden();

    // Writing a new scan version into it.
    $this->actingAs($this->ownerA)
        ->post('/admin/operations/assets/'.$foreignAsset->id.'/versions', [
            'file' => UploadedFile::fake()->image('inbraak.jpg'),
            'change_note' => 'Poging tot schrijven in vreemd dossier',
        ])
        ->assertForbidden();

    // Rebuilding its derivatives.
    $this->actingAs($this->ownerA)
        ->post('/admin/operations/assets/'.$foreignAsset->id.'/versions/'.$foreignFile->id.'/reprocess')
        ->assertForbidden();

    // Switching which version is the active one.
    $this->actingAs($this->ownerA)
        ->post('/admin/operations/assets/'.$foreignAsset->id.'/versions/'.$foreignFile->id.'/set-active')
        ->assertForbidden();

    // A viewer may read only its own, and may never write.
    $viewerDossier = ($this->makeDossier)($this->viewerB, 'FA-OWN-V02', 'Eigen scan van lezer');
    $this->actingAs($this->viewerB)
        ->get('/admin/operations/assets/'.$viewerDossier['asset']->id.'/versions')
        ->assertOk();
    $this->actingAs($this->viewerB)
        ->post('/admin/operations/assets/'.$viewerDossier['asset']->id.'/versions/'.$viewerDossier['file']->id.'/reprocess')
        ->assertForbidden();
});

it('allows an owner to list, reprocess and activate versions of its own dossier', function (): void {
    $asset = $this->dossierA['asset'];
    $file = $this->dossierA['file'];

    $this->actingAs($this->ownerA)
        ->get('/admin/operations/assets/'.$asset->id.'/versions')
        ->assertOk()
        ->assertSee('FA-OWN-A01');

    $this->actingAs($this->ownerA)
        ->post('/admin/operations/assets/'.$asset->id.'/versions/'.$file->id.'/reprocess')
        ->assertRedirect();

    $this->actingAs($this->ownerA)
        ->post('/admin/operations/assets/'.$asset->id.'/versions/'.$file->id.'/set-active')
        ->assertRedirect();

    // The original is never rewritten by either operation.
    expect($file->fresh()->sha256)->toBe(hash('sha256', (string) Storage::disk('local')->get('originals/FA-OWN-A01.jpg')));
    expect($file->fresh()->storage_key)->toBe('originals/FA-OWN-A01.jpg');
});

it('keeps the file-belongs-to-asset guard so an owner cannot reach a foreign file through its own dossier', function (): void {
    $ownAsset = $this->dossierA['asset'];
    $foreignFile = $this->dossierB['file'];

    // Authorised on the dossier, but the file belongs to someone else: 404, not 200.
    $this->actingAs($this->ownerA)
        ->post('/admin/operations/assets/'.$ownAsset->id.'/versions/'.$foreignFile->id.'/reprocess')
        ->assertNotFound();

    $this->actingAs($this->ownerA)
        ->post('/admin/operations/assets/'.$ownAsset->id.'/versions/'.$foreignFile->id.'/set-active')
        ->assertNotFound();

    expect($foreignFile->fresh()->is_primary)->toBeTrue();
});

it('denies dispatching OCR work against another owner dossier', function (): void {
    $this->actingAs($this->ownerA)
        ->post('/admin/operations/ocr/assets/'.$this->dossierB['asset']->id.'/dispatch')
        ->assertForbidden();

    // A viewer cannot start work even on its own dossier: starting OCR writes.
    $viewerDossier = ($this->makeDossier)($this->viewerB, 'FA-OWN-V03', 'Eigen tekst');
    $this->actingAs($this->viewerB)
        ->post('/admin/operations/ocr/assets/'.$viewerDossier['asset']->id.'/dispatch')
        ->assertForbidden();

    $this->actingAs($this->ownerA)
        ->post('/admin/operations/ocr/assets/'.$this->dossierA['asset']->id.'/dispatch')
        ->assertRedirect();
});
