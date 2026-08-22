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
            // Idempotent: this seeder runs in every environment including
            // production, and running it twice must not duplicate a lookup
            // that live deals point at.
            DealType::query()->firstOrCreate(
                ['team_id' => null, 'name' => $type['name']],
                ['side' => $type['side'], 'sort_order' => $type['sort_order']],
            );
        }
    }
}
