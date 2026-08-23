<?php

declare(strict_types=1);

use App\Models\Deal;
use App\Models\DealType;
use App\Models\Gate;
use App\Models\Stage;
use App\Models\StageTemplate;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\Workflow;
use App\Models\WorkflowTemplate;
use App\Support\Tenancy\CrossTenantException;
use App\Support\Tenancy\ForeignReferenceException;
use App\Support\Tenancy\MissingTeamContextException;
use App\Support\Tenancy\TeamContext;
use App\Support\Workflow\AdvanceWorkflow;
use App\Support\Workflow\InstantiateWorkflow;
use Illuminate\Database\QueryException;

/**
 * The tenant boundary around deals and workflows (ADR 0002 · issue #42).
 *
 * `CLAUDE.md`: *"A gap here is a release blocker, not a follow-up."* Slice 2
 * adds the tables that actually hold the money and the addresses, so the
 * isolation suite grows with them.
 *
 * One case here is not covered by the global scope and needs saying out loud:
 * `deals.deal_type_id` carries a **plain** foreign key rather than a composite
 * one, because a system deal type has `team_id = null` and a composite key
 * from a NOT NULL `deals.team_id` can never match `(null, id)`. ADR 0002
 * anticipates it — *"where Postgres cannot express the constraint, the
 * relationship carries a test instead"* — and this is that test.
 */
beforeEach(function (): void {
    [$this->teamA, $this->memberA] = $this->teamWithMember();
    [$this->teamB, $this->memberB] = $this->teamWithMember();
});

it('hides another team’s deals, workflows, stages, and tasks', function (): void {
    $deal = app(TeamContext::class)->runFor($this->teamB, function () {
        $deal = Deal::factory()->create(['team_id' => $this->teamB->getKey()]);
        $workflow = Workflow::factory()->create([
            'team_id' => $this->teamB->getKey(),
            'deal_id' => $deal->getKey(),
        ]);
        $stage = Stage::factory()->create([
            'team_id' => $this->teamB->getKey(),
            'workflow_id' => $workflow->getKey(),
        ]);
        Gate::factory()->create(['team_id' => $this->teamB->getKey(), 'stage_id' => $stage->getKey()]);
        Task::factory()->create([
            'team_id' => $this->teamB->getKey(),
            'deal_id' => $deal->getKey(),
            'stage_id' => $stage->getKey(),
        ]);

        return $deal;
    });

    app(TeamContext::class)->runFor($this->teamA, function () use ($deal): void {
        expect(Deal::query()->count())->toBe(0)
            ->and(Deal::query()->find($deal->getKey()))->toBeNull()
            ->and(Workflow::query()->count())->toBe(0)
            ->and(Stage::query()->count())->toBe(0)
            ->and(Gate::query()->count())->toBe(0)
            ->and(Task::query()->count())->toBe(0);
    });
});

it('refuses to create a deal against another team’s private deal type', function (): void {
    $foreign = app(TeamContext::class)->runFor(
        $this->teamB,
        fn () => DealType::factory()->create(['team_id' => $this->teamB->getKey()]),
    );

    app(TeamContext::class)->runFor($this->teamA, function () use ($foreign): void {
        // Team A cannot see it to pick it...
        expect(DealType::query()->visibleTo($this->teamA)->pluck('id')->all())
            ->not->toContain($foreign->getKey());

        /*
         * ...and cannot use it by naming its id anyway.
         *
         * The read-side assertion above was the whole test for one review
         * round, and it would have passed just as happily with the write wide
         * open — which it was. `deals.deal_type_id` is a plain foreign key
         * (a composite one cannot express the `team_id IS NULL` system rows),
         * so the database will accept any id in the table and the model has to
         * be the one that refuses.
         */
        expect(fn () => Deal::factory()->create([
            'team_id' => $this->teamA->getKey(),
            'deal_type_id' => $foreign->getKey(),
        ]))->toThrow(ForeignReferenceException::class);
    });
});

it('lets a team use its own private deal type, with no team_id in the request', function (): void {
    /*
     * The shape `CLAUDE.md` mandates, and the shape nothing tested.
     *
     * `team_id` is deliberately not fillable — a request body must not choose
     * a tenant — so a controller creates a deal without it and `BelongsToTeam`
     * fills it on `creating`. The first version of the guard above ran on
     * `saving`, which fires *first*, so it compared a real deal type against a
     * null `team_id` and refused every team-owned type while waving the shared
     * ones through. `DealFactory` sets `team_id`, which is why 455 tests
     * agreed with it.
     */
    app(TeamContext::class)->runFor($this->teamA, function (): void {
        $own = DealType::factory()->create(['team_id' => $this->teamA->getKey()]);

        Deal::create(['deal_type_id' => $own->getKey(), 'name' => '11 Ash Court']);

        $deal = Deal::query()->sole();

        expect($deal->team_id)->toBe($this->teamA->getKey())
            ->and($deal->deal_type_id)->toBe($own->getKey());
    });
});

