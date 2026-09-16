<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Worklist;
use App\Modules\Catalogue\Models\WorklistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createWorklistTestUser(string $roleKey, array $permissions): User
{
    $role = Role::query()->firstOrCreate(['key' => $roleKey], ['name' => ucfirst($roleKey)]);
    $permissionModels = collect($permissions)->map(function ($key) {
        return Permission::query()->firstOrCreate(['key' => $key], ['name' => $key]);
    });
    $role->permissions()->sync($permissionModels->pluck('id'));

    $user = User::query()->create([
        'name' => 'User '.$roleKey,
        'email' => $roleKey.'@example.test',
        'password' => 'password123',
    ]);
    $user->roles()->attach($role);

    return $user;
}

it('displays smart queue statistics and creates dynamic worklists for missing metadata', function (): void {
    $admin = createWorklistTestUser('admin', ['assets.view', 'assets.update', 'assets.publish']);

    // Asset with missing date
    Asset::query()->create([
        'accession_number' => 'FA-WL-01',
        'title' => 'Foto zonder datum',
        'date_precision' => 'unknown',
        'created_by_user_id' => $admin->id,
    ]);

    // Asset with missing rights
    $asset2 = Asset::query()->create([
        'accession_number' => 'FA-WL-02',
        'title' => 'Foto met onbekende rechten',
        'date_precision' => 'exact',
        'date_earliest' => '1950-01-01',
        'date_latest' => '1950-01-01',
        'created_by_user_id' => $admin->id,
    ]);
    $asset2->rights()->create(['verification_status' => 'unverified']);

    // Check index statistics
    $this->actingAs($admin)
        ->get(route('catalogue.worklists.index'))
        ->assertOk()
        ->assertSee('Werklijsten &amp; Curatiewachtrijen', false)
        ->assertSee('Datering ontbreekt');

    // Create a worklist for missing dates
    $response = $this->actingAs($admin)->post(route('catalogue.worklists.store'), [
        'title' => 'Dateringen aanvullen batch 1',
        'worklist_type' => 'missing_date',
        'description' => 'Zoek in archiefboeken naar jaartal.',
        'limit' => 10,
    ]);

    $worklist = Worklist::query()->where('title', 'Dateringen aanvullen batch 1')->first();
    expect($worklist)->not->toBeNull();
    $response->assertRedirect(route('catalogue.worklists.show', $worklist));

    // Verify item was automatically populated
    expect($worklist->items)->toHaveCount(1);
    expect($worklist->items->first()->asset->accession_number)->toBe('FA-WL-01');
});

it('updates worklist item progress, assigns notes, and computes progress percentage', function (): void {
    $admin = createWorklistTestUser('admin', ['assets.view', 'assets.update', 'assets.publish']);

    $asset = Asset::query()->create([
        'accession_number' => 'FA-WL-03',
        'title' => 'Curatie foto 3',
        'created_by_user_id' => $admin->id,
    ]);

    $worklist = Worklist::query()->create([
        'title' => 'Rechtenonderzoek Q3',
        'worklist_type' => 'custom',
        'created_by_user_id' => $admin->id,
        'status' => 'active',
    ]);

    $item = WorklistItem::query()->create([
        'worklist_id' => $worklist->id,
        'asset_id' => $asset->id,
        'status' => 'pending',
    ]);

    expect($worklist->progressPercentage())->toBe(0);

    // Update item to in_progress
    $this->actingAs($admin)
        ->post(route('catalogue.worklists.items.update', [$worklist, $item]), [
            'status' => 'in_progress',
            'note' => 'Contact opgenomen met erfgenaam.',
        ])
        ->assertRedirect();

    $item->refresh();
    expect($item->status)->toBe('in_progress');
    expect($item->note)->toBe('Contact opgenomen met erfgenaam.');

    // Complete item
    $this->actingAs($admin)
        ->post(route('catalogue.worklists.items.update', [$worklist, $item]), [
            'status' => 'completed',
            'note' => 'Toestemming ontvangen.',
        ])
        ->assertRedirect();

    $item->refresh();
    $worklist->refresh();

    expect($item->status)->toBe('completed');
    expect($item->completed_at)->not->toBeNull();
    expect($item->completed_by_user_id)->toBe($admin->id);
    expect($worklist->progressPercentage())->toBe(100);
    expect($worklist->status)->toBe('completed');
});
