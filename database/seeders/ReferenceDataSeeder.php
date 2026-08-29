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
 *
 * The three system deal types join them for the same reason: a team with no
 * deal type cannot open a deal, and PRD §2.2 fixes which three exist.
 *
 * The shipped template packs join them on the same argument (#87): F4.2 says
 * the product ships working templates rather than an empty screen, and a pack
 * is a catalogue entry identical for everybody. `TemplatePackSeeder` finds
 * nothing today — `database/packs/` is empty while #87 waits on #11's content
 * — and it is wired up first on purpose, so landing the content is dropping a
 * file in rather than also writing the mechanism to read it.
 *
 * Last, because a pack's workflow associates itself with deal types by name,
 * and the three have to exist to be named.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            SystemRoleSeeder::class,
            DealTypeSeeder::class,
            TemplatePackSeeder::class,
        ]);
    }
}
