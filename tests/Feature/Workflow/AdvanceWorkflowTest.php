<?php

declare(strict_types=1);

use App\Enums\StageState;
use App\Enums\WorkflowState;
use App\Models\ActivityEvent;
use App\Models\AuditEntry;
use App\Models\Deal;
use App\Models\Gate;
use App\Models\Stage;
use App\Models\Task;
use App\Models\Workflow;
use App\Support\Workflow\AdvanceWorkflow;
use App\Support\Workflow\Gates\UnknownGateType;
use App\Support\Workflow\NothingToAdvance;
use Illuminate\Support\Facades\DB;

/**
 * The single mutation path (PRD §8.3 · issue #68).
 *
 * The Build Plan calls `AdvanceWorkflow` the architectural keystone. These
 * tests are about the guarantees that make it one: it refuses when a gate
 * says so, it says *everything* that is wrong at once, and nothing gets past
 * it quietly.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

/**
 * A running workflow with two stages, the first active.
 *
 * Built directly rather than through `InstantiateWorkflow` so an advance test
 * that fails is telling you about advancing.
 *
 * @return array{0: Workflow, 1: Stage, 2: Stage}
 */
function runningWorkflow(Deal $deal, bool $milestone = false): array
{
    $workflow = Workflow::factory()->create([
        'team_id' => $deal->team_id,
        'deal_id' => $deal->getKey(),
        'state' => WorkflowState::Active,
    ]);

    $first = Stage::factory()->active()->create([
        'team_id' => $deal->team_id,
        'workflow_id' => $workflow->getKey(),
        'name' => 'Listing Preparation',
        'sort_order' => 0,
    ]);

    if ($milestone) {
        $first->forceFill([
            'is_milestone' => true,
            'milestone_label' => 'Your home is on the market',
        ])->save();
    }

    $second = Stage::factory()->create([
        'team_id' => $deal->team_id,
        'workflow_id' => $workflow->getKey(),
        'name' => 'Go Live',
        'sort_order' => 1,
    ]);

    $workflow->forceFill(['current_stage_id' => $first->getKey()])->save();

    return [$workflow->fresh(), $first, $second];
}

it('advances a stage when nothing is in the way', function (): void {
    [$workflow, $first, $second] = runningWorkflow($this->deal);

    $result = app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    expect($result->advanced)->toBeTrue()
        ->and($first->fresh()->state)->toBe(StageState::Complete)
        ->and($first->fresh()->actual_end)->not->toBeNull()
        ->and($first->fresh()->completed_by)->toBe($this->member->getKey())
        ->and($second->fresh()->state)->toBe(StageState::Active)
        ->and($workflow->fresh()->current_stage_id)->toBe($second->getKey());
});

it('refuses when a blocking gate is unmet, and marks the stage blocked', function (): void {
    [$workflow, $first] = runningWorkflow($this->deal);

    Gate::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'gate_type' => 'manual_confirmation',
        'label' => 'Photos are back',
    ]);

    $result = app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    expect($result->advanced)->toBeFalse()
        ->and($result->blockedBy)->toHaveCount(1)
        ->and($first->fresh()->state)->toBe(StageState::Blocked)
        ->and($workflow->fresh()->current_stage_id)->toBe($first->getKey());
});

/**
 * Issue #68: *"A blocked advance returns every unmet gate."*
 *
 * The reason is the user's afternoon. Told about one gate, somebody clears it,
 * clicks again, and is told about the next — three round trips to learn what
 * one screen could have said in one.
 */
it('returns every unmet blocking gate, not just the first', function (): void {
    [$workflow, $first] = runningWorkflow($this->deal);

    foreach (['Photos are back', 'Survey is in', 'Sellers have signed'] as $index => $label) {
        Gate::factory()->create([
            'team_id' => $this->team->getKey(),
            'stage_id' => $first->getKey(),
            'gate_type' => 'manual_confirmation',
            'label' => $label,
            'sort_order' => $index,
        ]);
    }

    $result = app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    expect($result->blockedBy)->toHaveCount(3)
        ->and($result->reasons())->toHaveCount(3);
});

it('lets an advisory gate be unmet without refusing', function (): void {
    [$workflow, $first, $second] = runningWorkflow($this->deal);

    Gate::factory()->advisory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'gate_type' => 'manual_confirmation',
        'label' => 'You probably want the survey',
    ]);

    $result = app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    // Shown and explained, never enforced.
    expect($result->advanced)->toBeTrue()
        ->and($result->advisories)->toHaveCount(1)
        ->and($second->fresh()->state)->toBe(StageState::Active);
});

