<?php

declare(strict_types=1);

use App\Enums\StageState;
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
 * S16 — the deal timeline (PRD §4.4 F4.6–F4.8 · Design System §7.4 · #76).
 *
 * #76 names six key states and the tests below are organised around them:
 * 5 stages, 20 stages, blocked, overridden, skipped, and **multiple concurrent
 * workflows** — the last being, in its own words, the one that *"breaks naive
 * designs"*.
 *
 * Two properties are worth more than the rest and have cases of their own:
 *
 * - **Looking at a timeline changes nothing.** The screen evaluates every
 *   running workflow on the deal, which is more evaluation than any other
 *   screen does, so if the read path wrote anything this is where it would
 *   show worst.
 * - **The active stage is badged live, and every other stage from the record.**
 *   `stages.state` is a cache only an advance attempt refreshes, so a stage
 *   cached `blocked` whose gate has since been satisfied would badge Blocked
 *   over a requirements pane showing nothing in the way.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);
});

/**
 * A deal carrying one running workflow with `$count` stages, the first active.
 *
 * Built directly rather than through `InstantiateWorkflow`, for the reason
 * `DealOverviewTest` gives: a failure here should be telling you about the
 * timeline.
 *
 * @return array{0: Deal, 1: Workflow, 2: Illuminate\Support\Collection<int, Stage>}
 */
function timelineDeal(int $count = 5, array $workflowAttributes = []): array
{
    $team = test()->team;

    $type = DealType::factory()->create(['team_id' => $team->getKey()]);

    $deal = Deal::factory()->create([
        'team_id' => $team->getKey(),
        'deal_type_id' => $type->getKey(),
    ]);

    $workflow = Workflow::factory()->create([
        'team_id' => $team->getKey(),
        'deal_id' => $deal->getKey(),
        'name' => 'Listing to Close',
        'state' => WorkflowState::Active,
        ...$workflowAttributes,
    ]);

    $stages = collect(range(0, $count - 1))->map(function (int $index) use ($team, $workflow): Stage {
        $factory = Stage::factory();

        return ($index === 0 ? $factory->active() : $factory)->create([
            'team_id' => $team->getKey(),
            'workflow_id' => $workflow->getKey(),
            'name' => "Stage {$index}",
            'sort_order' => $index,
        ]);
    });

    $workflow->forceFill(['current_stage_id' => $stages->first()->getKey()])->save();

    return [$deal, $workflow->fresh(), $stages];
}

/** An unmet, blocking, manual gate on a stage. */
function timelineGate(Stage $stage, string $label, array $attributes = []): Gate
{
    return Gate::factory()->create([
        'team_id' => $stage->team_id,
        'stage_id' => $stage->getKey(),
        'gate_type' => 'manual_confirmation',
        'label' => $label,
        ...$attributes,
    ]);
}

/* -------------------------------------------------------------------------
 * The six key states (#76)
 * ---------------------------------------------------------------------- */

it('renders a five-stage workflow as one rail', function (): void {
    [$deal, $workflow, $stages] = timelineDeal(5);

    $this->get("/deals/{$deal->getKey()}/timeline")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Deals/Timeline')
            ->has('workflows', 1)
            ->where('workflows.0.id', $workflow->getKey())
            ->where('workflows.0.name', 'Listing to Close')
            ->where('workflows.0.isRunning', true)
            ->where('workflows.0.activeStageId', $stages->first()->getKey())
            ->has('workflows.0.stages', 5)
            // 1-based, so the rail can say "stage 3 of 5" without arithmetic.
            ->where('workflows.0.stages.0.position', 1)
            ->where('workflows.0.stages.4.position', 5)
            ->where('workflows.0.stages.0.isActive', true)
            ->where('workflows.0.stages.1.isActive', false));
});

