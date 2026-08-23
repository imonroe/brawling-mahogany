<?php

declare(strict_types=1);

use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Properties (PRD §4.3 F3.4, §6.2 · S35–S37 · issue #61).
 *
 * **Team-owned and reusable across deals.** The same house is a candidate on
 * one buyer's deal and the subject of a seller's at the same time, and is
 * listed again two years later — so it is a record of its own rather than a
 * column on a deal.
 *
 * ## `type` and `status`, not `type_id` and `status_id`
 *
 * PRD §6.2 and issue #61 both say lookup ids. Both are describing the shape
 * `deal_types` has, and the reason that one is a table does not apply here:
 * teams add their own deal types, and PRD §6.3 fixes both of these
 * vocabularies. `DocumentedVocabularyTest` already holds `PropertyType` and
 * `PropertyStatus` against the document, so a table would be a second place
 * for the same list to live and drift from.
 *
 * PRD §7.11 makes it more than a convenience. It corrects the rough data model
 * by ruling that *"Undergoing improvements"* and *"Staged"* are **workflow
 * positions, not market status**, and belong to a stage. A team-editable
 * lookup is exactly how those get added back; an enum held against the
 * document is how they stay out.
 *
 * ## `state_code`, not `state`
 *
 * Every other model in this schema uses `state` for a state machine
 * (`DealState`, `StageState`, `WorkflowState`). A `properties.state` holding
 * "CO" would read as one at a glance, on the one table where it is a
 * postal abbreviation. IA §10's *"City, ST ZIP"* is what the column holds and
 * `state_code` is what it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table): void {
            $table->productDefaults();

            /*
             * The address, in the pieces `formatAddress()` takes.
             *
             * IA §10 fixes the rendering — street on line one, "City, ST ZIP"
             * on line two — and `resources/js/lib/formatters.ts` owns it, so
             * these columns exist to feed that and nothing else composes an
             * address itself.
             *
             * All nullable. A buyer-side deal collects candidates before
             * anybody has a full address for some of them, and a property with
             * a street and no ZIP is more useful than one that could not be
             * saved.
             */
            $table->string('street')->nullable();
            $table->string('unit')->nullable();
            $table->string('city')->nullable();
            $table->string('state_code', 2)->nullable();
            $table->string('postal_code', 16)->nullable();

            // The county's identifier for the parcel. The one durable key a
            // property has, and the only reliable way to notice two records
            // are the same house typed differently.
            $table->string('parcel_number')->nullable();

            $table->string('type')->default(PropertyType::SingleFamily->value);
            $table->string('status')->default(PropertyStatus::PreListing->value);

            $table->unsignedSmallInteger('beds')->nullable();
            // Exact, not a float. Half-baths are ordinary and `numeric` is the
            // only type that holds 2.5 without surprises (ADR 0001 makes the
            // same argument for money).
            $table->decimal('baths', 3, 1)->nullable();
            $table->unsignedInteger('sqft')->nullable();
            $table->unsignedSmallInteger('year_built')->nullable();

            $table->text('notes')->nullable();

            // S35 filters by status and sorts by address.
            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'city', 'street']);
        });

        /*
         * One parcel number per team, when there is one.
         *
         * Partial, so the many properties with no parcel number yet do not
         * collide with each other — and folded, because a county writes
         * `1234-56-789` and somebody types `1234-56-789 ` or changes the case
         * of a letter suffix.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX properties_team_parcel_unique
                ON properties (team_id, lower(parcel_number))
                WHERE deleted_at IS NULL AND parcel_number IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
