<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Models\Deal;
use App\Models\Notification;
use App\Models\Person;
use App\Models\Team;

/**
 * The panel's rows, grouped (S08 · issue #101).
 *
 * ## Grouping is the requirement, not a nicety
 *
 * #101: *"twelve 'task assigned' notifications from one workflow
 * instantiation should read as one line, not twelve."* Instantiating a
 * workflow is precisely the event that produces a dozen of one type on one
 * deal in one second, and a panel that renders them one per line is a panel
 * whose unread badge means *"a workflow started"* and whose contents nobody
 * scrolls.
 *
 * ## Grouped in PHP, not in SQL
 *
 * The window is small — the newest {@see self::PAGE} rows for one person — and
 * the grouping key is (type, deal, day), which a `GROUP BY` would have to
 * express in the database's timezone rather than the team's. Reading a bounded
 * page and folding it here keeps the timezone question in one place and the
 * query a single indexed scan.
 *
 * The consequence is worth stating: a group's count is the count **within the
 * page**, so a burst larger than the page reads as a page-sized group. That is
 * the honest failure — it under-counts rather than dropping rows — and the
 * page is generous enough that reaching it means something has gone wrong
 * upstream.
 */
final class NotificationFeed
{
    /** How many rows the panel reads. Generous, because they fold. */
    public const PAGE = 100;

    /** How many the shell's popover shows before *"see all"*. */
    public const PREVIEW = 8;

