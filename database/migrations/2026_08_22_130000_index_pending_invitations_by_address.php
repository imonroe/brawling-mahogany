<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make "which teams have invited me" an index lookup (ADR 0003).
 *
 * `PendingInvitations::for()` asks `lower(email) = ?` with **no `team_id`**,
 * because the whole point is a person with no membership anywhere and
 * therefore no team to scope to. Both existing indexes on `team_invitations`
 * lead with `team_id` — `(team_id, email)` and the partial unique on
 * `(team_id, lower(email))` — so neither has a usable leading column and the
 * query was a sequential scan.
 *
 * That mattered more than it looks: the answer is a shared Inertia prop, so
 * the query runs on **every authenticated request**, and rows are never
 * deleted before the 30-day purge, so the table grows with every invitation
 * ever sent across every tenant.
 *
 * Partial over the three predicates a partial index can hold — deleted,
 * accepted, revoked — which are `team_invitations_pending_unique`'s own three.
 * An invitation that has been spent, called back, or purged is not one
 * anybody is waiting on, so it does not belong in the index.
 *
 * **Expired ones are still in it.** `scopePending()` has a fourth predicate,
 * `expires_at > now()`, and `now()` is not immutable, so Postgres will not
 * accept it here; the scope filters those rows after the index has found
 * them. Nothing deletes an expired invitation before the 30-day purge, so
 * this index does accumulate them — a much smaller set than the table, and
 * the reason to say so rather than claim the predicates match.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX team_invitations_pending_email
            ON team_invitations (lower(email))
            WHERE deleted_at IS NULL AND accepted_at IS NULL AND revoked_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS team_invitations_pending_email');
    }
};
