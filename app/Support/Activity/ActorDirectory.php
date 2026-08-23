<?php

declare(strict_types=1);

namespace App\Support\Activity;

use App\Models\ActivityEvent;
use App\Models\Person;
use App\Models\TeamMembership;
use Illuminate\Support\Collection;

/**
 * Actor names for a page of timeline entries, in two queries rather than
 * two hundred (issue #81).
 *
 * `Person::displayNameWithin($team)` is the correct answer for one event and
 * the wrong shape for fifty: called inside a `map()` it costs a `teams` lookup
 * *and* a `team_memberships` lookup per row, so a fifty-event page issued
 * around a hundred queries beyond the one that fetched the events.
 *
 * The fix is not a different name function, it is asking once. Two queries,
 * whatever the page size:
 *
 *  1. every membership in the resolved team for the actors on this page, which
 *     is where a name lives since #140;
 *  2. the `people` rows for whichever actors hold no membership here at all,
 *     so an event left behind by a platform administrator acting outside the
 *     team still says who, the way `displayNameWithin` does.
 *
 * Both are skipped entirely when the page has no human actors on it — an
 * automation-only feed asks nothing.
 */
final class ActorDirectory
{
    /**
     * @param  array<string, string>  $names  person id => display name
     */
    private function __construct(private readonly array $names) {}

    /**
     * @param  Collection<int, ActivityEvent>|iterable<ActivityEvent>  $events
     */
    public static function for(iterable $events): self
    {
        $ids = collect($events)
            ->map(fn (ActivityEvent $event): ?string => $event->actor_person_id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return new self([]);
        }

        /*
         * The membership carries the name this team typed (#140), and the
         * global scope narrows it to the resolved team without being asked —
         * which is also what makes this the *team's* name for somebody rather
         * than another team's.
         *
         * `withTrashed()`, because the timeline outlives the membership. An
         * event is a record of something that happened, and a team that has
         * since removed somebody still typed their name — reaching past the
         * soft delete says *"Priya Raman archived a deal"* where dropping to
         * the fallback below would print their sign-in address instead. That
         * is both worse to read and more than the row needs to disclose.
         */
        $memberships = TeamMembership::query()
            ->withTrashed()
            ->whereIn('person_id', $ids->all())
            /*
             * Most recent removal first, because the `??=` below keeps the
             * first row it sees for a person and the latest record is the one
             * the team last meant.
             *
             * `team_memberships_team_person_unique` is partial
             * (`WHERE deleted_at IS NULL`), so the live pass is single-valued
             * by construction — but nothing stops two *removed* rows for one
             * person, and added/removed/added/removed gives exactly that.
             * Without this it is whichever Postgres hands back first.
             */
            ->orderByDesc('deleted_at')
            ->get(['id', 'person_id', 'first_name', 'last_name', 'deleted_at']);

        /*
         * A person can hold a removed membership *and* a live one — added,
         * removed, added again — and the live one is what the team means by
         * them now.
         *
         * Taken in two passes rather than by sorting one. A sort decides this
         * by the order rows come back in, and `whereIn` promises no order at
         * all: the comparator can be neutered to a constant and the right
         * answer still falls out of Postgres most of the time, which is a
         * guarantee that holds until it does not. Live first, removed only
         * where nothing live exists, is the same rule with nothing left to
         * chance.
         */
        $names = [];

        foreach ($memberships->whereNull('deleted_at') as $membership) {
            $names[(string) $membership->person_id] = $membership->fullName();
        }

        // `??=`, so a live name is never overwritten, and the ordering above
        // makes the most recent removal win among the rest.
        foreach ($memberships->whereNotNull('deleted_at') as $membership) {
            $names[(string) $membership->person_id] ??= $membership->fullName();
        }

        $missing = $ids->reject(fn (string $id): bool => isset($names[$id]))->values();

        if ($missing->isNotEmpty()) {
            /*
             * The same fallback `displayNameWithin` uses, and for the same
             * reason: an event with no name against it reads as though nobody
             * did it.
             *
             * **This is a routine path, not an exotic one.** `records:purge`
             * hard-deletes a `team_memberships` row thirty days after it is
             * removed, so `withTrashed()` above defers this fallback rather
             * than avoiding it — every actor a team removed a month ago
             * arrives here, as does a platform administrator who never held a
             * membership at all. What it prints is a sign-in address, which
             * is a colleague's work address rather than a client's: a client
             * has no login, so a client is never an actor. Diverging from
             * `displayNameWithin` here would put this back to two answers for
             * one question, which is the thing that went wrong in the first
             * place. `ActivityFeedTest` pins it so it stays a decision.
             */
            foreach (Person::query()->whereIn('id', $missing->all())->get(['id', 'email']) as $person) {
                $names[(string) $person->getKey()] = $person->email ?? 'Unknown';
            }
        }

        return new self($names);
    }

    /**
     * The name to put against one event, or null when nothing human did it.
     *
     * Null rather than a placeholder: a scheduled automation has no person
     * behind it, and inventing one puts a name on the timeline that never
     * touched the record.
     */
    public function nameOf(ActivityEvent $event): ?string
    {
        $id = $event->actor_person_id;

        return $id === null ? null : ($this->names[$id] ?? null);
    }
}
