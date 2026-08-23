<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which properties a deal is about (PRD §4.3 F3.4 · S36 · issue #61).
 *
 * ## Many to many, in both directions
 *
 * Issue #61's definition of done: *"A property can be linked to more than one
 * deal over time and shows both."* A house is listed, falls through, and is
 * listed again by the same team a year later — that is two deals and one
 * property, and a `deals.property_id` column would have made the second deal
 * overwrite the first deal's history of what it was about.
 *
 * The other direction is just as real and is why this is not a one-to-many the
 * other way round either: a buyer-side deal tours nine houses before it makes
 * an offer on one, and PRD §7.13 wants all nine on the record.
 *
 * ## `is_subject`, and what it is not
 *
 * IA §10 derives a deal's name from *"subject property street address"*, so
 * "which of these nine" has to have an answer. One row per deal may carry the
 * flag, and the partial unique index below is what makes that true rather
 * than a convention.
 *
 * What this issue does **not** build is the deal side of it: the properties
 * tab, the interest vocabulary that distinguishes a house being toured from
 * one under offer, and the explicit promote interaction are #62. All #61 does
 * is set the flag when a deal acquires its first property, because a deal with
 * exactly one property and no subject would be a deal that cannot be named.
 *
 * ## A full table, not a bare pivot
 *
 * `productDefaults()` rather than two columns and a primary key, for the
 * reason every other table here has it: `records:purge` (PRD §9) discovers its
 * tables through `team_id`, and soft deletes are what the 30-day window is
 * made of. #62 adds `interest_status` to this row, which a bare pivot would
 * have had to be migrated into anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_properties', function (Blueprint $table): void {
            $table->productDefaults();

            $table->teamScopedForeign('deal_id', 'deals');
            $table->teamScopedForeign('property_id', 'properties');

            $table->boolean('is_subject')->default(false);

            // S36 asks a property which deals it is on; #62 will ask a deal
            // which properties it is about.
            $table->index(['property_id', 'deal_id']);
        });

        /*
         * The same property twice on one deal is a duplicate with no meaning.
         * Partial, so unlinking frees the pairing again — a property removed
         * from a deal by mistake can be put back.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX deal_properties_unique_pair
                ON deal_properties (deal_id, property_id)
                WHERE deleted_at IS NULL
        SQL);

        /*
         * At most one subject property per deal.
         *
         * The same backstop `deal_participants` puts under its primary
         * participant: the code that sets the flag demotes the incumbent in
         * the same transaction, and this makes the invariant true even when a
         * future caller forgets.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX deal_properties_one_subject
                ON deal_properties (deal_id)
                WHERE is_subject AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_properties');
    }
};
