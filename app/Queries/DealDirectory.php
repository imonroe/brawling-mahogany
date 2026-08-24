<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\DealState;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\DealType;
use App\Models\Task;
use App\Models\Workflow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The deals index's query (Screen Inventory S13 · issue #78 · PRD §4.9 F9.1).
 *
 * Modelled on `PropertyDirectory`, which is the shape this codebase settled
 * on for an index: one builder shared by the rows and the counts, so a filter
 * bar can never disagree with the list under it.
 *
 * ## Closed deals are excluded by default, and that is a volume decision
 *
 * Issue #78: *"A team with two years of history has hundreds"*, against PRD
 * §9's twenty-five active. The default segment is `open`, not `all`, so the
 * screen a person opens twenty times a day is the twenty-five rows they are
 * working rather than the eight hundred they are not.
 */
final class DealDirectory
{
    public const PER_PAGE = 25;

    /**
     * Sorts a person can ask for, and what each one orders by.
     *
     * An allowlist rather than a column name off the query string, for the
     * ordinary reason: `orderBy($request->input('sort'))` is an injection
     * hole, and a typo would be a 500 rather than a screen.
     *
     * **These are `dealRow.ts`'s column keys**, which is the vocabulary the UI
     * speaks: `Table` emits `column.key` and the page puts it straight into
     * the query string. This list first said `'name'` where the column is
     * called `'primary'`, so pressing **Deal** sent a key the allowlist did
     * not contain, the request fell to the default ordering, and the branch
     * that exists for hand-typed junk swallowed the app's own button. `date`
     * worked only because the two vocabularies happen to spell it the same.
     *
     * Keys rather than key => column, because neither sort *is* a column: the
     * deal sorts by the string `displayName()` produces and the date by a
     * subquery result. `DealsIndexTest` reads the `sortable` entries out of
     * `dealRow.ts` and checks every one of them is here, so the two lists
     * cannot drift apart again.
     */
    public const SORTS = ['primary', 'date'];

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(
        string $segment = 'open',
        string $search = '',
        ?string $dealTypeId = null,
        string $sort = '',
        string $direction = 'asc',
    ): LengthAwarePaginator {
        $query = $this->query($segment, $search, $dealTypeId)
            ->select('deals.*')
            /*
             * The column list and the next-due-date subquery are added
             * **here**, not in `query()`.
             *
             * `segmentCounts()` shares `query()` and then groups by state, and
             * both of these are grouping errors against that: `deals.*`
             * selects ungrouped columns, and the correlated subquery
             * references an ungrouped `deals.id`. Neither is anything the
             * counts need.
             *
             * So `query()` owns the **filter** and nothing else, and each
             * caller selects what its own rows require. That is the split
             * `PropertyDirectory` already has; sharing one more line than the
             * two callers agree on is what broke it.
             */
            ->withNextDueDate()
            /*
             * The client, the stage and the subject property, eager-loaded.
             *
             * Twenty-five rows each asking for their own participant is the
             * N+1 `DealsIndexBudgetTest` refuses. `currentStage` is the one
             * `Workflow` calls *"a denormalised convenience for the deals
             * index"* — this is the screen it was denormalised for, so it is
             * read rather than walked.
             */
            ->with([
                'dealType:id,name,side',
                'participants' => fn ($participants) => $participants->with('membership:id,first_name,last_name'),
                'propertyLinks' => fn ($links) => $links->with('property:id,street,city,state,postal_code,parcel_number'),
                'workflows' => fn ($workflows) => $workflows->with('currentStage:id,name'),
            ]);

        $this->applySort($query, $sort, $direction);

        return $query
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Deal $deal): array => self::row($deal));
    }

    /**
     * How many deals sit behind each segment.
     *
     * One grouped query, not one per segment — and grouped through the
     * **Eloquent** builder rather than `getQuery()`, because ADR 0002's first
     * layer is applied when the Eloquent builder runs and a base-builder count
     * would have been every team's deals in one filter bar. The counts come
     * off hydrated models rather than `pluck()`, because `pluck()` returns
     * cast values and the grouping key would arrive as a `DealState` instance.
     *
     * Both traps are `PropertyDirectory`'s, carried rather than rediscovered.
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    public function segmentCounts(string $search = '', ?string $dealTypeId = null): array
    {
        $counts = [];

        $rows = $this->query('all', $search, $dealTypeId)
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->get();

        foreach ($rows as $row) {
            $counts[$row->state->value] = (int) $row->getAttribute('total');
        }

        $open = $counts[DealState::Active->value] ?? 0;

        return [
            ['value' => 'open', 'label' => 'Open', 'count' => $open],
            ['value' => 'all', 'label' => 'All', 'count' => array_sum($counts)],
            ...array_map(
                fn (DealState $state): array => [
                    'value' => $state->value,
                    'label' => $state->label(),
                    'count' => $counts[$state->value] ?? 0,
                ],
                array_values(array_filter(
                    DealState::cases(),
                    fn (DealState $state): bool => $state !== DealState::Active,
                )),
            ),
        ];
    }

    /**
     * @return Builder<Deal>
     */
    public function query(string $segment = 'open', string $search = '', ?string $dealTypeId = null): Builder
    {
        $query = Deal::query();

        /*
         * `open` and `all` are the two segments that are not a state, and
         * anything unrecognised falls to `open` rather than emptying the
         * screen — the same rule `PropertyController` states for its own
         * filter: a hand-typed query string should not look like a bug.
         */
        $state = DealState::tryFrom($segment);

        if ($state instanceof DealState) {
            $query->where('deals.state', $state->value);
        } elseif ($segment !== 'all') {
            $query->open();
        }

        if ($dealTypeId !== null && $dealTypeId !== '' && $dealTypeId !== 'all') {
            $query->where('deals.deal_type_id', $dealTypeId);
        }

        $search = trim($search);

        if ($search !== '') {
            /*
             * `ILIKE` rather than `LIKE lower(…)`, and the wildcards escaped,
             * so a search for `100%` finds the row containing "100%" rather
             * than every row. `PropertyDirectory`'s reasoning, verbatim.
             *
             * Both name columns, because `Deal::displayName()` reads the typed
             * name first and falls back to the generated one — searching only
             * one of them would miss whichever half a given deal is shown by.
             */
            $term = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function (Builder $where) use ($term): void {
                $where->where('deals.name', 'ilike', $term)
                    ->orWhere('deals.generated_name', 'ilike', $term);
            });
        }

        return $query;
    }

    /**
     * One row, in the shape `DealRow.vue` reads.
     *
     * Nothing is formatted here. IA §10 fixes every rule and
     * `lib/formatters.ts` is where they live, so the owner arrives as name
     * parts and the date as an ISO string — a controller building the strings
     * would be the ninety-one-screens problem starting.
     *
     * `state` is the raw code for the same reason: `lib/states.ts` decides the
     * label and the tone, and throws on one it does not know rather than
     * rendering an unstyled badge.
     *
     * @return array<string, mixed>
     */
    public static function row(Deal $deal): array
    {
        return [
            'id' => $deal->getKey(),
            'name' => $deal->displayName(),
            'url' => route('deals.show', $deal),
            'client' => self::clientName($deal),
            'stage' => self::stageName($deal),
            'state' => $deal->state->value,
            'dealTypeName' => $deal->dealType?->name,
            'nextDate' => self::nextDate($deal),
        ];
    }

    /**
     * Whose deal this is.
     *
     * The main contact when somebody has said which, otherwise the first
     * participant — the same rule and the same ordering `DealHeader` uses, so
     * the index and the deal it opens name the same human. Null renders as an
     * empty cell rather than "Unknown": a deal five minutes old has nobody on
     * it yet, and that is an ordinary state.
     */
    private static function clientName(Deal $deal): ?string
    {
        $participant = $deal->participants->firstWhere('is_primary', true)
            ?? $deal->participants->first();

        return $participant instanceof DealParticipant ? $participant->fullName() : null;
    }

    /**
     * Which stage the deal is standing in.
     *
     * PRD §7.5 gives a deal concurrent workflows on purpose, and one cell
     * cannot name two stages. Settled the way `DealHeader::advanceTarget()`
     * settled the same question for the Advance button: **only when exactly
     * one workflow is running**. With two, the cell is empty and the deal page
     * shows both — a cell that silently picked one would be wrong half the
     * time and never say so.
     */
    private static function stageName(Deal $deal): ?string
    {
        $running = $deal->workflows->filter(
            fn (Workflow $workflow): bool => $workflow->isRunning() && $workflow->currentStage !== null,
        );

        return $running->count() === 1 ? $running->first()?->currentStage?->name : null;
    }

    /**
     * The soonest open task's due date, as the "next date" column.
     *
     * **Not a key date**, because `key_dates` does not exist — Dates and
     * Deadlines is S18, in Slice 4. An open task's due date is the nearest
     * real answer the schema can give today, and it is a true one: it is a
     * date somebody on this deal has to do something by.
     *
     * Computed in SQL by `Deal::withNextDueDate()` rather than by walking
     * `$deal->tasks` per row, which is the N+1 the budget test exists to
     * catch. When S18 lands, this is the method that changes, and the column
     * label stops being a near-enough.
     */
    private static function nextDate(Deal $deal): ?string
    {
        $due = $deal->getAttribute('next_due_date');

        return is_string($due) && $due !== '' ? $due : null;
    }

    /**
     * @param  Builder<Deal>  $query
     */
    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        /*
         * A `match` on the key, not an `if`/`else` chain.
         *
         * The `else` used to be `'primary'` by position: adding a third key to
         * `SORTS` and to `dealRow.ts` would satisfy the test that binds those
         * two lists, light that column's chevron, and order the rows by the
         * **deal name** — a header confirming a sort nobody asked for, which
         * is the defect this screen has now been through twice. An unhandled
         * key falls to the default it already falls to for junk.
         *
         * The arms are exact literals, so anything that is not one of them —
         * a new `SORTS` key with no arm, or a hand-typed `?sort=banana` —
         * lands on `default`. There used to be an `in_array($sort, SORTS)`
         * wrapped round the subject as well; it maps one set of unhandled
         * strings onto another and changes nothing, which a mutation proved.
         * The allowlist that matters is the controller's, because that is the
         * one deciding what gets **echoed back** to the page.
         */
        match ($sort) {
            /*
             * Deals with nothing due sort last either way round: an empty cell
             * is not "soonest", and it is not "latest" either.
             */
            'date' => $query->orderByRaw("next_due_date {$direction} nulls last"),

            /*
             * **The string the cell shows**, which is not either column on its
             * own. `Deal::displayName()` prefers the typed `name` and falls
             * back to `generated_name`, so ordering by `generated_name`
             * alphabetised by a value the reader cannot see: a deal typed
             * "Aardvark" with a generated name of "9 Zoo Lane" sorted last
             * under an ascending Deal header.
             *
             * `nullif(btrim(...))` rather than a bare `coalesce`, because
             * `displayName()` falls back on **blank** and `coalesce` falls
             * back on null — so a `name` of `''` was a value `coalesce` kept
             * and sorted by. The row and the sort have to agree on what blank
             * means, so the character set is PHP `trim()`'s default rather
             * than `btrim()`'s space-only one: a name of a single tab renders
             * as its generated name, and without the tab in here it sorted
             * under one.
             *
             * `trim()`'s list is ` \t\n\r\0\x0B` and this one is that list
             * **minus the NUL**, deliberately. `E'\000'` is not a string
             * Postgres will accept — *invalid byte sequence for encoding
             * "UTF8": 0x00* — because a `text` value cannot contain a NUL at
             * all, so there is nothing here to trim. The two lists differ by a
             * character that cannot reach this column.
             *
             * `nulls last` in both directions: Postgres defaults to NULLS
             * FIRST on a descending sort, and a deal with no name at all
             * heading the list on one of the two presses is not an order
             * anybody asked for. It is what puts "Untitled deal" last.
             */
            'primary' => $query->orderByRaw(
                "coalesce(nullif(btrim(deals.name, E' \\t\\n\\r\\013'), ''), "
                ."nullif(btrim(deals.generated_name, E' \\t\\n\\r\\013'), '')) {$direction} nulls last",
            ),

            /*
             * The default, and it is the one the index on `deals` was written
             * for: `(team_id, state, opened_at)`. Sorting by name instead
             * would not use it.
             */
            default => $query->orderByDesc('deals.opened_at'),
        };

        /*
         * A tiebreaker, because ties are the normal case here.
         *
         * `opened_at` is a date two deals created in one sitting share, and
         * `generated_name` is nullable — a deal with neither a subject
         * property nor a named client has nothing to sort by at all. Postgres
         * gives no stable order among equal keys, so without this a row could
         * appear on page one and page two, or on neither.
         *
         * `PropertyDirectory` carries the same line for the same reason, and
         * a test reads this method's source to check it stayed last.
         */
        $query->orderBy('deals.id');
    }

    /**
     * Every deal type this team can filter by, for the chip.
     *
     * Derived from the team's **deals**, not from `deal_types`.
     *
     * `DealType` carries no global team scope — the shipped types are shared
     * rows with a null `team_id`, which is what makes them shared — so
     * `DealType::query()->get()` would have offered another team's private
     * type in this team's filter bar. Taking the ids off scoped deals means
     * the tenancy is done by the scope that already exists rather than by a
     * `where` somebody has to remember.
     *
     * It also means the chip only offers types that would select something.
     * A filter whose every option can return an empty list reads as a bug.
     *
     * @return list<array{value: string, label: string}>
     */
    public function dealTypeOptions(): array
    {
        $ids = Deal::query()->distinct()->pluck('deal_type_id')->filter()->all();

        if ($ids === []) {
            return [];
        }

        return array_values(DealType::query()
            ->whereKey($ids)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (DealType $type): array => [
                'value' => (string) $type->getKey(),
                'label' => $type->name,
            ])
            ->all());
    }
}