it('renders a twenty-stage workflow without dropping any of it', function (): void {
    /*
     * #76: *"a 20-stage workflow does not require the user to lose their
     * place"*. The scrolling half of that answer is the rail's; the half a
     * feature test can hold is that all twenty arrive, in order, with exactly
     * one of them marked active for the rail to scroll to.
     */
    [$deal] = timelineDeal(20);

    $this->get("/deals/{$deal->getKey()}/timeline")
        ->assertOk()
        ->assertInertia(function ($page): void {
            $page->has('workflows.0.stages', 20);

            $stages = $page->toArray()['props']['workflows'][0]['stages'];

            expect(array_column($stages, 'position'))->toBe(range(1, 20));
            expect(array_column($stages, 'name'))->toBe(
                array_map(fn (int $i): string => "Stage {$i}", range(0, 19)),
            );
            expect(array_filter(array_column($stages, 'isActive')))->toHaveCount(1);
        });
});

it('badges a stage blocked when something is in its way', function (): void {
    [$deal, $workflow, $stages] = timelineDeal(3);

    timelineGate($stages->first(), 'Seller has signed the listing agreement');

    $this->get("/deals/{$deal->getKey()}/timeline")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workflows.0.stages.0.state', StageState::Blocked->value)
            ->where('workflows.0.canAdvance', false)
            ->where('workflows.0.stages.0.gateCounts.blocking', 1)
            ->where('workflows.0.stages.0.gateCounts.cleared', 0)
            // §7.4's sub-line: the evaluator's own sentence, not a generic one.
            ->where('workflows.0.stages.0.gates.0.label', 'Seller has signed the listing agreement')
            ->where('workflows.0.stages.0.gates.0.blocksAdvance', true));
});

it('marks a stage that was advanced over an override, without calling it a state', function (): void {
    /*
     * IA §8 has five stage states and `overridden` is not one of them, so an
     * overridden stage is `complete` **and** carries `hasOverride`. §7.4 gives
     * it a different marker over the same badge, which is the whole point: what
     * happened is that the stage completed; *how* is the second fact.
     */
    [$deal, $workflow, $stages] = timelineDeal(3);

    $first = $stages->first();

    $gate = timelineGate($first, 'Inspection waived');

    app(TeamContext::class)->runFor($this->team, function () use ($first, $gate): void {
        DB::table('gates')->where('id', $gate->getKey())->update([
            'overridden' => true,
            'override_reason' => 'Buyer waived in writing, scanned copy on file.',
        ]);

        DB::table('stages')->where('id', $first->getKey())->update([
            'state' => StageState::Complete->value,
        ]);
    });

    $this->get("/deals/{$deal->getKey()}/timeline")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workflows.0.stages.0.state', StageState::Complete->value)
            ->where('workflows.0.stages.0.hasOverride', true)
            // Cleared counts it; met does not. §7.4: *"cleared", not "met"*.
            ->where('workflows.0.stages.0.gateCounts.cleared', 1)
            ->where('workflows.0.stages.0.gateCounts.overridden', 1)
            ->where('workflows.0.stages.0.gates.0.gateState', 'overridden'));
});

it('carries a skipped stage and whether anybody said why', function (): void {
    [$deal, $workflow, $stages] = timelineDeal(3);

    $skipped = $stages->get(1);

    app(TeamContext::class)->runFor($this->team, function () use ($skipped): void {
        DB::table('stages')->where('id', $skipped->getKey())->update([
            'state' => StageState::Skipped->value,
        ]);
    });

    $this->get("/deals/{$deal->getKey()}/timeline")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workflows.0.stages.1.state', StageState::Skipped->value)
            /*
             * Null, and carried anyway. F4.12's skip is #70 and nothing writes
             * `skipped_reason` yet — but IA §7 calls conflating Skip with
             * Override legally material, and the difference a reader can see is
             * that one of them always says why. A field that only appears once
             * somebody fills it is a field the screen forgets to draw.
             */
            ->where('workflows.0.stages.1.skippedReason', null));
});

