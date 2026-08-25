<?php

declare(strict_types=1);

use App\Enums\PersonLifecycleState;
use App\Enums\StageState;
use App\Enums\TaskSource;
use App\Enums\WorkflowState;
use App\Models\Deal;
use App\Models\Gate;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Stage;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Support\Permissions;
use App\Support\Tenancy\TeamContext;
use App\Support\Workflow\AdvanceWorkflow;
use Illuminate\Support\Str;
use Inertia\Support\SessionKey;

/**
 * S23, the advance stage modal, and the S24 override it reaches (#77, #69).
 *
 * The Screen Inventory names five key states for S23 — all gates met, one
 * unmet, several unmet, advisory only, last stage — and this file renders each
 * of them through the endpoint the dialog actually reads. The payload is
 * asserted rather than the markup, because the payload is what makes the
 * refusal explainable: every gate carries the sentence its own evaluator
 * wrote, and Design System §7.4's "what happens when you advance" block is
 * built on the server so that it can be checked at all.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

/**
 * A running workflow with two stages, the first active.
 *
 * @return array{0: Workflow, 1: Stage, 2: Stage}
 */
function advanceModalWorkflow(Deal $deal, bool $milestone = false): array
{
    $workflow = Workflow::factory()->create([
        'team_id' => $deal->team_id,
        'deal_id' => $deal->getKey(),
        'name' => 'Listing',
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
        'name' => 'Property Listed',
        'sort_order' => 1,
    ]);

    $workflow->forceFill(['current_stage_id' => $first->getKey()])->save();

    return [$workflow->fresh(), $first, $second];
}

/**
 * A member of the acting team holding exactly these permissions.
 *
 * PRD §4.2 F2.2's roles are composed rather than fixed, and IA §7 keeps
 * advance, override and skip apart precisely so a team can hand out one
 * without the others. The tests below need three different shapes of person,
 * so the shape is a parameter.
 *
 * @param  list<string>  $permissions
 */
function advanceModalMemberWith(array $permissions, string $roleKey): Person
{
    $team = test()->team;
    $person = Person::factory()->create();

    app(TeamContext::class)->runFor($team, function () use ($team, $person, $permissions, $roleKey): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $team->getKey(),
            'person_id' => $person->getKey(),
            'first_name' => 'Emily',
            'last_name' => 'Roth',
            'status' => PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        $role = Role::factory()->create([
            'team_id' => $team->getKey(),
            'key' => $roleKey,
            'name' => Str::headline($roleKey),
        ]);

        $role->permissions()->sync(
            Permission::query()->whereIn('key', $permissions)->pluck('id')->all(),
        );

        $membership->roles()->attach($role->getKey());
    });

    return $person;
}

/** Somebody who may advance and may also override. */
function advanceModalOverrider(): Person
{
    return advanceModalMemberWith([
        Permissions::VIEW_DEALS,
        Permissions::ADVANCE_WORKFLOW,
        Permissions::OVERRIDE_GATE,
    ], 'senior_agent');
}

/** @return array<string, mixed> */
function advanceModalPreview(Deal $deal, Workflow $workflow): array
{
    /** @var array<string, mixed> */
    return test()
        ->getJson("/deals/{$deal->getKey()}/workflows/{$workflow->getKey()}/advance")
        ->assertOk()
        ->json();
}

/**
 * One consequence entry by its kind, so a test names the row it means rather
 * than its position.
 *
 * @param  array<string, mixed>  $preview
 * @return array<string, string|null>
 */
function advanceModalConsequence(array $preview, string $kind): array
{
    /** @var list<array<string, string|null>> $entries */
    $entries = $preview['consequences'];

    foreach ($entries as $entry) {
        if ($entry['kind'] === $kind) {
            return $entry;
        }
    }

    throw new RuntimeException("No [{$kind}] consequence in the preview.");
}

/* -------------------------------------------------------------------------
 * The five key states
 * ---------------------------------------------------------------------- */

