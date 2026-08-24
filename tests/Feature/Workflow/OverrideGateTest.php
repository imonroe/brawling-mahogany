<?php

declare(strict_types=1);

use App\Enums\StageState;
use App\Enums\TaskSource;
use App\Enums\WorkflowState;
use App\Models\ActivityEvent;
use App\Models\AuditEntry;
use App\Models\Deal;
use App\Models\Gate;
use App\Models\Person;
use App\Models\Stage;
use App\Models\Task;
use App\Models\Workflow;
use App\Support\Workflow\AdvanceWorkflow;
use App\Support\Workflow\GateNotOnWorkflow;
use App\Support\Workflow\OverrideNeedsAReason;

/**
 * Overriding a gate (PRD §4.4 F4.9, §5.5 · IA §7 · issues #69, #77).
 *
 * F4.9 is **four artefacts**, and #69 says which of them is the one that gets
 * dropped: *"A follow-up task is created so the bypassed gate does not
 * vanish… an override defers an obligation; it does not delete one."* So every
 * one of the four is asserted, and each is asserted against something a broken
 * implementation would not produce — a count of zero is also what you get when
 * nothing was written, which is why nothing here checks for one.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

/**
 * A running workflow, its first stage active, and one blocking gate on it.
 *
 * `document_present` on purpose: it is one of the three types that cannot
 * clear on its own in Slice 2, so it is the gate the feature actually exists
 * for. Its own message tells the reader to override it.
 *
 * @return array{0: Workflow, 1: Stage, 2: Stage, 3: Gate}
 */
function overridableWorkflow(Deal $deal): array
{
    $workflow = Workflow::factory()->create([
        'team_id' => $deal->team_id,
        'deal_id' => $deal->getKey(),
        'name' => 'Under Contract',
        'state' => WorkflowState::Active,
    ]);

    $first = Stage::factory()->active()->create([
        'team_id' => $deal->team_id,
        'workflow_id' => $workflow->getKey(),
        'name' => 'Appraisal',
        'sort_order' => 0,
    ]);

    $second = Stage::factory()->create([
        'team_id' => $deal->team_id,
        'workflow_id' => $workflow->getKey(),
        'name' => 'Closing',
        'sort_order' => 1,
    ]);

    $gate = Gate::factory()->ofType('document_present', ['category' => 'appraisal'])->create([
        'team_id' => $deal->team_id,
        'stage_id' => $first->getKey(),
        'label' => 'Appraisal is back',
    ]);

    $workflow->forceFill(['current_stage_id' => $first->getKey()])->save();

    return [$workflow->fresh(), $first, $second, $gate];
}

const OVERRIDE_REASON = 'Appraisal received by email, uploading tomorrow.';