it('draws two concurrent workflows as two rails', function (): void {
    /*
     * F4.7, and #76's *"the one that breaks naive designs"*. Two workflows have
     * independent stage sequences with no shared order, so the payload keeps
     * them apart — a merged rail would have to invent an ordering, and any
     * invention is wrong for somebody.
     */
    [$deal, $first] = timelineDeal(3);

    $second = Workflow::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
        'name' => 'Pre-listing Improvements',
        'state' => WorkflowState::Active,
    ]);

    $stage = Stage::factory()->active()->create([
        'team_id' => $this->team->getKey(),
        'workflow_id' => $second->getKey(),
        'name' => 'Painting',
        'sort_order' => 0,
    ]);

    $second->forceFill(['current_stage_id' => $stage->getKey()])->save();

    $this->get("/deals/{$deal->getKey()}/timeline")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('workflows', 2)
            ->where('workflows.0.name', 'Listing to Close')
            ->where('workflows.1.name', 'Pre-listing Improvements')
            // Each names its own active stage; neither borrows the other's.
            ->where('workflows.1.activeStageId', $stage->getKey())
            ->has('workflows.0.stages', 3)
            ->has('workflows.1.stages', 1)
            /*
             * And the header still refuses to name one advance target, which is
             * the same fact from the other end: with two running, "Advance" has
             * no single meaning. The rail's own buttons are per workflow.
             */
            ->where('dealHeader.advance', null));
});

/* -------------------------------------------------------------------------
 * Live versus recorded
 * ---------------------------------------------------------------------- */

it('badges the active stage from the live verdict, not the cached column', function (): void {
    /*
     * The cache says blocked; the gate has since been met. An advance attempt
     * is what refreshes `stages.state`, and nobody has made one — so a screen
     * reading the column would badge Blocked beside a requirements pane
     * showing nothing in the way, which is one card disagreeing with itself.
     */
    [$deal, $workflow, $stages] = timelineDeal(3);

    $first = $stages->first();

    timelineGate($first, 'Photos uploaded', ['is_met' => true, 'met_at' => now()]);

    app(TeamContext::class)->runFor($this->team, function () use ($first): void {
        DB::table('stages')->where('id', $first->getKey())->update([
            'state' => StageState::Blocked->value,
        ]);
    });

    $this->get("/deals/{$deal->getKey()}/timeline")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workflows.0.stages.0.state', StageState::Active->value)
            ->where('workflows.0.canAdvance', true));

    // And the column it disagreed with is still exactly as it was.
    expect($first->fresh()->state)->toBe(StageState::Blocked);
});

it('changes nothing about the deal by being looked at', function (): void {
    /*
     * The fixture matters as much as the assertion: an advance attempt really
     * would mark this stage blocked and cache `is_met`, so a read path that
     * quietly attempted one would move both columns. Against a clean fixture
     * the assertion passes for the wrong reason.
     */
    [$deal, $workflow, $stages] = timelineDeal(3);

    $first = $stages->first();
    $gate = timelineGate($first, 'Seller has signed');

    $before = [
        'stage' => $first->fresh()->state,
        'gateMet' => $gate->fresh()->is_met,
        'workflowStage' => $workflow->fresh()->current_stage_id,
        'stageUpdated' => $first->fresh()->updated_at?->toIso8601String(),
    ];

    $this->get("/deals/{$deal->getKey()}/timeline")->assertOk();

    expect($first->fresh()->state)->toBe($before['stage'])
        ->and($gate->fresh()->is_met)->toBe($before['gateMet'])
        ->and($workflow->fresh()->current_stage_id)->toBe($before['workflowStage'])
        ->and($first->fresh()->updated_at?->toIso8601String())->toBe($before['stageUpdated']);
});

it('reports a finished stage’s gates as recorded rather than re-evaluating them', function (): void {
    /*
     * A complete stage's gates are a record of what happened, not a question
     * still open. So they arrive with their stored state and **no** blocking
     * claim: nothing on a stage nobody is advancing is in the way right now,
     * and an amber "still to clear" on a stage finished in June is the screen
     * inventing an obligation.
     */
    [$deal, $workflow, $stages] = timelineDeal(3);

    $done = $stages->get(1);

    timelineGate($done, 'Survey returned', ['is_met' => true, 'met_at' => now()]);

    app(TeamContext::class)->runFor($this->team, function () use ($done): void {
        DB::table('stages')->where('id', $done->getKey())->update([
            'state' => StageState::Complete->value,
        ]);
    });

    $this->get("/deals/{$deal->getKey()}/timeline")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workflows.0.stages.1.gates.0.gateState', 'met')
            ->where('workflows.0.stages.1.gates.0.blocksAdvance', false)
            ->where('workflows.0.stages.1.gateCounts.blocking', 0)
            ->where('workflows.0.stages.1.gateCounts.cleared', 1));
});

