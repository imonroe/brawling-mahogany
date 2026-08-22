<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DealSide;
use App\Models\DealType;
use Illuminate\Database\Seeder;

/**
 * The three deal types every team starts with (issue #58 · PRD §2.2).
 *
 * System rows: `team_id` is null, so they belong to nobody and are visible to
 * everybody. A team adds its own beside them and never edits these.
 *
 * ## What is deliberately absent
 *
 * **Commercial** is out of v1 (PRD §2.2), deferred to a template pack rather
 * than dropped.
 *
 * **Ongoing rental and property management is out permanently**, and for a
 * licensing reason rather than a scheduling one. Emily: her brokerage does not
 * manage rentals and *"a lot of us aren't allowed to."* Tenant placement stays
 * in, which is what "Rental Placement" means and why it is not called
 * "Rental" — a name that suggested management would be an invitation to build
 * it.
 */
class DealTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Seller Representation', 'side' => DealSide::Sell, 'sort_order' => 0],
            ['name' => 'Buyer Representation', 'side' => DealSide::Buy, 'sort_order' => 1],
            ['name' => 'Rental Placement', 'side' => DealSide::Rent, 'sort_order' => 2],
        ];

        foreach ($types as $type) {
            /*
             * Idempotent *and* current.
             *
             * This runs on every deploy, so running it twice must not
             * duplicate a lookup that live deals point at — but `firstOrCreate`
             * would also mean a corrected `side` or a re-ordered picker never
             * landed after the first release, which is not what
             * `deploy-staging.yml` promises the seed step does. The name is the
             * identity; everything else is the definition, and the definition
             * ships with the code that changed it.
             *
             * Renaming a system type is a different operation and needs a
             * migration: this would insert the new name beside the old one and
             * leave every existing deal pointing at the old row. That is what
             * `ReferenceDataTest`'s "and no more" assertion is for.
             */
            DealType::query()->updateOrCreate(
                ['team_id' => null, 'name' => $type['name']],
                ['side' => $type['side'], 'sort_order' => $type['sort_order']],
            );
        }
    }
}