it('renders the all-gates-met state, with what advancing will do', function (): void {
    [$workflow, $first, $second] = advanceModalWorkflow($this->deal);

    Gate::factory()->met()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'label' => 'Photos are back',
    ]);

    $preview = advanceModalPreview($this->deal, $workflow);

    expect($preview['canAdvance'])->toBeTrue()
        ->and($preview['isLastStage'])->toBeFalse()
        ->and($preview['stage']['name'])->toBe('Listing Preparation')
        ->and($preview['stage']['position'])->toBe(1)
        ->and($preview['stage']['total'])->toBe(2)
        ->and($preview['nextStage']['id'])->toBe($second->getKey())
        // A met gate is still on the checklist. §7.4's pane carries a count
        // ("2 of 3 cleared") that a reader has to be able to check against rows.
        ->and($preview['gates'])->toHaveCount(1)
        ->and($preview['gates'][0]['met'])->toBeTrue()
        ->and($preview['gates'][0]['blocksAdvance'])->toBeFalse()
        ->and($preview['counts'])->toBe([
            'total' => 1,
            'blocking' => 0,
            'advisory' => 0,
            'overridden' => 0,
            'cleared' => 1,
        ]);

    // §7.4: the block is four entries in a fixed order, and never optional.
    expect(array_column($preview['consequences'], 'kind'))
        ->toBe(['emails', 'tasks', 'calendar', 'completion']);

    expect(advanceModalConsequence($preview, 'completion')['label'])
        ->toBe('Listing Preparation completes and Property Listed begins');
});

it('renders one unmet gate with the sentence its evaluator wrote', function (): void {
    [$workflow, $first] = advanceModalWorkflow($this->deal);

    Gate::factory()->ofType('required_tasks_complete')->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'label' => 'Required work is done',
    ]);

    Task::factory()->required()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'stage_id' => $first->getKey(),
    ]);

    $preview = advanceModalPreview($this->deal, $workflow);

    expect($preview['canAdvance'])->toBeFalse()
        ->and($preview['counts']['blocking'])->toBe(1)
        ->and($preview['gates'][0]['blocksAdvance'])->toBeTrue()
        // Not "blocked", and not a count: PRD §5.4 wants the reader told what
        // is waiting and where to go about it.
        ->and($preview['gates'][0]['explanation'])->toContain('1 of 1 required tasks')
        ->and($preview['gates'][0]['linkTarget']['type'])->toBe('tasks');
});

/**
 * #77: *"a list of five blockers needs prioritising and grouping, not five
 * identical rows."*
 *
 * The grouping is done in the dialog, from `linkTarget` — which is only
 * possible because the payload carries every blocker with its own resolution
 * rather than a shared "blocked" flag.
 */
it('renders several unmet gates, blocking first and each with its own way out', function (): void {
    [$workflow, $first] = advanceModalWorkflow($this->deal);

    Gate::factory()->ofType('document_present', ['category' => 'listing agreement'])->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'label' => 'Signed listing agreement',
        'sort_order' => 0,
    ]);

    Gate::factory()->ofType('field_populated', ['field' => 'transaction_value'])->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'label' => 'Price is agreed',
        'sort_order' => 1,
    ]);

    Gate::factory()->advisory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'label' => 'You probably want the survey',
        'sort_order' => 2,
    ]);

    Gate::factory()->met()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'label' => 'Photos are back',
        'sort_order' => 3,
    ]);

    $preview = advanceModalPreview($this->deal, $workflow);

    expect($preview['canAdvance'])->toBeFalse()
        ->and($preview['counts'])->toBe([
            'total' => 4,
            'blocking' => 2,
            'advisory' => 1,
            'overridden' => 0,
            'cleared' => 1,
        ]);

    // Blocking, then advisory, then met — the order a person should read them
    // in, and the order the dialog renders without re-sorting.
    expect(array_column($preview['gates'], 'label'))->toBe([
        'Signed listing agreement',
        'Price is agreed',
        'You probably want the survey',
        'Photos are back',
    ]);

    // Two blockers, two different next actions. One has a screen to go to and
    // one cannot clear on its own at all — which is what the dialog groups by.
    expect($preview['gates'][0]['linkTarget']['type'])->toBe('awaiting_slice')
        ->and($preview['gates'][0]['explanation'])->toContain('listing agreement')
        ->and($preview['gates'][1]['linkTarget']['type'])->toBe('deal_field');
});

