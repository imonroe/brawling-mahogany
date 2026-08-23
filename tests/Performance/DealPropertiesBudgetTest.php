<?php

declare(strict_types=1);

use App\Enums\DealSide;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\Property;
use App\Support\Properties\PropertyDeals;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * S20's query budget (issue #62).
 *
 * The house standard, from `PeopleIndexBudgetTest`: *"the same page, ten times
 * the rows, the same number of queries."* This screen renders one property per
 * link, and a buyer's deal is the case #62 describes as twelve — so asking each
 * link for its own property is the N+1 that would land first.
 *
 * `toBe()`, not a factor. #148 shipped a budget loose enough for a tenfold
 * N+1 to fit inside it.
 *
 * Both fixtures are built **before** either is counted. Seeding inside the
 * counted closure measures the seed, which is what #61's first version of this
 * did.
 *
 * @return array{0: App\Models\Team, 1: App\Models\Person, 2: string}
 */
function dealWithProperties(int $count): array
{
    [$team, $member] = test()->teamWithMember();

    $deal = app(TeamContext::class)->runFor($team, function () use ($team, $count): Deal {
        $type = DealType::factory()->create(['team_id' => $team->getKey(), 'side' => DealSide::Buy]);
        $deal = Deal::factory()->create(['team_id' => $team->getKey(), 'deal_type_id' => $type->getKey()]);

        $links = app(PropertyDeals::class);

        for ($i = 0; $i < $count; $i++) {
            $links->link(
                Property::factory()->create(['team_id' => $team->getKey(), 'street' => "{$i} Main St"]),
                $deal,
            );
        }

        return $deal;
    });

    return [$team, $member, "/deals/{$deal->getKey()}/properties"];
}

function countDealPropertyQueries(Closure $callback): int
{
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $callback();

    return $queries;
}

it('does not grow its query count with the number of properties on the deal', function (): void {
    [$smallTeam, $smallMember, $smallUrl] = dealWithProperties(2);
    [$largeTeam, $largeMember, $largeUrl] = dealWithProperties(12);

    $small = countDealPropertyQueries(function () use ($smallMember, $smallTeam, $smallUrl): void {
        $this->actingAsPerson($smallMember, $smallTeam);
        $this->get($smallUrl)->assertOk();
    });

    $large = countDealPropertyQueries(function () use ($largeMember, $largeTeam, $largeUrl): void {
        $this->actingAsPerson($largeMember, $largeTeam);
        $this->get($largeUrl)->assertOk();
    });

    expect($large)->toBe($small);
});
