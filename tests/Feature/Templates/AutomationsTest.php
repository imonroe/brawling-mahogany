<?php

declare(strict_types=1);

use App\Enums\AutomationActionType;
use App\Enums\AutomationTrigger;
use App\Enums\MessageChannel;
use App\Enums\RecipientRuleType;
use App\Models\ActionDefinition;
use App\Models\GateTemplate;
use App\Models\MessageTemplate;
use App\Models\StageTemplate;
use App\Models\TeamMembership;
use App\Models\TemplatePack;
use App\Models\WorkflowTemplate;
use App\Support\Messages\ChannelMismatch;
use App\Support\Tenancy\ArchivedReferenceException;
use App\Support\Tenancy\TeamContext;
use Illuminate\Database\QueryException;

/**
 * S44 — the automation editor (PRD §4.5 F5.1–F5.4, F5.10 · issue #91).
 *
 * The definition of done is *"invalid combinations are impossible to save"*,
 * and the Screen Inventory says why it is hard: *"Trigger, action, recipient
 * rule, all interdependent."* Most of this file is one refused combination
 * each — the four dropdowns' worth of nonsense the progressive form is built
 * to make unreachable, asserted against the server rather than the form.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();

    $owner = app(TeamContext::class)->runFor($this->team, fn (): TeamMembership => TeamMembership::query()
        ->whereHas('roles', fn ($query) => $query->where('roles.key', 'team_owner'))
        ->sole());

    $this->actingAsPerson($this->enrollTwoFactor($owner->person), $this->team);
});

/** @return array{0: WorkflowTemplate, 1: StageTemplate} */
function automationStage(): array
{
    $team = test()->team;

    return app(TeamContext::class)->runFor($team, function () use ($team): array {
        $template = WorkflowTemplate::factory()->create(['team_id' => $team->getKey()]);

        $stage = StageTemplate::factory()->create([
            'workflow_template_id' => $template->getKey(),
            'name' => 'Listing Preparation',
            'sort_order' => 0,
        ]);

        return [$template, $stage];
    });
}

function emailTemplate(array $attributes = []): MessageTemplate
{
    return app(TeamContext::class)->runFor(
        test()->team,
        fn (): MessageTemplate => MessageTemplate::factory()->create([
            'team_id' => test()->team->getKey(),
            ...$attributes,
        ]),
    );
}

function automationUrl(WorkflowTemplate $template, StageTemplate $stage): string
{
    return "/templates/{$template->getKey()}/stages/{$stage->getKey()}/automations";
}

it('adds an automation that sends an email when the stage completes', function (): void {
    [$template, $stage] = automationStage();
    $message = emailTemplate(['name' => 'Property listed']);

    $this->post(automationUrl($template, $stage), [
        'trigger' => AutomationTrigger::StageCompletion->value,
        'action_type' => AutomationActionType::SendEmail->value,
        'message_template_id' => $message->getKey(),
        // F5.10's arrangement: prepared automatically, released by a human.
        'executionMode' => 'approval',
    ])->assertRedirect();

    $automation = ActionDefinition::query()->sole();

    expect($automation->stage_template_id)->toBe($stage->getKey())
        // Mirrors the parent template's team, always.
        ->and($automation->team_id)->toBe($this->team->getKey())
        ->and($automation->requires_approval)->toBeTrue()
        ->and($automation->is_manual)->toBeFalse()
        ->and($automation->executionMode())->toBe('approval');
});

it('adds an automation that creates a task', function (): void {
    [$template, $stage] = automationStage();

    $this->post(automationUrl($template, $stage), [
        'trigger' => AutomationTrigger::StageStart->value,
        'action_type' => AutomationActionType::CreateTask->value,
        'executionMode' => 'automatic',
        'config' => ['taskTitle' => 'Order the survey', 'taskDueOffsetDays' => -3],
    ])->assertRedirect();

    expect(ActionDefinition::query()->sole()->configuration())
        ->toBe(['taskTitle' => 'Order the survey', 'taskDueOffsetDays' => -3]);
});

