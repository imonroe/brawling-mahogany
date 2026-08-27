<?php

declare(strict_types=1);

namespace App\Support\Notifications;

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
            ->with('deal')
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

        $teams = Team::query()
            ->whereIn('id', $rows->pluck('team_id')->unique()->all())
            ->pluck('name', 'id');

        $groups = [];

        foreach ($rows as $row) {
            /*
             * The key: what happened, where, and when — to the day. The day is
             * in the row's own team's zone, because *"3 tasks were assigned to
             * you"* should mean one working day to the person reading it, and
             * a person in two teams may be reading two zones on one screen.
             */
            $team = $teams[$row->team_id] ?? null;

            $day = $row->created_at
                ?->copy()
                ->setTimezone($this->timezoneOf($row->team_id))
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
                    'dealName' => $row->deal?->displayName(),
                    'teamId' => $row->team_id,
                    'teamName' => $team,
                    'url' => $row->url(),
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

    public function unreadCountFor(Person $person): int
    {
        return Notification::query()->forPerson($person)->unread()->count();
    }

    /**
     * A team's timezone, read once per team per request.
     *
     * @var array<string, string>
     */
    private array $timezones = [];

    private function timezoneOf(string $teamId): string
    {
        return $this->timezones[$teamId] ??= (string) (Team::query()
            ->whereKey($teamId)
            ->value('timezone') ?? config('app.timezone'));
    }
}
