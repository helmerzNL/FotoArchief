<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\SavedAssetSearch;
use App\Modules\Catalogue\Models\Worklist;
use App\Modules\Catalogue\Models\WorklistItem;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function dailyCatalogueUser(string $role = 'administrator'): User
{
    app(DatabaseSeeder::class)->run();
    $user = User::query()->create(['name' => 'Catalogue user', 'email' => str()->ulid().'@example.test', 'password' => 'disposable-test-password']);
    $user->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

    return $user;
}

function dailyCatalogueAsset(User $user, array $values = []): Asset
{
    return Asset::query()->create($values + ['accession_number' => 'FA-'.str()->ulid(), 'title' => 'Daily catalogue', 'created_by_user_id' => $user->id, 'lock_version' => 1]);
}

function dailyCatalogueMetadata(array $values = []): array
{
    return $values + ['title' => 'My title', 'description' => 'My description', 'date_precision' => 'unknown', 'date_earliest' => null, 'date_latest' => null, 'date_display' => null, 'tags' => '', 'rights_holder' => '', 'rights_status' => 'unverified', 'rights_note' => '', 'lock_version' => 1];
}

it('saves only whitelisted personal filters and rechecks ownership when searches run', function (): void {
    $owner = dailyCatalogueUser('volunteer');
    $other = dailyCatalogueUser('volunteer');
    $own = dailyCatalogueAsset($owner, ['description' => null]);
    $foreign = dailyCatalogueAsset($other, ['description' => null]);
    $this->actingAs($owner)->get(route('admin.assets.index', ['missing' => 'description']))->assertOk()->assertSee($own->accession_number)->assertDontSee($foreign->accession_number);
    $this->post(route('catalogue.searches.store'), ['name' => 'Need descriptions', 'filters' => ['missing' => 'description', 'cursor' => $own->id, 'provider_token' => 'must-not-store']])->assertRedirect();
    $search = SavedAssetSearch::query()->sole();
    expect($search->filters)->toBe(['missing' => 'description']);
    $this->get(route('catalogue.searches.run', $search))->assertRedirect(route('admin.assets.index', ['missing' => 'description']));
    $this->actingAs($other)->get(route('catalogue.searches.run', $search))->assertForbidden();
    $this->delete(route('catalogue.searches.destroy', $search), ['confirm' => 1])->assertForbidden();
    $owner->roles()->detach();
    $this->actingAs($owner)->get(route('catalogue.searches.run', $search))->assertForbidden();
});

it('handles each confirmed bulk revision separately without unrequested rights changes or duplicate replay', function (): void {
    $user = dailyCatalogueUser();
    $first = dailyCatalogueAsset($user);
    $second = dailyCatalogueAsset($user);
    $preview = $this->actingAs($user)->post(route('catalogue.bulk.preview'), [
        'asset_ids' => [$first->id, $second->id], 'lock_versions' => [$first->id => 1, $second->id => 1],
        'tags_to_add' => 'Archive', 'update_dates' => 1, 'date_precision' => 'year', 'date_earliest' => '1945-06-12',
        'rights_status' => 'verified',
    ])->assertOk();
    expect($first->tags()->count())->toBe(0);
    $second->update(['lock_version' => 2, 'title' => 'Concurrent']);
    $receipt = $preview->viewData('receipt');
    $this->post(route('catalogue.bulk.apply'), ['receipt' => $receipt])->assertSessionHasErrors('confirm');
    $result = $this->post(route('catalogue.bulk.apply'), ['receipt' => $receipt, 'confirm' => 1])->assertOk()->viewData('results');
    expect(array_column($result, 'ok'))->toBe([true, false]);
    expect($first->fresh()->date_earliest->format('Y-m-d'))->toBe('1945-01-01');
    expect($first->rights()->count())->toBe(0);
    expect($second->fresh()->title)->toBe('Concurrent');
    expect($second->tags()->count())->toBe(0);
    $again = $this->post(route('catalogue.bulk.apply'), ['receipt' => $receipt, 'confirm' => 1])->assertOk()->viewData('results');
    expect(array_column($again, 'ok'))->toBe([false, false]);
    $this->travel(61)->minutes();
    $this->post(route('catalogue.bulk.apply'), ['receipt' => $receipt, 'confirm' => 1])->assertSessionHasErrors('receipt');
});

it('rejects invalid dates before preview and binds receipts to their creator', function (): void {
    $user = dailyCatalogueUser();
    $asset = dailyCatalogueAsset($user);
    $input = ['asset_ids' => [$asset->id], 'lock_versions' => [$asset->id => 1], 'update_dates' => 1, 'date_precision' => 'before', 'date_earliest' => '1950-01-01'];
    $this->actingAs($user)->post(route('catalogue.bulk.preview'), $input)->assertSessionHasErrors('changes');
    $input['date_earliest'] = null;
    $input['date_latest'] = '1950-01-01';
    $receipt = $this->post(route('catalogue.bulk.preview'), $input)->assertOk()->viewData('receipt');
    $other = dailyCatalogueUser();
    $this->actingAs($other)->post(route('catalogue.bulk.apply'), ['receipt' => $receipt, 'confirm' => 1])->assertSessionHasErrors('receipt');
    $this->actingAs($user)->post(route('catalogue.bulk.apply'), ['receipt' => $receipt.'tamper', 'confirm' => 1])->assertSessionHasErrors('receipt');
    expect($asset->fresh()->lock_version)->toBe(1);
});

