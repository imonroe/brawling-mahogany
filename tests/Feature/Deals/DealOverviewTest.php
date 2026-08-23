<?php

declare(strict_types=1);

use App\Enums\DealSide;
use App\Enums\DealState;
use App\Enums\ParticipantRole;
use App\Enums\PersonLifecycleState;
use App\Enums\StageState;
use App\Enums\WorkflowState;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\Gate;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Property;
use App\Models\Role;
use App\Models\Stage;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Support\Deals\DealRoster;
use App\Support\Permissions;
use App\Support\Properties\PropertyDeals;
use App\Support\Tenancy\TeamContext;
use App\Support\Workflow\AdvanceWorkflow;
use Inertia\Support\SessionKey;

/**
 * S15 — the deal overview (PRD §4.3 F3.7 · issue #75).
 *
 * The screen is held to one sentence: *"If a user has to scroll or click to
 * learn what is blocking the deal, the screen has failed."* So the tests below
 * are mostly about the payload carrying **every** unmet gate with the sentence
 * its evaluator wrote — and about the screen not changing anything by being
 * looked at, which is the property the whole read-only design rests on.
 *
 * Screen Inventory names five key states, and each has a case here: active,
 * blocked, closed, no workflow attached, no property.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);
});

/**
 * A deal with one running workflow of two stages, the first active.
 *
 * Built directly rather than through `InstantiateWorkflow`, for the reason
 * `AdvanceWorkflowTest` gives: a failure here should be telling you about the
 * overview.
 *
 * @return array{0: Deal, 1: Workflow, 2: Stage, 3: Stage}
 */
function overviewDeal(DealSide $side = DealSide::Sell, array $dealAttributes = []): array
{
    $team = test()->team;

    $type = DealType::factory()->create(['team_id' => $team->getKey(), 'side' => $side]);

    $deal = Deal::factory()->create([
        'team_id' => $team->getKey(),
        'deal_type_id' => $type->getKey(),
        ...$dealAttributes,
    ]);

    $workflow = Workflow::factory()->create([
        'team_id' => $team->getKey(),
        'deal_id' => $deal->getKey(),
        'name' => 'Listing to Close',
        'state' => WorkflowState::Active,
    ]);

    $first = Stage::factory()->active()->create([
        'team_id' => $team->getKey(),
        'workflow_id' => $workflow->getKey(),
        'name' => 'Listing Preparation',
        'sort_order' => 0,
    ]);

    $second = Stage::factory()->create([
        'team_id' => $team->getKey(),
        'workflow_id' => $workflow->getKey(),
        'name' => 'Go Live',
        'sort_order' => 1,
    ]);

    $workflow->forceFill(['current_stage_id' => $first->getKey()])->save();

    return [$deal, $workflow->fresh(), $first, $second];
}

/** An unmet, blocking, manual gate on a stage. */
function overviewGate(Stage $stage, string $label, array $attributes = []): Gate
{
    return Gate::factory()->create([
        'team_id' => $stage->team_id,
        'stage_id' => $stage->getKey(),
        'gate_type' => 'manual_confirmation',
        'label' => $label,
        ...$attributes,
    ]);
}

/**
 * PRD §4.2 F2.2's Read Only role: sees the pipeline, cannot move it.
 *
 * Composed here rather than seeded, because the five system roles do not
 * include one — `Permissions::forSystemRoles()` gives a Team Member
 * `workflow.advance` and a Status Viewer nothing at all, and neither is the
 * shape this needs.
 */
function overviewReadOnlyMember(): Person
{
    $team = test()->team;
    $person = Person::factory()->create();

    app(TeamContext::class)->runFor($team, function () use ($team, $person): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $team->getKey(),
            'person_id' => $person->getKey(),
            'first_name' => 'Read',
            'last_name' => 'Only',
            'status' => PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        $role = Role::query()->create([
            'team_id' => $team->getKey(),
            'key' => 'read_only',
            'name' => 'Read Only',
        ]);

        $role->permissions()->sync(
            Permission::query()->where('key', Permissions::VIEW_DEALS)->pluck('id')->all(),
        );

        $membership->roles()->attach($role->getKey());
    });

    return $person;
}

/* -------------------------------------------------------------------------
 * The five key states (Screen Inventory S15)
 * ---------------------------------------------------------------------- */