/* -------------------------------------------------------------------------
 * The rest of the payload
 * ---------------------------------------------------------------------- */

it('carries the milestone flag and its label', function (): void {
    [$deal, $workflow, $stages] = timelineDeal(3);

    $stages->get(1)->forceFill([
        'is_milestone' => true,
        'milestone_label' => 'Under contract',
    ])->save();

    $this->get("/deals/{$deal->getKey()}/timeline")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workflows.0.stages.1.isMilestone', true)
            ->where('workflows.0.stages.1.milestoneLabel', 'Under contract')
            ->where('workflows.0.stages.0.isMilestone', false));
});

it('counts a stage’s tasks and lists them', function (): void {
    [$deal, $workflow, $stages] = timelineDeal(3);

    $first = $stages->first();

    app(TeamContext::class)->runFor($this->team, function () use ($deal, $first): void {
        Task::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $deal->getKey(),
            'stage_id' => $first->getKey(),
            'title' => 'Order the sign',
            'sort_order' => 0,
        ]);

        Task::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $deal->getKey(),
            'stage_id' => $first->getKey(),
            'title' => 'Book the photographer',
            'completed_at' => now(),
            'sort_order' => 1,
        ]);
    });

    $this->get("/deals/{$deal->getKey()}/timeline")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workflows.0.stages.0.tasks.total', 2)
            ->where('workflows.0.stages.0.tasks.complete', 1)
            ->has('workflows.0.stages.0.tasks.items', 2)
            ->where('workflows.0.stages.0.tasks.items.0.title', 'Order the sign'));
});

it('says why there is no advance when the workflow is not running', function (): void {
    /*
     * A card that simply omits the button leaves the reader to guess between
     * "on hold", "finished" and "not started". `WorkflowState::advanceRefusal()`
     * is a sentence per state and the rail says it once.
     */
    [$deal] = timelineDeal(3, ['state' => WorkflowState::OnHold]);

    $this->get("/deals/{$deal->getKey()}/timeline")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workflows.0.isRunning', false)
            ->where('workflows.0.canAdvance', false)
            ->where('workflows.0.refusal', WorkflowState::OnHold->advanceRefusal()));
});

it('renders a deal with no workflow at all', function (): void {
    $type = DealType::factory()->create(['team_id' => $this->team->getKey()]);

    $deal = Deal::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_type_id' => $type->getKey(),
    ]);

    $this->get("/deals/{$deal->getKey()}/timeline")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Deals/Timeline')
            ->has('workflows', 0));
});

it('gives the timeline the same header every other deal tab wears', function (): void {
    // #75 folded eight tabs onto one `DealHeader` payload so they cannot
    // disagree about the client's name or the counts. This is the ninth caller.
    [$deal] = timelineDeal(3);

    $this->get("/deals/{$deal->getKey()}/timeline")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('dealHeader')
            ->where('dealHeader.id', $deal->getKey())
            ->has('dealHeader.counts.people')
            ->has('dealHeader.counts.properties'));
});

it('refuses a deal belonging to another team', function (): void {
    [$otherTeam] = $this->teamWithMember();

    $type = DealType::factory()->create(['team_id' => $otherTeam->getKey()]);

    $deal = app(TeamContext::class)->runFor($otherTeam, fn (): Deal => Deal::factory()->create([
        'team_id' => $otherTeam->getKey(),
        'deal_type_id' => $type->getKey(),
    ]));

    // 404, not 403 — ADR 0002: a tenant must not learn that a record exists.
    $this->get("/deals/{$deal->getKey()}/timeline")->assertNotFound();
});
