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
            ->when($window === 'upcoming', fn ($query) => $query
                ->whereBetween('date', [$today, CarbonImmutable::parse($today)
                    ->addDays(self::HORIZON_DAYS)
                    ->toDateString()]))
            ->when($window === 'overdue', fn ($query) => $query->where('date', '<', $today))
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
            'counts' => $this->counts($today),
        ]);
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
    private function counts(string $today): array
    {
        $horizon = CarbonImmutable::parse($today)->addDays(self::HORIZON_DAYS)->toDateString();

        $count = static fn (Closure $narrow): \Illuminate\Database\Query\Builder => $narrow(
            KeyDate::query()->confirmed(),
        )->toBase()->selectRaw('count(*)');

        $row = DB::query()
            ->selectSub(
                $count(fn ($query) => $query->whereBetween('date', [$today, $horizon])),
                'upcoming',
            )
            ->selectSub($count(fn ($query) => $query->where('date', '<', $today)), 'overdue')
            /*
             * Critical counts the ones still ahead. A critical deadline that
             * has passed is already in `overdue`, and counting it twice would
             * make the toggle look like it widened the list it narrows.
             */
            ->selectSub(
                $count(fn ($query) => $query->where('is_critical', true)->where('date', '>=', $today)),
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