it('refuses a template on an action that sends nothing', function (): void {
    // "Create a task" storing a pointer to an email nobody ever reads.
    [$template, $stage] = automationStage();
    $message = emailTemplate();

    $this->post(automationUrl($template, $stage), [
        'trigger' => AutomationTrigger::StageStart->value,
        'action_type' => AutomationActionType::CreateTask->value,
        'message_template_id' => $message->getKey(),
        'executionMode' => 'automatic',
        'config' => ['taskTitle' => 'x'],
    ])->assertSessionHasErrors('message_template_id');
});

it('refuses an email action pointed at a push template', function (): void {
    // An HTML body on a lock screen, and nobody would notice until a client
    // received it.
    [$template, $stage] = automationStage();

    $push = emailTemplate([
        'channel' => MessageChannel::Push,
        'subject' => null,
        'body_html' => null,
        'recipient_rule' => ['type' => RecipientRuleType::TeamOwner->value],
    ]);

    $this->post(automationUrl($template, $stage), [
        'trigger' => AutomationTrigger::StageStart->value,
        'action_type' => AutomationActionType::SendEmail->value,
        'message_template_id' => $push->getKey(),
        'executionMode' => 'automatic',
    ])->assertSessionHasErrors('message_template_id');
});

it('refuses an archived template', function (): void {
    [$template, $stage] = automationStage();
    $message = emailTemplate(['archived_at' => now()]);

    $this->post(automationUrl($template, $stage), [
        'trigger' => AutomationTrigger::StageStart->value,
        'action_type' => AutomationActionType::SendEmail->value,
        'message_template_id' => $message->getKey(),
        'executionMode' => 'automatic',
    ])->assertSessionHasErrors('message_template_id');
});

it('guards the archived and mismatched cases on the model too', function (): void {
    /*
     * The request is one caller. #92's instantiation and, later, a pack
     * install are others — and a rule written into one caller is a rule the
     * second caller is written without (ADR 0002's finding, repeated by
     * `HasDocuments`).
     */
    [$template, $stage] = automationStage();

    app(TeamContext::class)->runFor($this->team, function () use ($stage): void {
        $archived = MessageTemplate::factory()->create([
            'team_id' => $this->team->getKey(),
            'archived_at' => now(),
        ]);

        expect(fn () => ActionDefinition::factory()->sendingEmail()->create([
            'team_id' => $this->team->getKey(),
            'stage_template_id' => $stage->getKey(),
            'message_template_id' => $archived->getKey(),
        ]))->toThrow(ArchivedReferenceException::class);

        $push = MessageTemplate::factory()->push()->create(['team_id' => $this->team->getKey()]);

        expect(fn () => ActionDefinition::factory()->sendingEmail()->create([
            'team_id' => $this->team->getKey(),
            'stage_template_id' => $stage->getKey(),
            'message_template_id' => $push->getKey(),
        ]))->toThrow(ChannelMismatch::class);
    });
});

it('guards the pair when the action type changes, not only the template', function (): void {
    /*
     * The same finding as the template's channel, from the third side: the
     * hook watched only `message_template_id`, so switching an existing
     * automation from *create a task* to *send an email* saved a mismatched
     * pair with nothing looking at it.
     */
    [, $stage] = automationStage();
    $push = emailTemplate([
        'channel' => MessageChannel::Push,
        'subject' => null,
        'body_html' => null,
        'recipient_rule' => ['type' => RecipientRuleType::TeamOwner->value],
    ]);

    app(TeamContext::class)->runFor($this->team, function () use ($stage, $push): void {
        $automation = ActionDefinition::factory()->create([
            'team_id' => $this->team->getKey(),
            'stage_template_id' => $stage->getKey(),
            'action_type' => AutomationActionType::PostInternalNotification,
            'message_template_id' => $push->getKey(),
        ]);

        // The template never moves; only the action does.
        expect(fn () => $automation->forceFill([
            'action_type' => AutomationActionType::SendEmail,
        ])->save())->toThrow(ChannelMismatch::class);
    });
});

