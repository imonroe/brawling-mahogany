<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\DealState;
use App\Enums\StageState;
use App\Enums\TaskState;
use App\Models\Deal;
use App\Models\Person;
use App\Models\Stage;
use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * S10 — the team dashboard (PRD §4.9 F9.1 · Design System §9.3 · #79).
 *
 * *"Designed for 25 concurrent active deals. Current stage per deal, blocked
 * deals, overdue tasks, key dates in the next 14 days, recent activity."* G8
 * is where the 25 comes from — *"Emily pushed for 25 over Ian's proposed 12"*
 * — and PRD §9 holds the whole page to 400ms p95 at that volume with 500 past
 * clients behind it.
 *
 * ## Two of the four stats cannot be answered honestly yet, and both say so
 *
 * **Blocked stages reads the cache, and the cache is only true at the moment
 * something refreshed it.** `stages.state` is written by an advance attempt
 * and by nothing else, so a stage whose gate somebody satisfied this morning
 * still reads `blocked` until the next attempt. The alternative is evaluating
 * every gate on twenty-five deals on every dashboard render, which is the
 * budget PRD §9 sets, spent on a number nobody clicks. So the tile counts what
 * the record says and the line beneath it says *when* the record was written —
 * the same trade S16 makes in the other direction, and for the same reason:
 * that screen shows one expanded card where a stale badge contradicts the
 * pane beside it, and this one shows a count with nothing to contradict.
 *
 * **"Closing in 14 days" is not a question this product can answer.**
 * `key_dates` is S18, in Slice 4. Rather than claim a closing date the
 * database does not hold, the fourth tile counts what is genuinely due —
 * `Deal::withNextDueDate()`, the same near-enough S13's column already uses —
 * and is labelled for what it counts. Design System §9.3 fixes the four
 * stats; departing from one of its labels is recorded in the Screen Inventory
 * rather than papered over by a heading that lies.
 */
final class TeamDashboard
{
    /** F9.1's window: *"key dates in the next 14 days"*. */
    public const HORIZON_DAYS = 14;

    /** How many rows each panel shows before it defers to its own screen. */
    private const PANEL_ROWS = 6;

    /**
     * @return array<string, mixed>
     */
    public static function for(Person $person): array
    {
        /*
         * One pass over the active deals, and everything else counted off it.
         *
         * Twenty-five rows is small enough to hold, and holding it is what
         * turns four stat tiles and two panels into one query rather than six
         * — `DashboardBudgetTest` is what keeps it that way when somebody adds
         * a seventh reader.
         */
        $deals = Deal::query()
            ->where('state', DealState::Active)
            ->withNextDueDate()
            ->with([
                'workflows' => fn ($query) => $query->with([
                    'stages' => fn ($stages) => $stages->orderBy('sort_order'),
                ]),
            ])
            ->orderBy('name')
            ->get();

        $stages = $deals
            ->flatMap(fn (Deal $deal): Collection => $deal->workflows->flatMap(
                fn ($workflow): Collection => $workflow->stages,
            ));

        $blocked = $stages->filter(
            fn (Stage $stage): bool => $stage->state === StageState::Blocked,
        );

        $overdue = self::overdueTasks($person);
        $dueSoon = self::dueSoon($deals);

        return [
            'stats' => [
                'activeDeals' => $deals->count(),
                'blockedStages' => $blocked->count(),
                'overdueTasks' => $overdue->count(),
                'dueSoon' => $dueSoon->count(),
            ],
            /*
             * §9.3's *"Needs attention"* panel: the deals that are actually in
             * the way, blocked first and then whatever is late. One list
             * rather than two tiles' worth of rows, because the question the
             * screen answers on arrival is *"is anything on fire"* and two
             * lists make somebody read both to find out.
             */
            'needsAttention' => self::needsAttention($deals, $blocked, $overdue),
            'deals' => $deals->take(self::PANEL_ROWS)->map(self::dealRow(...))->values()->all(),
            'dueSoon' => $dueSoon->take(self::PANEL_ROWS)->map(self::dealRow(...))->values()->all(),
        ];
    }

    /**
     * Every open task in the team that is past its due date.
     *
     * Team-wide rather than the reader's own — that is My Work's question, and
     * F9.1 puts this one on the *team* dashboard. Asked of `Task::state()` so
     * the number agrees with every badge in the product, which also means it
     * reads today in the team's calendar rather than the server's.
     *
     * @return Collection<int, Task>
     */
    private static function overdueTasks(Person $person): Collection
    {
        unset($person);

        return Task::query()
            ->open()
            ->whereNotNull('due_date')
            ->whereHas('deal', fn ($query) => $query->where('state', DealState::Active))
            ->get()
            ->filter(fn (Task $task): bool => $task->state() === TaskState::Overdue)
            ->values();
    }

    /**
     * Active deals with something due inside F9.1's fortnight.
     *
     * @param  Collection<int, Deal>  $deals
     * @return Collection<int, Deal>
     */
    private static function dueSoon(Collection $deals): Collection
    {
        $horizon = now()->addDays(self::HORIZON_DAYS)->toDateString();
        $today = now()->toDateString();

        return $deals
            ->filter(function (Deal $deal) use ($today, $horizon): bool {
                $due = $deal->getAttribute('next_due_date');

                return is_string($due) && $due !== '' && $due >= $today && $due <= $horizon;
            })
            ->sortBy(fn (Deal $deal): string => (string) $deal->getAttribute('next_due_date'))
            ->values();
    }

    /**
     * @param  Collection<int, Deal>  $deals
     * @param  Collection<int, Stage>  $blocked
     * @param  Collection<int, Task>  $overdue
     * @return array<int, array<string, mixed>>
     */
    private static function needsAttention(Collection $deals, Collection $blocked, Collection $overdue): array
    {
        $blockedDealIds = $blocked
            ->map(fn (Stage $stage): ?string => $stage->workflow?->deal_id)
            ->filter()
            ->unique();

        $overdueCounts = $overdue->groupBy('deal_id')->map->count();

        return $deals
            ->filter(fn (Deal $deal): bool => $blockedDealIds->contains($deal->getKey())
                || $overdueCounts->has($deal->getKey()))
            // Blocked before merely late: a blocked deal is one nobody can
            // move at all, and a late one is one somebody can.
            ->sortBy(fn (Deal $deal): array => [
                $blockedDealIds->contains($deal->getKey()) ? 0 : 1,
                -($overdueCounts->get($deal->getKey()) ?? 0),
                $deal->displayName(),
            ])
            ->take(self::PANEL_ROWS)
            ->map(fn (Deal $deal): array => [
                ...self::dealRow($deal),
                'isBlocked' => $blockedDealIds->contains($deal->getKey()),
                'overdueCount' => $overdueCounts->get($deal->getKey()) ?? 0,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function dealRow(Deal $deal): array
    {
        $stage = $deal->workflows
            ->flatMap(fn ($workflow): Collection => $workflow->stages)
            ->first(fn (Stage $one): bool => in_array(
                $one->state,
                [StageState::Active, StageState::Blocked],
                true,
            ));

        return [
            'id' => $deal->getKey(),
            'name' => $deal->displayName(),
            'url' => '/deals/'.$deal->getKey(),
            'stageName' => $stage?->name,
            'stageState' => $stage?->state->value,
            // A day, not an instant (#165).
            'nextDueDate' => is_string($deal->getAttribute('next_due_date'))
                ? $deal->getAttribute('next_due_date')
                : null,
        ];
    }
}
