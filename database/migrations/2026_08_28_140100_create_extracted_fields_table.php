<?php

declare(strict_types=1);

use App\Enums\ExtractedFieldReviewState;
use App\Enums\ExtractedFieldType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `extracted_fields` — the human review layer (PRD §6.2, §7.16 · issue #115).
 *
 * PRD §7.16 states why the table exists at all:
 *
 * > **Model output cannot be written straight into `key_dates`.** `extractions`
 * > and `extracted_fields` exist so that every automatically proposed date
 * > carries its source page, its confidence, and the identity of the human who
 * > confirmed it.
 *
 * And §6.2 states the invariant every screen inherits:
 *
 * > **Nothing reaches `key_dates` or `tasks` except through a confirmed row
 * > here.**
 *
 * That is enforced by `App\Support\Extraction\ConfirmExtractedField` being the
 * only caller of the extracted-source entry points on `SaveKeyDate` and
 * `DealTasks`, and by `tests/Unit/ExtractionConfirmationPathTest.php` reading
 * the source and failing when a second one appears.
 *
 * ## `label` is not in PRD §6.2's list, deliberately
 *
 * §6.2 gives *key* fields rather than an exhaustive schema. A proposal needs a
 * name — *"Inspection objection"* — and putting it in `proposed_value` beside
 * the date would mean every reader parsing one column into two. `field_type`
 * stays the small closed vocabulary that decides what confirming *creates*
 * (`ExtractedFieldType`), and `label` carries what a human reads.
 *
 * ## `created_record_type`/`_id` point at a row nothing points back at
 *
 * By design: a confirmed key date is an ordinary key date. It carries
 * `source = extracted` and its own `confirmed_by`/`confirmed_at` (shipped in
 * #106), which is what a *screen* needs. This pair is what an *audit* needs —
 * the path from the created row back to the passage it came from. Nothing
 * cascades across it, so `ConfirmExtractedField` nulls it when the record is
 * deleted rather than relying on a foreign key that cannot exist over a
 * polymorphic target.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extracted_fields', function (Blueprint $table): void {
            $table->productDefaults();

            $table->teamScopedForeign('extraction_id', 'extractions');

            $table->string('field_type');
            $table->string('label');

            /*
             * Text, not a typed column, and it holds a date for a key date and
             * a title for a task. A `date` column would be the obvious choice
             * for two thirds of the rows and would make the third impossible —
             * and, more to the point, a **model's proposal is not yet a date**.
             * It is a string the model produced, which may be `"Aug 31"`,
             * `"30 days after MEC"`, or something that will not parse at all.
             * Storing it as text is what lets S66 show a human the thing the
             * model actually said before anybody agrees it is a date.
             */
            $table->text('proposed_value');

            /*
             * Design System §2.5, explicitly: **confidence is not a state.**
             * It is a property of a proposal, rendered as an icon and a number
             * rather than a `StatusBadge`, and it is *information, never
             * permission* — a high-confidence date still needs a human click.
             *
             * Two decimal places on a 0..1 scale. More precision would imply a
             * calibration no provider offers.
             */
            $table->decimal('confidence', 3, 2)->nullable();

            $table->unsignedSmallInteger('source_page')->nullable();

            /*
             * The passage, verbatim. Design System §7.4 puts it in band 2 of
             * the review card, and Screen Inventory is explicit that the source
             * *"is not a link to check later; it is on screen next to the
             * value."* This column is what makes that possible without
             * re-reading the document on every render.
             */
            $table->text('source_snippet')->nullable();

            $table->string('review_state')->default(ExtractedFieldReviewState::Pending->value);

            /*
             * F10.4's *"what the human changed"* — #118 calls it *"the valuable
             * one … simultaneously the audit trail, the quality metric, and the
             * input to improving the prompt."* Null until reviewed; equal to
             * `proposed_value` on a plain confirm; different on an edit, which
             * is exactly the comparison the 85%-confirmed-without-edit target
             * is computed from.
             */
            $table->text('final_value')->nullable();

            $table->foreignUlid('reviewed_by')->nullable()
                ->constrained('people')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->string('created_record_type')->nullable();
            $table->ulid('created_record_id')->nullable();

            /*
             * Type-specific extras that are not worth a column each: whether a
             * date is critical, a suggested anchor and offset, an inspection
             * finding's severity. Read only by `ConfirmExtractedField`'s
             * per-type branch, which is the one place that knows what a given
             * `field_type` may carry.
             */
            $table->config('payload');

            $table->unsignedSmallInteger('sort_order')->default(0);

            /* S66 reads one extraction's fields in order, every time. */
            $table->index(['extraction_id', 'sort_order']);

            /* #118's scorecard reads across a team by outcome. */
            $table->index(['team_id', 'review_state']);
        });

        DB::statement(sprintf(
            "ALTER TABLE extracted_fields ADD CONSTRAINT extracted_fields_type_check CHECK (field_type IN ('%s'))",
            implode("','", array_column(ExtractedFieldType::cases(), 'value')),
        ));

        DB::statement(sprintf(
            "ALTER TABLE extracted_fields ADD CONSTRAINT extracted_fields_review_state_check CHECK (review_state IN ('%s'))",
            implode("','", array_column(ExtractedFieldReviewState::cases(), 'value')),
        ));

        DB::statement(
            'ALTER TABLE extracted_fields ADD CONSTRAINT extracted_fields_confidence_range_check '
            .'CHECK (confidence IS NULL OR (confidence >= 0 AND confidence <= 1))',
        );

        /*
         * The invariant, at the layer that cannot be argued with: a row that
         * has left `pending` was looked at, and carries when.
         *
         * Without it, a bulk path that forgot to stamp provenance would produce
         * exactly what PRD §7.16 says this table exists to prevent — a
         * confirmed value with no human behind it. ADR 0002's argument for
         * composite foreign keys is this argument: a rule the database holds is
         * a rule no later caller can be written without.
         *
         * ## Why `reviewed_by` is deliberately *not* in this predicate
         *
         * It is `ON DELETE SET NULL` against `people`, and CLAUDE.md records
         * the trap this would otherwise walk into one table over: *"a CHECK
         * constraint is evaluated on the UPDATE that `ON DELETE SET NULL`
         * performs."* Naming `reviewed_by` here would make every reviewed row
         * one the foreign key cannot detach — so purging a departed colleague
         * would fail, and `records:purge` deletes a table per statement inside
         * one transaction, which means a single such row stops a team's nightly
         * purge indefinitely.
         *
         * The provenance does not go missing when that happens. `audit_log`
         * holds who confirmed what, append-only and outside this table's
         * lifetime, which is where PRD §9 asks for it — and is the copy that
         * still answers the question after the account is gone.
         */
        DB::statement(
            'ALTER TABLE extracted_fields ADD CONSTRAINT extracted_fields_reviewed_completely_check '
            ."CHECK (review_state = 'pending' OR reviewed_at IS NOT NULL)",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('extracted_fields');
    }
};
