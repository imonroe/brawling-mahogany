<?php

declare(strict_types=1);

use App\Enums\AutomationState;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Models\Gate;
use App\Models\KeyDate;
use App\Models\Stage;
use App\Models\Task;
use App\Models\Workflow;
use App\Support\Workflow\Gates\GateRegistry;
use App\Support\Workflow\Gates\UnknownGateType;
use Illuminate\Support\Carbon;

/**
 * The seven evaluators, each in isolation (issue #67).
 *
 * The rule the whole design rests on: *"adding a gate type means adding a
 * class, never touching advancement logic."* These tests exercise the classes
 * directly, without an advance, which is what proves they are separable.
 *
 * **Feature rather than Unit**, and it was in the wrong one first. Every gate
 * type except the deferred three derives its answer from rows — the tasks on a
 * stage, a field on the deal, the approver's membership — so these need a real
 * Postgres. `tests/Pest.php` gives `RefreshDatabase` to everything but `Unit`,
 * and docs/Testing.md says so plainly: *"everything but Unit talks to a real
 * Postgres."* In `Unit` they passed locally only because a Feature test had
 * already migrated the shared database, and failed in CI where the ordering
 * differed. Order-dependence is a bug in the test, not a flake in the runner.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $workflow = Workflow::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $this->stage = Stage::factory()->active()->create([
        'team_id' => $this->team->getKey(),
        'workflow_id' => $workflow->getKey(),
    ]);
});

function gateOfType(string $type, array $config = [], array $attributes = []): Gate
{
    return Gate::factory()->create(array_merge([
        'team_id' => test()->team->getKey(),
        'stage_id' => test()->stage->getKey(),
        'gate_type' => $type,
        'config' => $config,
    ], $attributes));
}

function verdictFor(Gate $gate)
{
    return app(GateRegistry::class)->evaluate($gate->fresh());
}

/**
 * The one issue #67 singles out.
 *
 * > A test asserts that an unknown gate type never evaluates as met. **Failing
 * > open on a gate is the worst available bug in this product.**
 */
it('never evaluates an unknown gate type as met', function (): void {
    $gate = gateOfType('a_type_that_does_not_exist');

    expect(fn () => verdictFor($gate))->toThrow(UnknownGateType::class);
});

it('throws rather than returning a default for an empty gate type', function (): void {
    $gate = gateOfType('');

    expect(fn () => verdictFor($gate))->toThrow(UnknownGateType::class);
});

it('registers exactly the seven documented types', function (): void {
    expect(GateRegistry::types())->toBe([
        'manual_confirmation',
        'required_tasks_complete',
        'field_populated',
        'approval',
        'document_present',
        'action_completed',
        'date_reached',
    ]);
});

it('clears a manual confirmation only when somebody ticked it', function (): void {
    $gate = gateOfType('manual_confirmation');

    expect(verdictFor($gate)->met)->toBeFalse();

    $gate->forceFill(['is_met' => true])->save();

    expect(verdictFor($gate)->met)->toBeTrue();
});

it('clears required tasks when a stage has none', function (): void {
    // Trivially satisfied, not a failure. Refusing here would make the gate
    // impossible to clear rather than already clear.
    $gate = gateOfType('required_tasks_complete');

    expect(verdictFor($gate)->met)->toBeTrue()
        ->and(verdictFor($gate)->explanation)->toContain('No tasks');
});

it('counts the outstanding required tasks in the sentence', function (): void {
    $gate = gateOfType('required_tasks_complete');

    foreach (range(1, 3) as $i) {
        Task::factory()->required()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $this->deal->getKey(),
            'stage_id' => $this->stage->getKey(),
        ]);
    }

    // Optional tasks do not count towards a required-tasks gate.
    Task::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'stage_id' => $this->stage->getKey(),
    ]);

    $verdict = verdictFor($gate);

    expect($verdict->met)->toBeFalse()
        ->and($verdict->explanation)->toBe('3 of 3 required tasks are still open.')
        ->and($verdict->linkTarget['type'])->toBe('tasks');
});

