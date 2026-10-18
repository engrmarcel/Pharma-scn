<?php

namespace Database\Seeders;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Syncs config/permissions.php into the database. Safe to re-run on every
 * deploy: system role permission sets are replaced, custom roles untouched.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registry = config('permissions.permissions');

        foreach ($registry as $code => [$scope, $description]) {
            Permission::query()->updateOrCreate(['code' => $code], ['scope' => $scope, 'description' => $description]);
        }

        $ids = Permission::query()->pluck('id', 'code');

        foreach (config('permissions.roles') as $scope => $roles) {
            $scopeCodes = array_keys(array_filter($registry, fn ($p) => $p[0] === $scope));

            foreach ($roles as $code => [$name, $permissions]) {
                $role = Role::query()->updateOrCreate(
                    ['organization_id' => null, 'scope' => $scope, 'code' => $code],
                    ['name' => $name, 'is_system' => true],
                );

                $granted = $permissions === '*' ? $scopeCodes : array_intersect($permissions, $scopeCodes);
                $role->permissions()->sync($ids->only($granted)->values());
            }
        }
    }
}
