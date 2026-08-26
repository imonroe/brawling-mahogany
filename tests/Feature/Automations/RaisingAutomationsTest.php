<?php

declare(strict_types=1);

use App\Enums\AutomationActionType;
use App\Enums\AutomationState;
use App\Enums\AutomationTrigger;
use App\Enums\ParticipantRole;
use App\Enums\RecipientRuleType;
use App\Jobs\RunAutomation;
use App\Models\ActionDefinition;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\GateTemplate;
use App\Models\MessageTemplate;
use App\Models\StageTemplate;
use App\Models\TaskTemplate;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Models\WorkflowTemplate;
use App\Support\Automation\RaiseAutomations;
use App\Support\Deals\DealTasks;
use App\Support\Workflow\AdvanceWorkflow;
use App\Support\Workflow\InstantiateWorkflow;
use Illuminate\Support\Facades\Queue;

/**
 * A trigger fires and an instance appears (PRD §4.5 F5.1–F5.4, F5.10 · #92).
 *
 * The half of the automation runtime that decides **what** happens, before any
 * of it reaches a transport. `SendingAutomationsTest` covers the other half.
 */
beforeEach(function (): void {
    Queue::fake();

    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    /*
     * The 30-day approval window off by default.
     *
     * F5.7 holds every outbound email for a team's first month, which is
     * exactly right in production and would make almost every assertion in
     * this file about `awaiting_approval`. The tests that are *about* the
     * window turn it back on.
     */
    $this->team->forceFill([
        'approval_required_until' => now()->subDay(),
        'sandbox_mode' => false,
    ])->save();

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $this->client = TeamMembership::factory()->create([
        'team_id' => $this->team->getKey(),
        'first_name' => 'Dana',
        'last_name' => 'Okafor',
        'email' => 'dana@example.test',
    ]);

    DealParticipant::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'team_membership_id' => $this->client->getKey(),
        'participant_role' => ParticipantRole::Seller,
        'is_primary' => true,
    ]);
});

/**
 * A two-stage template, with whatever automations the test asks for on the
 * stage it names.
 *
 * @param  array<int, array<string, mixed>>  $automations  keyed by the stage's sort order
 */
function templateWithAutomations(array $automations): WorkflowTemplate
{
    $template = WorkflowTemplate::factory()->create([
        'team_id' => test()->team->getKey(),
        'name' => 'Listing',
    ]);

    foreach ([0 => 'Listing Preparation', 1 => 'Go Live'] as $order => $name) {
        $stage = StageTemplate::factory()->create([
            'workflow_template_id' => $template->getKey(),
            'name' => $name,
            'sort_order' => $order,
        ]);

        foreach ($automations[$order] ?? [] as $attributes) {
            ActionDefinition::factory()->create([
                'team_id' => test()->team->getKey(),
                'stage_template_id' => $stage->getKey(),
                ...$attributes,
            ]);
        }
    }

    return $template;
}

function emailAutomationTemplate(): MessageTemplate
{
    return MessageTemplate::factory()->create([
        'team_id' => test()->team->getKey(),
        'name' => 'Your home is on the market',
        'subject' => 'Hello {{ client_name }}',
        'body_text' => 'Your listing at {{ property_address }} is live.',
        'body_html' => null,
        'recipient_rule' => [
            'type' => RecipientRuleType::ParticipantRole->value,
            'participantRole' => ParticipantRole::Seller->value,
        ],
    ]);
}

function instantiate(WorkflowTemplate $template): Workflow
{
    return app(InstantiateWorkflow::class)->handle(test()->deal, $template);
}

it('snapshots a stage template’s automations onto the workflow', function (): void {
    $workflow = instantiate(templateWithAutomations([
        1 => [['trigger' => AutomationTrigger::StageCompletion, 'config' => ['taskTitle' => 'Send the survey']]],
    ]));

    $snapshot = $workflow->template_snapshot['stages'][1]['automations'];

    expect($snapshot)->toHaveCount(1)
        ->and($snapshot[0]['trigger'])->toBe(AutomationTrigger::StageCompletion->value)
        ->and($snapshot[0]['action_type'])->toBe(AutomationActionType::CreateTask->value);
});