it('clears a field gate when the field is filled in', function (): void {
    $gate = gateOfType('field_populated', ['field' => 'transaction_value', 'label' => 'Transaction value']);

    expect(verdictFor($gate)->met)->toBeFalse();

    $this->deal->forceFill(['transaction_value' => 45_000_00])->save();

    expect(verdictFor($gate)->met)->toBeTrue();
});

it('treats a whitespace-only field as empty', function (): void {
    $gate = gateOfType('field_populated', ['field' => 'notes']);

    $this->deal->forceFill(['notes' => '   '])->save();

    expect(verdictFor($gate)->met)->toBeFalse();
});

/**
 * `config` is data a team edits on S43, so the field name is user input. A gate
 * that could name any attribute is a way to read whatever the model exposes.
 */
it('refuses a field gate naming something outside the allow-list', function (): void {
    $gate = gateOfType('field_populated', ['field' => 'team_id']);

    $verdict = verdictFor($gate);

    expect($verdict->met)->toBeFalse()
        ->and($verdict->explanation)->toContain('not a field a gate can ask about');
});

it('refuses a field gate that names no field at all', function (): void {
    $verdict = verdictFor(gateOfType('field_populated'));

    expect($verdict->met)->toBeFalse()
        ->and($verdict->explanation)->toContain('does not say which field');
});

it('clears an approval only while the approver still holds the role', function (): void {
    $gate = gateOfType('approval', ['role' => 'team_member']);

    expect(verdictFor($gate)->met)->toBeFalse();

    $gate->forceFill([
        'is_met' => true,
        'met_at' => now(),
        'met_by' => $this->member->getKey(),
    ])->save();

    expect(verdictFor($gate)->met)->toBeTrue();

    // The approver leaves the team. An approval from somebody who is gone is
    // not an approval, and a gate that cleared on it would report a fact that
    // stopped being true.
    $this->member->membershipIn($this->team)->revoke();

    expect(verdictFor($gate)->met)->toBeFalse()
        ->and(verdictFor($gate)->explanation)->toContain('no longer holds that role');
});

it('refuses an approval gate that names no role', function (): void {
    $verdict = verdictFor(gateOfType('approval'));

    expect($verdict->met)->toBeFalse()
        ->and($verdict->explanation)->toContain('does not say whose approval');
});

/**
 * Issue #67: *"the three deferred evaluators return an explanatory unmet,
 * never a silent false, and each names the issue that will wire it."*
 *
 * **All three are wired now.** `document_present` left with S21 (#98, #104),
 * `action_completed` with the send path (#92), and `date_reached` with the
 * contingency calendar (#109) — so the dataset is empty and the mechanism is
 * asserted directly below instead, against a verdict built by hand.
 *
 * The alternative was deleting this, and that is worse: the *rule* is still
 * live. A gate type added in Slice 5 or 6 will be deferred exactly the same
 * way, and this is where the shape it must take is written down.
 */
it('says which issue will wire a gate type that is still deferred', function (): void {
    $verdict = App\Support\Workflow\Gates\GateVerdict::notYetWired(
        'This stage is waiting for something Slice 6 will build.',
        '#131',
    );

    expect($verdict->met)->toBeFalse()
        ->and($verdict->explanation)->toContain('#131')
        ->and($verdict->explanation)->toContain('not wired up yet')
        // Unmet rather than met, because a gate that has not been built cannot
        // have been satisfied — the safe direction on a gate is always closed.
        ->and($verdict->explanation)->toContain('override it with a reason')
        ->and($verdict->linkTarget['type'])->toBe('awaiting_slice')
        ->and($verdict->linkTarget['issue'])->toBe('#131');
});

it('sends somebody to the upload that clears a document gate', function (): void {
    /*
     * CLAUDE.md names `DocumentPresentEvaluator` as one of two evaluators
     * owing the *"a row nothing can reach"* check: a gate type with exactly
     * one way to be satisfied, never verified as reachable from a screen.
     *
     * PRD §5.4 asks that *"each unmet gate links directly to the thing that
     * clears it"*, so the verdict carries where to go rather than only what is
     * wrong — which is the difference between a blocker somebody can act on
     * and one they have to go looking for.
     */
    $verdict = verdictFor(gateOfType('document_present', ['category' => 'inspection_report']));

    expect($verdict->met)->toBeFalse()
        ->and($verdict->explanation)->not->toContain('not wired up yet')
        ->and($verdict->linkTarget['type'])->toBe('document_upload')
        ->and($verdict->linkTarget['category'])->toBe('inspection_report');
});

