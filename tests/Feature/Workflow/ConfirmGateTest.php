<?php

declare(strict_types=1);

use App\Enums\StageState;
use App\Enums\WorkflowState;
use App\Models\ActivityEvent;
use App\Models\AuditEntry;
use App\Models\Deal;
use App\Models\Gate;
use App\Models\Stage;
use App\Models\Workflow;
use App\Support\Workflow\AdvanceWorkflow;
use App\Support\Workflow\GateNotOnWorkflow;

/**
 * Ticking a manual gate (PRD §4.4 F4.8 · S23).
 *
 * ## What this closes
 *
 * `ManualConfirmationEvaluator` answers by reading `gates.is_met`, and until
 * this landed the only writer of that column was the cache refresh inside
 * `AdvanceWorkflow` — which reads the evaluator. So a manual gate could never
 * clear on its own, and the only way past the most common gate type in the
 * product was an **override**: the act IA §7 reserves for *"the condition
 * should have been met and was not"*, with an audit entry and a follow-up task
 * each time.
 *
 * `CLAUDE.md` names this exact shape from S17 — *"a row nothing can reach is a
 * rule nobody is following"* — and it had a second tell: `GatePolicy::update`
 * already existed, carrying the docblock *"Ticking a manual gate is ordinary
 * deal work"*, for a permission no route ever asked for. Nothing failed,
 * because each half worked.
 *
 * The property worth the most tests is therefore not that ticking works. It is
 * that ticking and overriding stay **different acts on different columns**,
 * which is IA §8's *"overridden is not a kind of met"* held rather than
 * remembered.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

/**
 * A running workflow with one blocking manual gate on its active stage.
 *
 * @return array{0: Workflow, 1: Stage, 2: Gate}
 */
function confirmableWorkflow(Deal $deal): array
{
    $workflow = Workflow::factory()->create([
        'team_id' => $deal->team_id,
        'deal_id' => $deal->getKey(),
        'name' => 'Listing to Close',
        'state' => WorkflowState::Active,
    ]);

    $stage = Stage::factory()->active()->create([
        'team_id' => $deal->team_id,
        'workflow_id' => $workflow->getKey(),
        'name' => 'Pre-listing',
        'sort_order' => 0,
    ]);

    Stage::factory()->create([
        'team_id' => $deal->team_id,
        'workflow_id' => $workflow->getKey(),
        'name' => 'On Market',
        'sort_order' => 1,
    ]);

    $gate = Gate::factory()->ofType('manual_confirmation')->create([
        'team_id' => $deal->team_id,
        'stage_id' => $stage->getKey(),
        'label' => 'Seller signed the listing agreement',
    ]);

    $workflow->forceFill(['current_stage_id' => $stage->getKey()])->save();

    return [$workflow->fresh(), $stage, $gate];
}

it('clears the gate, so an advance can happen without an override', function (): void {
    /*
     * The whole point, stated end to end: before this route existed the only
     * way to reach the second stage was to override, and the assertion that
     * matters is the one about `overridden` staying false through an advance
     * that succeeded.
     */
    [$workflow, $stage, $gate] = confirmableWorkflow($this->deal);

    expect(app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member)->advanced)
        ->toBeFalse();

    $result = app(AdvanceWorkflow::class)->confirm($workflow->fresh(), $gate, $this->member);

    expect($result->changed)->toBeTrue()
        ->and($gate->refresh()->is_met)->toBeTrue()
        // IA §8: this is Met, and nothing about it is Overridden.
        ->and($gate->overridden)->toBeFalse()
        ->and($gate->override_reason)->toBeNull()
        ->and($gate->overridden_by)->toBeNull();

    // Confirming does not advance — clearing one of three blockers must not
    // move a deal past the other two, so Advance is a second, deliberate act.
    expect($stage->refresh()->state)->not->toBe(StageState::Complete);

    expect(app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member)->advanced)
        ->toBeTrue();

    expect($gate->refresh()->overridden)->toBeFalse();
});

it('records it on the timeline and not in the audit log', function (): void {
    /*
     * PRD §9 lists what `audit_log` covers, and **gate overrides** is on it
     * while the ordinary path is not. Confirming happens many times a deal;
     * writing each one into an append-only table with its own retention would
     * bury the overrides it exists to make findable.
     *
     * Asserted as a pair rather than as two tests, because the value is the
     * contrast — an implementation that audited everything would pass the
     * first half alone.
     */
    [$workflow, , $gate] = confirmableWorkflow($this->deal);

    app(AdvanceWorkflow::class)->confirm($workflow, $gate, $this->member);

    $event = ActivityEvent::query()->where('event_type', 'gate.confirmed')->sole();

    expect($event->summary)->toContain('Seller signed the listing agreement')
        ->and($event->actor_person_id)->toBe($this->member->getKey())
        // The subject is the workflow and the deal is where a team looks for
        // it — the split `RecordActivity` exists to keep straight.
        ->and($event->deal_id)->toBe($this->deal->getKey());

    expect(AuditEntry::query()->where('action', 'like', '%gate%')->count())->toBe(0);
});

it('refuses a gate type that is not ticked but derived', function (): void {
    /*
     * Every other evaluator derives its answer from something real, so a tick
     * would be a claim rather than a cache — and the next advance would
     * overwrite it from the evaluator anyway, which is a control that appears
     * to work and silently does not.
     */
    [$workflow, $stage] = confirmableWorkflow($this->deal);

    $derived = Gate::factory()->ofType('required_tasks_complete')->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $stage->getKey(),
        'label' => 'Everything on the checklist',
    ]);

    $result = app(AdvanceWorkflow::class)->confirm($workflow, $derived, $this->member);

    expect($result->changed)->toBeFalse()
        ->and($result->refusal)->toContain('not something to tick')
        ->and($derived->refresh()->is_met)->toBeFalse();
});