it('renders an active deal with its current stage and nothing in the way', function (): void {
    [$deal, $workflow, $first] = overviewDeal();

    $this->get("/deals/{$deal->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Deals/Overview')
            ->where('dealHeader.id', $deal->getKey())
            ->where('dealHeader.state', DealState::Active->value)
            // Exactly one workflow is running, so §8.4's single primary
            // Advance button has an unambiguous target.
            ->where('dealHeader.advance.workflowId', $workflow->getKey())
            ->where('dealHeader.advance.stageId', $first->getKey())
            ->has('workflows', 1)
            ->where('workflows.0.currentStage.name', 'Listing Preparation')
            ->where('workflows.0.currentStage.position', 1)
            ->where('workflows.0.currentStage.total', 2)
            ->where('workflows.0.canAdvance', true)
            ->has('workflows.0.gates', 0));
});

it('shows every unmet gate on a blocked deal, with the sentence its evaluator wrote', function (): void {
    [$deal, , $first] = overviewDeal();

    overviewGate($first, 'Photos are back');
    overviewGate($first, 'Sellers have signed');

    $gate = Gate::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'gate_type' => 'required_tasks_complete',
        'label' => 'Required work is done',
    ]);

    Task::factory()->required()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
        'stage_id' => $first->getKey(),
    ]);

    $this->get("/deals/{$deal->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Every one of them, not the first: being told about one gate,
            // clearing it and being told about the next is three round trips
            // to learn what one screen could have said.
            ->has('workflows.0.gates', 3)
            ->where('workflows.0.canAdvance', false)
            ->where('workflows.0.gates.0.explanation', 'Nobody has confirmed this yet.')
            ->where('workflows.0.gates.0.isBlocking', true)
            // The evaluator's own sentence, counted rather than generic — and
            // the link target it wrote, which is what PRD §5.4 asks for.
            ->where('workflows.0.gates.2.explanation', '1 of 1 required tasks are still open.')
            ->where('workflows.0.gates.2.linkTarget.type', 'tasks')
            ->where('workflows.0.gates.2.linkTarget.stage', $first->getKey()));

    unset($gate);
});

it('separates an advisory gate from one that blocks', function (): void {
    [$deal, , $first] = overviewDeal();

    overviewGate($first, 'Survey is in');
    Gate::factory()->advisory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'gate_type' => 'manual_confirmation',
        'label' => 'You probably want the staging quote',
        'sort_order' => 5,
    ]);

    $this->get("/deals/{$deal->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('workflows.0.gates', 2)
            // Blocking first: #75's standard is that the reader learns what is
            // stopping the deal without scrolling.
            ->where('workflows.0.gates.0.label', 'Survey is in')
            ->where('workflows.0.gates.0.isBlocking', true)
            ->where('workflows.0.gates.1.isBlocking', false));
});

it('renders a closed deal, and offers no advance on a finished workflow', function (): void {
    [$deal, $workflow, $first, $second] = overviewDeal();

    // Closing a deal is F3.8 and not this screen's job; the state is set here
    // the way the engine would leave it.
    $deal->transitionTo(DealState::Closed);
    $deal->forceFill(['closed_at' => now()])->save();

    foreach ([$first, $second] as $stage) {
        // `pending → complete` is not a legal move (IA §8), so the second
        // stage is walked through the state it would really have passed
        // through rather than teleported.
        if ($stage->state === StageState::Pending) {
            $stage->transitionTo(StageState::Active)->save();
        }

        $stage->transitionTo(StageState::Complete);
        $stage->forceFill(['actual_end' => now()])->save();
    }

    $workflow->transitionTo(WorkflowState::Completed);
    $workflow->forceFill(['current_stage_id' => null, 'actual_end' => now()])->save();

    $this->get("/deals/{$deal->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dealHeader.state', DealState::Closed->value)
            // Nothing to advance, so the header offers no primary action —
            // `AdvanceWorkflow` throws rather than refuses on a workflow with
            // no active stage, and a button here would be offering a 500.
            ->where('dealHeader.advance', null)
            ->has('workflows', 1)
            ->where('workflows.0.isRunning', false)
            ->where('workflows.0.currentStage', null)
            ->where('workflows.0.canAdvance', false));
});

it('names the way out when no workflow is attached', function (): void {
    $type = DealType::factory()->create(['team_id' => $this->team->getKey()]);
    $deal = Deal::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_type_id' => $type->getKey(),
    ]);

    $this->get("/deals/{$deal->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('workflows', 0)
            ->where('dealHeader.advance', null)
            ->where('dealHeader.counts.properties', 0));
});