it('still refuses a foreign deal type when the request carries no team_id', function (): void {
    $foreign = app(TeamContext::class)->runFor(
        $this->teamB,
        fn () => DealType::factory()->create(['team_id' => $this->teamB->getKey()]),
    );

    app(TeamContext::class)->runFor($this->teamA, function () use ($foreign): void {
        expect(fn () => Deal::create(['deal_type_id' => $foreign->getKey(), 'name' => 'Probe']))
            ->toThrow(ForeignReferenceException::class);
    });
});

it('refuses a foreign deal type on an update too', function (): void {
    $foreign = app(TeamContext::class)->runFor(
        $this->teamB,
        fn () => DealType::factory()->create(['team_id' => $this->teamB->getKey()]),
    );

    app(TeamContext::class)->runFor($this->teamA, function () use ($foreign): void {
        $deal = Deal::factory()->create(['team_id' => $this->teamA->getKey()]);

        expect(fn () => $deal->forceFill(['deal_type_id' => $foreign->getKey()])->save())
            ->toThrow(ForeignReferenceException::class);
    });
});

it('refuses to instantiate another team’s private workflow template', function (): void {
    $foreign = app(TeamContext::class)->runFor(
        $this->teamB,
        fn () => WorkflowTemplate::factory()->create([
            'team_id' => $this->teamB->getKey(),
            'name' => 'Team B private process',
        ]),
    );

    app(TeamContext::class)->runFor($this->teamA, function () use ($foreign): void {
        $deal = Deal::factory()->create(['team_id' => $this->teamA->getKey()]);

        /*
         * A team's workflow template *is* its process written down — the stage
         * names, the gate configuration, the task titles. Instantiating a
         * foreign one copied all of it into this team's runtime rows, where it
         * is readable on every screen the deal appears on.
         *
         * `stage_templates`, `gate_templates` and `task_templates` carry no
         * `team_id` of their own; they inherit it through the parent. This is
         * the check that actually asks the parent.
         */
        expect(fn () => app(InstantiateWorkflow::class)->handle($deal, $foreign))
            ->toThrow(ForeignReferenceException::class);

        expect(Workflow::query()->count())->toBe(0)
            ->and(Stage::query()->count())->toBe(0);
    });
});

it('drops a task assignee who is not on the deal’s team', function (): void {
    // `tasks.assignee_id` references `people`, which carries no team_id — so
    // the database cannot refuse a foreign person and the service has to.
    $teamBPerson = (string) $this->memberB->getKey();

    app(TeamContext::class)->runFor($this->teamA, function () use ($teamBPerson): void {
        $deal = Deal::factory()->create(['team_id' => $this->teamA->getKey()]);

        // The definition tables carry no `team_id` of their own — they inherit
        // it through the workflow template, which is exactly why the check
        // being tested has to ask the parent.
        $template = WorkflowTemplate::factory()->create(['team_id' => $this->teamA->getKey()]);
        $stageTemplate = StageTemplate::factory()->create([
            'workflow_template_id' => $template->getKey(),
        ]);
        TaskTemplate::factory()->create([
            'stage_template_id' => $stageTemplate->getKey(),
            'owner_role' => 'coordinator',
        ]);

        app(InstantiateWorkflow::class)->handle(
            $deal,
            $template,
            roleAssignments: ['coordinator' => (string) $teamBPerson],
        );

        // Unassigned, not assigned to somebody in another team. An unassigned
        // task shows up on the stage and is fixable in a click.
        expect(Task::query()->whereNotNull('assignee_id')->count())->toBe(0)
            ->and(Task::query()->count())->toBe(1);
    });
});

it('lets both teams use a shared system deal type', function (): void {
    // The case that made a composite key impossible, and the reason the shared
    // row exists at all.
    $system = DealType::factory()->system()->create();

    foreach ([$this->teamA, $this->teamB] as $team) {
        app(TeamContext::class)->runFor($team, function () use ($system, $team): void {
            $deal = Deal::factory()->create([
                'team_id' => $team->getKey(),
                'deal_type_id' => $system->getKey(),
            ]);

            expect($deal->dealType->isSystem())->toBeTrue();
        });
    }
});

it('cannot point a stage at another team’s workflow, at the database', function (): void {
    $foreignWorkflow = app(TeamContext::class)->runFor(
        $this->teamB,
        fn () => Workflow::factory()->create([
            'team_id' => $this->teamB->getKey(),
            'deal_id' => Deal::factory()->create(['team_id' => $this->teamB->getKey()])->getKey(),
        ]),
    );

    // ADR 0002 layer 2: not merely unlikely, unrepresentable. The composite
    // key over (team_id, workflow_id) has nothing to match.
    app(TeamContext::class)->runFor($this->teamA, function () use ($foreignWorkflow): void {
        expect(fn () => Stage::factory()->create([
            'team_id' => $this->teamA->getKey(),
            'workflow_id' => $foreignWorkflow->getKey(),
        ]))->toThrow(QueryException::class);
    });
});