it('does not rewrite a running deal when the template’s automations change', function (): void {
    /*
     * F4.5, and PRD §7.1 calls it the highest-impact correction in the
     * document. A team that adds "email the seller on completion" today does
     * not retroactively owe an email on every stage that completed last month
     * — and, just as importantly, the deal already running does not silently
     * acquire it mid-flight.
     */
    $template = templateWithAutomations([1 => []]);
    $workflow = instantiate($template);

    $stage = $template->stageTemplates()->where('sort_order', 1)->sole();

    ActionDefinition::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_template_id' => $stage->getKey(),
        'trigger' => AutomationTrigger::StageCompletion,
    ]);

    expect($workflow->fresh()->template_snapshot['stages'][1]['automations'])->toBe([]);
});

it('raises a stage_start automation when a workflow is instantiated', function (): void {
    instantiate(templateWithAutomations([
        0 => [['trigger' => AutomationTrigger::StageStart, 'config' => ['taskTitle' => 'Order the survey']]],
    ]));

    expect(ActionInstance::query()->count())->toBe(1)
        ->and(ActionInstance::query()->sole()->trigger)->toBe(AutomationTrigger::StageStart);
});

it('raises a workflow_start automation wherever it hangs', function (): void {
    /*
     * The automation is on the *second* stage template, which never becomes
     * active at instantiation. `workflow_start` is a fact about the workflow
     * rather than about a stage, and #91 deliberately gave automations only
     * one parent — so every stage is asked.
     */
    instantiate(templateWithAutomations([
        1 => [['trigger' => AutomationTrigger::WorkflowStart, 'config' => ['taskTitle' => 'Say hello']]],
    ]));

    expect(ActionInstance::query()->sole()->trigger)->toBe(AutomationTrigger::WorkflowStart);
});

it('raises the completion and the next stage’s start on one advance', function (): void {
    $workflow = instantiate(templateWithAutomations([
        0 => [['trigger' => AutomationTrigger::StageCompletion, 'config' => ['taskTitle' => 'Close out prep']]],
        1 => [['trigger' => AutomationTrigger::StageStart, 'config' => ['taskTitle' => 'Book the photographer']]],
    ]));

    app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    expect(ActionInstance::query()->pluck('trigger')->map->value->sort()->values()->all())
        ->toBe([AutomationTrigger::StageCompletion->value, AutomationTrigger::StageStart->value]);
});

it('raises a workflow_completion automation only when the stages run out', function (): void {
    $workflow = instantiate(templateWithAutomations([
        0 => [['trigger' => AutomationTrigger::WorkflowCompletion, 'config' => ['taskTitle' => 'Archive it']]],
    ]));

    app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    expect(ActionInstance::query()->count())->toBe(0);

    app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member);

    expect(ActionInstance::query()->sole()->trigger)->toBe(AutomationTrigger::WorkflowCompletion);
});

it('renders the words at raise time, against this deal', function (): void {
    /*
     * F5.10: reaching a milestone *"pre-fills the relevant outbound email with
     * the right recipient and content, ready to review and send"*. A message
     * rendered at send time cannot be pre-filled, reviewed, or edited.
     */
    $message = emailAutomationTemplate();

    $workflow = instantiate(templateWithAutomations([
        0 => [[
            'trigger' => AutomationTrigger::StageCompletion,
            'action_type' => AutomationActionType::SendEmail,
            'message_template_id' => $message->getKey(),
            'config' => [],
        ]],
    ]));

    app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    $instance = ActionInstance::query()->sole();

    expect($instance->rendered()->subject)->toBe('Hello Dana Okafor')
        ->and($instance->recipients())->toBe([
            ['name' => 'Dana Okafor', 'email' => 'dana@example.test'],
        ]);
});

it('snapshots S87’s announcement into the payload, beside those words', function (): void {
    /*
     * The frame an email wears is drawn from the payload, not from a live read
     * at send time — because a message can sit in the approval queue for days,
     * and what an approver reads on S48 *is* the payload. Anything in the
     * email not derived from it was approved by nobody.
     */
    $message = emailAutomationTemplate();

    $template = templateWithAutomations([
        0 => [[
            'trigger' => AutomationTrigger::StageCompletion,
            'action_type' => AutomationActionType::SendEmail,
            'message_template_id' => $message->getKey(),
            'config' => [],
        ]],
    ]);

    $template->stageTemplates()->where('sort_order', 0)->sole()->forceFill([
        'is_milestone' => true,
        'client_facing_label' => 'Your home is on the market',
    ])->save();

    $workflow = instantiate($template);

    app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    $payload = ActionInstance::query()->sole()->payload ?? [];

    expect($payload['milestone']['headline'])->toBe('Your home is on the market');
});

