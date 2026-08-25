<?php

declare(strict_types=1);

use App\Enums\OfferDirection;
use App\Enums\OfferStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Offers (PRD §4.3 F3.6, §6.2, §7.9 · S22 · issue #73).
 *
 * PRD §7.9 names the gap this fills: *"Missing: offers and the contingency
 * calendar. Nothing covers offers or the chain of dates governing a live
 * transaction."*
 *
 * ## What this table is not
 *
 * **Not a contract record, and not an e-signature.** PRD §2.2 confirms
 * e-signature is unnecessary — Emily's market signs through CTM — and §10
 * keeps the executed document and its security obligation there. This is the
 * team's own working record of terms and dates, which is why there is no file
 * column and no signature column and there must not be one.
 *
 * ## `contingencies` is jsonb because Slice 4 reads it, not because it is vague
 *
 * #73: *"the input to the key dates chain in Slice 4 — inspection objection,
 * appraisal, loan, closing."* Those are a handful of named dates that vary by
 * contract, and a column each would be four migrations chasing a form. The
 * shape is settled when S18 consumes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table): void {
            $table->productDefaults();

            $table->teamScopedForeign('deal_id', 'deals');
            /*
             * Nullable, because an offer can precede the property being on the
             * deal — a buyer's agent writes one on a listing the team has not
             * linked yet, and #62's promotion is what usually follows an
             * acceptance rather than what precedes the offer.
             */
            $table->teamScopedForeign('property_id', 'properties', nullable: true);

            $table->string('direction');
            $table->string('status')->default(OfferStatus::Draft->value);

            // Integer cents, never a float (ADR 0001). IA §10 renders them.
            $table->money('amount');
            $table->money('earnest_money', nullable: true);

            $table->text('terms')->nullable();
            $table->config('contingencies');

            /*
             * Days, not instants — and named `_on` rather than `_at` for it.
             *
             * #165 is what happens when a day is given an instant, and
             * `CodeDisciplineTest` guards it by column name: `expires_at` on
             * `team_invitations` genuinely is a timestamp, so a date column
             * borrowing that name makes the guard fire on somebody else's
             * correct code. The existing date columns already avoid the
             * suffix — `due_date`, `planned_start`, `planned_end` — and this
             * follows them.
             */
            $table->date('submitted_on')->nullable();
            $table->date('expires_on')->nullable();

            // S22 lists a deal's offers newest first; the dashboard and Slice
            // 4's date chain both ask a deal for its accepted one.
            $table->index(['deal_id', 'status']);
        });

        DB::statement(sprintf(
            "ALTER TABLE offers ADD CONSTRAINT offers_direction_check CHECK (direction IN ('%s'))",
            implode("','", array_column(OfferDirection::cases(), 'value')),
        ));

        DB::statement(sprintf(
            "ALTER TABLE offers ADD CONSTRAINT offers_status_check CHECK (status IN ('%s'))",
            implode("','", array_column(OfferStatus::cases(), 'value')),
        ));

        /*
         * At most one accepted offer per deal.
         *
         * The same backstop `deal_properties` puts under its subject flag: the
         * service that accepts one rejects the others in the same transaction,
         * and this makes the invariant true even when a future caller forgets.
         * A deal with two accepted offers is a deal whose closing date chain
         * has two answers.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX offers_one_accepted
                ON offers (deal_id)
                WHERE status = 'accepted' AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