    /**
     * @return list<array<string, mixed>>
     */
    public function groupsFor(Person $person, int $limit): array
    {
        $rows = Notification::query()
            ->forPerson($person)
            ->orderByDesc('created_at')
            /*
             * A tiebreaker, because `created_at` is stamped in PHP and a
             * workflow instantiation writes a dozen rows inside one second.
             * Without it Postgres returns heap order and the panel reorders
             * itself between two loads of identical data — the same finding
             * `AlertOnFailures` records about `executed_at`.
             */
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        /*
         * **Both lookups lift the team scope, and that is the whole of blocking
         * finding #5.**
         *
         * `forPerson()` lifts it from the notifications query, and an
         * `->with('deal')` beside it does **not** inherit that: the eager load
         * issues its own `Deal` query, which still carries Deal's global scope
         * for whichever team happens to be resolved. So the cross-team row —
         * the feature this whole panel exists for — came back with a null deal
         * and a link built from `deal_id` alone that 404s through Deal's
         * team-scoped route binding. Measured by review; the test asserted only
         * that two groups came back, so it passed with both names null.
         *
         * Lifting the scope here is safe in the way `ApplyDeliveryEvent`'s is:
         * the ids are not arbitrary. They come off rows addressed to this
         * person, each carrying its own `team_id`, and a composite foreign key
         * makes a notification pointing at another team's deal
         * unrepresentable. So this reads exactly the deals the person was
         * already told about.
         */
        $teamIds = $rows->pluck('team_id')->unique()->all();

        /*
         * `Team::query()`, not `Team::withoutTeamScope()`: `Team` is the tenant
         * boundary rather than a tenant-scoped table, so it has no such scope
         * and no such method — the call was a 500 on every panel load.
         *
         * `withTrashed()` for the reason `ApplyDeliveryEvent` gives: `Team`
         * soft-deletes, so a team inside its 30-day purge window would come
         * back null and every line from it would lose the name that says which
         * team it is about — on the one screen whose whole point is reading
         * across teams.
         *
         * `->all()` on both, so a missing key is honestly `null`. A `Collection`
         * types `get()` as its value type, which made `$team?->timezone` look
         * unnecessary to PHPStan while being exactly what stops a fatal.
         */
        $teams = Team::withTrashed()
            ->whereIn('id', $teamIds)
            ->get()
            ->keyBy('id')
            ->all();

        $deals = Deal::withoutTeamScope()
            ->whereIn('id', $rows->pluck('deal_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id')
            ->all();

        $groups = [];

        foreach ($rows as $row) {
            /*
             * The key: what happened, where, and when — to the day. The day is
             * in the row's own team's zone, because *"3 tasks were assigned to
             * you"* should mean one working day to the person reading it, and
             * a person in two teams may be reading two zones on one screen.
             */
            $team = $teams[$row->team_id] ?? null;
            $deal = $row->deal_id === null ? null : ($deals[$row->deal_id] ?? null);

            $day = $row->created_at
                ?->copy()
                ->setTimezone($team->timezone ?? config('app.timezone'))
                ->toDateString() ?? '';

            $key = $row->type->groups()
                ? implode('|', [$row->type->value, (string) $row->deal_id, $day, $row->team_id])
                : (string) $row->getKey();

            if (! array_key_exists($key, $groups)) {
                $groups[$key] = [
                    'id' => (string) $row->getKey(),
                    'type' => $row->type->value,
                    'summary' => $row->summary,
                    'dealId' => $row->deal_id,
                    /*
                     * Null when the deal has been deleted, which is a real
                     * state rather than the scoping bug above: the line still
                     * says what happened, and the screen renders no link.
                     */
                    'dealName' => $deal?->displayName(),
                    'teamId' => $row->team_id,
                    'teamName' => $team?->name,
                    /*
                     * **Through the app's own opener, not straight at the
                     * deal.** A notification from another team links to a deal
                     * the resolved team cannot see, and Deal's team-scoped
                     * route binding turns that into a 404 — for the person the
                     * cross-team panel exists to serve. `notifications.open`
                     * switches first and then redirects, so one click works
                     * from either team.
                     *
                     * Null when there is nothing to open, so the screen
                     * renders plain text rather than a link that goes nowhere.
                     */
                    'url' => $deal === null ? null : route('notifications.open', ['notification' => $row->getKey()]),
                    'occurredAt' => $row->created_at?->toIso8601String(),
                    'count' => 0,
                    'unread' => 0,
                    /*
                     * Every id in the group, so *"mark read"* on a folded line
                     * marks what it folded. A line that dismissed only the
                     * newest of twelve would leave the badge saying eleven,
                     * which is the panel telling somebody their action did not
                     * work.
                     */
                    'ids' => [],
                ];
            }

            $groups[$key]['count']++;
            $groups[$key]['ids'][] = (string) $row->getKey();

            if ($row->read_at === null) {
                $groups[$key]['unread']++;
            }
        }

        return array_values(array_map(
            function (array $group): array {
                if ($group['count'] > 1) {
                    $group['summary'] = NotificationTypeLabel::grouped($group['type'], $group['count']);
                }

                return $group;
            },
            $groups,
        ));
    }

    /**
     * Every id folded into the same line as this one.
     *
     * ## Why this reads the feed rather than rebuilding the key
     *
     * A folded line stands for as many rows as it folded, so opening one has
     * to mark the line rather than the row — otherwise the badge still says
     * three after somebody has dealt with all three. Round 2 of review found
     * `open()` marking exactly one.
     *
     * The grouping key is (type, deal, day-in-the-team's-timezone), and
     * writing that predicate a second time here is the shape `CLAUDE.md`
     * warns about twice over: *"a rule enforced at call sites is enforced at
     * some call sites"*, and the two would drift the first time the key
     * changed. So this asks {@see self::groupsFor()} — the one implementation
     * — and takes the group the row landed in.
     *
     * Bounded by `PAGE` for free, and honest when it falls off the end: a row
     * older than the page is marked on its own, which is exactly what the
     * panel would have shown for it anyway.
     *
     * @return list<string>
     */
    public function idsGroupedWith(Person $person, Notification $row): array
    {
        $id = (string) $row->getKey();

        foreach ($this->groupsFor($person, self::PAGE) as $group) {
            /** @var list<string> $ids */
            $ids = $group['ids'];

            if (in_array($id, $ids, true)) {
                return $ids;
            }
        }

        return [$id];
    }
}