it('writes no announcement for an ordinary stage', function (): void {
    $message = emailAutomationTemplate();

    $workflow = instantiate(templateWithAutomations([
        0 => [[
            'trigger' => AutomationTrigger::StageCompletion,
            'action_type' => AutomationActionType::SendEmail,
            'message_template_id' => $message->getKey(),
            'config' => [],
        ]],
    ]));

    app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    expect((ActionInstance::query()->sole()->payload ?? [])['milestone'])->toBeNull();
});

it('records the addresses rather than the rule', function (): void {
    /*
     * PRD §7.12 refuses an address on a *template*, because a template is
     * reused. An instance is one message to one set of people on one deal, and
     * recording who it was for is what lets S49 answer "did the client get
     * told" months after the participant list changed.
     */
    $message = emailAutomationTemplate();

    $workflow = instantiate(templateWithAutomations([
        0 => [[
            'trigger' => AutomationTrigger::StageCompletion,
            'action_type' => AutomationActionType::SendEmail,
            'message_template_id' => $message->getKey(),
            'config' => [],
        ]],
    ]));

    app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    $instance = ActionInstance::query()->sole();

    DealParticipant::query()->delete();

    expect($instance->fresh()->recipients())->toBe([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test'],
    ]);
});

it('does not raise the same automation twice when a stage is reopened', function (): void {
    /*
     * `AdvanceWorkflow::reopen()` wrote this contract before there was a table
     * to hold it: *"an action that already fired stays fired — a client
     * emailed when the stage first completed must not be emailed again on the
     * second advance."*
     */
    $workflow = instantiate(templateWithAutomations([
        0 => [['trigger' => AutomationTrigger::StageCompletion, 'config' => ['taskTitle' => 'Close out prep']]],
    ]));

    $advance = app(AdvanceWorkflow::class);

    $advance->handle($workflow, $this->member);
    expect(ActionInstance::query()->count())->toBe(1);

    $advance->reopen($workflow->fresh(), $workflow->stages()->where('sort_order', 0)->sole(), $this->member);
    $advance->handle($workflow->fresh(), $this->member);

    expect(ActionInstance::query()->count())->toBe(1);
});

it('does raise it again when the first attempt was cancelled', function (): void {
    /*
     * The other half, and the one a naive `exists()` gets wrong. A skipped
     * stage cancels its queue and **nothing went out**; if that stage comes
     * back and is worked properly, the automation has not fired and is owed.
     * Excluding cancelled rows is what tells "already said" from "never
     * said", and a dedupe over every row would silence the second case
     * forever.
     *
     * The raise is called directly rather than through an advance, because
     * what is being pinned is the dedupe's own rule — the two callers that
     * reach it are covered above.
     */
    $workflow = instantiate(templateWithAutomations([
        0 => [['trigger' => AutomationTrigger::StageStart, 'config' => ['taskTitle' => 'Order the survey']]],
    ]));

    $stage = $workflow->stages()->where('sort_order', 0)->sole();
    $raised = ActionInstance::query()->sole();

    app(AdvanceWorkflow::class)->skip($workflow, $stage, $this->member, 'Cash sale, no prep needed.');

    expect($raised->fresh()->state)->toBe(AutomationState::Cancelled);

    app(RaiseAutomations::class)->forStage($stage->fresh(), AutomationTrigger::StageStart);

    expect(ActionInstance::query()->where('state', '!=', AutomationState::Cancelled)->count())->toBe(1);
});

it('cancels what was queued for a stage that turns out not to apply', function (): void {
    /*
     * IA §7 keeps Skip and Override apart because they mean different things,
     * and this is the difference in the one place a client would notice it.
     * Telling the client the inspection is scheduled, on a cash sale with no
     * inspection, is the exact error F5.9 exists to prevent arriving through
     * the front door.
     */
    $workflow = instantiate(templateWithAutomations([
        0 => [['trigger' => AutomationTrigger::StageStart, 'config' => ['taskTitle' => 'Order the survey']]],
    ]));

    app(AdvanceWorkflow::class)->skip(
        $workflow,
        $workflow->stages()->where('sort_order', 0)->sole(),
        $this->member,
        'Cash sale, no prep needed.',
    );

    expect(ActionInstance::query()->sole()->state)->toBe(AutomationState::Cancelled);
});