it('writes all four of F4.9’s artefacts', function (): void {
    [$workflow, $stage, , $gate] = overridableWorkflow($this->deal);

    $result = app(AdvanceWorkflow::class)
        ->override($workflow, $gate, $this->member, OVERRIDE_REASON);

    expect($result->overridden)->toBeTrue();

    // 1. The flag, with who and why — and `is_met` untouched, because IA §8
    //    says overridden is not a kind of met.
    $gate->refresh();

    expect($gate->overridden)->toBeTrue()
        ->and($gate->override_reason)->toBe(OVERRIDE_REASON)
        ->and($gate->overridden_by)->toBe($this->member->getKey())
        ->and($gate->is_met)->toBeFalse()
        ->and($gate->state()->value)->toBe('overridden');

    // 2. The immutable audit entry.
    $entry = AuditEntry::query()->where('action', 'workflow.gate_overridden')->sole();

    expect($entry->actor_person_id)->toBe($this->member->getKey())
        ->and($entry->reason)->toBe(OVERRIDE_REASON)
        ->and($entry->auditable_id)->toBe($gate->getKey());

    // 3. The distinct timeline marker, its own event type rather than a
    //    reworded advance.
    $event = ActivityEvent::query()->where('event_type', 'gate.overridden')->sole();

    expect($event->deal_id)->toBe($this->deal->getKey())
        ->and($event->summary)->toContain('Appraisal is back')
        // The reason belongs to the audit log, which has the retention and the
        // readership for it. The timeline is read by anyone on the deal.
        ->and($event->summary)->not->toContain('uploading tomorrow');

    // 4. The follow-up, linked to the deal, the stage and the person.
    $task = Task::query()->sole();

    expect($result->followUp?->getKey())->toBe($task->getKey())
        ->and($task->deal_id)->toBe($this->deal->getKey())
        ->and($task->stage_id)->toBe($stage->getKey())
        ->and($task->assignee_id)->toBe($this->member->getKey())
        ->and($task->source)->toBe(TaskSource::Override)
        ->and($task->title)->toContain('Appraisal is back')
        /*
         * The same rule as the timeline marker above, and it needs its own
         * assertion because the task is read somewhere else: My Work (S11)
         * shows it to anybody the deal is shared with, while the audit entry
         * has the retention and the access control the reason needs. It was
         * interpolated here for a round, three lines under a comment saying
         * the reason lives in the audit log.
         */
        ->and($task->description)->not->toContain('uploading tomorrow')
        /*
         * And the description does not claim the stage moved. Overriding
         * never advances — that is the invariant the test below pins from the
         * other direction — so a follow-up saying the stage "was advanced" is
         * wrong on a deal that is still sitting exactly where it was.
         */
        ->and($task->description)->not->toContain('was advanced');
});

/**
 * #69: *"The audit entry records who, when, **which gate**, and why."*
 *
 * The subject alone is an id. Six weeks later somebody reading the log wants
 * to know it was the appraisal, and a join to a row a later slice may have
 * archived is not an answer.
 */
it('names the gate in the audit entry rather than only pointing at it', function (): void {
    [$workflow, $stage, , $gate] = overridableWorkflow($this->deal);

    app(AdvanceWorkflow::class)->override($workflow, $gate, $this->member, OVERRIDE_REASON);

    $after = AuditEntry::query()->where('action', 'workflow.gate_overridden')->sole()->after;

    expect($after['gate_label'])->toBe('Appraisal is back')
        ->and($after['gate_type'])->toBe('document_present')
        ->and($after['stage_name'])->toBe('Appraisal')
        ->and($after['stage_id'])->toBe($stage->getKey())
        ->and($after['workflow_id'])->toBe($workflow->getKey())
        ->and($after['follow_up_task_id'])->toBe(Task::query()->sole()->getKey());
});

/**
 * The trap the follow-up task walks straight into if it is marked required.
 *
 * `is_required` feeds `required_tasks_complete`, which counts the required
 * tasks on **this** stage — the one the person is about to leave. A required
 * follow-up would therefore be counted by a tasks gate on the same stage and
 * would block the very advance the override exists to permit, one click after
 * clearing the thing that was blocking it.
 */
it('creates a follow-up that does not block the advance it just unblocked', function (): void {
    [$workflow, $stage, $next, $gate] = overridableWorkflow($this->deal);

    // A tasks gate on the same stage, currently satisfied — every required
    // task on it is done.
    Gate::factory()->ofType('required_tasks_complete')->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $stage->getKey(),
        'label' => 'Required work is done',
        'sort_order' => 1,
    ]);

    Task::factory()->required()->completed()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'stage_id' => $stage->getKey(),
    ]);

    app(AdvanceWorkflow::class)->override($workflow, $gate, $this->member, OVERRIDE_REASON);

    $result = app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member);

    expect($result->advanced)->toBeTrue()
        ->and($stage->fresh()->state)->toBe(StageState::Complete)
        ->and($next->fresh()->state)->toBe(StageState::Active)
        // And the obligation survived the advance it permitted.
        ->and(Task::query()->where('source', TaskSource::Override)->open()->count())->toBe(1);
});

