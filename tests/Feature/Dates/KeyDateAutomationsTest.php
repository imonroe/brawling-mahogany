<?php

declare(strict_types=1);

use App\Enums\AutomationActionType;
use App\Enums\AutomationState;
use App\Enums\AutomationTrigger;
use App\Enums\OffsetBasis;
use App\Models\ActionDefinition;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Models\KeyDate;
use App\Models\StageTemplate;
use App\Models\WorkflowTemplate;
use App\Support\Dates\SaveKeyDate;
use App\Support\Workflow\InstantiateWorkflow;
use Illuminate\Support\Facades\Queue;

/**
 * F5.3's *a number of days from a key date*, wired (#106 · #92).
 *
 * #106 names this as part of the cascade rather than as a follow-up: *"pending
 * `action_instances` scheduled off a moved date — reschedule or cancel."*
 * Getting it wrong is not cosmetic. An email saying *"your inspection
 * objection deadline is Friday"* going out on the old schedule after the
 * deadline moved to the following Wednesday is worse than no email, because
 * the client acts on it.
 */
beforeEach(function (): void {
    Queue::fake();

    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    // F5.7's 30-day window off: it would make every assertion here about
    // `awaiting_approval` rather than about scheduling.
    $this->team->forceFill([
        'approval_required_until' => now()->subDay(),
        'timezone' => 'America/Denver',
    ])->save();

    $this->withTeam($this->team->refresh());

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    $this->save = app(SaveKeyDate::class);
});

/** A workflow on the deal whose one stage carries a key-date automation. */
function workflowAwaitingKeyDate(string $name, int $offsetDays): void
{
    $template = WorkflowTemplate::factory()->create([
        'team_id' => test()->team->getKey(),
        'name' => 'Listing',
    ]);

    $stage = StageTemplate::factory()->create([
        'workflow_template_id' => $template->getKey(),
        'name' => 'Under Contract',
        'sort_order' => 0,
    ]);

    ActionDefinition::factory()->create([
        'team_id' => test()->team->getKey(),
        'stage_template_id' => $stage->getKey(),
        'trigger' => AutomationTrigger::KeyDateOffset,
        'action_type' => AutomationActionType::CreateTask,
        'config' => [
            'taskTitle' => 'Chase the inspector',
            'keyDateName' => $name,
            'offsetDays' => $offsetDays,
        ],
    ]);

    app(InstantiateWorkflow::class)->handle(test()->deal, $template);
}

it('schedules an automation off a key date, in the team’s morning', function (): void {
    workflowAwaitingKeyDate('Inspection objection', -3);

    $this->save->add($this->deal, [
        'name' => 'Inspection objection',
        'date' => '2026-09-15',
    ]);

    $instance = ActionInstance::query()
        ->where('trigger', AutomationTrigger::KeyDateOffset->value)
        ->sole();

    /*
     * Three days before the 15th, at 8am in Denver — which is 14:00 UTC in
     * September. An instant computed by adding hours to a UTC midnight is an
     * hour wrong twice a year, in exactly the fortnight a contingency deadline
     * is most likely to be argued about.
     */
    expect($instance->scheduled_for?->toDateTimeString())->toBe('2026-09-12 14:00:00')
        ->and($instance->state)->toBe(AutomationState::Pending);
});

it('matches the key date by name, however it was typed', function (): void {
    workflowAwaitingKeyDate('Inspection Objection', 0);

    $this->save->add($this->deal, [
        'name' => '  inspection objection ',
        'date' => '2026-09-15',
    ]);

    expect(ActionInstance::query()->where('trigger', AutomationTrigger::KeyDateOffset->value)->count())
        ->toBe(1);
});

it('raises nothing for a key date no automation names', function (): void {
    workflowAwaitingKeyDate('Inspection objection', -3);

    $this->save->add($this->deal, ['name' => 'Closing', 'date' => '2026-10-01']);

    expect(ActionInstance::query()->where('trigger', AutomationTrigger::KeyDateOffset->value)->count())
        ->toBe(0);
});