it('refuses a requirement that is not on this stage', function (): void {
    // A `gate_cleared` automation naming a gate from another stage is an
    // automation that can never fire, and nothing anywhere would say so.
    [$template, $stage] = automationStage();

    $elsewhere = app(TeamContext::class)->runFor($this->team, function () use ($template): GateTemplate {
        $other = StageTemplate::factory()->create([
            'workflow_template_id' => $template->getKey(),
            'sort_order' => 1,
        ]);

        return GateTemplate::factory()->create(['stage_template_id' => $other->getKey()]);
    });

    $this->post(automationUrl($template, $stage), [
        'trigger' => AutomationTrigger::GateCleared->value,
        'action_type' => AutomationActionType::CreateTask->value,
        'executionMode' => 'automatic',
        'config' => ['gateTemplateId' => $elsewhere->getKey(), 'taskTitle' => 'x'],
    ])->assertSessionHasErrors('config.gateTemplateId');
});

it('refuses a trigger nothing can raise yet', function (): void {
    /*
     * `key_date_offset` left this list with Slice 4 (#106) — `key_dates`
     * exists and `KeyDateAutomations` schedules off it — so the case is
     * asserted against the one trigger still unavailable: `post_closing_offset`
     * needs Keep in Touch, which is Slice 6.
     *
     * A picker offering a trigger nothing can raise lets somebody believe they
     * have set a deadline reminder that will never fire, which is the failure
     * this guard exists for and is not specific to which trigger it is.
     */
    [$template, $stage] = automationStage();

    $this->post(automationUrl($template, $stage), [
        'trigger' => AutomationTrigger::PostClosingOffset->value,
        'action_type' => AutomationActionType::CreateTask->value,
        'executionMode' => 'automatic',
        'config' => ['taskTitle' => 'x'],
    ])->assertSessionHasErrors('trigger');
});

it('accepts a key-date offset, and insists it names a date', function (): void {
    /*
     * F5.3's *a number of days from a key date* (#106). The name is free text
     * because an automation lives on a **template**, which has never met the
     * deal it will run on — so the two sides meet on the word the team uses.
     *
     * What is enforced is that there **is** one: an automation naming nothing
     * fires on nothing, which is an automation somebody believes is running.
     */
    [$template, $stage] = automationStage();

    $this->post(automationUrl($template, $stage), [
        'trigger' => AutomationTrigger::KeyDateOffset->value,
        'action_type' => AutomationActionType::CreateTask->value,
        'executionMode' => 'automatic',
        'config' => ['taskTitle' => 'Chase the inspector'],
    ])->assertSessionHasErrors('config.keyDateName');

    $this->post(automationUrl($template, $stage), [
        'trigger' => AutomationTrigger::KeyDateOffset->value,
        'action_type' => AutomationActionType::CreateTask->value,
        'executionMode' => 'automatic',
        'config' => [
            'taskTitle' => 'Chase the inspector',
            'keyDateName' => 'Inspection objection',
            'offsetDays' => -3,
        ],
    ])->assertSessionHasNoErrors();
});

it('refuses approval on an action that does not send', function (): void {
    // F5.7 is about releasing a queued *message*. There is no sense in which a
    // created task is approved.
    [$template, $stage] = automationStage();

    $this->post(automationUrl($template, $stage), [
        'trigger' => AutomationTrigger::StageStart->value,
        'action_type' => AutomationActionType::CreateTask->value,
        'executionMode' => 'approval',
        'config' => ['taskTitle' => 'x'],
    ])->assertSessionHasErrors('executionMode');
});

it('refuses two humans in the loop, in the database as well as the form', function (): void {
    /*
     * F5.4's manual prompt and F5.7's approval are the same moment from two
     * ends, so the columns carry a CHECK constraint rather than the invariant
     * living only in a controller. The form offers one three-way choice; this
     * is what holds when something else writes the row.
     */
    [$template, $stage] = automationStage();

    app(TeamContext::class)->runFor($this->team, function () use ($stage): void {
        expect(fn () => ActionDefinition::factory()->create([
            'team_id' => $this->team->getKey(),
            'stage_template_id' => $stage->getKey(),
            'is_manual' => true,
            'requires_approval' => true,
        ]))->toThrow(QueryException::class);
    });
});

