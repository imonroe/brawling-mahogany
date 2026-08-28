<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Models\CalendarFeed;
use App\Models\Deal;
use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Audit\AuditLogger;
use App\Support\Permissions;
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
             * **And the person still has to be able to open the calendar.**
             * `live()` asks only whether the *feed* was revoked, so without a
             * predicate here a colleague who left in March goes on fetching
             * the team's whole calendar — every showing, every closing date,
             * every deal name — from a URL nobody remembers exists. Whoever
             * removes somebody's access is not going to think of their
             * calendar subscription; the subscription has to think of them.
             *
             * ## Which question, decided (#194)
             *
             * Round 1 of #193's review added revocation only, matching
             * `Notification::scopeForPerson()`, and argued against
             * `carryingAccess()` — correctly, because *"holds any key on the
             * team surface"* reads more strictly than the app writes and would
             * cut off somebody who still works there.
             *
             * That argument is about a different key. The question this feed
             * turns on is the one `EventPolicy::viewAny()` asks about the
             * screen — **`calendar.view`, and nothing wider** — and it was
             * never asked here at all. So a person moved onto a narrower
             * composed role (S75 makes that two clicks), or a departing agent
             * whose roles are stripped while the membership is left in place
             * for a handover, met a 403 on the calendar and kept a live `.ics`
             * in Google delivering the same events. It is the same question
             * the screen already answers, asked once more where the token is
             * resolved.
             *
             * ## It gates; it does not revoke
             *
             * Editing a role writes nothing to `calendar_feeds`. The row stays
             * live and the next fetch simply matches nothing, so restoring the
             * permission restores the subscription already in somebody's
             * calendar — a role edited by mistake costs a fetch interval, not
             * every URL the team has issued. Revoking is still the deliberate
             * act, on S60, and still immediate.
             *
             * Nothing tells the subscriber, and nothing can: a person without
             * `calendar.view` is refused S57, and S60 is a modal over it. The
             * feed going quiet is the only signal available, which is the
             * argument for the permission being the *screen's* key rather than
             * a second one invented here.
             *
             * The cost of that is real and is written down rather than
             * discovered: while the gate is closed **no screen lists this feed
             * to anybody**, so there is no Revoke button to reach. Not a
             * missing permission — `destroy()` already authorises the holder
             * *or* somebody who can update the team — a missing list:
             * `feedsFor()` shows a person their own feeds only, and it rides
             * in the props of the screen the holder can no longer open. So a
             * URL sitting in a third party's calendar is re-armed by a later
             * role edit rather than ended by one, and the screen that would
             * let somebody end it is #206.
             *
             * ## Revocation is asked once, inside the scope
             *
             * `holdingPermission()` mirrors `hasPermission()`, which is false
             * for a revoked membership before it looks at a single role. An
             * earlier round of this change also spelled `whereNull('revoked_at')`
             * here, arguing the duplicate was the copy whose loss would be
             * silent. Review found that neither copy was: with two of them,
             * deleting *either* left the suite green, so the argument was true
             * of a guard nothing could falsify. One definition, and *'stops
             * serving the moment the person is no longer on the team'* fails
             * if it goes.
             */
            ->whereIn('person_id', TeamMembership::withoutTeamScope()
                ->select('person_id')
                ->whereColumn('team_memberships.team_id', 'calendar_feeds.team_id')
                ->holdingPermission(Permissions::VIEW_CALENDAR))
            ->with('team')
            ->first();

        return $feed instanceof CalendarFeed ? $feed : null;
    }
}
