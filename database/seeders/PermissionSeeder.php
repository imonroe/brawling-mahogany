<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

/**
 * The permission catalogue, seeded from code (PRD §6.2).
 *
 * Idempotent in all three directions, which is what makes it safe to run on
 * every deploy: a new key is inserted, a changed description is updated, and a
 * key deleted from App\Support\Permissions leaves the table rather than
 * lingering as a tombstone some role still points at.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $catalogue = Permissions::catalogue();

        foreach ($catalogue as $key => $definition) {
            Permission::query()->updateOrCreate(
                ['key' => $key],
                ['group' => $definition['group'], 'description' => $definition['description']],
            );
        }

        Permission::query()->whereNotIn('key', array_keys($catalogue))->delete();
    }
}
