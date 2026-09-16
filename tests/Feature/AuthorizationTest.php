<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function userWithPermission(string $permission): User
{
    $permissionModel = Permission::query()->create([
        'key' => $permission,
        'name' => $permission,
    ]);
    $role = Role::query()->create([
        'key' => 'test-role',
        'name' => 'Test role',
    ]);
    $role->permissions()->attach($permissionModel);

    $user = User::query()->create([
        'name' => 'Test user',
        'email' => 'test@example.test',
        'password' => 'not-a-real-password',
    ]);
    $user->roles()->attach($role);

    return $user;
}

it('permits an assigned permission through the server-side gate', function (): void {
    $user = userWithPermission('assets.publish');

    expect(Gate::forUser($user)->allows('assets.publish'))->toBeTrue()
        ->and($user->hasPermission('assets.publish'))->toBeTrue();
});

it('denies an unassigned permission through the server-side gate', function (): void {
    $user = userWithPermission('assets.view');

    expect(Gate::forUser($user)->allows('assets.purge'))->toBeFalse()
        ->and($user->hasPermission('assets.purge'))->toBeFalse();
});