it('moves what is already queued when the date moves', function (): void {
    workflowAwaitingKeyDate('Inspection objection', -3);

    $date = $this->save->add($this->deal, [
        'name' => 'Inspection objection',
        'date' => '2026-09-15',
    ]);

    $this->save->edit($date, ['date' => '2026-09-22']);

    $instances = ActionInstance::query()
        ->where('trigger', AutomationTrigger::KeyDateOffset->value)
        ->get();

    // Rescheduled, never re-raised: one row, at the new instant.
    expect($instances)->toHaveCount(1)
        ->and($instances->first()?->scheduled_for?->toDateTimeString())->toBe('2026-09-19 14:00:00');
});

it('reschedules an automation the cascade moved, not only the date somebody edited', function (): void {
    workflowAwaitingKeyDate('Inspection objection', 0);

    $anchor = $this->save->add($this->deal, [
        'name' => 'Mutual acceptance',
        'date' => '2026-09-01',
    ]);

    $this->save->add($this->deal, [
        'name' => 'Inspection objection',
        'anchor_key_date_id' => $anchor->getKey(),
        'offset_days' => 10,
        'offset_basis' => OffsetBasis::Calendar->value,
    ]);

    /*
     * A client email hangs off the objection deadline as readily as off the
     * closing date that dragged it, so the reschedule has to be handed the
     * whole cascade rather than only the row somebody typed into.
     */
    $this->save->edit($anchor, ['date' => '2026-09-04']);

    expect(
        ActionInstance::query()
            ->where('trigger', AutomationTrigger::KeyDateOffset->value)
            ->sole()
            ->scheduled_for?->toDateTimeString(),
    )->toBe('2026-09-14 14:00:00');
});

it('cancels what was queued when the date is removed', function (): void {
    workflowAwaitingKeyDate('Inspection objection', -3);

    $date = $this->save->add($this->deal, [
        'name' => 'Inspection objection',
        'date' => '2026-09-15',
    ]);

    $this->save->remove($date);

    $instance = ActionInstance::query()
        ->where('trigger', AutomationTrigger::KeyDateOffset->value)
        ->sole();

    /*
     * Cancelled rather than deleted, with a reason a person can read on S47.
     * *"Already sent"* and *"never sent"* are different facts, and a cancelled
     * row is how the second one is recorded.
     */
    expect($instance->state)->toBe(AutomationState::Cancelled)
        ->and($instance->error)->toContain('removed before the message went out');
});

it('never touches a message that has reached a transport', function (): void {
    workflowAwaitingKeyDate('Inspection objection', -3);

    $date = $this->save->add($this->deal, [
        'name' => 'Inspection objection',
        'date' => '2026-09-15',
    ]);

    $instance = ActionInstance::query()
        ->where('trigger', AutomationTrigger::KeyDateOffset->value)
        ->sole();

    // A claimed `message_key` means a worker has already handed it over, and
    // nothing outside `automations:reap-unconfirmed` may decide its fate.
    $instance->forceFill(['message_key' => 'claimed'])->save();

    $this->save->edit($date, ['date' => '2026-09-22']);
    $this->save->remove($date->refresh());

    $instance->refresh();

    expect($instance->state)->toBe(AutomationState::Pending)
        ->and($instance->scheduled_for?->toDateTimeString())->toBe('2026-09-12 14:00:00');
});

it('schedules nothing off a date nobody has confirmed', function (): void {
    workflowAwaitingKeyDate('Inspection objection', -3);

    /*
     * PRD §4.10 forbids extraction writing into a live record without human
     * confirmation, and scheduling a client email off a machine's reading of a
     * contract is exactly that, one layer out.
     */
    KeyDate::factory()->pending()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Inspection objection',
        'date' => '2026-09-15',
    ]);

    app(App\Support\Dates\KeyDateAutomations::class)->reschedule(
        [KeyDate::query()->sole()],
        $this->deal,
    );

    expect(ActionInstance::query()->where('trigger', AutomationTrigger::KeyDateOffset->value)->count())
        ->toBe(0);
});
