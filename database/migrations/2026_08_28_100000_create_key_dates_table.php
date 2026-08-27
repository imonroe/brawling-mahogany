<?php

declare(strict_types=1);

use App\Enums\KeyDateSource;
use App\Enums\OffsetBasis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The contingency calendar (PRD §4.8 F8.2, §6.2, §7.9 · IA §2 · issue #106).
 *
 * PRD §7.9 is unusually direct about what this table is worth:
 *
 * > **The competitor's single most-praised feature exists solely to populate
 * > this calendar. It is where the product earns its subscription.**
 *
 * Which is also why Slice 5's extraction is sequenced *after* it — *"build the
 * destination, then build the shortcut."*
 *
 * ## The code name stays `key_dates`; the UI says Dates & Deadlines
 *
 * IA §2 and §11. Emily's exact phrase, never "Key dates" in front of a person
 * and never "Important dates" internally. And never Milestone in the old broad
 * sense — that word means a moment on a stage now, and nothing else.
 *
 * ## Why the derivation columns are on the row rather than in a join table
 *
 * A derived date is `anchor + offset_days`, and every date has at most one
 * anchor: an inspection objection deadline is *ten days after mutual
 * acceptance* and is not simultaneously something else. A join table would
 * model a many-to-many that the domain does not have, and would let two rows
 * disagree about what one date derives from.
 *
 * The anchor is a self-reference, which is what makes the cascade transitive:
 * moving mutual acceptance moves the objection deadline, which moves anything
 * derived from *that*. `App\Support\Dates\KeyDateGraph` refuses a cycle, and
 * this migration cannot — Postgres has no way to express "this ULID chain does
 * not loop" as a constraint.
 *
 * ## The extraction provenance columns exist from the start
 *
 * #106: *"so Slice 5 is a feature, not a migration."* `source` says where the
 * value came from and `confirmed_at` says whether a person has agreed to it.
 * An extracted, unconfirmed date is real enough to show and **not** real
 * enough to count as a deadline (#107, #116) — a distinction that needs both
 * columns, because "extracted" alone never stops being true after somebody
 * confirms it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('key_dates', function (Blueprint $table): void {
            $table->productDefaults();

            $table->teamScopedForeign('deal_id', 'deals');

            $table->string('name');

            /*
             * A **day**, not an instant, and named without the `_at` suffix
             * for it — the convention `offers` records at length. A closing
             * date is a date on a contract; giving it a time would invent a
             * precision the document does not have and would make the row
             * change meaning when the team's timezone is read.
             */
            $table->date('date');

            $table->boolean('is_critical')->default(false);

            /*
             * The derivation. Nullable together: a date typed straight in
             * carries none of them, and `is_derived` is the flag that says
             * whether the other two are being *followed* right now.
             *
             * `nullable: true` on the anchor because most dates have none, and
             * because the composite foreign key back into this same table is
             * what keeps an anchor inside the tenant. `ON DELETE` is
             * deliberately **not** a cascade here — see the constraint below.
             */
            $table->foreignUlid('anchor_key_date_id')->nullable();
            $table->smallInteger('offset_days')->nullable();
            $table->string('offset_basis')->nullable();

            /*
             * Whether this row is currently following its anchor.
             *
             * Separate from `anchor_key_date_id` being set, because #106 asks
             * for a specific behaviour: *"a derived date that has been manually
             * overridden stops following its anchor, and says so."* Clearing
             * the anchor would lose the *and says so* — the screen could no
             * longer tell somebody this date used to be ten days after mutual
             * acceptance and is now a date a human typed.
             */
            $table->boolean('is_derived')->default(false);
            $table->timestamp('detached_at')->nullable();

            /*
             * Where the value came from, and who agreed to it (Slice 5).
             *
             * `confirmed_by` is a plain `foreignUlid` rather than a
             * team-scoped one: `people` holds credentials and carries no
             * `team_id` (ADR 0002), so there is no composite key to make.
             */
            $table->string('source')->default(KeyDateSource::Manual->value);
            $table->foreignUlid('confirmed_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            /*
             * How far ahead this date is announced (#109, F8.4).
             *
             * A list of whole days — `[7, 3, 1]` reads as *a week before, three
             * days before, the day before*. JSONB rather than a column each
             * because the count varies per date and per team, and rather than
             * a child table because nothing ever queries *"which dates remind
             * at three days"* — the sweep asks the opposite question, and asks
             * it per date.
             *
             * Null means *use the default for this kind of date*, which is a
             * different statement from `[]` — an empty list is somebody having
             * deliberately turned every reminder off. `KeyDate::reminderDays()`
             * is where the two part.
             */
            $table->config('reminder_offsets');

            $table->text('notes')->nullable();

            /*
             * S59 reads *"every date across every deal in the next 14 days"*
             * and S18 reads *"this deal's dates, in order"*. Two indexes, one
             * each — the cross-deal one leads on `team_id` because that query
             * has no deal to narrow by.
             */
            $table->index(['deal_id', 'date']);
            $table->index(['team_id', 'date']);
            $table->index(['anchor_key_date_id']);
        });

        /*
         * The anchor stays inside the tenant, and inside the deal.
         *
         * ADR 0002's second layer, applied to a self-reference: `(team_id,
         * anchor_key_date_id)` against `(team_id, id)` makes an anchor in
         * another team unrepresentable rather than merely unlikely.
         *
         * **`ON DELETE SET NULL`, and the column list is named.** A cascade
         * would be wrong — deleting mutual acceptance must not delete the
         * objection deadline derived from it, it must leave that date standing
         * with the value it last had. And naming only `anchor_key_date_id`
         * matters: `SET NULL` on a composite key nulls *every* referencing
         * column unless the list is given, which would blank the row's own
         * `team_id` and hand it to the global scope as an orphan.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE key_dates
                ADD CONSTRAINT key_dates_anchor_foreign
                FOREIGN KEY (team_id, anchor_key_date_id)
                REFERENCES key_dates (team_id, id)
                ON DELETE SET NULL (anchor_key_date_id)
        SQL);

        DB::statement(sprintf(
            "ALTER TABLE key_dates ADD CONSTRAINT key_dates_source_check CHECK (source IN ('%s'))",
            implode("','", array_column(KeyDateSource::cases(), 'value')),
        ));

        DB::statement(sprintf(
            'ALTER TABLE key_dates ADD CONSTRAINT key_dates_offset_basis_check '
            ."CHECK (offset_basis IS NULL OR offset_basis IN ('%s'))",
            implode("','", array_column(OffsetBasis::cases(), 'value')),
        ));

        /*
         * A derived date needs all three parts, or it is not derived.
         *
         * Without this, `is_derived = true` with a null anchor is a row the
         * calculator has to have an opinion about — and the opinion it would
         * have to take is "leave the date alone", which is indistinguishable
         * from not being derived at all. Refusing the state is cheaper than
         * carrying a branch for it in every reader.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE key_dates
                ADD CONSTRAINT key_dates_derivation_complete_check
                CHECK (
                    is_derived = false
                    OR (anchor_key_date_id IS NOT NULL AND offset_days IS NOT NULL AND offset_basis IS NOT NULL)
                )
        SQL);

        /*
         * Nothing may anchor to itself.
         *
         * The one link of a cycle a database *can* see. Longer loops are
         * `KeyDateGraph`'s job, but a row pointing at itself is worth refusing
         * here too: it is the cheapest mistake to make and the one a
         * hand-written UPDATE would reach past every service.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE key_dates
                ADD CONSTRAINT key_dates_no_self_anchor_check
                CHECK (anchor_key_date_id IS NULL OR anchor_key_date_id <> id)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('key_dates');
    }
};
