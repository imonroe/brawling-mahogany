<?php

declare(strict_types=1);

use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\ExternalLink;
use App\Models\Property;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * S35's query budget (issue #61).
 *
 * The house standard, from `PeopleIndexBudgetTest`: *"the same page, ten times
 * the rows, the same number of queries."* This screen renders a linked-deal
 * count per row and a count per status in the filter bar, which are the two
 * shapes that turn into an N+1 the moment somebody asks per row instead of
 * once — S76 shipped exactly that and this is the guard that stops it landing
 * here too.
 *
 * `toBe`, not "within a factor of two". A tenfold N+1 fits comfortably inside
 * a doubling budget, which is how #148's first version of this passed.
 */
function seedProperties(int $count): array
{
    [$team, $member] = test()->teamWithMember();

    app(TeamContext::class)->runFor($team, function () use ($team, $count): void {
        $deal = Deal::factory()->create(['team_id' => $team->getKey()]);

        for ($i = 0; $i < $count; $i++) {
            $property = Property::factory()->create([
                'team_id' => $team->getKey(),
                'street' => "{$i} Main St",
            ]);

            // A link on each, so the per-row count is doing real work rather
            // than returning zero from an empty table.
            DealProperty::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => $deal->getKey(),
                'property_id' => $property->getKey(),
            ]);
        }
    });

    return [$team, $member];
}

function countPropertyQueries(Closure $callback): int
{
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $callback();

    return $queries;
}

it('does not grow its query count with the number of properties', function (): void {
    [$smallTeam, $smallMember] = seedProperties(2);
    [$largeTeam, $largeMember] = seedProperties(20);

    $small = countPropertyQueries(function () use ($smallMember, $smallTeam): void {
        $this->actingAsPerson($smallMember, $smallTeam);
        $this->get('/properties')->assertOk();
    });

    $large = countPropertyQueries(function () use ($largeMember, $largeTeam): void {
        $this->actingAsPerson($largeMember, $largeTeam);
        $this->get('/properties')->assertOk();
    });

    expect($large)->toBe($small);
});

it('does not grow the detail screen’s query count with what is on the property', function (): void {
    /*
     * Ten deals and ten links against two, on the same screen.
     *
     * An absolute number would have measured the shell rather than the screen:
     * the layout's permission checks re-query the membership on every ability
     * and dwarf this page's own queries, but they are constant per page. What
     * can regress is growth with the data, so that is what is asserted.
     *
     * Both fixtures are built **before** either is counted. Seeding inside the
     * counted closure measured the seed — the first version of this compared
     * 49 to 97 and would have passed a real N+1 as easily as it failed a
     * healthy screen.
     */
    [$smallTeam, $smallMember, $smallUrl] = detailFixture(2);
    [$largeTeam, $largeMember, $largeUrl] = detailFixture(10);

    $small = countPropertyQueries(function () use ($smallMember, $smallTeam, $smallUrl): void {
        $this->actingAsPerson($smallMember, $smallTeam);
        $this->get($smallUrl)->assertOk();
    });

    $large = countPropertyQueries(function () use ($largeMember, $largeTeam, $largeUrl): void {
        $this->actingAsPerson($largeMember, $largeTeam);
        $this->get($largeUrl)->assertOk();
    });

    expect($large)->toBe($small);
});

/**
 * A property carrying `$count` linked deals and `$count` external links.
 *
 * @return array{0: App\Models\Team, 1: App\Models\Person, 2: string}
 */
function detailFixture(int $count): array
{
    [$team, $member] = test()->teamWithMember();

    $property = app(TeamContext::class)->runFor($team, function () use ($team, $count): Property {
        $property = Property::factory()->create(['team_id' => $team->getKey()]);

        for ($i = 0; $i < $count; $i++) {
            DealProperty::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => Deal::factory()->create(['team_id' => $team->getKey()])->getKey(),
                'property_id' => $property->getKey(),
            ]);
        }

        ExternalLink::factory()->count($count)->attachedTo($property)->create();

        return $property;
    });

    return [$team, $member, "/properties/{$property->getKey()}"];
}
