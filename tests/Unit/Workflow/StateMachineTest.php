<?php

declare(strict_types=1);

use App\Enums\DealState;
use App\Enums\StageState;
use App\Enums\WorkflowState;
use App\Models\Deal;
use App\Models\Stage;
use App\Models\Workflow;
use App\Support\States\IllegalStateTransition;

/**
 * State transitions are enforced on the model (#59, #65).
 *
 * Both issues ask for the same thing in the same words: *"transitions are
 * enforced by the model, not by controllers; an illegal transition throws."*
 *
 * This is not an alternative to `AdvanceWorkflow`. This says which moves are
 * *possible*; #68 decides which are *permitted*, having evaluated the gates.
 */
it('refuses a deal transition that skips the closing', function (): void {
    $deal = new Deal(['state' => DealState::Active]);
    $deal->state = DealState::Active;

    expect(fn () => $deal->transitionTo(DealState::Nurture))
        ->toThrow(IllegalStateTransition::class);
});

it('walks a deal from active to nurture the long way', function (): void {
    // PRD §7.15: the rough data model "had no place for anything after
    // closing". Closing hands the deal to the nurture system rather than
    // ending it, which is what Slice 6 picks up.
    $deal = new Deal;
    $deal->state = DealState::Active;

    // The cast turns the written value back into the enum on read, which is
    // the behaviour every screen depends on.
    $deal->transitionTo(DealState::Closed);
    expect($deal->state)->toBe(DealState::Closed);

    $deal->transitionTo(DealState::Nurture);
    expect($deal->state)->toBe(DealState::Nurture);
});

it('lets a deal that fell through come back', function (): void {
    // A collapse at inspection often returns — the buyer finds another house
    // with the same agent.
    $deal = new Deal;
    $deal->state = DealState::FellThrough;

    expect($deal->canTransitionTo(DealState::Active))->toBeTrue();
});

it('keeps a cancelled deal cancelled', function (): void {
    $deal = new Deal;
    $deal->state = DealState::Cancelled;

    expect($deal->availableTransitions())->toBe([])
        ->and(fn () => $deal->transitionTo(DealState::Active))
        ->toThrow(IllegalStateTransition::class);
});

it('treats a transition to the state it is already in as a no-op', function (): void {
    $deal = new Deal;
    $deal->state = DealState::Active;

    expect(fn () => $deal->transitionTo(DealState::Active))->not->toThrow(IllegalStateTransition::class);
});

it('refuses to complete a stage that never started', function (): void {
    $stage = new Stage;
    $stage->state = StageState::Pending;

    expect(fn () => $stage->transitionTo(StageState::Complete))
        ->toThrow(IllegalStateTransition::class);
});

it('lets a blocked stage become active or complete', function (): void {
    // Blocked is a display state for a stage somebody is standing in and
    // cannot leave, not a stage of its own.
    $stage = new Stage;
    $stage->state = StageState::Blocked;

    expect($stage->canTransitionTo(StageState::Active))->toBeTrue()
        ->and($stage->canTransitionTo(StageState::Complete))->toBeTrue();
});

it('lets a completed stage reopen', function (): void {
    // Emily's case: an inspection stage closes, the report comes back with a
    // second issue, and the work reopens (#70).
    $stage = new Stage;
    $stage->state = StageState::Complete;

    expect($stage->canTransitionTo(StageState::Active))->toBeTrue();
});

it('lets a workflow come off hold but never un-complete', function (): void {
    $workflow = new Workflow;
    $workflow->state = WorkflowState::OnHold;

    expect($workflow->canTransitionTo(WorkflowState::Active))->toBeTrue();

    $workflow->state = WorkflowState::Completed;

    expect($workflow->availableTransitions())->toBe([]);
});

it('names both states in the exception and neither record', function (): void {
    // PRD §9 keeps PII out of logs, and an exception message is a log entry by
    // the time anybody reads it. A deal name is a client's street address.
    $deal = new Deal;
    $deal->state = DealState::Cancelled;
    $deal->name = '123 Main St · Bosart Purchase';

    try {
        $deal->transitionTo(DealState::Active);
        $this->fail('The transition should have thrown.');
    } catch (IllegalStateTransition $exception) {
        expect($exception->getMessage())
            ->toContain('cancelled')
            ->toContain('active')
            ->not->toContain('Main St')
            ->not->toContain('Bosart');
    }
});