it('refuses to let a shared automation name one team’s template', function (): void {
    /*
     * A **system** stage template is shared by every team, so an automation on
     * it pointing at one team's message template would send that team's words,
     * with their signature, from every other team on the platform. The
     * composite foreign key is silent here — Postgres keys are MATCH SIMPLE
     * and a null `team_id` satisfies one without checking — so the CHECK
     * constraint is what actually closes it.
     */
    $shared = app(TeamContext::class)->runWithoutScope(function (): StageTemplate {
        $pack = TemplatePack::factory()->create();

        return StageTemplate::factory()->create([
            'workflow_template_id' => WorkflowTemplate::factory()->create([
                'team_id' => null,
                'template_pack_id' => $pack->getKey(),
            ])->getKey(),
        ]);
    });

    $message = emailTemplate();

    app(TeamContext::class)->runFor($this->team, function () use ($shared, $message): void {
        expect(fn () => ActionDefinition::factory()->sendingEmail()->create([
            'team_id' => null,
            'stage_template_id' => $shared->getKey(),
            'message_template_id' => $message->getKey(),
        ]))->toThrow(QueryException::class);
    });
});

it('refuses to attach an automation to a pack’s template', function (): void {
    // Every nested template route authorizes against the workflow template: a
    // policy guarding the parent while a child route lets somebody add an
    // automation is a guard with a door beside it.
    $shared = app(TeamContext::class)->runWithoutScope(function (): array {
        $template = WorkflowTemplate::factory()->create([
            'team_id' => null,
            'template_pack_id' => TemplatePack::factory()->create()->getKey(),
        ]);

        return [$template, StageTemplate::factory()->create([
            'workflow_template_id' => $template->getKey(),
        ])];
    });

    $this->post(automationUrl($shared[0], $shared[1]), [
        'trigger' => AutomationTrigger::StageStart->value,
        'action_type' => AutomationActionType::CreateTask->value,
        'executionMode' => 'automatic',
        'config' => ['taskTitle' => 'x'],
    ])->assertForbidden();

    expect(ActionDefinition::query()->count())->toBe(0);
});

it('edits and removes an automation', function (): void {
    [$template, $stage] = automationStage();

    $automation = app(TeamContext::class)->runFor(
        $this->team,
        fn (): ActionDefinition => ActionDefinition::factory()->create([
            'team_id' => $this->team->getKey(),
            'stage_template_id' => $stage->getKey(),
        ]),
    );

    $this->patch(automationUrl($template, $stage)."/{$automation->getKey()}", [
        'trigger' => AutomationTrigger::WorkflowStart->value,
        'action_type' => AutomationActionType::ManualPrompt->value,
        'executionMode' => 'manual',
        'config' => ['instruction' => 'Ring the seller'],
    ])->assertRedirect();

    expect($automation->fresh()->trigger)->toBe(AutomationTrigger::WorkflowStart)
        ->and($automation->fresh()->is_manual)->toBeTrue();

    $this->delete(automationUrl($template, $stage)."/{$automation->getKey()}")
        ->assertRedirect();

    expect(ActionDefinition::query()->whereKey($automation->getKey())->exists())->toBeFalse();
});

it('keeps an automation switched off when a patch omits the flag', function (): void {
    /*
     * The dialog always sends it, so only a hand-written or later caller hits
     * this — but on an update the sane default is *keep*, not *activate*, and
     * this is the flag that decides whether something fires.
     */
    [$template, $stage] = automationStage();

    $automation = app(TeamContext::class)->runFor(
        $this->team,
        fn (): ActionDefinition => ActionDefinition::factory()->create([
            'team_id' => $this->team->getKey(),
            'stage_template_id' => $stage->getKey(),
            'is_active' => false,
        ]),
    );

    $this->patch(automationUrl($template, $stage)."/{$automation->getKey()}", [
        'trigger' => AutomationTrigger::StageStart->value,
        'action_type' => AutomationActionType::CreateTask->value,
        'executionMode' => 'automatic',
        'config' => ['taskTitle' => 'Order the survey'],
    ])->assertRedirect();

    expect($automation->fresh()->is_active)->toBeFalse();

    // The control: a new one is on, because that is what creating means.
    $this->post(automationUrl($template, $stage), [
        'trigger' => AutomationTrigger::StageStart->value,
        'action_type' => AutomationActionType::CreateTask->value,
        'executionMode' => 'automatic',
        'config' => ['taskTitle' => 'Book the photographer'],
    ])->assertRedirect();

    /*
     * Found by what it holds, not by `latest()`. Both rows are created inside
     * the same second, so ordering by `created_at` picks whichever the planner
     * felt like returning — and this control passed or failed on that.
     */
    $created = ActionDefinition::query()
        ->whereJsonContains('config->taskTitle', 'Book the photographer')
        ->sole();

    expect($created->is_active)->toBeTrue();
});

