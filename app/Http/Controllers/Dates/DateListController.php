<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dates;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\KeyDate;
use App\Queries\CalendarBoard;
use App\Queries\DealDates;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S59 — every deadline, across every deal (PRD §4.8 F8.2 · issue #107).
 *
 * > This is the screen an agent checks on Monday morning to see the week's
 * > exposure across every deal.
 *
 * ## The four filters are one question asked four ways
 *
 * `next 14 days` matches the dashboard panel (F9.1) and is the default,
 * because the default has to be the Monday-morning question. `overdue` is the
 * one that is not about a window at all — it is everything already behind,
 * however far back — and `critical` narrows rather than replaces, so it is a
 * toggle beside the window rather than a fifth tab.
 *
 * ## Past due is unmissable here and never reaches a client
 *
 * #107 is explicit about both halves. These are legally significant deadlines
 * and a missed inspection objection has consequences, so the internal screen
 * says so plainly — while IA §9 keeps *overdue*, *blocked* and *failed* off
 * the client surface entirely. Two audiences, two vocabularies, and the split
 * is why the client page is a different layout rather than the same one with
 * fewer rows.
 */
class DateListController extends Controller
{
    /** F9.1's window, and S59's default. */
    private const HORIZON_DAYS = 14;

    private const WINDOWS = ['upcoming', 'overdue', 'all'];

    public function index(Request $request, DealDates $dates, CalendarBoard $board): Response
    {
        $this->authorize('viewAny', [KeyDate::class, null]);

        $window = in_array($request->query('window'), self::WINDOWS, true)
            ? (string) $request->query('window')
            : 'upcoming';

        $criticalOnly = $request->boolean('critical');

        $today = CarbonImmutable::now($board->timezone())->toDateString();

        $rows = KeyDate::query()
            /*
             * An extracted date nobody has confirmed is not a deadline (#116).
             * This is the screen where counting it would do the most damage —
             * a machine's reading of a contract, listed beside real deadlines,
             * is a date somebody plans their week around.
             */
            ->confirmed()
            /*
             * And on a deal that is still running. Without it the Overdue tab
             * grows for ever: every past deadline of every deal the team has
             * ever closed, on the screen Screen Inventory calls the one an
             * agent checks to see the week's exposure. The reminder sweep has
             * always filtered this way — three readers, one rule, and it lives
             * on the model now rather than in whichever of them remembered.
             */
            ->onOpenDeals()
            ->tap(fn (Builder $query) => self::within($query, $window, $today))
            ->when($criticalOnly, fn ($query) => $query->where('is_critical', true))
            ->with(['deal', 'anchor'])
            ->orderBy('date')
            ->orderBy('name')
            ->get();

        return Inertia::render('Dates/Index', [
            'window' => $window,
            'criticalOnly' => $criticalOnly,
            'today' => $today,
            'horizonDays' => self::HORIZON_DAYS,
            'dates' => $rows->map(fn (KeyDate $date): array => [
                ...$dates->row($date),
                'deal' => $date->deal instanceof Deal
                    ? [
                        'label' => $date->deal->displayName(),
                        'url' => route('deals.dates.index', $date->deal),
                    ]
                    : null,
            ])->values()->all(),
            'counts' => $this->counts($window, $criticalOnly, $today),
        ]);
    }

    /**
     * The window a tab shows, as a narrowing both the list and its counts apply.
     *
     * One function rather than two copies of the same two `when()`s: a badge
     * that counts a different set from the list beneath it is worse than no
     * badge, and the way that happens is a second place to state the rule.
     *
     * @param  Builder<KeyDate>  $query
     * @return Builder<KeyDate>
     */
    private static function within(Builder $query, string $window, string $today): Builder
    {
        return $query
            ->when($window === 'upcoming', fn (Builder $inner): Builder => $inner
                ->whereBetween('date', [$today, CarbonImmutable::parse($today)
                    ->addDays(self::HORIZON_DAYS)
                    ->toDateString()]))
            ->when($window === 'overdue', fn (Builder $inner): Builder => $inner
                ->where('date', '<', $today));
    }

    /**
     * The three numbers the filter bar carries, in one round trip.
     *
     * The shape `App\Queries\ShellCounts` settled on after
     * `PeopleIndexBudgetTest` measured what a count per tab costs: three
     * **scalar subqueries** in a single statement, not three statements. What
     * that saves is the round trip rather than the work, and this is a screen
     * somebody refreshes all morning.
     *
     * `toBase()`, never `getQuery()`. They look interchangeable and are not —
     * the second hands back the base builder with the team scope *not yet
     * applied*, which is how a shell badge once counted every team's rows on
     * every team's page.
     *
     * @return array{upcoming: int, overdue: int, critical: int}
     */
    private function counts(string $window, bool $criticalOnly, string $today): array
    {
        $count = static fn (Closure $narrow): \Illuminate\Database\Query\Builder => $narrow(
            // The same two narrowings the list above applies, or the badge
            // counts rows the tab beneath it does not show.
            KeyDate::query()->confirmed()->onOpenDeals(),
        )->toBase()->selectRaw('count(*)');

        /*
         * **The tab numbers carry the toggle too.** `Dates/Index.vue` keeps
         * *Critical only* on across a tab press, so with it ticked the badges
         * counted every date in each window while the list beneath showed only
         * the critical ones: *"Past due (3)"* over one row, and pressing it
         * produced one. The toggle's own count was fixed a round earlier and
         * these two were the half left behind — which is the same lesson
         * again, that a badge disagreeing with its list comes from a second
         * place stating the rule.
         */
        $narrow = static fn (string $window): Closure => static fn (Builder $q): Builder => self::within(
            $q,
            $window,
            $today,
        )->when($criticalOnly, fn (Builder $inner): Builder => $inner->where('is_critical', true));

        $row = DB::query()
            ->selectSub($count($narrow('upcoming')), 'upcoming')
            ->selectSub($count($narrow('overdue')), 'overdue')
            /*
             * **Critical counts what the toggle would leave**, which means it
             * has to sit inside the window the tab is showing. It counted
             * *"critical and still ahead"* regardless of the window, so on the
             * Past due tab a checkbox reading `(0)` produced three rows — and
             * three overdue critical deadlines is exactly the state S59 exists
             * to surface. It was wrong the other way on Next 14 days too,
             * counting critical dates past the horizon the list stops at.
             *
             * The same `within()` the list applies, so the number and the rows
             * cannot disagree about which question is being asked.
             */
            ->selectSub(
                $count(fn (Builder $q) => self::within($q, $window, $today)->where('is_critical', true)),
                'critical',
            )
            ->first();

        return [
            'upcoming' => (int) ($row->upcoming ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'critical' => (int) ($row->critical ?? 0),
        ];
    }
}
