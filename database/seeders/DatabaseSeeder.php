<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * The two reference seeders run everywhere, including production: the
     * permission catalogue and the five system roles are part of the schema in
     * everything but name. The demo team is local only.
     *
     * Note the absence of `WithoutModelEvents`. It was here, and it has to go:
     * the trait silences model events, and `BelongsToTeam` fills `team_id` in
     * a `creating` hook. Seeding with events off writes rows with no tenant —
     * which the not-null column catches, loudly, but only after somebody has
     * spent an afternoon wondering why.
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        if (! app()->isProduction()) {
            $this->call(DemoTeamSeeder::class);
        }
    }
}
