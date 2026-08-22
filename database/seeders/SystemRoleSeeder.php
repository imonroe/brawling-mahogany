<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SystemRole;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

/**
 * The five access roles (PRD §4.2 F2.2), shared by every team.
 *
 * `team_id` is null on all five: they are the product's roles, not a
 * customer's, and a team's own composed roles (F2.3) sit alongside them.
 *
 * Re-running this resets each system role's permissions to what the code says
 * they are. That is deliberate — a system role edited in the database is a
 * drift, not a customisation, and F2.3 is the supported way to differ.
 */
class SystemRoleSeeder extends Seeder
{
    public function run(): void
    {
        $permissionIds = Permission::query()->pluck('id', 'key');

        foreach (Permissions::forSystemRoles() as $key => $permissions) {
            $role = SystemRole::from($key);

            $model = Role::query()->updateOrCreate(
                ['key' => $key, 'team_id' => null],
                ['name' => $role->label(), 'description' => $role->description(), 'is_system' => true],
            );

            $model->permissions()->sync(
                collect($permissions)->map(fn (string $permission): string => $permissionIds[$permission])->all(),
            );
        }
    }
}
