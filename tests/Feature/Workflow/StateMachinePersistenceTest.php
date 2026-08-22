<?php

declare(strict_types=1);

use App\Enums\StageState;
use App\Models\Deal;
use App\Models\Stage;
use App\Models\Workflow;
use App\Support\States\IllegalStateTransition;

/**
 * The transition map holds however the attribute was written (#65).
 *
 * `tests/Unit/Workflow/StateMachineTest.php` covers the map itself and needs
 * no database. This one does — it is about what happens on `save()` — so it
 * lives in the Feature suite, which is the suite `tests/Pest.php` gives
 * `RefreshDatabase` to. A source-reading guard put in `tests/Unit/` earlier in
 * this slice passed locally and found no schema in CI, for exactly that
 * reason.
 *
 * ## What this closes
 *
 * `transitionTo()` is a door, and adversarial review walked past it three
 * ways: `setAttribute('state', …)`, `$stage->{$column} = …`, and a
 * `forceFill`. Each landed an illegal state with nothing checked — a `pending`
 * stage straight to `complete`, no gates evaluated, no audit entry. The
 * trait's own docblock argues that a rule enforced at call sites is enforced
 * at *some* call sites; the `saving` hook is that argument applied to the
 * trait itself.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    $workflow = Workflow::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
    ]);

    $this->stage = Stage::factory()->create([
        'team_id' => $this->team->getKey(),
        'workflow_id' => $workflow->getKey(),
        'state' => StageState::Pending,
    ]);
});

it('refuses an illegal state written straight onto the attribute', function (callable $write): void {
    $write($this->stage);

    expect(fn () => $this->stage->save())->toThrow(IllegalStateTransition::class);

    // And nothing landed.
    expect($this->stage->fresh()->state)->toBe(StageState::Pending);
})->with([
    'assignment' => [fn (Stage $stage) => $stage->state = StageState::Complete],
    'setAttribute' => [fn (Stage $stage) => $stage->setAttribute('state', 'complete')],
    'variable column' => [function (Stage $stage): void {
        $column = 'state';
        $stage->{$column} = StageState::Complete;
    }],
    'forceFill' => [fn (Stage $stage) => $stage->forceFill(['state' => 'complete'])],
]);

it('allows a legal move written the same way', function (): void {
    // The hook checks the map, not the spelling. `pending → active` is legal
    // however it was written, and a guard that refused every direct write
    // would be a guard nobody could work with.
    $this->stage->forceFill(['state' => StageState::Active->value])->save();

    expect($this->stage->fresh()->state)->toBe(StageState::Active);
});

it('lets a record be created in any state, because there is nothing to move from', function (): void {
    // How a workflow gets its opening state (`InstantiateWorkflow`), and how
    // every factory in the suite works.
    $created = Stage::factory()->create([
        'team_id' => $this->team->getKey(),
        'workflow_id' => $this->stage->workflow_id,
        'state' => StageState::Complete,
    ]);

    expect($created->fresh()->state)->toBe(StageState::Complete);
});

it('says nothing about a save that does not touch the state', function (): void {
    $this->stage->forceFill(['name' => 'Renamed'])->save();

    expect($this->stage->fresh()->name)->toBe('Renamed')
        ->and($this->stage->fresh()->state)->toBe(StageState::Pending);
});
