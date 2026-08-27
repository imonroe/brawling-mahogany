<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tokenised read-only iCal feeds (PRD §4.8 F8.3, §14.2 A7 · S60 · issue #108).
 *
 * F8.3: *"per-user and per-deal. **Works everywhere, no OAuth.** The v1
 * approach."* Two-way Google Calendar sync (F8.5) is deferred past v1, and
 * PRD A7 records the assumption to watch rather than pre-build against.
 *
 * ## The token is a credential, and #108 is explicit about what that means
 *
 * > A feed URL is a bearer token in a query string that will be pasted into
 * > Google Calendar, emailed to a colleague, and forgotten.
 *
 * So: long, random, per-feed, never derived from a person or a deal id;
 * revocable, with revocation immediate; scoped to exactly one team; purged
 * with the team; rate-limited; and serving no PII beyond what a calendar
 * needs.
 *
 * ## Hashed **and** encrypted, which is not belt-and-braces
 *
 * They answer different questions, and a feed needs both.
 *
 *  - `token_hash` is what a request is matched against. Deterministic sha256,
 *    the same shape `team_invitations` and `status_page_links` use, so a
 *    lookup can find the row from the token without the token being readable
 *    from a dump.
 *  - `token` is encrypted at rest, and exists so S60 can show the URL **again**
 *    — which is the whole difference between this credential and the other
 *    two. An invitation is used once and a status page link is emailed; a
 *    calendar feed is subscribed to on a laptop and then on a phone a week
 *    later, and a URL nobody can read back is a feed somebody has to revoke
 *    and re-add on every device.
 *
 * PRD §9 asks for *"application-level encryption for stored credentials and
 * tokens"* rather than hashing precisely because of cases like this one. The
 * cost is stated rather than implied: a database dump **plus** `APP_KEY` is a
 * set of working feed URLs. What that buys is bounded — a feed is read-only
 * and carries no PII beyond a title and a time — and it is why the two other
 * token tables in this product are hashed and not encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_feeds', function (Blueprint $table): void {
            $table->productDefaults();

            /*
             * Whose feed it is. Not nullable: every feed belongs to somebody,
             * because *"revoke this feed"* is a thing a person does to their
             * own and an owner does to a colleague's, and a feed belonging to
             * the team at large has nobody to ask.
             *
             * A plain `foreignUlid`, not a team-scoped one: `people` holds
             * credentials and carries no `team_id` (ADR 0002), so there is no
             * composite key to make.
             */
            $table->foreignUlid('person_id')->constrained('people')->cascadeOnDelete();

            /*
             * Null for a personal feed — *"everything they can see"* — and set
             * for a per-deal one. F8.3 asks for both, and they are the same
             * row with one column different rather than two tables: the token,
             * the revoke and the rate limit are identical, and only the
             * question the feed asks changes.
             */
            $table->teamScopedForeign('deal_id', 'deals', nullable: true);

            $table->string('token_hash', 64)->unique();
            // Laravel's `encrypted` cast. Long, because ciphertext is.
            $table->text('token');

            /*
             * What the person called it. S60 lists a person's feeds, and
             * *"Calendar feed"* four times over is a list nobody can revoke
             * the right row from.
             */
            $table->string('name');

            $table->timestamp('revoked_at')->nullable();

            /*
             * *"Is this still being read?"* — the question that decides
             * whether a forgotten feed is worth revoking. Not an audit trail:
             * a calendar client polls every few hours, so an entry per fetch
             * would be the noisiest table in the product.
             */
            $table->timestamp('last_fetched_at')->nullable();
            $table->unsignedInteger('fetch_count')->default(0);

            $table->index(['team_id', 'person_id']);
        });

        /*
         * One live feed per person per subject, **per team**.
         *
         * Two personal feeds for one person is two URLs to revoke and no way
         * to tell them apart, and the *"generate"* button on S60 is one click.
         * A partial index rather than a service check, because the button is
         * one click and a double-tap is the ordinary way to get two.
         *
         * `team_id` leads it, and its absence was a real defect rather than an
         * omission: `ManageCalendarFeeds::generate()` clears the way with an
         * ordinary **team-scoped** query, so *"generating replaces rather than
         * adds"* was scoped to the team somebody is standing in while the index
         * enforcing it was not. A person holding memberships in two agencies —
         * the case `Person` and `TeamMembership` exist to tell apart — got one
         * live personal feed in total, and pressing Generate in the second team
         * was a unique-violation 500 with nothing on screen able to explain it:
         * the row to revoke was on the other team's list, which S60 does not
         * show them. An index has to agree with the query that maintains it.
         *
         * `COALESCE` on the deal, because Postgres treats nulls as distinct in
         * a unique index — without it every personal feed would be unique from
         * every other and the constraint would hold for nothing.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX calendar_feeds_one_live_per_subject
                ON calendar_feeds (team_id, person_id, COALESCE(deal_id, '-'))
                WHERE revoked_at IS NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');
    }
};
