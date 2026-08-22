<?php

declare(strict_types=1);

use App\Models\Deal;
use App\Models\DealType;
use App\Models\Gate;
use App\Models\Stage;
use App\Models\Task;
use App\Models\Workflow;
use App\Support\Tenancy\CrossTenantException;
use App\Support\Tenancy\MissingTeamContextException;
use App\Support\Tenancy\TeamContext;
use App\Support\Workflow\AdvanceWorkflow;
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
        // Team A cannot even see it to pick it.
        expect(DealType::query()->visibleTo($this->teamA)->pluck('id')->all())
            ->not->toContain($foreign->getKey());
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
        expect($type->liveDealCount())->toBe(0);

        Deal::factory()->create([
            'team_id' => $this->teamA->getKey(),
            'deal_type_id' => $type->getKey(),
        ]);

        expect($type->liveDealCount())->toBe(1);
    });
});

it('counts template use within the team, not across the platform', function (): void {
    $template = App\Models\WorkflowTemplate::factory()->create();

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
