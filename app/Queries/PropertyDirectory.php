<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\PropertyStatus;
use App\Models\Property;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The properties index's query (Screen Inventory S35 · issue #61).
 *
 * Paginated and counted in one pass each, for the reason `PeopleDirectory`
 * carries: PRD §3.4 puts real volume behind these screens, and a team that has
 * been running for three years has every house it ever listed in here.
 *
 * The linked-deal count is a `withCount`, not a relation walked per row. Ten
 * properties asking their own question is the N+1 that
 * `tests/Performance/PropertiesIndexBudgetTest.php` refuses.
 */
final class PropertyDirectory
{
    public const PER_PAGE = 24;

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(?PropertyStatus $status = null, string $search = ''): LengthAwarePaginator
    {
        return $this->query($status, $search)
            ->withCount('dealLinks')
            ->orderBy('city')
            ->orderBy('street')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Property $property): array => self::row($property));
    }

    /**
     * How many properties sit behind each status filter.
     *
     * One grouped query rather than one per status: eight statuses is eight
     * round trips for a filter bar nobody clicks most of the time.
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    public function statusCounts(string $search = ''): array
    {
        /*
         * Grouped through the **Eloquent** builder, not `getQuery()`.
         *
         * Dropping to the base query builder here would have dropped the
         * global team scope with it — ADR 0002's first layer is applied when
         * the Eloquent builder runs, so a base-builder count would have been
         * every team's properties in one filter bar. The counts are read off
         * hydrated models rather than `pluck()` for the reason #148 found the
         * hard way: `pluck()` returns cast values, so the grouping key would
         * have come back as a `PropertyStatus` instance.
         */
        $counts = [];

        foreach ($this->query(null, $search)->selectRaw('status, count(*) as total')->groupBy('status')->get() as $row) {
            $counts[$row->status->value] = (int) $row->getAttribute('total');
        }

        return [
            ['value' => 'all', 'label' => 'All', 'count' => array_sum($counts)],
            ...array_map(
                fn (PropertyStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'count' => $counts[$status->value] ?? 0,
                ],
                PropertyStatus::cases(),
            ),
        ];
    }

    /**
     * @return Builder<Property>
     */
    public function query(?PropertyStatus $status = null, string $search = ''): Builder
    {
        $query = Property::query();

        if ($status instanceof PropertyStatus) {
            $query->withStatus($status);
        }

        $search = trim($search);

        if ($search !== '') {
            /*
             * Address and parcel number, case-insensitively.
             *
             * `ILIKE` rather than `LIKE lower(…)` because Postgres has it and
             * the alternative is a function call on every row. The wildcards
             * are escaped: a search for `100%` must find the row containing
             * "100%", not every row.
             */
            $term = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function (Builder $where) use ($term): void {
                $where->where('street', 'ilike', $term)
                    ->orWhere('city', 'ilike', $term)
                    ->orWhere('postal_code', 'ilike', $term)
                    ->orWhere('parcel_number', 'ilike', $term);
            });
        }

        return $query;
    }

    /**
     * One row of the grid or the list — the same data either way.
     *
     * S35's toggle changes the layout and nothing else. Sending two shapes
     * would mean the toggle re-fetched, and a view switch that hits the server
     * feels broken.
     *
     * @return array<string, mixed>
     */
    public static function row(Property $property): array
    {
        return [
            'id' => $property->getKey(),
            'name' => $property->displayName(),
            'address' => self::address($property),
            'type' => $property->type->value,
            'typeLabel' => $property->type->label(),
            'status' => $property->status->value,
            'beds' => $property->beds,
            'baths' => $property->baths,
            'sqft' => $property->sqft,
            'dealCount' => (int) ($property->deal_links_count ?? 0),
        ];
    }

    /**
     * The parts `lib/formatters.ts` needs, named the way it names them.
     *
     * The server does not format the address. IA §10 fixes the rule — street
     * on line one, City, ST ZIP on line two — and `formatAddress()` is where
     * it lives; a controller building the string here would be the ninety-one
     * screens problem starting.
     *
     * @return array<string, string|null>
     */
    public static function address(Property $property): array
    {
        return [
            'street' => $property->street,
            'unit' => $property->unit,
            'city' => $property->city,
            // `state`, because that is what `AddressParts` calls it. The
            // column is `state_code` so it does not read as a state machine.
            'state' => $property->state_code,
            'postalCode' => $property->postal_code,
        ];
    }
}