/*
 * `action_completed` was the third of them and is wired as of #92, so it is
 * off the list above rather than merely still on it and passing for a reason
 * nobody checked. The cases below are what replaces it — a deferred evaluator
 * whose slice landed and whose dataset entry stayed would be a test asserting
 * the wrong sentence about working code.
 */
it('refuses an action gate that names no automation', function (): void {
    $verdict = verdictFor(gateOfType('action_completed'));

    expect($verdict->met)->toBeFalse()
        ->and($verdict->explanation)->toContain('does not say which automation')
        ->and($verdict->linkTarget['type'])->toBe('gate_config');
});

it('clears an action gate once its automation has been sent', function (): void {
    $gate = gateOfType('action_completed', [
        'actionDefinitionId' => '01ACTIONDEFINITION00000000',
        'label' => 'The welcome email',
    ]);

    // Raised but not yet gone: the state F5.7 exists to create, and a gate
    // that cleared on it would advance a deal past a message nobody has read.
    $waiting = ActionInstance::factory()->awaitingApproval()->create([
        'team_id' => $gate->team_id,
        'deal_id' => $gate->stage->workflow->deal_id,
        'stage_id' => $gate->stage_id,
        'action_definition_id' => '01ACTIONDEFINITION00000000',
    ]);

    expect(verdictFor($gate)->met)->toBeFalse()
        ->and(verdictFor($gate)->explanation)->toContain('waiting for somebody to review');

    $waiting->forceFill(['state' => AutomationState::Sent, 'executed_at' => now()])->save();

    expect(verdictFor($gate)->met)->toBeTrue()
        ->and(verdictFor($gate)->explanation)->toContain('The welcome email has run');
});

it('does not let an automation sent on another stage clear this one', function (): void {
    /*
     * F4.7 lets one deal run several workflows at once, so one automation
     * definition legitimately produces an instance per running stage. A gate
     * that asked only about the definition would clear on somebody else's.
     */
    $gate = gateOfType('action_completed', ['actionDefinitionId' => '01ACTIONDEFINITION00000000']);

    ActionInstance::factory()->sent()->create([
        'team_id' => $gate->team_id,
        'deal_id' => $gate->stage->workflow->deal_id,
        'stage_id' => null,
        'action_definition_id' => '01ACTIONDEFINITION00000000',
    ]);

    expect(verdictFor($gate)->met)->toBeFalse()
        ->and(verdictFor($gate)->explanation)->toContain('has not run yet');
});

it('gives every verdict a sentence somebody could act on', function (): void {
    // PRD §5.4: "each unmet gate links directly to the thing that clears it."
    // A boolean cannot build S23, so no evaluator is allowed to return an
    // empty explanation.
    foreach (GateRegistry::types() as $type) {
        $verdict = verdictFor(gateOfType($type, ['field' => 'notes', 'role' => 'team_member']));

        expect(trim($verdict->explanation))->not->toBe('', "The {$type} evaluator returned no explanation.");
    }
});

it('says so when a document gate looks somewhere nothing can attach', function (string $target): void {
    /*
     * S21 attaches documents to the **deal**; the property gallery takes
     * photographs and nothing attaches to a stage at all. So a gate configured
     * to look at either has exactly one way to be satisfied and no way to
     * reach it — CLAUDE.md's *"a row nothing can reach"*, still open here
     * after #104 closed the deal case.
     *
     * An advance blocked by a requirement nobody can clear is worse than one
     * blocked by a requirement somebody can: the second has a next action, and
     * the first looks like a bug in the product.
     */
    $verdict = verdictFor(gateOfType('document_present', [
        'category' => 'inspection_report',
        'attachedTo' => $target,
    ]));

    expect($verdict->met)->toBeFalse()
        ->and($verdict->explanation)->toContain('documents attach to the deal')
        // No link, because there is nowhere useful to send anybody: the fix is
        // editing the template, not visiting a screen.
        ->and($verdict->linkTarget)->toBe([]);
})->with(['stage', 'property']);

