<?php

declare(strict_types=1);

use App\Enums\WorkflowState;
use App\Models\Deal;
use App\Models\Gate;
use App\Models\Stage;
use App\Models\Task;
use App\Models\Workflow;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * S23's query budget (issue #77).
 *
 * The house standard, from `PeopleIndexBudgetTest`: *"the same page, ten times
 * the rows, the same number of queries."* This endpoint grows two ways at
 * once — a row per gate on the stage, and a stage per workflow — and both are
 * lists somebody adds to from a template editor without ever seeing this file.
 *
 * `toBe()`, not a factor. #148 shipped a budget loose enough for a tenfold
 * N+1 to fit inside it.
 *
 * Both fixtures are built **before** either is counted. Seeding inside the
 * counted closure measures the seed.
 *
 * ## What the fixture is shaped to catch
 *
 * Three things, each of which this endpoint would do by default:
 *
 *  - `FieldPopulatedEvaluator` walking `$gate->stage->workflow->deal` per
 *    gate, unless the controller fills the inverse relations in from the graph
 *    already in memory.
 *  - `Workflow::stageAfter()` and `Workflow::activeStage()` querying per call
 *    rather than reading the loaded stage list.
 *  - The "what happens when you advance" block counting tasks per stage
 *    instead of per advance.
 *
 * Gate *evaluation* is per gate by design — one evaluator per gate is the
 * whole architecture (PRD §8.3) — but an evaluator that only reads its own row
 * and its parents must cost nothing, and that is the claim pinned here.
 *
 * @return array{0: App\Models\Team, 1: App\Models\Person, 2: string}
 */
function advancePreviewFixture(int $size): array
{
    [$team, $member] = test()->teamWithMember();

    $url = app(TeamContext::class)->runFor($team, function () use ($team, $size): string {
        $deal = Deal::factory()->create(['team_id' => $team->getKey()]);

        $workflow = Workflow::factory()->create([
            'team_id' => $team->getKey(),
            'deal_id' => $deal->getKey(),
            'state' => WorkflowState::Active,
        ]);

        $active = null;

        // Stages grow with the fixture, so `stageAfter()` and the position
        // lookup have a list to walk rather than a pair.
        for ($position = 0; $position < $size + 1; $position++) {
            $stage = Stage::factory()
                ->when($position === 0, fn ($factory) => $factory->active())
                ->create([
                    'team_id' => $team->getKey(),
                    'workflow_id' => $workflow->getKey(),
                    'name' => "Stage {$position}",
                    'sort_order' => $position,
                ]);

            $active ??= $stage;

            // An open task on every stage, so the consequence block has both
            // halves of its sentence to count.
            Task::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => $deal->getKey(),
                'stage_id' => $stage->getKey(),
            ]);
        }

        for ($i = 0; $i < $size; $i++) {
            // One gate that reads only its own row, and one that walks up to
            // the deal. The second is the interesting one.
            Gate::factory()->create([
                'team_id' => $team->getKey(),
                'stage_id' => $active->getKey(),
                'label' => "Photos are back {$i}",
                'sort_order' => $i * 2,
            ]);

            Gate::factory()
                ->ofType('field_populated', ['field' => 'transaction_value'])
                ->create([
                    'team_id' => $team->getKey(),
                    'stage_id' => $active->getKey(),
                    'label' => "Price is agreed {$i}",
                    'sort_order' => $i * 2 + 1,
                ]);
        }

        $workflow->forceFill(['current_stage_id' => $active->getKey()])->save();

        return "/deals/{$deal->getKey()}/workflows/{$workflow->getKey()}/advance";
    });

    return [$team, $member, $url];
}

function countAdvancePreviewQueries(Closure $callback): int
{
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $callback();

    return $queries;
}

it('does not grow its query count with the gates, stages or tasks on a stage', function (): void {
    [$smallTeam, $smallMember, $smallUrl] = advancePreviewFixture(1);
    [$largeTeam, $largeMember, $largeUrl] = advancePreviewFixture(10);

    $small = countAdvancePreviewQueries(function () use ($smallMember, $smallTeam, $smallUrl): void {
        $this->actingAsPerson($smallMember, $smallTeam);
        $this->getJson($smallUrl)->assertOk();
    });

    $large = countAdvancePreviewQueries(function () use ($largeMember, $largeTeam, $largeUrl): void {
        $this->actingAsPerson($largeMember, $largeTeam);
        $this->getJson($largeUrl)->assertOk();
    });

    expect($large)->toBe($small);
});

/**
 * The guard on the guard.
 *
 * The assertion above passes just as happily if the large fixture never got
 * built — twenty gates and eleven stages that do not exist cost nothing to
 * render. So the payload is checked for the rows it is supposed to be
 * measuring.
 */
it('really did render the larger workflow', function (): void {
    [$team, $member, $url] = advancePreviewFixture(10);

    $this->actingAsPerson($member, $team);

    $payload = $this->getJson($url)->assertOk()->json();

    expect($payload['gates'])->toHaveCount(20)
        ->and($payload['stage']['total'])->toBe(11);
});
