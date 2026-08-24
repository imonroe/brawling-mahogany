<?php

declare(strict_types=1);

use App\Enums\WorkflowState;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\Gate;
use App\Models\Stage;
use App\Models\Task;
use App\Models\Workflow;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * S16's query budget (issue #76).
 *
 * The house standard: *"the same page, ten times the rows, the same number of
 * queries."* `toBe()`, not a factor — #148 shipped a budget loose enough for a
 * tenfold N+1 to fit inside it.
 *
 * ## Why this screen needs its own, beside S15's
 *
 * The overview renders **one card per workflow** and evaluates the active stage
 * of each. The timeline renders **every stage of every workflow**, with that
 * stage's gates and its tasks — so it fans out one level deeper, and along an
 * axis S15's fixture does not grow at all. `stages.tasks` is an eager-load only
 * this screen needs, and a screen that forgot it would still pass every
 * assertion in `DealTimelineTest`: the data would be right and there would
 * simply be one query per stage fetching it.
 *
 * Both fixtures are built **before** either is counted. Seeding inside the
 * counted closure measures the seed, which is what #61's first version of this
 * did.
 *
 * ## What the fixture is shaped to catch
 *
 * Every axis this screen fans out along grows, because a fixture that grows
 * only one of them measures only one of them:
 *
 * - **workflows**, so `Workflow::activeStage()` reading the loaded stage list
 *   rather than querying is held;
 * - **stages per workflow**, the axis S15 never grows;
 * - **gates per stage**, including a `field_populated` gate that walks
 *   `$gate->stage->workflow->deal` — the walk the controller's relation
 *   back-fill exists to prevent, and one query per gate per render without it;
 * - **tasks per stage**, which only this screen loads.
 */
function timelineBudgetFixture(int $size): array
{
    [$team, $member] = test()->teamWithMember();

    $deal = app(TeamContext::class)->runFor($team, function () use ($team, $size): Deal {
        $type = DealType::factory()->create(['team_id' => $team->getKey()]);

        $deal = Deal::factory()->create([
            'team_id' => $team->getKey(),
            'deal_type_id' => $type->getKey(),
        ]);

        for ($w = 0; $w < $size; $w++) {
            $workflow = Workflow::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => $deal->getKey(),
                'name' => "Workflow {$w}",
                'state' => WorkflowState::Active,
            ]);

            $active = null;

            // Stages grow with the fixture — the axis S15's budget never moves.
            for ($position = 0; $position < $size * 2; $position++) {
                $stage = Stage::factory()
                    ->when($position === 0, fn ($factory) => $factory->active())
                    ->create([
                        'team_id' => $team->getKey(),
                        'workflow_id' => $workflow->getKey(),
                        'name' => "Stage {$position}",
                        'sort_order' => $position,
                    ]);

                $active ??= $stage;

                /*
                 * Gates and tasks on **every** stage, not only the active one.
                 * A collapsed row counts its tasks for the meta string and asks
                 * its gates whether any was overridden, so both are read for
                 * stages nobody is advancing — and a fixture that hung them
                 * only off the active stage would leave those two reads
                 * costing one query each, forever, unmeasured.
                 */
                Gate::factory()->create([
                    'team_id' => $team->getKey(),
                    'stage_id' => $stage->getKey(),
                    'label' => 'Photos are back',
                    'sort_order' => 0,
                ]);

                Gate::factory()
                    ->ofType('field_populated', ['field' => 'transaction_value'])
                    ->create([
                        'team_id' => $team->getKey(),
                        'stage_id' => $stage->getKey(),
                        'label' => 'Price is agreed',
                        'sort_order' => 1,
                    ]);

                Task::factory()->create([
                    'team_id' => $team->getKey(),
                    'deal_id' => $deal->getKey(),
                    'stage_id' => $stage->getKey(),
                    'title' => "Task {$position}",
                    'sort_order' => 0,
                ]);
            }

            $workflow->forceFill(['current_stage_id' => $active->getKey()])->save();
        }

        return $deal;
    });

    return [$team, $member, "/deals/{$deal->getKey()}/timeline"];
}

function countDealTimelineQueries(Closure $callback): int
{
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $callback();

    return $queries;
}

it('does not grow its query count with the workflows, stages, gates or tasks on a deal', function (): void {
    [$smallTeam, $smallMember, $smallUrl] = timelineBudgetFixture(1);
    [$largeTeam, $largeMember, $largeUrl] = timelineBudgetFixture(5);

    $small = countDealTimelineQueries(function () use ($smallMember, $smallTeam, $smallUrl): void {
        $this->actingAsPerson($smallMember, $smallTeam);
        $this->get($smallUrl)->assertOk();
    });

    $large = countDealTimelineQueries(function () use ($largeMember, $largeTeam, $largeUrl): void {
        $this->actingAsPerson($largeMember, $largeTeam);
        $this->get($largeUrl)->assertOk();
    });

    expect($large)->toBe($small);
});