it('guards an archived template from a context that is not the row’s team', function (): void {
    /*
     * `MessageTemplate` is `BelongsToTeam`, so a **scoped** read inside the
     * hook answered *"is this visible to whoever happens to be in context"*
     * rather than *"what is this row pointing at"* — and returned null, which
     * the guard read as "nothing to check" and let straight through.
     *
     * The callers the hook exists for are exactly the ones at risk: #92's
     * instantiation and a pack install run under another team's context or
     * none at all.
     */
    [, $stage] = automationStage();

    $archived = emailTemplate(['archived_at' => now()]);

    [$other] = $this->teamWithMember();

    // Team B's context, writing team A's row. The composite foreign key still
    // refuses a genuinely cross-tenant pointer; what used to be skipped is the
    // archived check, which is half of why the hook is there.
    app(TeamContext::class)->runFor($other, function () use ($stage, $archived): void {
        expect(fn () => ActionDefinition::factory()->sendingEmail()->create([
            'team_id' => $this->team->getKey(),
            'stage_template_id' => $stage->getKey(),
            'message_template_id' => $archived->getKey(),
        ]))->toThrow(ArchivedReferenceException::class);
    });
});

it('shows a stage’s automations on the template editor', function (): void {
    [$template, $stage] = automationStage();
    $message = emailTemplate(['name' => 'Property listed']);

    app(TeamContext::class)->runFor($this->team, fn () => ActionDefinition::factory()->sendingEmail()->create([
        'team_id' => $this->team->getKey(),
        'stage_template_id' => $stage->getKey(),
        'message_template_id' => $message->getKey(),
        'trigger' => AutomationTrigger::StageCompletion,
    ]));

    $this->get("/templates/{$template->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('template.stages.0.automations', 1)
            ->where('template.stages.0.automations.0.description', 'When the stage completes: send an email — “Property listed”')
            ->where('template.stages.0.automations.0.isComplete', true)
            // Selectable rather than complete: a trigger nothing can raise
            // builds an automation that silently never fires. `missing`
            // rather than a null `where`, because a key that is not there is
            // the assertion — `where(…, null)` fails on an absent key with a
            // message about the key rather than about the rule.
            ->has('automationTriggers.stage_completion')
            // `key_date_offset` became selectable with Slice 4 (#106), which
            // is what "selectable rather than complete" is *for* — the list
            // grows as each trigger acquires something that can raise it.
            // `post_closing_offset` is the one still waiting, on Slice 6.
            ->has('automationTriggers.key_date_offset')
            ->missing('automationTriggers.post_closing_offset')
            ->missing('automationActions.create_calendar_event'));
});

it('shows an automation that lost its template as needing one', function (): void {
    /*
     * Reachable, because hard-deleting a template nulls the pointer rather
     * than cascading — the automation survives, pointing at nothing, and the
     * screen says so instead of letting it look like it will run.
     */
    [$template, $stage] = automationStage();
    $message = emailTemplate();

    $automation = app(TeamContext::class)->runFor(
        $this->team,
        fn (): ActionDefinition => ActionDefinition::factory()->sendingEmail()->create([
            'team_id' => $this->team->getKey(),
            'stage_template_id' => $stage->getKey(),
            'message_template_id' => $message->getKey(),
        ]),
    );

    app(TeamContext::class)->runFor($this->team, fn () => $message->forceDelete());

    expect($automation->fresh()->message_template_id)->toBeNull()
        ->and($automation->fresh()->isComplete())->toBeFalse()
        // …and the automation is still there, which is the point of nulling
        // rather than cascading.
        ->and($automation->fresh()->team_id)->toBe($this->team->getKey());
});
