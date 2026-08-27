<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Models\CalendarFeed;
use App\Models\Deal;
use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only writer of `calendar_feeds` (PRD §4.8 F8.3 · S60 · issue #108).
 *
 * Three verbs — generate, revoke, and record a fetch — and the first two are
 * audited, because #108 treats a feed URL as a credential and PRD §9 puts
 * credential lifecycle in the audit log.
 */
final class ManageCalendarFeeds
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * A feed for this person, of this subject.
     *
     * ## Generating replaces rather than adds
     *
     * A person who presses *Generate* twice means *"give me a URL"*, not
     * *"give me two"* — and two live feeds for one subject is two URLs to
     * revoke and no way to tell which is in which calendar. The partial unique
     * index makes it true at the database as well, because the button is one
     * click and a double-tap is the ordinary way to get two.
     *
     * The consequence is stated on S60 rather than discovered: regenerating
     * breaks the subscription already in somebody's calendar.
     */
    public function generate(Team $team, Person $person, ?Deal $deal, string $name): CalendarFeed
    {
        $token = Str::random(CalendarFeed::TOKEN_LENGTH);

        $feed = new CalendarFeed;

        DB::transaction(function () use ($team, $person, $deal, $name, $token, $feed): void {
            CalendarFeed::query()
                ->where('person_id', $person->getKey())
                ->where('deal_id', $deal?->getKey())
                ->live()
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            $feed->forceFill([
                'team_id' => $team->getKey(),
                'person_id' => $person->getKey(),
                'deal_id' => $deal?->getKey(),
                'token' => $token,
                'token_hash' => CalendarFeed::hashToken($token),
                'name' => $name,
            ])->save();
        });

        $this->audit->record(
            action: 'calendar_feed.generated',
            auditable: $feed,
            teamId: $team->getKey(),
            actorPersonId: $person->getKey(),
            after: ['deal_id' => $deal?->getKey(), 'name' => $name],
        );

        return $feed;
    }

    /**
     * Revocation is immediate, which is why S60 has the state.
     *
     * No grace period and nothing queued: the next fetch from a calendar
     * client matches nothing and gets a 404. A feed URL that went to the wrong
     * person is a feed URL that has to stop working now, not on the next
     * sweep.
     */
    public function revoke(CalendarFeed $feed, ?Person $actor = null): void
    {
        if ($feed->isRevoked()) {
            return;
        }

        $feed->forceFill(['revoked_at' => now()])->save();

        $this->audit->record(
            action: 'calendar_feed.revoked',
            auditable: $feed,
            teamId: $feed->team_id,
            actorPersonId: $actor?->getKey(),
            after: ['deal_id' => $feed->deal_id],
        );
    }

    /**
     * Note that a calendar client read it.
     *
     * Not an audit entry, and not an activity event. A subscribed client polls
     * every few hours forever, so a row per fetch would be the noisiest table
     * in the product and would say nothing an operator could act on. What is
     * worth knowing is the pair S60 shows: *is this still being read, and when
     * last* — which is what decides whether a forgotten feed is worth
     * revoking.
     */
    /**
     * Bump the fetch counter.
     *
     * Team-scoped like every other write here, so **the caller runs it inside
     * the feed's team**. Called from outside one, it threw on every fetch a
     * real calendar client made.
     */
    public function recordFetch(CalendarFeed $feed): void
    {
        CalendarFeed::query()
            ->whereKey($feed->getKey())
            ->update([
                'last_fetched_at' => now(),
                'fetch_count' => DB::raw('fetch_count + 1'),
                'updated_at' => now(),
            ]);
    }

    /**
     * The live feed a token names, or null.
     *
     * `withoutTeamScope()` because a calendar client sends no session — the
     * token is what establishes the tenant, which is ADR 0002's stated
     * exception and the same one the status page makes. An equality match on a
     * unique sha256 column can only ever find one row.
     */
    public function findByToken(string $token): ?CalendarFeed
    {
        /*
         * `team` only. `deal` is team-scoped, and eager-loading it here runs a
         * `Deal` query with nothing resolved — so a **per-deal** feed threw
         * `MissingTeamContextException` before the caller reached the line
         * that establishes the tenant, while a whole-team feed (null
         * `deal_id`, no query) worked. The lookup that finds the row cannot
         * itself depend on the tenant the row is about to name; the deal is
         * loaded by the caller, inside the team.
         */
        $feed = CalendarFeed::withoutTeamScope()
            ->where('token_hash', CalendarFeed::hashToken($token))
            ->live()
            /*
             * **And the person still has to be on the team.** `live()` asks
             * only whether the *feed* was revoked, so without this a colleague
             * who left in March goes on fetching the team's whole calendar —
             * every showing, every closing date, every deal name — from a URL
             * nobody remembers exists, and revoking their membership does
             * nothing about it. Whoever removes somebody's access is not going
             * to think of their calendar subscription; the subscription has to
             * think of them.
             *
             * The same predicate `Notification::scopeForPerson()` uses, and
             * deliberately the same: **revocation only**, not
             * `carryingAccess()`. Reading more strictly than the app writes
             * would kill the feed of somebody who still works there and holds
             * a role composed without a team-surface permission.
             */
            ->whereIn('person_id', TeamMembership::withoutTeamScope()
                ->select('person_id')
                ->whereColumn('team_memberships.team_id', 'calendar_feeds.team_id')
                ->whereNull('revoked_at'))
            ->with('team')
            ->first();

        return $feed instanceof CalendarFeed ? $feed : null;
    }
}
