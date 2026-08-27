<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Magic-link access to a deal's status page (PRD §4.7 F7.1, §9 · issue #110).
 *
 * F7.1: *"signed, expiring, single-use. No password."* PRD §3.3 is why —
 * a client uses this *"once every seven years, in the middle of the largest
 * transaction of their life… must work on a phone, first try, no password."*
 * A client who has to reset a password to see a timeline calls the agent
 * instead, which is the outcome the feature exists to reduce.
 *
 * ## One row, two credentials, two lifetimes
 *
 * #110 names the trade and asks for it to be decided rather than inherited:
 * *"a strictly single-use 30-minute link means a client who reopens the page
 * an hour later is locked out — which is a support call to the agent."*
 *
 * So the link is single-use for **establishing** a session, and the session
 * lasts long enough to be useful. Both live on this row:
 *
 *  - `token_hash` is the emailed link. Thirty minutes, one use (PRD §9).
 *  - `session_token_hash` is minted when that link is spent, and lasts
 *    {@see App\Models\StatusPageLink::SESSION_DAYS} days.
 *
 * One row rather than two tables because it is **one grant of access**: the
 * agent who revokes it means *"this person can no longer see this deal"*, and
 * a revoke that had to find a session belonging to a link is a revoke with a
 * way to miss one.
 *
 * ## Both are hashed, for the reason the invitation token is
 *
 * A leaked database dump must not be a set of working keys to every client's
 * transaction. The plaintext exists for exactly as long as it takes to put it
 * in an email.
 *
 * ## Why `team_membership_id` and not an email column
 *
 * The membership *is* what the team knows about this person, and it already
 * holds the address (Slice 1 moved contact details there). A copy here would
 * be a second place a client's email lives and would go stale the day somebody
 * corrects it — and the revoked-membership check every other reader makes
 * would not apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_page_links', function (Blueprint $table): void {
            $table->productDefaults();

            $table->teamScopedForeign('deal_id', 'deals');
            $table->teamScopedForeign('team_membership_id', 'team_memberships');

            /*
             * The emailed credential. `unique` because a collision would hand
             * one client another's deal — vanishingly unlikely with 32 random
             * bytes, and the kind of thing worth having the database refuse
             * rather than trusting the generator.
             */
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();

            /*
             * Minted when the link above is spent. Nullable and unique
             * together — Postgres allows many nulls in a unique index, so an
             * unused link constrains nothing.
             */
            $table->string('session_token_hash', 64)->nullable()->unique();
            $table->timestamp('session_expires_at')->nullable();

            /*
             * Revocation kills both credentials at once, which is what makes
             * one row the right shape: an agent revoking access means the
             * person, not the token.
             */
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUlid('revoked_by')->nullable()->constrained('people')->nullOnDelete();

            /*
             * PRD §9's *"every issuance and use writes an activity event"* is
             * the audit trail; this is the operational one — S65's *"has the
             * client looked?"*, which is the question an agent actually asks.
             */
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);

            /*
             * S65 lists a deal's links newest first. `deal_id` and
             * `team_membership_id` each already carry an index from
             * `teamScopedForeign()`, so only the composite is new.
             */
            $table->index(['deal_id', 'created_at']);
        });

        /*
         * A session cannot exist without the link that minted it.
         *
         * The two columns are set together by `IssueStatusPageLink::redeem()`
         * and a row with one and not the other is a state no reader has an
         * opinion about — which is the shape `key_dates` refuses for its own
         * derivation, one table over.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE status_page_links
                ADD CONSTRAINT status_page_links_session_complete_check
                CHECK (
                    (session_token_hash IS NULL AND session_expires_at IS NULL)
                    OR (session_token_hash IS NOT NULL AND session_expires_at IS NOT NULL)
                )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_links');
    }
};