it('preserves stale input and requires a fresh per-field choice after another concurrent edit', function (): void {
    $user = dailyCatalogueUser();
    $asset = dailyCatalogueAsset($user, ['title' => 'Current title', 'lock_version' => 2]);
    $response = $this->actingAs($user)->put(route('admin.assets.update', $asset), dailyCatalogueMetadata())->assertOk()->assertViewIs('catalogue.conflict');
    expect($response->viewData('proposed')['description'])->toBe('My description');
    $choices = array_fill_keys(array_keys($response->viewData('current')), 'current');
    $choices['description'] = 'proposed';
    $asset->update(['title' => 'Newest title', 'lock_version' => 3]);
    $response = $this->post(route('admin.assets.resolve', $asset), ['receipt' => $response->viewData('receipt'), 'choices' => $choices, 'confirm' => 1])->assertOk()->assertViewIs('catalogue.conflict');
    expect($response->viewData('current')['title'])->toBe('Newest title');
    expect($response->viewData('proposed')['description'])->toBe('My description');
    expect($asset->fresh()->description)->toBeNull();
    $this->post(route('admin.assets.resolve', $asset), ['receipt' => $response->viewData('receipt'), 'choices' => $choices, 'confirm' => 1])->assertRedirect(route('admin.assets.show', $asset));
    expect($asset->fresh()->title)->toBe('Newest title')->and($asset->fresh()->description)->toBe('My description')->and($asset->fresh()->lock_version)->toBe(4);
});

it('records handovers and progress without granting access or changing ownership', function (): void {
    $owner = dailyCatalogueUser('volunteer');
    $outsider = dailyCatalogueUser('volunteer');
    $admin = dailyCatalogueUser();
    $asset = dailyCatalogueAsset($owner);
    $worklist = Worklist::query()->create(['title' => 'Assignment', 'worklist_type' => 'custom', 'created_by_user_id' => $owner->id, 'status' => 'active']);
    $item = WorklistItem::query()->create(['worklist_id' => $worklist->id, 'asset_id' => $asset->id, 'status' => 'pending']);
    $update = ['title' => 'Assignment', 'status' => 'active', 'assigned_to_user_id' => $outsider->id];
    $this->actingAs($owner)->put(route('catalogue.worklists.update', $worklist), $update)->assertSessionHasErrors('assigned_to_user_id');
    $update['assigned_to_user_id'] = $admin->id;
    $this->put(route('catalogue.worklists.update', $worklist), $update)->assertRedirect();
    expect($asset->fresh()->created_by_user_id)->toBe($owner->id);
    $this->actingAs($outsider)->get(route('catalogue.worklists.show', $worklist))->assertForbidden();
    $this->post(route('catalogue.worklists.items.update', [$worklist, $item]), ['status' => 'completed'])->assertForbidden();
    $this->actingAs($admin)->post(route('catalogue.worklists.items.update', [$worklist, $item]), ['status' => 'completed'])->assertRedirect();
    expect($worklist->fresh()->status)->toBe('completed');
    $this->post(route('catalogue.worklists.items.update', [$worklist, $item]), ['status' => 'in_progress'])->assertRedirect();
    expect($worklist->fresh()->status)->toBe('in_progress');
    expect(DB::table('worklist_events')->where('worklist_id', $worklist->id)->pluck('event_type')->all())->toBe(['transferred', 'progress', 'progress']);
    $this->get(route('catalogue.worklists.show', $worklist))->assertOk()->assertSee(__('daily.history'));
});

it('enforces bounded previews and saved search quotas and does not flag valid before dating', function (): void {
    $user = dailyCatalogueUser();
    $asset = dailyCatalogueAsset($user, ['date_precision' => 'before', 'date_latest' => '1950-01-01']);
    $this->actingAs($user)->get(route('admin.assets.index', ['missing' => 'dating']))->assertOk()->assertDontSee($asset->accession_number);
    $ids = [];
    for ($i = 0; $i < 26; $i++) {
        $ids[] = (string) str()->ulid();
    }
    $this->get(route('catalogue.bulk.confirm', ['asset_ids' => $ids]))->assertSessionHasErrors('asset_ids');
    $this->post(route('catalogue.bulk.preview'), ['asset_ids' => $ids, 'lock_versions' => array_fill_keys($ids, 1)])->assertSessionHasErrors('asset_ids');
    for ($i = 0; $i < 50; $i++) {
        SavedAssetSearch::query()->create(['user_id' => $user->id, 'name' => 'Saved '.$i, 'filters' => ['missing' => 'dating']]);
    }
    $this->post(route('catalogue.searches.store'), ['name' => 'Overflow', 'filters' => ['missing' => 'dating']])->assertStatus(422);
    expect(SavedAssetSearch::query()->count())->toBe(50);
});

it('rechecks per-photo permissions after a bulk preview and leaves inaccessible originals unchanged', function (): void {
    $user = dailyCatalogueUser();
    $foreignOwner = dailyCatalogueUser('volunteer');
    $own = dailyCatalogueAsset($user);
    $foreign = dailyCatalogueAsset($foreignOwner);
    $receipt = $this->actingAs($user)->post(route('catalogue.bulk.preview'), [
        'asset_ids' => [$own->id, $foreign->id], 'lock_versions' => [$own->id => 1, $foreign->id => 1], 'tags_to_add' => 'Reviewed',
    ])->assertOk()->viewData('receipt');
    $user->roles()->sync([Role::query()->where('key', 'volunteer')->firstOrFail()->id]);
    $response = $this->post(route('catalogue.bulk.apply'), ['receipt' => $receipt, 'confirm' => 1])->assertOk()->assertDontSee($foreign->accession_number);
    expect(array_column($response->viewData('results'), 'ok'))->toBe([true, false]);
    expect($foreign->fresh()->lock_version)->toBe(1);
    expect($foreign->tags()->count())->toBe(0);
});