it('renders a buy-side deal that has no subject property yet', function (): void {
    [$deal] = overviewDeal(DealSide::Buy);

    // #62 stopped making a buyer's first house the subject, so this is the
    // ordinary state of a buy-side deal rather than a broken one.
    app(TeamContext::class)->runFor($this->team, fn () => app(PropertyDeals::class)->link(
        Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '9 Elm St']),
        $deal,
    ));

    expect($deal->propertyLinks()->where('is_subject', true)->exists())->toBeFalse();

    $this->get("/deals/{$deal->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('subjectProperty', null)
            ->where('candidateCount', 1)
            // No subject means no street, so the header's location pair has
            // nothing to say and is absent rather than blank.
            ->where('dealHeader.location', null));
});

it('carries the subject property and the locality the header shows', function (): void {
    [$deal] = overviewDeal();

    // A sell-side deal's first house becomes its subject on the way in, which
    // is `PropertyDeals::link()`'s own rule and the reason this reads as one
    // call rather than a link and a promotion.
    app(TeamContext::class)->runFor($this->team, fn () => app(PropertyDeals::class)->link(
        Property::factory()->create([
            'team_id' => $this->team->getKey(),
            'street' => '123 Main St',
            'city' => 'Indianapolis',
            'state_code' => 'IN',
        ]),
        $deal,
    ));

    $this->get("/deals/{$deal->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('subjectProperty.address.street', '123 Main St')
            // Parts, not a formatted string: `lib/formatters.ts` owns the
            // rules (IA §10) and the server never assembles an address.
            ->where('dealHeader.location.city', 'Indianapolis')
            ->where('dealHeader.location.state', 'IN'));
});

/* -------------------------------------------------------------------------
 * The read-only guarantee
 * ---------------------------------------------------------------------- */

/**
 * The claim the whole design rests on: looking at the deal changes nothing.
 *
 * `AdvanceWorkflow` answers "what is blocking this" **by attempting the
 * advance**, which writes `stages.state = blocked` and refreshes the
 * `gates.is_met` cache. `DescribeBlockers` re-runs the same evaluators and
 * writes neither.
 *
 * The second half of this test is what stops the first half being vacuous. A
 * fixture with nothing unmet would pass it without the read-only path existing
 * at all, so the same fixture is then handed to `AdvanceWorkflow` — which does
 * mark the stage blocked. The assertion is therefore about *which* of the two
 * paths writes, not about whether anything ever would.
 */
it('changes nothing about a stage an advance attempt would have marked blocked', function (): void {
    [$deal, $workflow, $first] = overviewDeal();

    $gate = overviewGate($first, 'Photos are back');

    expect($first->fresh()->state)->toBe(StageState::Active)
        ->and($gate->fresh()->is_met)->toBeFalse();

    $this->get("/deals/{$deal->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('workflows.0.gates', 1));

    expect($first->fresh()->state)->toBe(StageState::Active)
        ->and($first->fresh()->updated_at->equalTo($first->updated_at))->toBeTrue()
        ->and($gate->fresh()->is_met)->toBeFalse()
        ->and($gate->fresh()->updated_at->equalTo($gate->updated_at))->toBeTrue();

    // The control. Without this the assertions above pass on a fixture where
    // nothing was ever going to be written.
    app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member);

    expect($first->fresh()->state)->toBe(StageState::Blocked);
});

/**
 * The cache is not consulted either, and the two answers can differ.
 *
 * `gates.is_met` is only refreshed when an advance is attempted, so a gate
 * cleared this morning still reads `false` until somebody presses the button.
 * A screen rendering from the column would tell somebody to go and do
 * something already done.
 */
it('reads the live verdict rather than the cached is_met column', function (): void {
    [$deal, , $first] = overviewDeal();

    $gate = Gate::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $first->getKey(),
        'gate_type' => 'required_tasks_complete',
        'label' => 'Required work is done',
        // A stale cached false: the tasks are all done, but no advance has
        // been attempted since, so nothing has refreshed the column.
        'is_met' => false,
    ]);

    Task::factory()->required()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
        'stage_id' => $first->getKey(),
        'completed_at' => now(),
    ]);

    $this->get("/deals/{$deal->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('workflows.0.gates', 0)
            ->where('workflows.0.canAdvance', true));

    // Still stale, because reading it right did not write it back.
    expect($gate->fresh()->is_met)->toBeFalse();
});

/* -------------------------------------------------------------------------
 * Activity
 * ---------------------------------------------------------------------- */

/**
 * F3.7 wants recent activity, and the most important entry is not the deal's.
 *
 * `AdvanceWorkflow` records against the **workflow**, so an activity card that
 * asked only about the deal would never once mention an advance — which is the
 * single event a person opening this screen most wants to see.
 */
