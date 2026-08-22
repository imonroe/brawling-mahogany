<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The rows that are part of the schema in everything but name.
 *
 * The permission catalogue and the five system roles are not sample data:
 * PRD §6.2 seeds permissions in code, and PRD §4.2 F2.2 fixes the five access
 * roles. Every environment has them, including production, and the test suite
 * seeds them once alongside its fresh migration.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            SystemRoleSeeder::class,
        ]);
    }
}
