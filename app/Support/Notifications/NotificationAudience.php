<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use Illuminate\Support\Collection;

/**
 * Who hears about something that happened on a deal (issue #101).
 *
 * ## The honest version of "the team", pending a watcher list
 *
 * #101's five types split in two. *Task assigned* and *deadline approaching*
 * are about **me**, and the recipient is the assignee — there is no question
 * to answer. The other three are about the **work**, and the product has no
 * notion yet of who is watching a particular deal: PRD §6.2's roster is
 * participants (clients, vendors, the other side), not colleagues, and a deal
 * has no owner column.
 *
 * So the audience is *the people in this team who could act on it* — members
 * holding the permission the notification is about, with an **active**
 * membership. That is right for the small teams this product is for (PRD §3:
 * *"small independent teams"*), and it is wrong at twenty people, where a
 * per-deal watcher list is the answer. Bounded rather than unbounded for
 * exactly that reason: an accidental broadcast to a large team is the failure
 * that teaches everybody to ignore the panel.
 *
 * The bound is a **cap, not a filter**: if a team ever exceeds it, some people
 * are not told, which is why it is generous and why the follow-up is named.
 */
final class NotificationAudience
{
    /**
     * Enough for a whole small team, short of a broadcast.
     */
    public const MAX = 25;

    /**
     * Resolved audiences, keyed by team and permission.
     *
     * Round 2 of review's finding: `AdvanceWorkflow` calls this **once per
     * cleared gate**, inside the advance's own transaction, and each call is a
     * membership select plus two eager loads. An advance that clears four
     * gates paid for four identical answers while holding a write transaction
     * open — the cost of which is not the queries, it is the lock.
     *
     * Memoised on the instance rather than in the cache, and the instance is
     * bound `scoped()` in `AppServiceProvider` for the same reason `Notify` is
     * (#101): per request, so a role changed on S17 is honoured by the next
     * thing that happens, and never per process, where it would outlive the
     * change that invalidates it.
     *
     * @var array<string, Collection<int, Person>>
     */
    private array $resolved = [];

    /**
     * @return Collection<int, Person>
     */
    public function holding(Team $team, string $permission): Collection
    {
        return $this->resolved[$team->getKey().'|'.$permission] ??= $this->resolve($team, $permission);
    }

    /**
     * @return Collection<int, Person>
     */
    private function resolve(Team $team, string $permission): Collection
    {
        return TeamMembership::query()
            ->where('team_id', $team->getKey())
            ->active()
            ->with(['roles.permissions', 'person'])
            /*
             * Ordered, because the list is capped. Without it *which* twenty-
             * five of a larger team are told is whatever the heap returns, and
             * it can differ between two notifications about the same deal —
             * the finding `AlertOnFailures::audience()` records one feature
             * over.
             */
            ->orderBy('id')
            ->get()
            ->filter(static fn (TeamMembership $membership): bool => $membership->hasPermission($permission))
            ->map(static fn (TeamMembership $membership): Person => $membership->person)
            /*
             * **Not somebody inside their own deletion window.**
             * `TeamMembership::person()` is deliberately `withTrashed()`, so an
             * account deleted yesterday still resolves — which is right for an
             * audit entry naming who did something, and wrong for a list of
             * people to write to. PRD §9's thirty days is a recovery window,
             * not a period of continued service.
             */
            ->reject(static fn (Person $person): bool => $person->trashed())
            /*
             * One row per human. A membership belongs to one person, but a
             * person could hold two in a team across a future re-invite, and a
             * duplicate here is somebody told twice about one thing.
             */
            ->unique(static fn (Person $person): string => (string) $person->getKey())
            ->take(self::MAX)
            ->values();
    }
}