it('shows activity recorded against the deal and against its workflows', function (): void {
    [$deal, $workflow] = overviewDeal();

    app(TeamContext::class)->runFor($this->team, fn () => app(PropertyDeals::class)->link(
        Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '4 Oak Ave']),
        $deal,
    ));

    app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member);

    $this->get("/deals/{$deal->getKey()}")
        ->assertOk()
        ->assertInertia(function ($page): void {
            $types = collect($page->toArray()['props']['activity'])->pluck('eventType');

            expect($types)->toContain('property.linked')
                ->and($types)->toContain('stage.advanced');
        });
});

/* -------------------------------------------------------------------------
 * Routing and access
 * ---------------------------------------------------------------------- */

/**
 * `routes/web.php` used to say the wizard was safe because `deals/create` is
 * two segments and `deals/{deal}/…` is three — *"the day somebody adds
 * `deals/{deal}` that stops being true."* That day is this issue.
 */
it('still reaches the wizard at /deals/create now that /deals/{deal} exists', function (): void {
    DealType::factory()->create(['team_id' => $this->team->getKey()]);

    $this->get('/deals/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Deals/Create'));
});

it('sends the finished wizard to the deal overview', function (): void {
    $type = DealType::factory()->create(['team_id' => $this->team->getKey(), 'side' => DealSide::Sell]);

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();

    $this->post('/deals/create')
        ->assertRedirect(route('deals.show', Deal::query()->sole()));
});

it('gives a person with no deal permission a 403', function (): void {
    [$deal] = overviewDeal();

    // A membership with a role holding nothing at all.
    $stranger = Person::factory()->create();

    app(TeamContext::class)->runFor($this->team, fn () => TeamMembership::query()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $stranger->getKey(),
        'first_name' => 'No',
        'last_name' => 'Access',
        'status' => PersonLifecycleState::Active,
        'joined_at' => now(),
    ]));

    $this->actingAsPerson($stranger, $this->team);

    $this->get("/deals/{$deal->getKey()}")->assertForbidden();
});

/* -------------------------------------------------------------------------
 * The advance endpoint
 * ---------------------------------------------------------------------- */

it('advances the stage the screen was looking at', function (): void {
    [$deal, $workflow, $first, $second] = overviewDeal();

    $this->post("/deals/{$deal->getKey()}/workflows/{$workflow->getKey()}/advance", [
        'expected_stage_id' => $first->getKey(),
    ])->assertRedirect();

    expect($first->fresh()->state)->toBe(StageState::Complete)
        ->and($first->fresh()->completed_by)->toBe($this->member->getKey())
        ->and($second->fresh()->state)->toBe(StageState::Active);
});

/**
 * Issue #68's rule, carried all the way to the browser.
 *
 * `AdvanceResult` collects every unmet blocking gate; the controller flashes
 * them as a list rather than through the validation error bag, because
 * Inertia's error resolution keeps only the first message per key — which
 * would have silently reintroduced the one-at-a-time behaviour the result
 * object exists to prevent.
 */
it('reports every reason a blocked advance refused, not the first', function (): void {
    [$deal, $workflow, $first] = overviewDeal();

    foreach (['Photos are back', 'Survey is in', 'Sellers have signed'] as $index => $label) {
        overviewGate($first, $label, ['sort_order' => $index]);
    }

    $this->post("/deals/{$deal->getKey()}/workflows/{$workflow->getKey()}/advance", [
        'expected_stage_id' => $first->getKey(),
    ])->assertRedirect();

    $flash = session(SessionKey::FLASH_DATA);

    expect($flash['advance']['refused'])->toBeFalse()
        ->and($flash['advance']['reasons'])->toHaveCount(3);
});

/**
 * The race the lock alone does not close.
 *
 * Under READ COMMITTED the second click re-reads after the first commits and
 * finds the *newly activated* stage, which it would then advance — two stages
 * from two clicks, one of them never worked. The screen says which stage it
 * was looking at, and a mismatch is a refusal.
 */
it('refuses when the stage the screen was looking at is no longer current', function (): void {
    [$deal, $workflow, $first, $second] = overviewDeal();

    // Somebody else got there first.
    app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->member);

    $this->post("/deals/{$deal->getKey()}/workflows/{$workflow->getKey()}/advance", [
        'expected_stage_id' => $first->getKey(),
    ])->assertRedirect();

    $flash = session(SessionKey::FLASH_DATA);

    expect($flash['advance']['refused'])->toBeTrue()
        ->and($flash['advance']['reasons'][0])->toContain('Somebody else advanced this workflow')
        // And the stage they never saw is untouched.
        ->and($second->fresh()->state)->toBe(StageState::Active);
});

