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
 *  2. the `people` rows for whichever actors hold no membership here, so an
 *     event left behind by somebody the team has since removed still says who,
 *     the way `displayNameWithin` does.
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
         */
        $names = TeamMembership::query()
            ->whereIn('person_id', $ids->all())
            ->get(['id', 'person_id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (TeamMembership $membership): array => [
                (string) $membership->person_id => $membership->fullName(),
            ])
            ->all();

        $missing = $ids->reject(fn (string $id): bool => isset($names[$id]))->values();

        if ($missing->isNotEmpty()) {
            // The same fallback `displayNameWithin` uses, and for the same
            // reason: an event with no name against it reads as though nobody
            // did it, which is worse than an address.
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