it('renders the advisory-only state as advanceable, and still explains it', function (): void {
    [$workflow, $first] = advanceModalWorkflow($this->deal);

    Gate::factory()->advisory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'label' => 'You probably want the survey',
    ]);

    $preview = advanceModalPreview($this->deal, $workflow);

    // Shown and explained, never enforced — and distinguishable from a
    // blocker, or people learn to ignore both (#77).
    expect($preview['canAdvance'])->toBeTrue()
        ->and($preview['counts']['blocking'])->toBe(0)
        ->and($preview['counts']['advisory'])->toBe(1)
        ->and($preview['gates'][0]['isBlocking'])->toBeFalse()
        ->and($preview['gates'][0]['blocksAdvance'])->toBeFalse()
        ->and($preview['gates'][0]['met'])->toBeFalse()
        ->and($preview['gates'][0]['explanation'])->not->toBe('');
});

it('says so when this is the last stage', function (): void {
    [$workflow, , $second] = advanceModalWorkflow($this->deal);

    // Get to the last stage the only way there is.
    app(AdvanceWorkflow::class)->handle($workflow, $this->member);

    $preview = advanceModalPreview($this->deal, $workflow->fresh());

    expect($preview['stage']['id'])->toBe($second->getKey())
        ->and($preview['isLastStage'])->toBeTrue()
        ->and($preview['nextStage'])->toBeNull()
        ->and($preview['canAdvance'])->toBeTrue();

    // The consequence names the workflow, because completing one is not
    // undoable in a tidy way — `completed` is a terminal state on purpose.
    expect(advanceModalConsequence($preview, 'completion')['label'])
        ->toBe('Property Listed completes, and so does Listing');
});

/* -------------------------------------------------------------------------
 * "What happens when you advance" (Design System §7.4)
 * ---------------------------------------------------------------------- */

it('names the milestone the client is recorded as being told', function (): void {
    [$workflow] = advanceModalWorkflow($this->deal, milestone: true);

    $emails = advanceModalConsequence(advanceModalPreview($this->deal, $workflow), 'emails');

    // PRD §4.5: an automation that emails the wrong client cannot be recalled,
    // so the block says who hears what. Nothing is sent in Slice 2, and the
    // row says which slice changes that rather than leaving it to be inferred
    // from an absent line.
    expect($emails['detail'])->toContain('Your home is on the market')
        ->and($emails['detail'])->toContain('slice 3');
});

it('warns that open tasks on this stage stay open', function (): void {
    [$workflow, $first, $second] = advanceModalWorkflow($this->deal);

    Task::factory()->count(3)->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'stage_id' => $first->getKey(),
    ]);

    Task::factory()->completed()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'stage_id' => $first->getKey(),
    ]);

    Task::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'stage_id' => $second->getKey(),
    ]);

    $tasks = advanceModalConsequence(advanceModalPreview($this->deal, $workflow), 'tasks');

    // Three open, not four: a completed task is not left behind.
    expect($tasks['detail'])->toContain('3 tasks on Listing Preparation stays open')
        ->and($tasks['detail'])->toContain('1 task on Property Listed becomes current work');
});

/* -------------------------------------------------------------------------
 * The preview endpoint itself
 * ---------------------------------------------------------------------- */

it('answers a workflow on hold with a sentence rather than a checklist', function (): void {
    [$workflow, $first] = advanceModalWorkflow($this->deal);

    $workflow->transitionTo(WorkflowState::OnHold);
    $workflow->save();

    $preview = advanceModalPreview($this->deal, $workflow->fresh());

    expect($preview['stage'])->toBeNull()
        ->and($preview['refusal'])->toContain('on hold')
        // And looking at it changed nothing, which is the property that lets
        // somebody open this dialog as often as they like.
        ->and($first->fresh()->state)->toBe(StageState::Active);
});