it('refuses a gate on a stage the workflow has moved past', function (): void {
    /*
     * A completed stage's gates are what happened, not a question still open.
     * Ticking one would rewrite the record to say a stage advanced over a gate
     * that was met, when `overridden` is there to say honestly that it did
     * not.
     */
    [$workflow, $stage, $gate] = confirmableWorkflow($this->deal);

    app(AdvanceWorkflow::class)->confirm($workflow->fresh(), $gate, $this->member);
    app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member);

    expect($stage->refresh()->state)->toBe(StageState::Complete);

    $result = app(AdvanceWorkflow::class)->unconfirm($workflow->fresh(), $gate, $this->member);

    expect($result->changed)->toBeFalse()
        ->and($result->refusal)->toContain('Pre-listing')
        ->and($gate->refresh()->is_met)->toBeTrue();
});

it('refuses to confirm the same gate twice', function (): void {
    [$workflow, , $gate] = confirmableWorkflow($this->deal);

    app(AdvanceWorkflow::class)->confirm($workflow->fresh(), $gate, $this->member);

    $again = app(AdvanceWorkflow::class)->confirm($workflow->fresh(), $gate, $this->member);

    expect($again->changed)->toBeFalse()
        ->and($again->refusal)->toContain('already confirmed');

    // One event, not two: recording the work twice reports it twice and
    // attributes it to whoever was second.
    expect(ActivityEvent::query()->where('event_type', 'gate.confirmed')->count())->toBe(1);
});

it('takes a confirmation back', function (): void {
    [$workflow, , $gate] = confirmableWorkflow($this->deal);

    app(AdvanceWorkflow::class)->confirm($workflow->fresh(), $gate, $this->member);

    $result = app(AdvanceWorkflow::class)->unconfirm($workflow->fresh(), $gate, $this->member);

    expect($result->changed)->toBeTrue()
        ->and($gate->refresh()->is_met)->toBeFalse();

    expect(ActivityEvent::query()->where('event_type', 'gate.unconfirmed')->exists())->toBeTrue();
});

it('records who ticked it, and clears that with the tick', function (): void {
    /*
     * `met_by` and `met_at` had **no writer anywhere in the application**:
     * the cache refresh sets `is_met` from an evaluator, which is not a
     * person, so `Gate::metBy()` resolved to null forever and two columns sat
     * dead beside the one this service had learned to move.
     * `evaluateGates()`'s own note reserves them for *"a human ticking
     * something"*, and this route is the first thing that is.
     */
    [$workflow, , $gate] = confirmableWorkflow($this->deal);

    app(AdvanceWorkflow::class)->confirm($workflow->fresh(), $gate, $this->member);

    expect($gate->refresh()->met_by)->toBe($this->member->getKey())
        ->and($gate->met_at)->not->toBeNull();

    app(AdvanceWorkflow::class)->unconfirm($workflow->fresh(), $gate, $this->member);

    // Cleared with the flag: an unticked gate still naming who ticked it is a
    // record disagreeing with itself.
    expect($gate->refresh()->met_by)->toBeNull()
        ->and($gate->met_at)->toBeNull();
});

it('refuses a gate belonging to another workflow', function (): void {
    [$workflow] = confirmableWorkflow($this->deal);
    [, , $otherGate] = confirmableWorkflow($this->deal);

    expect(fn () => app(AdvanceWorkflow::class)->confirm($workflow->fresh(), $otherGate, $this->member))
        ->toThrow(GateNotOnWorkflow::class);
});

it('ticks one over HTTP, and takes it back', function (): void {
    [$workflow, , $gate] = confirmableWorkflow($this->deal);

    $base = "/deals/{$this->deal->getKey()}/workflows/{$workflow->getKey()}/confirmation";

    $this->post($base, ['gate_id' => $gate->getKey()])->assertRedirect();

    expect($gate->refresh()->is_met)->toBeTrue();

    $this->delete($base, ['gate_id' => $gate->getKey()])->assertRedirect();

    expect($gate->refresh()->is_met)->toBeFalse();
});

it('refuses a gate id from another team’s workflow', function (): void {
    /*
     * The global scope answers "whose team" and the policy answers "may this
     * person" — and neither answers "whose workflow". A forged id is a
     * readable 422 rather than a 500 or, worse, a tick on somebody else's
     * deal.
     */
    [$workflow] = confirmableWorkflow($this->deal);

    [$otherTeam, $otherMember] = $this->teamWithMember();

    $otherGate = app(App\Support\Tenancy\TeamContext::class)->runFor($otherTeam, function () use ($otherTeam): Gate {
        $deal = Deal::factory()->create(['team_id' => $otherTeam->getKey()]);
        [, , $gate] = confirmableWorkflow($deal);

        return $gate;
    });

    $this->post(
        "/deals/{$this->deal->getKey()}/workflows/{$workflow->getKey()}/confirmation",
        ['gate_id' => $otherGate->getKey()],
    )->assertSessionHasErrors('gate_id');

    expect(Gate::withoutGlobalScopes()->find($otherGate->getKey())?->is_met)->toBeFalse();
});