it('cannot point a task at another team’s deal, at the database', function (): void {
    $foreignDeal = app(TeamContext::class)->runFor(
        $this->teamB,
        fn () => Deal::factory()->create(['team_id' => $this->teamB->getKey()]),
    );

    app(TeamContext::class)->runFor($this->teamA, function () use ($foreignDeal): void {
        expect(fn () => Task::factory()->create([
            'team_id' => $this->teamA->getKey(),
            'deal_id' => $foreignDeal->getKey(),
        ]))->toThrow(QueryException::class);
    });
});

/**
 * The in-use counts are per team, and the unscoped version was a leak.
 *
 * A system deal type and a system workflow template are shared by every team
 * on the platform. Counting their use without the scope answers "how many
 * deals does everybody have" and puts that number on one team's settings
 * screen — a cross-tenant disclosure through a warning label.
 */
it('counts in-use warnings within the team, not across the platform', function (): void {
    $type = DealType::factory()->system()->create();

    app(TeamContext::class)->runFor($this->teamB, function () use ($type): void {
        Deal::factory()->count(3)->create([
            'team_id' => $this->teamB->getKey(),
            'deal_type_id' => $type->getKey(),
        ]);
    });

    app(TeamContext::class)->runFor($this->teamA, function () use ($type): void {
        expect($type->dealCount())->toBe(0);

        Deal::factory()->create([
            'team_id' => $this->teamA->getKey(),
            'deal_type_id' => $type->getKey(),
        ]);

        expect($type->dealCount())->toBe(1);
    });
});

it('counts template use within the team, not across the platform', function (): void {
    $template = WorkflowTemplate::factory()->create();

    app(TeamContext::class)->runFor($this->teamB, function () use ($template): void {
        $deal = Deal::factory()->create(['team_id' => $this->teamB->getKey()]);

        Workflow::factory()->count(2)->create([
            'team_id' => $this->teamB->getKey(),
            'deal_id' => $deal->getKey(),
            'workflow_template_id' => $template->getKey(),
        ]);
    });

    app(TeamContext::class)->runFor($this->teamA, function () use ($template): void {
        expect($template->inUseCount())->toBe(0);
    });
});

it('refuses to write a deal into a team other than the resolved one', function (): void {
    app(TeamContext::class)->runFor($this->teamA, function (): void {
        expect(fn () => Deal::factory()->create(['team_id' => $this->teamB->getKey()]))
            ->toThrow(CrossTenantException::class);
    });
});

it('never lets a request body choose a tenant for a deal or a workflow', function (): void {
    // team_id is absent from every #[Fillable] list, so a POST cannot name one.
    foreach ([Deal::class, Workflow::class, Stage::class, Gate::class, Task::class] as $model) {
        expect((new $model)->getFillable())->not->toContain('team_id', "{$model} exposes team_id.");
    }
});

it('throws rather than advancing when no team is resolved', function (): void {
    $workflow = app(TeamContext::class)->runFor($this->teamA, function () {
        $deal = Deal::factory()->create(['team_id' => $this->teamA->getKey()]);

        return Workflow::factory()->create([
            'team_id' => $this->teamA->getKey(),
            'deal_id' => $deal->getKey(),
        ]);
    });

    app(TeamContext::class)->set(null);

    // The scope fails closed: no team, no rows, and a loud exception rather
    // than an advance on somebody else's deal.
    expect(fn () => app(AdvanceWorkflow::class)->handle($workflow))
        ->toThrow(MissingTeamContextException::class);
});

/**
 * The two routes S15 added (#75), through the boundary rather than around it.
 *
 * `/deals/{deal}` is the first two-segment deal route in the product, and the
 * advance endpoint is the first HTTP caller `AdvanceWorkflow` has ever had. A
 * 404 rather than a 403 on the overview, because ADR 0002 layer 3 is explicit:
 * *"a 403 confirms the record exists, which is itself a disclosure."*
 */
it('answers 404 on another team’s deal overview, and refuses to advance its workflow', function (): void {
    [$deal, $workflow, $stage] = app(TeamContext::class)->runFor($this->teamB, function (): array {
        $deal = Deal::factory()->create(['team_id' => $this->teamB->getKey()]);

        $workflow = Workflow::factory()->create([
            'team_id' => $this->teamB->getKey(),
            'deal_id' => $deal->getKey(),
            'state' => App\Enums\WorkflowState::Active,
        ]);

        $stage = Stage::factory()->active()->create([
            'team_id' => $this->teamB->getKey(),
            'workflow_id' => $workflow->getKey(),
            'sort_order' => 0,
        ]);

        Stage::factory()->create([
            'team_id' => $this->teamB->getKey(),
            'workflow_id' => $workflow->getKey(),
            'sort_order' => 1,
        ]);

        return [$deal, $workflow, $stage];
    });

    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->get("/deals/{$deal->getKey()}")->assertNotFound();

    $this->post("/deals/{$deal->getKey()}/workflows/{$workflow->getKey()}/advance")
        ->assertNotFound();

    // And nothing moved.
    expect($stage->fresh()->state)->toBe(App\Enums\StageState::Active);
});