it('changes nothing about a stage an advance attempt would have marked blocked', function (): void {
    [$workflow, $first] = advanceModalWorkflow($this->deal);

    /*
     * A blocking gate whose cached flag is a stale **true** — the required
     * task below is still open, so the derived answer is unmet. An advance
     * attempt would mark the stage blocked and rewrite the cache to false; a
     * read must do neither.
     */
    $gate = Gate::factory()->ofType('required_tasks_complete')->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'label' => 'Required work is done',
        'is_met' => true,
    ]);

    Task::factory()->required()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'stage_id' => $first->getKey(),
    ]);

    // The control: the payload really does report it as unmet, so the
    // assertions below are about writing rather than about an empty read.
    expect(advanceModalPreview($this->deal, $workflow)['gates'][0]['met'])->toBeFalse();

    expect($first->fresh()->state)->toBe(StageState::Active)
        ->and($gate->fresh()->is_met)->toBeTrue();
});

it('refuses the preview to somebody who may read deals but not move them', function (): void {
    [$workflow] = advanceModalWorkflow($this->deal);

    $this->actingAsPerson(
        advanceModalMemberWith([Permissions::VIEW_DEALS], 'read_only_for_advance'),
        $this->team,
    );

    $this->get("/deals/{$this->deal->getKey()}")->assertOk();

    $this->getJson("/deals/{$this->deal->getKey()}/workflows/{$workflow->getKey()}/advance")
        ->assertForbidden();
});

it('404s a preview aimed at another deal’s workflow', function (): void {
    [$workflow] = advanceModalWorkflow($this->deal);
    $otherDeal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    // Both deals are in the acting team, so the tenancy layers have no
    // objection — only the scoped binding answers "whose deal".
    $this->getJson("/deals/{$otherDeal->getKey()}/workflows/{$workflow->getKey()}/advance")
        ->assertNotFound();
});

/* -------------------------------------------------------------------------
 * The override endpoint (S24)
 * ---------------------------------------------------------------------- */

it('overrides a gate and hands back a follow-up task', function (): void {
    [$workflow, $first] = advanceModalWorkflow($this->deal);

    $gate = Gate::factory()->ofType('document_present', ['category' => 'appraisal'])->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'label' => 'Appraisal is back',
    ]);

    $overrider = advanceModalOverrider();
    $this->actingAsPerson($overrider, $this->team);

    $this->post("/deals/{$this->deal->getKey()}/workflows/{$workflow->getKey()}/override", [
        'gate_id' => $gate->getKey(),
        'reason' => 'Appraisal received by email, uploading tomorrow.',
    ])->assertRedirect();

    expect($gate->fresh()->overridden)->toBeTrue()
        ->and($gate->fresh()->overridden_by)->toBe($overrider->getKey())
        ->and(Task::query()->where('source', TaskSource::Override)->count())->toBe(1);

    // And the next preview shows it cleared, which is what reopens the dialog
    // onto a checklist somebody can act on.
    $preview = advanceModalPreview($this->deal, $workflow->fresh());

    expect($preview['canAdvance'])->toBeTrue()
        ->and($preview['gates'][0]['gateState'])->toBe('overridden')
        // IA §8: overridden is not a kind of met, and the payload still says
        // which — a screen that collapsed them would lose the only thing
        // anybody wants to know six weeks later.
        ->and($preview['gates'][0]['met'])->toBeFalse()
        ->and($preview['gates'][0]['isBlocking'])->toBeTrue()
        ->and($preview['gates'][0]['blocksAdvance'])->toBeFalse()
        /*
         * The whole counts array, not just `overridden`.
         *
         * Each of these is arithmetic over the same three buckets, and each
         * could be quietly wrong on its own: `cleared` reverting to
         * `count($met)` reads "0 of 1 cleared" above a row badged Overridden,
         * and `advisory` forgetting to subtract the overridden ones counts a
         * waived blocker as advice. Asserting one number leaves the others
         * free.
         */
        ->and($preview['counts'])->toBe([
            'total' => 1,
            'blocking' => 0,
            'advisory' => 0,
            'overridden' => 1,
            'cleared' => 1,
        ]);
});

/**
 * IA §7 and `WorkflowPolicy`: advance, override and skip are three
 * permissions. An assistant advances stages all day and must not thereby be
 * able to decide the survey was not needed.
 */
