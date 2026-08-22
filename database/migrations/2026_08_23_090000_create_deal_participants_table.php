<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who is involved in one deal, and as what (PRD §4.3 F3.3, §7.2 · issue #60).
 *
 * PRD §7.2 calls this *"the single biggest simplification available"*: Client,
 * Buyer, Seller and Service Provider were modelled as access roles, and they
 * are not. They are relationships to **one transaction**. The same person
 * sells in March and buys in June, and the global role list shrinks to five
 * genuine access tiers once that is true.
 *
 * ## `team_membership_id`, not `person_id`
 *
 * PRD §6.2 and issue #60 both say `person_id`. Both were written before #140,
 * and that decision moved every team-visible field — name, email, phone —
 * from `people` onto `team_memberships`. Three things follow, and together
 * they change the answer:
 *
 * 1. **A participant has to render.** `people` no longer holds a name;
 *    `TeamMembership::fullName()` does. Pointing at `people` would mean every
 *    screen re-deriving "which membership is this, in this team" for a value
 *    sitting one column away.
 * 2. **`people` carries no `team_id`.** ADR 0002's second layer wants a
 *    composite key over `(team_id, id)`, and `people` cannot offer one — so a
 *    `person_id` here would be a plain foreign key the database cannot refuse
 *    a foreign row through. That is exactly the hole `tasks.assignee_id` has,
 *    which `InstantiateWorkflow::assignableWithin()` now closes by hand
 *    because the schema could not.
 * 3. **A membership already means what a participant means.** It is a person
 *    *as this team knows them* — the directory entry, with their lifecycle
 *    status and vendor fields. A deal participant is a directory entry in a
 *    role on a deal.
 *
 * ADR 0002's own rule, learned from S76: reach for the model that carries the
 * layer rather than re-implementing the layer against one that does not. This
 * is that rule applied at schema time instead of at review time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_participants', function (Blueprint $table): void {
            $table->productDefaults();

            $table->teamScopedForeign('deal_id', 'deals');
            $table->teamScopedForeign('team_membership_id', 'team_memberships');

            // PRD §6.3. Thirteen values, held against the document by
            // `DocumentedVocabularyTest`.
            $table->string('participant_role');

            /*
             * Which of several people in one role is *the* one.
             *
             * Two spouses both sell; one takes the calls. Slice 3's message
             * recipient rules ("the Seller") need a single answer, and a rule
             * that resolved to two addresses would send the client's private
             * update to both.
             */
            $table->boolean('is_primary')->default(false);

            // Team-private, like every other `notes` column in this schema.
            $table->text('notes')->nullable();

            // S19 groups by role, and the deal detail header asks for the
            // primary client by name.
            $table->index(['deal_id', 'participant_role']);
        });

        /*
         * The same person, in the same role, twice on one deal is a duplicate
         * with no meaning — so the database refuses it outright.
         *
         * The same person in *two* roles on one deal is a different thing and
         * stays legal: it is unusual rather than impossible, and S25 answers
         * it with a warning rather than a refusal (issue #60: *"warns rather
         * than duplicating"*). Refusing what somebody might legitimately mean
         * is worse than telling them what they are about to do.
         *
         * Partial, so removing a participant frees the pairing again.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX deal_participants_unique_role
                ON deal_participants (deal_id, team_membership_id, participant_role)
                WHERE deleted_at IS NULL
        SQL);

        /*
         * At most one primary per role per deal.
         *
         * `AddParticipant` demotes the incumbent inside the same transaction,
         * so this is a backstop rather than a trap — the index exists to make
         * the invariant true even if a future caller forgets, which is the
         * same argument every composite key in this schema makes.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX deal_participants_one_primary_per_role
                ON deal_participants (deal_id, participant_role)
                WHERE is_primary AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_participants');
    }
};
