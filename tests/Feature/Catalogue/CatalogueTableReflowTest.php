<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('renders populated collection tables in focusable bounded scroll regions', function (): void {
    $user = User::query()->create(['name' => 'Reflow editor', 'email' => 'reflow@example.test', 'password' => 'test-password']);
    $role = Role::query()->create(['key' => 'reflow-editor', 'name' => 'Reflow editor']);
    foreach (['assets.view', 'assets.update', 'collections.manage'] as $key) {
        $role->permissions()->attach(Permission::query()->create(['key' => $key, 'name' => $key]));
    }
    $user->roles()->attach($role);
    $collection = Collection::query()->create(['title' => 'Reflow album', 'slug' => 'reflow-album']);
    Collection::query()->create(['title' => 'Child album', 'slug' => 'child-album', 'parent_id' => $collection->id]);
    $asset = Asset::query()->create(['title' => 'Populated collection photograph', 'accession_number' => 'FA-REFLOW-001', 'created_by_user_id' => $user->id]);
    $collection->assets()->attach($asset, ['id' => (string) Str::ulid(), 'position' => 1]);

    $response = $this->actingAs($user)->get(route('catalogue.collections.show', $collection))
        ->assertOk()->assertSee('FA-REFLOW-001');
    expect(substr_count($response->getContent(), '<table '))->toBe(2)
        ->and(substr_count($response->getContent(), 'class="catalogue-table-scroll" role="region"'))->toBe(2);

    // Optional rendered fixture for the standalone real-browser geometry check.
    if ($path = getenv('CATALOGUE_REFLOW_HTML')) {
        File::put($path, $response->getContent());
    }
});

it('wraps every catalogue table and constrains the reusable region', function (): void {
    $tables = 0;
    foreach (File::allFiles(resource_path('views/catalogue')) as $file) {
        $source = File::get($file->getPathname());
        $count = substr_count($source, '<table ');
        expect(substr_count($source, '<x-catalogue-table>'))->toBe($count)
            ->and(substr_count($source, '</x-catalogue-table>'))->toBe($count);
        $tables += $count;
    }
    expect($tables)->toBe(12);
    $component = File::get(resource_path('views/components/catalogue-table.blade.php'));
    expect($component)->toContain('max-width: 100%', 'min-width: 0', 'overflow-x: auto', 'tabindex="0"', 'aria-label=');
});