it('does not advance anything by itself', function (): void {
    [$workflow, $stage, $next, $gate] = overridableWorkflow($this->deal);

    // A second blocking gate, so advancing on the override would be visibly
    // wrong rather than merely early.
    Gate::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $stage->getKey(),
        'label' => 'Sellers have signed',
        'sort_order' => 1,
    ]);

    app(AdvanceWorkflow::class)->override($workflow, $gate, $this->member, OVERRIDE_REASON);

    expect($stage->fresh()->state)->toBe(StageState::Active)
        ->and($next->fresh()->state)->toBe(StageState::Pending)
        ->and($workflow->fresh()->current_stage_id)->toBe($stage->getKey())
        ->and(AuditEntry::query()->where('action', 'workflow.advanced')->count())->toBe(0);

    // And the advance that follows is still refused, by the gate nobody
    // overrode. Overriding one of two must not move a deal past the other.
    expect(app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member)->advanced)
        ->toBeFalse();
});

it('refuses a reason too short to mean anything, and writes nothing', function (): void {
    [$workflow, , , $gate] = overridableWorkflow($this->deal);

    expect(fn () => app(AdvanceWorkflow::class)->override($workflow, $gate, $this->member, 'ok'))
        ->toThrow(OverrideNeedsAReason::class);

    // Whitespace is not a reason either.
    expect(fn () => app(AdvanceWorkflow::class)->override(
        $workflow,
        $gate,
        $this->member,
        '           ',
    ))->toThrow(OverrideNeedsAReason::class);

    expect($gate->fresh()->overridden)->toBeFalse()
        ->and($gate->fresh()->override_reason)->toBeNull()
        ->and(Task::query()->count())->toBe(0);
});

it('trims the reason it stores', function (): void {
    [$workflow, , , $gate] = overridableWorkflow($this->deal);

    app(AdvanceWorkflow::class)
        ->override($workflow, $gate, $this->member, "  \n".OVERRIDE_REASON."  \n");

    expect($gate->fresh()->override_reason)->toBe(OVERRIDE_REASON);
});

it('throws when the gate belongs to another workflow on the same deal', function (): void {
    [$workflow] = overridableWorkflow($this->deal);
    // A second workflow on the *same deal in the same team*, so neither the
    // global scope nor the policy has anything to object to. Only the
    // relationship answers "whose workflow".
    [, , , $otherGate] = overridableWorkflow($this->deal);

    expect(fn () => app(AdvanceWorkflow::class)
        ->override($workflow, $otherGate, $this->member, OVERRIDE_REASON))
        ->toThrow(GateNotOnWorkflow::class);

    expect($otherGate->fresh()->overridden)->toBeFalse()
        ->and(Task::query()->count())->toBe(0);
});

it('refuses to override a gate on a stage nobody is standing in', function (): void {
    [$workflow, , $next, $gate] = overridableWorkflow($this->deal);

    // A gate on the stage *after* the current one. Overriding it would change
    // nothing about what happens next and would put a decision in the
    // permanent record that nobody had to make.
    $ahead = Gate::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $next->getKey(),
        'label' => 'Funds have cleared',
    ]);

    unset($gate);

    $result = app(AdvanceWorkflow::class)
        ->override($workflow, $ahead, $this->member, OVERRIDE_REASON);

    expect($result->overridden)->toBeFalse()
        ->and($result->refusal)->toContain('Closing is not the stage this workflow is in')
        ->and($ahead->fresh()->overridden)->toBeFalse();
});

it('refuses to override an advisory, which was never in the way', function (): void {
    [$workflow, $stage] = overridableWorkflow($this->deal);

    $advisory = Gate::factory()->advisory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $stage->getKey(),
        'label' => 'You probably want the survey',
        'sort_order' => 1,
    ]);

    $result = app(AdvanceWorkflow::class)
        ->override($workflow, $advisory, $this->member, OVERRIDE_REASON);

    expect($result->overridden)->toBeFalse()
        ->and($result->refusal)->toContain('advisory')
        ->and($advisory->fresh()->overridden)->toBeFalse()
        // PRD §12.2 measures the share of advances that used an override.
        // Overrides of things that never blocked anything are noise in that
        // number, so none is written.
        ->and(AuditEntry::query()->where('action', 'workflow.gate_overridden')->count())->toBe(0);
});

