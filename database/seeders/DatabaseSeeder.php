<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'assets.view',
            'assets.create',
            'assets.update',
            'assets.publish',
            'assets.delete',
            'assets.purge',
            'catalogue.manage',
            'collections.manage',
            'users.manage',
            'exports.create',
            'audit.view',
        ];

        foreach ($permissions as $key) {
            Permission::query()->updateOrCreate(
                ['key' => $key],
                ['name' => str_replace('.', ' ', ucfirst($key))],
            );
        }

        $rolePermissions = [
            'administrator' => $permissions,
            'archivist' => [
                'assets.view',
                'assets.create',
                'assets.update',
                'assets.publish',
                'assets.delete',
                'catalogue.manage',
                'collections.manage',
                'exports.create',
                'audit.view',
            ],
            'editor' => [
                'assets.view',
                'assets.create',
                'assets.update',
                'assets.publish',
                'catalogue.manage',
                'collections.manage',
            ],
            'volunteer' => [
                'assets.view',
                'assets.create',
                'assets.update',
            ],
            'viewer' => ['assets.view'],
        ];

        foreach ($rolePermissions as $key => $permissionKeys) {
            $role = Role::query()->updateOrCreate(
                ['key' => $key],
                ['name' => ucfirst($key)],
            );

            $role->permissions()->sync(
                Permission::query()
                    ->whereIn('key', $permissionKeys)
                    ->pluck('id'),
            );
        }
    }
}