/*
 * ---------------------------------------------------------------------------
 * date_reached (#109)
 * ---------------------------------------------------------------------------
 *
 * The second of the two evaluators CLAUDE.md named as owing a *"is this path
 * actually reachable"* check. It is reachable from both ends now: S43
 * configures the date this gate names, and S18 is where somebody moves it.
 */

it('clears a date gate on the day the date lands, and not before', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-14 12:00:00', 'UTC'));

    KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Inspection objection',
        'date' => '2026-09-15',
    ]);

    $gate = gateOfType('date_reached', ['keyDateName' => 'Inspection objection']);

    expect(verdictFor($gate)->met)->toBeFalse()
        ->and(verdictFor($gate)->explanation)->toContain('has not arrived yet')
        // PRD §5.4: the link goes to the thing somebody does about it, which
        // for a date is looking at it — and moving it if the contract moved.
        ->and(verdictFor($gate)->linkTarget['type'])->toBe('key_date');

    /*
     * **Today counts.** A deadline of the 15th has been reached on the 15th; a
     * gate that waited until the 16th would hold a stage for a day nobody
     * agreed to.
     */
    Carbon::setTestNow(Carbon::parse('2026-09-15 06:00:00', 'UTC'));

    expect(verdictFor($gate)->met)->toBeTrue();

    Carbon::setTestNow();
});

it('reads the day in the team’s calendar, not in UTC', function (): void {
    $this->team->forceFill(['timezone' => 'America/Denver'])->save();
    $this->withTeam($this->team->refresh());

    KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Closing',
        'date' => '2026-09-15',
    ]);

    $gate = gateOfType('date_reached', ['keyDateName' => 'Closing']);

    /*
     * 01:00 UTC on the 15th is still the **14th** in Denver, and a gate that
     * cleared then would have advanced a stage a day early — the mirror of the
     * defect `Task::state()` records, where a task read as overdue while the
     * reader still had six hours of their working day.
     */
    Carbon::setTestNow(Carbon::parse('2026-09-15 01:00:00', 'UTC'));

    expect(verdictFor($gate)->met)->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-09-15 18:00:00', 'UTC'));

    expect(verdictFor($gate)->met)->toBeTrue();

    Carbon::setTestNow();
});

it('matches the date by name however either side typed it', function (): void {
    KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => '  Inspection Objection ',
        'date' => now()->subDay()->toDateString(),
    ]);

    expect(verdictFor(gateOfType('date_reached', ['keyDateName' => 'inspection objection']))->met)
        ->toBeTrue();
});

it('says so when the deal has no date of that name', function (): void {
    $verdict = verdictFor(gateOfType('date_reached', ['keyDateName' => 'Inspection objection']));

    /*
     * An advance blocked by a requirement nobody can clear is worse than one
     * blocked by a requirement somebody can, because the second has a next
     * action. This one does: add the date.
     */
    expect($verdict->met)->toBeFalse()
        ->and($verdict->explanation)->toContain('has no date called')
        ->and($verdict->linkTarget['type'])->toBe('key_date');
});

it('refuses a date gate that names nothing', function (): void {
    $verdict = verdictFor(gateOfType('date_reached'));

    expect($verdict->met)->toBeFalse()
        ->and($verdict->explanation)->toContain('does not say which date');
});

it('is not cleared by a date nobody has confirmed', function (): void {
    /*
     * PRD §4.10: extraction never writes into a live record without human
     * confirmation, and clearing a gate is writing into the record by another
     * door.
     */
    KeyDate::factory()->pending()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Closing',
        'date' => now()->subWeek()->toDateString(),
    ]);

    $verdict = verdictFor(gateOfType('date_reached', ['keyDateName' => 'Closing']));

    expect($verdict->met)->toBeFalse()
        ->and($verdict->explanation)->toContain('has no date called');
});
