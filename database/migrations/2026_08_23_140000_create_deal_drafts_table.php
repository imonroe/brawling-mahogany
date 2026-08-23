<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A half-finished deal (S14 · PRD §5.2, §4.12 F12.3 · issue #74).
 *
 * Issue #74 states the requirement and the reason in one breath: *"Heather is
 * creating this on a phone, from a car, between showings. A half-finished deal
 * must survive a dropped connection. **Persist progress rather than holding it
 * in component state.**"*
 *
 * ## Why a staging table and not a `draft` deal state
 *
 * The obvious alternative is to create the `deals` row at step one and give
 * `DealState` a `draft` case. It was rejected for three reasons, in
 * descending order of how expensive they would be to undo:
 *
 * 1. **Every query about deals would have to learn about it.** The deals
 *    index, the dashboard counts, S76's in-use warning, `scopeOpen()`,
 *    `AdvanceWorkflow` — each becomes "except the drafts", and the one that
 *    forgets shows a customer a deal that does not exist yet. A half-typed
 *    address appearing in a colleague's pipeline is the failure mode.
 * 2. **`DealState` is bound to IA §8 by a test.** Adding a case means adding
 *    a row to the state vocabulary, a tone to Design System §2.4, and a badge
 *    the client status page has to be taught not to show — for something that
 *    is not a state of a deal but a state of a *form*.
 * 3. **There is already a precedent for exactly this shape.**
 *    `contact_imports` (S33) is a team-scoped staging row with a JSONB payload
 *    that holds what somebody has entered until they commit it, and
 *    `records:purge` already sweeps the abandoned ones. This table is modelled
 *    on it deliberately, down to the nulled-out author reference.
 *
 * The cost is that the wizard writes to two tables instead of one, and that
 * `CreateDealFromDraft` has to be a transaction. Both are cheap.
 *
 * ## One open draft per person, per team
 *
 * "Interrupting the wizard and returning resumes it" only has an unambiguous
 * meaning if there is one thing to resume. The partial unique index below is
 * what makes that true rather than a convention the controller remembers —
 * and it is partial on `completed_at` as well as `deleted_at`, so finishing a
 * deal frees the slot for the next one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_drafts', function (Blueprint $table): void {
            $table->productDefaults();

            /*
             * Whose draft. Nulled rather than cascaded when the account goes,
             * for the same reason `contact_imports` does it: the row is
             * team data, and losing it because the person who started it left
             * would lose work the team owns. A draft with no author is
             * unreachable by resume and swept by the abandonment sweep, which
             * is the correct end for it.
             */
            $table->foreignUlid('created_by_person_id')->nullable()
                ->constrained('people')->nullOnDelete();

            // Where they got to. `DealDraftStep`, four cases.
            $table->string('step');

            /*
             * What they have entered so far.
             *
             * JSONB rather than columns, because this is a form in progress
             * rather than a record: every field is optional until the moment
             * it is not, and the shape changes whenever the wizard gains a
             * step. The real columns are on `deals`, `deal_participants` and
             * `deal_properties`, written by `CreateDealFromDraft` when there
             * is something worth writing.
             *
             * It holds **ids and typed values, never a snapshot** — a
             * property id rather than a copy of the address — so a draft
             * resumed after a colleague edited the property shows the current
             * one. Ids can go stale, and resume treats a missing one as an
             * unanswered step rather than an error.
             */
            $table->config('payload');

            /*
             * What it became. Kept rather than deleted outright so that
             * "I finished this on the train" has an answer, and so the
             * abandonment sweep can tell a finished draft from a forgotten
             * one.
             */
            $table->teamScopedForeign('deal_id', 'deals', nullable: true);
            $table->timestamp('completed_at')->nullable();
        });

        /*
         * At most one live draft per person per team.
         *
         * Partial on both `completed_at` and `deleted_at`: a completed draft
         * is history and an abandoned one is gone, and neither should stop
         * somebody starting a new deal.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX deal_drafts_one_open_per_person
                ON deal_drafts (team_id, created_by_person_id)
                WHERE completed_at IS NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_drafts');
    }
};
