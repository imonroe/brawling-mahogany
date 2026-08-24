<?php

declare(strict_types=1);

use App\Enums\ParticipantRole;
use App\Enums\WorkflowState;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\DealProperty;
use App\Models\DealType;
use App\Models\Person;
use App\Models\Property;
use App\Models\Stage;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * S13's query budget (issue #78).
 *
 * The house standard, from `PeopleIndexBudgetTest`: *"the same page, ten times
 * the rows, the same number of queries."*
 *
 * This screen has **four** places an N+1 can hide, and every row has to grow
 * all four or the guard measures nothing: the client comes from a participant,
 * the stage from a workflow's current stage, the next date from the deal's
 * tasks, and the deal type from a lookup. A fixture that grew the deals and
 * left them empty would be twenty rows of nulls, and twenty nulls cost one
 * query however badly the code asks for them.
 *
 * `toBe`, not "within a factor of two". A tenfold N+1 fits comfortably inside
 * a doubling budget, which is how #148's first version of this passed.
 */
function seedDeals(int $count, bool $withProperties = false): array
{
    [$team, $member] = test()->teamWithMember();

    app(TeamContext::class)->runFor($team, function () use ($team, $count, $withProperties): void {
        $type = DealType::query()->whereNull('team_id')->firstOrFail();

        for ($i = 0; $i < $count; $i++) {
            $deal = Deal::factory()->create([
                'team_id' => $team->getKey(),
                'deal_type_id' => $type->getKey(),
                'generated_name' => "{$i} Main St",
            ]);

            // A client, so the participant lookup is doing real work.
            $membership = TeamMembership::query()->create([
                'team_id' => $team->getKey(),
                'person_id' => Person::factory()->create()->getKey(),
                'first_name' => 'Client',
                'last_name' => "Number {$i}",
            ]);

            DealParticipant::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => $deal->getKey(),
                'team_membership_id' => $membership->getKey(),
                'participant_role' => ParticipantRole::Buyer,
                'is_primary' => true,
            ]);

            // A running workflow on a stage, so the stage cell resolves.
            $workflow = Workflow::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => $deal->getKey(),
                'state' => WorkflowState::Active,
            ]);

            $stage = Stage::factory()->active()->create([
                'team_id' => $team->getKey(),
                'workflow_id' => $workflow->getKey(),
                'name' => 'Inspection',
                'sort_order' => 0,
            ]);

            $workflow->forceFill(['current_stage_id' => $stage->getKey()])->save();

            // And an open task, so the next-date subquery returns a date.
            Task::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => $deal->getKey(),
                'due_date' => now()->addDays($i + 1)->toDateString(),
            ]);

            /*
             * A subject property, for the test that no cell reads one. Off by
             * default so the growth test above measures the four cells it was
             * written for and nothing else.
             */
            if ($withProperties) {
                DealProperty::factory()->create([
                    'team_id' => $team->getKey(),
                    'deal_id' => $deal->getKey(),
                    'property_id' => Property::factory()->create(['team_id' => $team->getKey()])->getKey(),
                    'is_subject' => true,
                ]);
            }
        }
    });

    return [$team, $member];
}

function countDealsIndexQueries(Closure $callback): int
{
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $callback();

    return $queries;
}

it('does not grow its query count with the number of deals', function (): void {
    /*
     * Both fixtures seeded before either is counted. Seeding inside the
     * counted closure measures the seed, which is what #61's first version of
     * this did.
     */
    [$smallTeam, $smallMember] = seedDeals(2);
    [$largeTeam, $largeMember] = seedDeals(25);

    $small = countDealsIndexQueries(function () use ($smallMember, $smallTeam): void {
        $this->actingAsPerson($smallMember, $smallTeam);
        $this->get('/deals')->assertOk();
    });

    $large = countDealsIndexQueries(function () use ($largeMember, $largeTeam): void {
        $this->actingAsPerson($largeMember, $largeTeam);
        $this->get('/deals')->assertOk();
    });

    /*
     * Not an absolute number. An absolute one would measure the shell rather
     * than the screen: the layout's permission checks re-query the membership
     * on every ability and dwarf this page's own queries. What can regress is
     * growth with the data, so that is what is asserted.
     *
     * 25 is #78's number — *"25 rows render within the p95 budget"* — so the
     * large fixture is the case the issue names rather than a round number.
     */
    expect($large)->toBe($small);
});

/**
 * The index does not pay for a relation no cell reads.
 *
 * `paginate()` eager-loaded `propertyLinks` with a nested `property` and
 * nothing ever touched it: `row()` names a deal with `displayName()`, which
 * reads the `name` and `generated_name` **columns**. The subject property is
 * how `generated_name` was *derived*, upstream, by `NameDeal` — it is not
 * something this screen asks the database for.
 *
 * The growth test above cannot see this. It asserts 25 deals cost what 2
 * deals cost, which is the right shape for an N+1 and blind to a fixed cost
 * paid once per page. So this one holds the two fixtures **the same size**
 * and varies only whether the deals have properties hanging off them: an
 * eager-load that nothing reads shows up as the linked team costing more.
 *
 * It is a ceiling on nothing in particular, which is the point — an absolute
 * number would measure the layout's permission checks, as the test above
 * explains.
 */
it('does not pay for a relation no cell reads', function (): void {
    [$plainTeam, $plainMember] = seedDeals(3);
    [$linkedTeam, $linkedMember] = seedDeals(3, withProperties: true);

    $plain = countDealsIndexQueries(function () use ($plainMember, $plainTeam): void {
        $this->actingAsPerson($plainMember, $plainTeam);
        $this->get('/deals')->assertOk();
    });

    $linked = countDealsIndexQueries(function () use ($linkedMember, $linkedTeam): void {
        $this->actingAsPerson($linkedMember, $linkedTeam);
        $this->get('/deals')->assertOk();
    });

    expect($linked)->toBe($plain);
});
