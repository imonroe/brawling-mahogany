<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * *"Which teams is this person in?"* asked from the person's end (#101).
 *
 * ## Why there was no index for it
 *
 * Every index on `team_memberships` leads with `team_id`, which is right for
 * every question a tenant asks — the People index, the members screen, the
 * roster. But three queries in the product ask the mirror question, about the
 * **actor** rather than the tenant, and lead with `person_id`:
 *
 *  - `Person::activeTeams()`, which runs on **every request** to build the
 *    team switcher.
 *  - `Person::membershipIn()`.
 *  - `Notification::scopeForPerson()`'s membership subquery, added by round 3
 *    of review on #189 and running inside `ShellCounts`' badge count, so also
 *    on every request.
 *
 * Postgres will use `(team_id, person_id)` for a predicate on `person_id`
 * alone, but only as a full scan of that index rather than a seek — which is
 * what `EXPLAIN` showed. That is cheap on a small table and gets less cheap
 * exactly as the platform grows, and it is the wrong shape whatever the row
 * count.
 *
 * ## `revoked_at` in the index, not only `person_id`
 *
 * All three queries pair the person with *"and not revoked"*. Including the
 * column makes the filter part of the seek instead of a heap check, and it
 * costs nothing here: this is a narrow table nobody writes to in bulk.
 *
 * Deliberately **not** partial (`WHERE revoked_at IS NULL`). A partial index
 * would be smaller and would serve the three queries above, and would then be
 * useless to the one sweep that asks the opposite question — the retention
 * purge, which looks for memberships revoked *before* a cutoff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_memberships', function (Blueprint $table): void {
            $table->index(['person_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('team_memberships', function (Blueprint $table): void {
            $table->dropIndex(['person_id', 'revoked_at']);
        });
    }
};