it('holds an outbound email for approval inside the team’s first 30 days', function (): void {
    // F5.7's safety net, and the automation's own setting cannot opt out of
    // it — which is the difference between a default and a suggestion.
    $this->team->forceFill(['approval_required_until' => now()->addDays(20)])->save();

    $message = emailAutomationTemplate();

    $workflow = instantiate(templateWithAutomations([
        0 => [[
            'trigger' => AutomationTrigger::StageCompletion,
            'action_type' => AutomationActionType::SendEmail,
            'message_template_id' => $message->getKey(),
            'config' => [],
            'requires_approval' => false,
        ]],
    ]));

    app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    expect(ActionInstance::query()->sole()->state)->toBe(AutomationState::AwaitingApproval);

    // And nothing was queued: an instance waiting for a person is released by
    // `ApproveMessage` and by nothing else.
    Queue::assertNothingPushed();
});

it('does not hold a task-creating automation for approval', function (): void {
    // It reaches nobody, and a queue full of task-creations waiting for
    // approval teaches people to approve without reading.
    $this->team->forceFill(['approval_required_until' => now()->addDays(20)])->save();

    $workflow = instantiate(templateWithAutomations([
        0 => [['trigger' => AutomationTrigger::StageCompletion, 'config' => ['taskTitle' => 'Close out prep']]],
    ]));

    app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    expect(ActionInstance::query()->sole()->state)->toBe(AutomationState::Pending);

    Queue::assertPushed(RunAutomation::class);
});

