<?php

declare(strict_types=1);

use App\Models\Deal;
use App\Models\DealType;
use Illuminate\Support\Facades\DB;

/**
 * S76's query budget (issue #58).
 *
 * The house standard is `PeopleIndexBudgetTest`'s: *"the same page, ten times
 * the rows, the same number of queries."* This screen shipped its first draft
 * asking each type for its own deal count, which was 12 queries at zero custom
 * types and 22 at ten — an N+1 in a project that already holds that line with
 * a test, on a page whose whole job is to render a count per row.
 *
 * One grouped `count(*)` collapses it, and this is what stops the per-row
 * version coming back.
 */
function seedDealTypes(int $count, string $label): array
{
    [$team, $member] = test()->teamWithOwner();

    test()->enrollTwoFactor($member);

    app(App\Support\Tenancy\TeamContext::class)->runFor($team, function () use ($team, $count, $label): void {
        for ($i = 0; $i < $count; $i++) {
            $type = DealType::factory()->create([
                'team_id' => $team->getKey(),
                'name' => "{$label} type {$i}",
            ]);

            // Deals on each, so the count is doing real work rather than
            // returning zero from an empty table.
            Deal::factory()->count(2)->create([
                'team_id' => $team->getKey(),
                'deal_type_id' => $type->getKey(),
            ]);
        }
    });

    return [$team, $member];
}

function countDealTypeQueries(Closure $callback): int
{
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $callback();

    return $queries;
}

it('does not grow its query count with the number of deal types', function (): void {
    [$smallTeam, $smallMember] = seedDealTypes(2, 'small');

    $this->actingAsPerson($smallMember, $smallTeam);

    $small = countDealTypeQueries(fn () => $this->get('/settings/deal-types')->assertOk());

    [$largeTeam, $largeMember] = seedDealTypes(20, 'large');

    $this->actingAsPerson($largeMember, $largeTeam);

    $large = countDealTypeQueries(fn () => $this->get('/settings/deal-types')->assertOk());

    /*
     * **Exactly equal**, which is `PeopleIndexBudgetTest`'s assertion and the
     * only one that catches this.
     *
     * The first version allowed `$large - $small <= $small` — that is,
     * `$large <= 2 × $small` — and round 2 disproved it the right way, by
     * restoring the per-row count and watching the test still pass. A budget
     * that a tenfold N+1 fits inside is not a budget.
     */
    expect($large)->toBe(
        $small,
        'The deal types screen gained queries as it gained rows. The per-row '
        .'deal count is the usual cause, and so is asking the policy per row: '
        .'one grouped count(*) and one permission check for the page, not one '
        .'of each per type.',
    );
});

it('renders the whole screen in a handful of queries', function (): void {
    [$team, $member] = seedDealTypes(10, 'budget');

    $this->actingAsPerson($member, $team);

    $queries = countDealTypeQueries(fn () => $this->get('/settings/deal-types')->assertOk());

    /*
     * The types, the grouped count, the one permission check, and the shared
     * tenancy queries every request carries. 20 is `PeopleIndexBudgetTest`'s
     * number and this sits comfortably inside it — the point is the order of
     * magnitude, not the exact figure, which is what the growth test above
     * actually pins.
     *
     * For scale: asking the policy three times per row instead of once per
     * page put this at 73.
     */
    expect($queries)->toBeLessThanOrEqual(20);
});