it('treats an overridden gate as cleared without calling it met', function (): void {
    [$workflow, $first] = runningWorkflow($this->deal);

    $gate = Gate::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'gate_type' => 'manual_confirmation',
        'label' => 'Survey is in',
    ]);

    $gate->forceFill([
        'overridden' => true,
        'override_reason' => 'Sellers accepted the risk in writing.',
        'overridden_by' => $this->member->getKey(),
    ])->save();

    $result = app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    // IA §8: overridden is not a kind of met, and the record still says so.
    expect($result->advanced)->toBeTrue()
        ->and($gate->fresh()->is_met)->toBeFalse()
        ->and($gate->fresh()->state()->value)->toBe('overridden');
});

it('counts required tasks rather than trusting the cached flag', function (): void {
    [$workflow, $first] = runningWorkflow($this->deal);

    $gate = Gate::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'gate_type' => 'required_tasks_complete',
        'label' => 'Required work is done',
        // A stale cached true, which is exactly what must not be believed.
        'is_met' => true,
    ]);

    $task = Task::factory()->required()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'stage_id' => $first->getKey(),
    ]);

    $result = app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    expect($result->advanced)->toBeFalse()
        ->and($gate->fresh()->is_met)->toBeFalse()
        ->and($result->reasons()[0])->toContain('1 of 1 required tasks');

    $task->forceFill(['completed_at' => now()])->save();

    expect(app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member)->advanced)->toBeTrue();
});

/**
 * The bug this test was written to hold.
 *
 * A refused advance marks the stage blocked. `activeStage()` looked only for
 * `active`, so a workflow refused once had no stage anybody could find, every
 * later attempt threw, and clearing the gate could not unstick it. A deal
 * would have been permanently frozen by the mechanism meant to protect it.
 */
it('advances again once the gate that refused it is cleared', function (): void {
    [$workflow, $first, $second] = runningWorkflow($this->deal);

    $gate = Gate::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'gate_type' => 'manual_confirmation',
        'label' => 'Photos are back',
    ]);

    expect(app(AdvanceWorkflow::class)->handle($workflow, $this->member)->advanced)->toBeFalse()
        ->and($first->fresh()->state)->toBe(StageState::Blocked);

    $gate->forceFill(['is_met' => true, 'met_at' => now(), 'met_by' => $this->member->getKey()])->save();

    expect(app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member)->advanced)->toBeTrue()
        ->and($first->fresh()->state)->toBe(StageState::Complete)
        ->and($second->fresh()->state)->toBe(StageState::Active);
});

it('completes the workflow when the last stage is advanced', function (): void {
    [$workflow, $first, $second] = runningWorkflow($this->deal);

    app(AdvanceWorkflow::class)->handle($workflow, $this->member);
    $result = app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member);

    expect($result->workflowCompleted)->toBeTrue()
        ->and($workflow->fresh()->state)->toBe(WorkflowState::Completed)
        ->and($workflow->fresh()->actual_end)->not->toBeNull()
        ->and($workflow->fresh()->current_stage_id)->toBeNull()
        ->and($second->fresh()->state)->toBe(StageState::Complete)
        ->and($first->fresh()->state)->toBe(StageState::Complete);
});

it('records the milestone moment when the completed stage is one', function (): void {
    [$workflow] = runningWorkflow($this->deal, milestone: true);

    $result = app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    expect($result->milestoneAnnouncement)->toBe('Your home is on the market')
        ->and(ActivityEvent::query()->where('event_type', 'milestone.reached')->exists())->toBeTrue();
});

it('does not announce a stage that is not a milestone', function (): void {
    [$workflow] = runningWorkflow($this->deal);

    $result = app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    expect($result->milestoneAnnouncement)->toBeNull()
        ->and(ActivityEvent::query()->where('event_type', 'milestone.reached')->exists())->toBeFalse();
});

it('writes both a timeline entry and an audit entry', function (): void {
    [$workflow] = runningWorkflow($this->deal);

    app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    expect(ActivityEvent::query()->where('event_type', 'stage.advanced')->exists())->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'workflow.advanced')->exists())->toBeTrue();
});