it('queues nothing when the advance is refused', function (): void {
    /*
     * The ordering PRD §8.1 requires, asserted from the outside: a message
     * queued for an advance that did not happen is the failure §4.5 calls
     * unrecallable.
     */
    $workflow = instantiate(templateWithAutomations([
        0 => [['trigger' => AutomationTrigger::StageCompletion, 'config' => ['taskTitle' => 'Close out prep']]],
    ]));

    $workflow->forceFill(['state' => 'on_hold'])->save();

    $result = app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member);

    expect($result->wasRefused())->toBeTrue()
        ->and(ActionInstance::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('skips an automation whose message template has been removed', function (): void {
    $message = emailAutomationTemplate();

    $template = templateWithAutomations([
        0 => [[
            'trigger' => AutomationTrigger::StageCompletion,
            'action_type' => AutomationActionType::SendEmail,
            'message_template_id' => $message->getKey(),
            'config' => [],
        ]],
    ]);

    // Hard-deleted, which nulls the pointer rather than cascading — the state
    // S44 draws as "needs a template".
    ActionDefinition::query()->update(['message_template_id' => null]);

    $workflow = instantiate($template);

    app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    expect(ActionInstance::query()->count())->toBe(0);
});

it('ignores an inactive automation', function (): void {
    $workflow = instantiate(templateWithAutomations([
        0 => [[
            'trigger' => AutomationTrigger::StageCompletion,
            'config' => ['taskTitle' => 'Close out prep'],
            'is_active' => false,
        ]],
    ]));

    app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    expect(ActionInstance::query()->count())->toBe(0);
});

it('opens F5.7’s review window when a team is created', function (): void {
    /*
     * The rail PRD §4.5 calls a launch blocker, and it was live for every team
     * that already existed and dead for every team it was written for. The
     * migration's own comment said *"set on team creation"* and nothing kept
     * the promise: `ProvisionTeam` writes four columns and this was not one of
     * them, the column has no database default, and there was no hook.
     *
     * Fixed on the model rather than in `ProvisionTeam`, because that is not
     * the only door — `/admin` provisions teams and a later slice adds signup.
     */
    [$fresh] = $this->teamWithMember();

    expect($fresh->approvalIsMandatory())->toBeTrue()
        ->and($fresh->approval_required_until->isAfter(now()->addDays(29)))->toBeTrue();
});

it('holds a new team’s first outbound email even when the automation says otherwise', function (): void {
    // The end-to-end version of the case above: the automation's own setting
    // cannot opt out of the window, which is the difference between a default
    // and a suggestion.
    [$fresh, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $fresh);

    $deal = Deal::factory()->create(['team_id' => $fresh->getKey()]);

    $client = TeamMembership::factory()->create([
        'team_id' => $fresh->getKey(),
        'first_name' => 'Dana',
        'last_name' => 'Okafor',
        'email' => 'dana@example.test',
    ]);

    DealParticipant::factory()->create([
        'team_id' => $fresh->getKey(),
        'deal_id' => $deal->getKey(),
        'team_membership_id' => $client->getKey(),
        'participant_role' => ParticipantRole::Seller,
        'is_primary' => true,
    ]);

    $message = MessageTemplate::factory()->create([
        'team_id' => $fresh->getKey(),
        'subject' => 'Hello {{ client_name }}',
        'body_text' => 'Your listing is live.',
        'body_html' => null,
        'recipient_rule' => [
            'type' => RecipientRuleType::ParticipantRole->value,
            'participantRole' => ParticipantRole::Seller->value,
        ],
    ]);

    $template = WorkflowTemplate::factory()->create(['team_id' => $fresh->getKey()]);

    $stage = StageTemplate::factory()->create([
        'workflow_template_id' => $template->getKey(),
        'sort_order' => 0,
    ]);

    ActionDefinition::factory()->create([
        'team_id' => $fresh->getKey(),
        'stage_template_id' => $stage->getKey(),
        'trigger' => AutomationTrigger::StageCompletion,
        'action_type' => AutomationActionType::SendEmail,
        'message_template_id' => $message->getKey(),
        'config' => [],
        'requires_approval' => false,
    ]);

    $workflow = app(InstantiateWorkflow::class)->handle($deal, $template);

    app(AdvanceWorkflow::class)->handle($workflow, $member);

    expect(ActionInstance::query()->sole()->state)->toBe(AutomationState::AwaitingApproval);
});

it('raises gate_cleared for a gate no person ticks', function (): void {
    /*
     * `confirm()` covers the manual tick and covered nothing else, so a team
     * building *"when the required tasks are done, email the buyer"* got an
     * automation that saved cleanly, showed as active on S44, and never fired
     * — the silent non-delivery `CLAUDE.md` calls the worst possible answer to
     * *"has the client been told?"*.
     *
     * `required_tasks_complete` clears here without anybody touching a gate:
     * the evaluator notices the tasks are done, which is exactly the path that
     * was unwired.
     */
    $template = templateWithAutomations([]);

    $stageTemplate = $template->stageTemplates()->where('sort_order', 0)->sole();

    $gateTemplate = GateTemplate::factory()->create([
        'stage_template_id' => $stageTemplate->getKey(),
        'gate_type' => 'required_tasks_complete',
        'label' => 'The paperwork is in',
        'sort_order' => 0,
    ]);

    TaskTemplate::factory()->required()->create([
        'stage_template_id' => $stageTemplate->getKey(),
        'title' => 'File the disclosure',
    ]);

    ActionDefinition::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_template_id' => $stageTemplate->getKey(),
        'trigger' => AutomationTrigger::GateCleared,
        'config' => ['gateTemplateId' => $gateTemplate->getKey(), 'taskTitle' => 'Tell the buyer'],
    ]);

    $workflow = instantiate($template);

    // Blocked: the required task is open, so nothing has cleared.
    app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    expect(ActionInstance::query()->count())->toBe(0);

    $workflow->stages()->where('sort_order', 0)->sole()
        ->tasks()->update(['completed_at' => now()]);

    app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member);

    expect(ActionInstance::query()->sole()->trigger)->toBe(AutomationTrigger::GateCleared);
});

