<?php

declare(strict_types=1);

use App\Enums\WorkflowState;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\Gate;
use App\Models\Property;
use App\Models\Stage;
use App\Models\Workflow;
use App\Support\Deals\DealRoster;
use App\Support\Properties\PropertyDeals;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * S15's query budget (issue #75).
 *
 * The house standard, from `PeopleIndexBudgetTest`: *"the same page, ten times
 * the rows, the same number of queries."* This is the screen the whole product
 * routes through, and it fans out four ways at once — a card per workflow, a
 * rail per stage, a row per participant, a link per property — so it is the
 * one where an N+1 is most likely and least visible.
 *
 * `toBe()`, not a factor. #148 shipped a budget loose enough for a tenfold
 * N+1 to fit inside it.
 *
 * Both fixtures are built **before** either is counted. Seeding inside the
 * counted closure measures the seed, which is what #61's first version of this
 * did.
 *
 * ## What the fixture is shaped to catch
 *
 * Three specific N+1s, each of which existed at some point while #75 was
 * written:
 *
 *  - `Workflow::activeStage()` querying per workflow instead of reading the
 *    loaded stage list.
 *  - `FieldPopulatedEvaluator` walking `$gate->stage->workflow->deal` per gate,
 *    because `DealOverviewController` had not filled the inverse relations in
 *    from the graph already in memory.
 *  - The activity card resolving an actor's name per row.
 *
 * So the large fixture grows workflows, stages, gates, participants and
 * properties together. Gate *evaluation* is per gate by design — one evaluator
 * per gate is the whole architecture (PRD §8.3) — but an evaluator that only
 * reads its own row and its parents must cost nothing, and that is the claim
 * pinned here.
 *
 * @return array{0: App\Models\Team, 1: App\Models\Person, 2: string}
 */
function dealOverviewFixture(int $size): array
{
    [$team, $member] = test()->teamWithMember();

    /*
     * Authenticated **while the fixture is built**, so the activity these
     * writes record carries an actor.
     *
     * Without this the whole activity half of the budget was untestable: every
     * event had a null `actor_person_id`, so `$event->actor?->…` short-circuits
     * and a per-row name lookup costs nothing to measure. Reintroducing the
     * exact N+1 this file names in its own docblock left it green — the
     * vacuity this PR adds a rule to `docs/Testing.md` about, in the test that
     * rule was written from.
     */
    test()->actingAsPerson($member, $team);

    $deal = app(TeamContext::class)->runFor($team, function () use ($team, $size): Deal {
        $type = DealType::factory()->create(['team_id' => $team->getKey()]);

        $deal = Deal::factory()->create([
            'team_id' => $team->getKey(),
            'deal_type_id' => $type->getKey(),
        ]);

        $links = app(PropertyDeals::class);
        $roster = app(DealRoster::class);

        for ($i = 0; $i < $size; $i++) {
            $links->link(
                Property::factory()->create(['team_id' => $team->getKey(), 'street' => "{$i} Main St"]),
                $deal,
            );

            $roster->add($deal, participantFor($team), App\Enums\ParticipantRole::Contractor);

            $workflow = Workflow::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => $deal->getKey(),
                'name' => "Workflow {$i}",
                'state' => WorkflowState::Active,
            ]);

            // Three stages so the rail has something to render, and so the
            // active one is not simply the only one.
            $active = null;

            for ($position = 0; $position < 3; $position++) {
                $stage = Stage::factory()
                    ->when($position === 0, fn ($factory) => $factory->active())
                    ->create([
                        'team_id' => $team->getKey(),
                        'workflow_id' => $workflow->getKey(),
                        'name' => "Stage {$position}",
                        'sort_order' => $position,
                    ]);

                $active ??= $stage;
            }

            // One gate that reads only its own row, and one that walks up to
            // the deal. The second is the interesting one.
            Gate::factory()->create([
                'team_id' => $team->getKey(),
                'stage_id' => $active->getKey(),
                'label' => 'Photos are back',
                'sort_order' => 0,
            ]);

            Gate::factory()
                ->ofType('field_populated', ['field' => 'transaction_value'])
                ->create([
                    'team_id' => $team->getKey(),
                    'stage_id' => $active->getKey(),
                    'label' => 'Price is agreed',
                    'sort_order' => 1,
                ]);

            $workflow->forceFill(['current_stage_id' => $active->getKey()])->save();
        }

        return $deal;
    });

    return [$team, $member, "/deals/{$deal->getKey()}"];
}

/** A membership in the team, to hang a participant off. */
function participantFor(App\Models\Team $team): App\Models\TeamMembership
{
    return App\Models\TeamMembership::query()->create([
        'team_id' => $team->getKey(),
        'person_id' => App\Models\Person::factory()->create()->getKey(),
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'status' => App\Enums\PersonLifecycleState::Active,
        'joined_at' => now(),
    ]);
}

function countDealOverviewQueries(Closure $callback): int
{
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $callback();

    return $queries;
}

it('does not grow its query count with the workflows, stages, people or properties on a deal', function (): void {
    [$smallTeam, $smallMember, $smallUrl] = dealOverviewFixture(1);
    [$largeTeam, $largeMember, $largeUrl] = dealOverviewFixture(6);

    $small = countDealOverviewQueries(function () use ($smallMember, $smallTeam, $smallUrl): void {
        $this->actingAsPerson($smallMember, $smallTeam);
        $this->get($smallUrl)->assertOk();
    });

    $large = countDealOverviewQueries(function () use ($largeMember, $largeTeam, $largeUrl): void {
        $this->actingAsPerson($largeMember, $largeTeam);
        $this->get($largeUrl)->assertOk();
    });

    expect($large)->toBe($small);
});
