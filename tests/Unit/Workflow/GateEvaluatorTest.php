<?php

declare(strict_types=1);

use App\Models\Deal;
use App\Models\Gate;
use App\Models\Stage;
use App\Models\Task;
use App\Models\Workflow;
use App\Support\Workflow\Gates\GateRegistry;
use App\Support\Workflow\Gates\UnknownGateType;

/**
 * The seven evaluators, each in isolation (issue #67).
 *
 * The rule the whole design rests on: *"adding a gate type means adding a
 * class, never touching advancement logic."* These tests exercise the classes
 * directly, without an advance, which is what proves they are separable.
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
 */
it('explains a deferred gate rather than failing silently', function (string $type, string $issue): void {
    $verdict = verdictFor(gateOfType($type));

    expect($verdict->met)->toBeFalse()
        ->and($verdict->explanation)->toContain($issue)
        ->and($verdict->explanation)->toContain('not wired up yet')
        ->and($verdict->linkTarget['type'])->toBe('awaiting_slice')
        ->and($verdict->linkTarget['issue'])->toBe($issue);
})->with([
    'document present' => ['document_present', '#104'],
    'action completed' => ['action_completed', '#92'],
    'date reached' => ['date_reached', '#109'],
]);

it('gives every verdict a sentence somebody could act on', function (): void {
    // PRD §5.4: "each unmet gate links directly to the thing that clears it."
    // A boolean cannot build S23, so no evaluator is allowed to return an
    // empty explanation.
    foreach (GateRegistry::types() as $type) {
        $verdict = verdictFor(gateOfType($type, ['field' => 'notes', 'role' => 'team_member']));

        expect(trim($verdict->explanation))->not->toBe('', "The {$type} evaluator returned no explanation.");
    }
});