it('does not raise gate_cleared a second time when the gate is re-evaluated', function (): void {
    // The dedupe carries this, and it has to: every advance attempt
    // re-evaluates every gate, so a gate that stays met would otherwise fire
    // on each press of a button that refuses.
    $template = templateWithAutomations([]);

    $stageTemplate = $template->stageTemplates()->where('sort_order', 0)->sole();

    $gateTemplate = GateTemplate::factory()->create([
        'stage_template_id' => $stageTemplate->getKey(),
        'gate_type' => 'required_tasks_complete',
        'sort_order' => 0,
    ]);

    ActionDefinition::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_template_id' => $stageTemplate->getKey(),
        'trigger' => AutomationTrigger::GateCleared,
        'config' => ['gateTemplateId' => $gateTemplate->getKey(), 'taskTitle' => 'Tell the buyer'],
    ]);

    $workflow = instantiate($template);

    // No required tasks at all, so the gate is met on the first evaluation.
    app(AdvanceWorkflow::class)->handle($workflow, $this->member);
    app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member);

    expect(ActionInstance::query()->where('trigger', AutomationTrigger::GateCleared)->count())->toBe(1);
});

it('queues a gate_cleared message even when the advance itself refuses', function (): void {
    /*
     * The branch `AdvanceWorkflow`'s comment claims and nothing pinned: a
     * requirement that cleared during an attempt has cleared whether or not
     * two others are still in the way, and telling the client the survey is
     * back should not wait for the rest of the stage. `$raised` is passed by
     * reference precisely so the blocked branch's early return still reaches
     * `dispatchRaised()`.
     */
    $template = templateWithAutomations([]);

    $stageTemplate = $template->stageTemplates()->where('sort_order', 0)->sole();

    $clearing = GateTemplate::factory()->create([
        'stage_template_id' => $stageTemplate->getKey(),
        'gate_type' => 'required_tasks_complete',
        'sort_order' => 0,
    ]);

    // A second gate that cannot clear on its own, so the advance is refused
    // while the first one goes from unmet to met on the same attempt.
    GateTemplate::factory()->create([
        'stage_template_id' => $stageTemplate->getKey(),
        'gate_type' => 'manual_confirmation',
        'sort_order' => 1,
    ]);

    ActionDefinition::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_template_id' => $stageTemplate->getKey(),
        'trigger' => AutomationTrigger::GateCleared,
        'config' => ['gateTemplateId' => $clearing->getKey(), 'taskTitle' => 'Tell the buyer'],
    ]);

    $workflow = instantiate($template);

    $result = app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    expect($result->advanced)->toBeFalse()
        ->and(ActionInstance::query()->where('trigger', AutomationTrigger::GateCleared)->count())->toBe(1);

    Queue::assertPushed(RunAutomation::class);
});

it('notices a requirement clearing on the next advance rather than when the task is done', function (): void {
    /*
     * The gap the help text now names, pinned so a later reader meets the
     * behaviour rather than the sentence. `evaluateGates()` has one caller;
     * nothing re-evaluates a gate when a task is completed, so completing the
     * last required task raises nothing until somebody presses Advance —
     * including a press that is then refused.
     *
     * Evaluating from `DealTasks::complete()` is the follow-up that would make
     * *"when a requirement clears"* literal. Until then this is what happens,
     * and a test saying so is what stops the documentation drifting back.
     */
    $template = templateWithAutomations([]);

    $stageTemplate = $template->stageTemplates()->where('sort_order', 0)->sole();

    $gateTemplate = GateTemplate::factory()->create([
        'stage_template_id' => $stageTemplate->getKey(),
        'gate_type' => 'required_tasks_complete',
        'sort_order' => 0,
    ]);

    TaskTemplate::factory()->required()->create([
        'stage_template_id' => $stageTemplate->getKey(),
        'title' => 'File the disclosure',
    ]);

    ActionDefinition::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_template_id' => $stageTemplate->getKey(),
        'trigger' => AutomationTrigger::GateCleared,
        'config' => ['gateTemplateId' => $gateTemplate->getKey(), 'taskTitle' => 'Tell the buyer'],
    ]);

    $workflow = instantiate($template);
    $stage = $workflow->stages()->where('sort_order', 0)->sole();
    $task = $stage->tasks()->sole();

    app(DealTasks::class)->complete($this->deal, $task, $this->member);

    // The requirement is satisfied in the world and nothing has noticed.
    expect(ActionInstance::query()->count())->toBe(0)
        ->and($stage->gates()->sole()->fresh()->is_met)->toBeFalse();

    app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member);

    expect(ActionInstance::query()->where('trigger', AutomationTrigger::GateCleared)->count())->toBe(1);
});