/**
 * Issue #67: *"Failing open on a gate is the worst available bug in this
 * product."*
 */
it('throws rather than passing a gate whose type nobody recognises', function (): void {
    [$workflow, $first] = runningWorkflow($this->deal);

    Gate::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'gate_type' => 'invented_by_a_typo',
        'label' => 'Something nobody implemented',
    ]);

    expect(fn () => app(AdvanceWorkflow::class)->handle($workflow, $this->member))
        ->toThrow(UnknownGateType::class);

    // And the advance did not happen.
    expect($first->fresh()->state)->toBe(StageState::Active);
});

/**
 * A workflow that is not running is refused, not thrown at.
 *
 * The refusal is a sentence S23 renders beside an unmet gate, because a hold
 * is something somebody deliberately did rather than a bug: a listing paused
 * while the sellers travel is exactly what a hold is for, and the screen
 * saying so beats a 500.
 */
it('refuses to advance a workflow that is not running', function (WorkflowState $state, string $fragment): void {
    [$workflow] = runningWorkflow($this->deal);

    // Straight onto the row, because most of these are not states the machine
    // will move to from `active` — which is the point.
    DB::table('workflows')->where('id', $workflow->getKey())->update(['state' => $state->value]);

    $result = app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member);

    expect($result->advanced)->toBeFalse()
        ->and($result->refusal)->toContain($fragment)
        ->and($result->reasons())->toHaveCount(1);

    // Nothing moved, and nothing was recorded as having moved.
    expect(Stage::query()->where('workflow_id', $workflow->getKey())
        ->where('state', StageState::Complete)->count())->toBe(0)
        ->and(AuditEntry::query()->where('action', 'workflow.advanced')->count())->toBe(0);
})->with([
    'on hold' => [WorkflowState::OnHold, 'on hold'],
    'cancelled' => [WorkflowState::Cancelled, 'cancelled'],
    'completed' => [WorkflowState::Completed, 'already finished'],
    'not started' => [WorkflowState::NotStarted, 'has not started'],
]);

it('throws when a running workflow has no stage to advance', function (): void {
    // A different thing entirely, and it still throws: a running workflow with
    // nothing active means a screen offered a button it should not have.
    $workflow = Workflow::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'state' => WorkflowState::Active,
    ]);

    expect(fn () => app(AdvanceWorkflow::class)->handle($workflow, $this->member))
        ->toThrow(NothingToAdvance::class);
});

it('refuses when the stage somebody was looking at is no longer the active one', function (): void {
    // Two people on the same screen after a call. The row lock serialises the
    // clicks but does not merge them: the second transaction re-reads after
    // the first commits and finds the *next* stage active. Without the
    // expected-stage check it advances that one too, and the deal moves two
    // stages with one never worked.
    [$workflow, $first] = runningWorkflow($this->deal);

    $advance = app(AdvanceWorkflow::class);

    expect($advance->handle($workflow, $this->member, $first->getKey())->advanced)->toBeTrue();

    $result = $advance->handle($workflow->fresh(), $this->member, $first->getKey());

    expect($result->advanced)->toBeFalse()
        ->and($result->refusal)->toContain('Somebody else advanced this workflow');
});

it('skips over a skipped stage rather than activating it', function (): void {
    [$workflow, $first, $second] = runningWorkflow($this->deal);

    $third = Stage::factory()->create([
        'team_id' => $this->team->getKey(),
        'workflow_id' => $workflow->getKey(),
        'name' => 'Under Contract',
        'sort_order' => 2,
    ]);

    // Somebody decided the middle stage does not apply to this deal.
    $second->forceFill([
        'state' => StageState::Skipped->value,
        'skipped_reason' => 'Cash buyer, no financing stage needed.',
    ])->save();

    app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    expect($third->fresh()->state)->toBe(StageState::Active)
        ->and($second->fresh()->state)->toBe(StageState::Skipped)
        ->and($first->fresh()->state)->toBe(StageState::Complete);
});

it('runs with no HTTP request in scope', function (): void {
    // F12.5: the service takes a workflow and an actor and returns a result.
    // A native client or a webhook calls the same thing.
    [$workflow] = runningWorkflow($this->deal);

    $result = app(AdvanceWorkflow::class)->handle($workflow, null);

    expect($result->advanced)->toBeTrue()
        ->and($result->completedStage->completed_by)->toBeNull();
});
