<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contact details move from `people` to `team_memberships` (issue #140).
 *
 * ## What this closes
 *
 * Slice 1 shared one `people` row per human across teams, so adding somebody
 * by an address another team had already entered attached a membership to
 * *their* row — and showed this team the name and phone number that team
 * supplied. The write side was closed by a model hook; the read side is this.
 *
 * ## Why moving beats guarding
 *
 * Sharing was chosen so a stager working for two teams would be one record
 * with one phone number. That benefit only exists while the fields are shared,
 * and the fields are exactly what leaks. Once every team-visible field lives
 * on the membership each team holds its own view anyway — so the sharing buys
 * nothing and still costs the disclosure. A trade-off with no remaining
 * benefit is not a trade-off.
 *
 * ## What each table means afterwards
 *
 * **`people` is the login.** The sign-in address, the password, the second
 * factor, the super-admin flag. Nothing a team types. A row with a null
 * `email` is a person who cannot sign in and never will under that row —
 * which is most of the directory.
 *
 * **`team_memberships` is the person as this team knows them.** Name, address,
 * phone, lifecycle status, notes, vendor assessment. All of it team-private by
 * construction rather than by a rule somebody has to remember.
 *
 * PRD F2.1's *"one record per human, login credentials optional"* now means
 * one record per human **with a login**. A credential-less contact gets its
 * own row per team, because there is no longer anything for a shared one to
 * share.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_memberships', function (Blueprint $table): void {
            // Nullable for the backfill, tightened below. A membership with no
            // name is a row nobody can read on a screen.
            $table->string('first_name')->nullable()->after('person_id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('email')->nullable()->after('last_name');
            $table->string('phone')->nullable()->after('email');
        });

        // Every existing membership takes the details from the person it
        // points at. One team per person today in every environment that has
        // data, so nothing is lost or duplicated.
        DB::statement(<<<'SQL'
            UPDATE team_memberships
               SET first_name = people.first_name,
                   last_name  = people.last_name,
                   email      = people.email,
                   phone      = people.phone
              FROM people
             WHERE people.id = team_memberships.person_id
        SQL);

        // A membership with no name at all would render as a blank row. There
        // is no such data, but the column should say so.
        DB::statement("UPDATE team_memberships SET first_name = 'Unknown' WHERE first_name IS NULL");

        Schema::table('team_memberships', function (Blueprint $table): void {
            $table->string('first_name')->nullable(false)->change();
        });

        // One team cannot hold the same address twice. Folded, matching the
        // people index it replaces, and partial so a revoked or deleted
        // membership frees the address again.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX team_memberships_team_email_unique
                ON team_memberships (team_id, lower(email))
                WHERE deleted_at IS NULL AND revoked_at IS NULL AND email IS NOT NULL
        SQL);

        DB::statement('CREATE INDEX team_memberships_name_index ON team_memberships (team_id, last_name, first_name)');

        /*
         * `people.email` narrows to a login address.
         *
         * It stays, and stays unique over `lower(email)` — one account per
         * address is what makes "sign in once, work in two teams" true. What
         * changes is what a null means: before it was a contact with no email,
         * now it is a person with no login. Rows that hold no credentials and
         * never will are cleared, so the column stops carrying a second copy
         * of something a team typed.
         */
        DB::statement(<<<'SQL'
            UPDATE people
               SET email = NULL
             WHERE password IS NULL
        SQL);

        Schema::table('people', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'last_name', 'phone']);
        });
    }

    /**
     * As far back as it goes, which is not all the way.
     *
     * `up()` clears `people.email` for every credential-less row, and the
     * address it clears now lives on however many memberships hold it. If two
     * teams each added the same contact, there is no longer one answer to
     * *"what is this person's email"* — restoring one team's is arbitrary and
     * restoring both is impossible against `people`'s unique index.
     *
     * So `down()` puts back what it can and leaves the rest null rather than
     * aborting halfway: a rollback that throws on the second colliding pair
     * leaves the schema in neither shape, which is worse than a rollback that
     * completes with less data than it started with. **Roll forward, and take
     * a backup first** (`docs/Deployment.md` §5) — this direction exists for a
     * bad deploy on the day, not as an undo.
     */
    public function down(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone')->nullable();
        });

        // Names and phone numbers restore unconditionally: nothing is unique
        // about them, so the oldest membership simply wins.
        DB::statement(<<<'SQL'
            UPDATE people
               SET first_name = m.first_name,
                   last_name  = m.last_name,
                   phone      = m.phone
              FROM (
                    SELECT DISTINCT ON (person_id) person_id, first_name, last_name, phone
                      FROM team_memberships
                     WHERE deleted_at IS NULL
                     ORDER BY person_id, created_at
                   ) AS m
             WHERE m.person_id = people.id
        SQL);

        /*
         * The address is restored only where it can be, and the deduplication
         * is the whole point: two credential-less contacts in two teams
         * sharing an address is a state this migration *created*, and
         * `people`'s `lower(email)` unique index would refuse the second of
         * them.
         *
         * `DISTINCT ON (lower(email))` rather than a `NOT EXISTS` against
         * `people` alone, because a single `UPDATE` evaluates its subqueries
         * against one snapshot: both colliding rows would see the address as
         * free, both would be updated, and the index would abort the whole
         * statement. Deduplicating inside the source set is what makes it one
         * row per address before the write happens. Oldest membership wins;
         * the rest stay null.
         */
        DB::statement(<<<'SQL'
            UPDATE people
               SET email = m.email
              FROM (
                    SELECT DISTINCT ON (lower(tm.email)) tm.person_id, tm.email
                      FROM team_memberships AS tm
                      JOIN people AS p ON p.id = tm.person_id
                     WHERE tm.deleted_at IS NULL
                       AND tm.email IS NOT NULL
                       AND p.email IS NULL
                       AND NOT EXISTS (
                             SELECT 1
                               FROM people AS taken
                              WHERE lower(taken.email) = lower(tm.email)
                           )
                     ORDER BY lower(tm.email), tm.created_at
                   ) AS m
             WHERE m.person_id = people.id
               AND people.email IS NULL
        SQL);

        DB::statement('DROP INDEX IF EXISTS team_memberships_team_email_unique');
        DB::statement('DROP INDEX IF EXISTS team_memberships_name_index');

        Schema::table('team_memberships', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'last_name', 'email', 'phone']);
        });
    }
};