it('refuses to advance a workflow that is on hold, and says so', function (): void {
    [$deal, $workflow, $first] = overviewDeal();

    $workflow->transitionTo(WorkflowState::OnHold);
    $workflow->save();

    $this->post("/deals/{$deal->getKey()}/workflows/{$workflow->getKey()}/advance")
        ->assertRedirect();

    expect(session(SessionKey::FLASH_DATA)['advance']['refused'])->toBeTrue()
        ->and($first->fresh()->state)->toBe(StageState::Active);
});

/**
 * IA §7 and `WorkflowPolicy`: advance, override and skip are three
 * permissions. PRD §4.2 F2.2's Read Only role exists for a bookkeeper who
 * needs to see the pipeline and must not be able to move it.
 */
it('refuses an advance from somebody who may read deals but not move them', function (): void {
    [$deal, $workflow, $first] = overviewDeal();

    $this->actingAsPerson(overviewReadOnlyMember(), $this->team);

    // The control: they really can see the screen, so the 403 below is about
    // the ability it asks for rather than about the deal being invisible.
    $this->get("/deals/{$deal->getKey()}")->assertOk();

    $this->post("/deals/{$deal->getKey()}/workflows/{$workflow->getKey()}/advance", [
        'expected_stage_id' => $first->getKey(),
    ])->assertForbidden();

    expect($first->fresh()->state)->toBe(StageState::Active);
});

it('404s an advance aimed at another deal’s workflow', function (): void {
    [, $workflow, $first] = overviewDeal();
    [$otherDeal] = overviewDeal();

    // Both deals are in the acting team, so the tenancy layers have no
    // objection — only the scoped binding answers "whose deal".
    $this->post("/deals/{$otherDeal->getKey()}/workflows/{$workflow->getKey()}/advance", [
        'expected_stage_id' => $first->getKey(),
    ])->assertNotFound();

    expect($first->fresh()->state)->toBe(StageState::Active);
});

/* -------------------------------------------------------------------------
 * The header, shared by every deal tab
 * ---------------------------------------------------------------------- */

it('gives the people and properties tabs the same header the overview has', function (): void {
    [$deal] = overviewDeal();

    /*
     * A client and a house, so the header actually has something to disagree
     * about. With an empty deal every field is null on every tab and the
     * comparison below passes whether or not the payload is shared — which is
     * how the first version of this test was vacuous.
     */
    app(TeamContext::class)->runFor($this->team, function () use ($deal): void {
        app(DealRoster::class)->add(
            $deal,
            TeamMembership::query()->create([
                'team_id' => $this->team->getKey(),
                'person_id' => Person::factory()->create()->getKey(),
                'first_name' => 'Emily',
                'last_name' => 'Bosart',
                'status' => PersonLifecycleState::Active,
                'joined_at' => now(),
            ]),
            ParticipantRole::Seller,
            isPrimary: true,
        );

        app(PropertyDeals::class)->link(
            Property::factory()->create([
                'team_id' => $this->team->getKey(),
                'street' => '123 Main St',
                'city' => 'Indianapolis',
                'state_code' => 'IN',
            ]),
            $deal,
        );
    });

    $overview = $this->get("/deals/{$deal->getKey()}")->assertOk();
    $people = $this->get("/deals/{$deal->getKey()}/people")->assertOk();
    $properties = $this->get("/deals/{$deal->getKey()}/properties")->assertOk();

    $header = fn ($response): array => $response->viewData('page')['props']['dealHeader'];

    // The control: the header really does carry the facts a per-tab payload
    // could get wrong.
    expect($header($overview)['clientName'])->toBe('Emily Bosart')
        ->and($header($overview)['location']['city'])->toBe('Indianapolis')
        ->and($header($overview)['counts'])->toBe(['people' => 1, 'properties' => 1]);

    expect($header($people))->toBe($header($overview))
        ->and($header($properties))->toBe($header($overview));
});

it('offers no advance target when two workflows are running', function (): void {
    [$deal] = overviewDeal();

    $second = Workflow::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
        'name' => 'Pre-listing Improvements',
        'state' => WorkflowState::Active,
    ]);

    $stage = Stage::factory()->active()->create([
        'team_id' => $this->team->getKey(),
        'workflow_id' => $second->getKey(),
        'name' => 'Painting',
        'sort_order' => 0,
    ]);

    $this->get("/deals/{$deal->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('workflows', 2)
            // §8.4 has one primary button and PRD §7.5 has two workflows. A
            // button that silently picks one of them is worse than none, so
            // each card carries its own instead.
            ->where('dealHeader.advance', null)
            ->where('workflows.1.canAdvance', true));

    unset($stage);
});