it('refuses an override from somebody who may advance but not override', function (): void {
    [$workflow, $first] = advanceModalWorkflow($this->deal);

    $gate = Gate::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'label' => 'Appraisal is back',
    ]);

    // The control: this person really can advance, so the 403 below is about
    // the ability it asks for and not about the deal being invisible.
    $this->getJson("/deals/{$this->deal->getKey()}/workflows/{$workflow->getKey()}/advance")
        ->assertOk();

    $this->post("/deals/{$this->deal->getKey()}/workflows/{$workflow->getKey()}/override", [
        'gate_id' => $gate->getKey(),
        'reason' => 'Appraisal received by email, uploading tomorrow.',
    ])->assertForbidden();

    expect($gate->fresh()->overridden)->toBeFalse()
        ->and(Task::query()->count())->toBe(0);
});

/**
 * A refused override comes back as a flash, not an exception — and the shape
 * of that flash is a contract the dialog depends on.
 *
 * `AdvanceWorkflow::override()` returns a result rather than throwing, because
 * a workflow on hold is an ordinary outcome. So the request succeeds, Inertia
 * calls `onSuccess`, and the only thing telling `OverrideGateDialog` that
 * nothing happened is `advance.refused`. Every refusal path in the service had
 * its own test; none of them went through the controller, so the key the
 * screen reads was held by nothing.
 */
it('flashes the reason when an override is refused, and writes nothing', function (): void {
    [$workflow, $first] = advanceModalWorkflow($this->deal);

    $gate = Gate::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'label' => 'Appraisal is back',
    ]);

    app(TeamContext::class)->runFor($this->team, fn () => $workflow->transitionTo(WorkflowState::OnHold)->save());

    // Somebody who really may override, so the refusal below is the workflow's
    // state talking and not the permission check.
    $this->actingAsPerson(advanceModalOverrider(), $this->team);

    $this->post("/deals/{$this->deal->getKey()}/workflows/{$workflow->getKey()}/override", [
        'gate_id' => $gate->getKey(),
        'reason' => 'Appraisal received by email, uploading tomorrow.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $flash = session(SessionKey::FLASH_DATA)['advance'] ?? null;

    expect($flash)->not->toBeNull()
        ->and($flash['refused'])->toBeTrue()
        // The dialog renders `reasons[0]` in place of the field errors, so an
        // empty list is a refusal the person cannot see.
        ->and($flash['reasons'])->toHaveCount(1)
        ->and($flash['reasons'][0])->toBe(WorkflowState::OnHold->advanceRefusal());

    // And none of the four artefacts was written.
    expect($gate->fresh()->overridden)->toBeFalse()
        ->and(Task::query()->count())->toBe(0);
});

it('refuses an override with no reason, and one too short to mean anything', function (): void {
    [$workflow, $first] = advanceModalWorkflow($this->deal);

    $gate = Gate::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'label' => 'Appraisal is back',
    ]);

    $this->actingAsPerson(advanceModalOverrider(), $this->team);

    $url = "/deals/{$this->deal->getKey()}/workflows/{$workflow->getKey()}/override";

    $this->post($url, ['gate_id' => $gate->getKey()])
        ->assertSessionHasErrors('reason');

    $this->post($url, ['gate_id' => $gate->getKey(), 'reason' => 'ok'])
        ->assertSessionHasErrors('reason');

    expect($gate->fresh()->overridden)->toBeFalse()
        ->and(Task::query()->count())->toBe(0);
});

it('refuses an override naming a gate on another workflow', function (): void {
    [$workflow] = advanceModalWorkflow($this->deal);
    [, $otherStage] = advanceModalWorkflow($this->deal);

    // A gate in the same team on the same deal, on a different workflow.
    // Neither the global scope nor the policy has anything to object to.
    $foreign = Gate::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $otherStage->getKey(),
        'label' => 'Funds have cleared',
    ]);

    $this->actingAsPerson(advanceModalOverrider(), $this->team);

    $this->post("/deals/{$this->deal->getKey()}/workflows/{$workflow->getKey()}/override", [
        'gate_id' => $foreign->getKey(),
        'reason' => 'Appraisal received by email, uploading tomorrow.',
    ])->assertSessionHasErrors('gate_id');

    expect($foreign->fresh()->overridden)->toBeFalse()
        ->and(Task::query()->count())->toBe(0);
});