/**
 * The gate a colleague cleared while the modal was open.
 *
 * Evaluated rather than read off `gates.is_met`, which is a cache refreshed
 * only by an advance attempt. Believing the cache here would write into the
 * permanent record that the survey was missing when it was on the desk.
 */
it('refuses to override a gate that is met, even when the cache says otherwise', function (): void {
    [$workflow, $stage] = overridableWorkflow($this->deal);

    $tasks = Gate::factory()->ofType('required_tasks_complete')->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $stage->getKey(),
        'label' => 'Required work is done',
        // A stale cached false: the tasks are all done and nothing has
        // refreshed this.
        'is_met' => false,
        'sort_order' => 1,
    ]);

    Task::factory()->required()->completed()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'stage_id' => $stage->getKey(),
    ]);

    $result = app(AdvanceWorkflow::class)
        ->override($workflow, $tasks, $this->member, OVERRIDE_REASON);

    expect($result->overridden)->toBeFalse()
        ->and($result->refusal)->toContain('is met')
        ->and($tasks->fresh()->overridden)->toBeFalse();
});

it('refuses a second override of the same gate', function (): void {
    [$workflow, , , $gate] = overridableWorkflow($this->deal);

    app(AdvanceWorkflow::class)->override($workflow, $gate, $this->member, OVERRIDE_REASON);

    $second = app(AdvanceWorkflow::class)
        ->override($workflow->fresh(), $gate->fresh(), $this->member, 'A different reason entirely.');

    expect($second->overridden)->toBeFalse()
        ->and($second->refusal)->toContain('already been overridden')
        // The first reason survives, and there is still exactly one follow-up.
        ->and($gate->fresh()->override_reason)->toBe(OVERRIDE_REASON)
        ->and(Task::query()->where('source', TaskSource::Override)->count())->toBe(1);
});

it('refuses to override a gate on a workflow that is on hold', function (): void {
    [$workflow, , , $gate] = overridableWorkflow($this->deal);

    $workflow->transitionTo(WorkflowState::OnHold);
    $workflow->save();

    $result = app(AdvanceWorkflow::class)
        ->override($workflow->fresh(), $gate, $this->member, OVERRIDE_REASON);

    expect($result->overridden)->toBeFalse()
        ->and($result->refusal)->toContain('on hold')
        ->and($gate->fresh()->overridden)->toBeFalse()
        ->and(Task::query()->count())->toBe(0);
});

/**
 * The override is what lets the deal move, which is the whole reason it is in
 * this slice rather than the next one: three of the seven gate types cannot
 * clear on their own until Slice 3 or 4, so a workflow carrying one of them
 * has no other way forward.
 */
it('lets the advance through once the gate that refused it is overridden', function (): void {
    [$workflow, $stage, $next, $gate] = overridableWorkflow($this->deal);

    expect(app(AdvanceWorkflow::class)->handle($workflow, $this->member)->advanced)->toBeFalse()
        ->and($stage->fresh()->state)->toBe(StageState::Blocked);

    app(AdvanceWorkflow::class)->override($workflow->fresh(), $gate, $this->member, OVERRIDE_REASON);

    expect(app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member)->advanced)->toBeTrue()
        ->and($stage->fresh()->state)->toBe(StageState::Complete)
        ->and($next->fresh()->state)->toBe(StageState::Active)
        // And the record still says the gate was never met.
        ->and($gate->fresh()->is_met)->toBeFalse();
});

it('attributes the override to the person who made it and nobody else', function (): void {
    [$workflow, , , $gate] = overridableWorkflow($this->deal);

    // Signed in as one person, overriding as another — the audit entry has to
    // follow the argument, not the session, or a queued or console-issued
    // override would silently be attributed to whoever happened to be there.
    $other = Person::factory()->create();

    app(AdvanceWorkflow::class)->override($workflow, $gate, $other, OVERRIDE_REASON);

    expect($gate->fresh()->overridden_by)->toBe($other->getKey())
        ->and(AuditEntry::query()->where('action', 'workflow.gate_overridden')->sole()->actor_person_id)
        ->toBe($other->getKey())
        ->and(Task::query()->sole()->assignee_id)->toBe($other->getKey());
});
