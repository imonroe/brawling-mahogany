<?php

declare(strict_types=1);

use App\Enums\DealState;
use App\Enums\StageState;
use App\Models\Deal;
use App\Models\Stage;
use App\Models\Task;
use App\Models\Workflow;
use App\Support\Tenancy\TeamContext;

/**
 * S10 — the team dashboard (PRD F9.1 · #79).
 *
 * The four numbers are the screen. Two of them answer a question this product
 * cannot answer exactly, and both are documented rather than fudged — so what
 * is pinned here is that each counts what it claims to count, and that the
 * panel beneath them puts the deals nobody can move above the ones somebody
 * can.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);
});

/** An active deal with one stage in the given state. */
function dashboardDeal(string $name, StageState $state = StageState::Active): Deal
{
    $team = test()->team;

    return app(TeamContext::class)->runFor($team, function () use ($team, $name, $state): Deal {
        $deal = Deal::factory()->create([
            'team_id' => $team->getKey(),
            'name' => $name,
            'state' => DealState::Active,
        ]);

        $workflow = Workflow::factory()->create([
            'team_id' => $team->getKey(),
            'deal_id' => $deal->getKey(),
        ]);

        Stage::factory()->create([
            'team_id' => $team->getKey(),
            'workflow_id' => $workflow->getKey(),
            'name' => 'Under Contract',
            'sort_order' => 0,
            'state' => $state,
        ]);

        return $deal;
    });
}

it('meets a new team with the empty state rather than four zeroes', function (): void {
    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('stats.activeDeals', 0));
});

it('counts the active deals, and only the active ones', function (): void {
    dashboardDeal('Running');

    app(TeamContext::class)->runFor($this->team, function (): void {
        Deal::factory()->create([
            'team_id' => $this->team->getKey(),
            'name' => 'Closed last year',
            'state' => DealState::Closed,
        ]);
    });

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats.activeDeals', 1));
});

it('counts a blocked stage from the record, which is what the record says', function (): void {
    /*
     * `stages.state` is written by an advance attempt and by nothing else, so
     * this number is *"as of the last advance"* — which the tile says. The
     * alternative is evaluating every gate on twenty-five deals on every
     * render, which spends PRD §9's whole budget on a number nobody clicks.
     */
    dashboardDeal('Stuck', StageState::Blocked);
    dashboardDeal('Moving');

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats.blockedStages', 1));
});

it('counts overdue tasks across the team, not just the reader’s own', function (): void {
    /*
     * F9.1 puts this on the **team** dashboard. "My overdue tasks" is My
     * Work's question, and a team lead looking at this screen is looking for
     * what the team is late on.
     */
    $this->freezeAt('2026-08-25 12:00:00');

    $deal = dashboardDeal('One');

    app(TeamContext::class)->runFor($this->team, function () use ($deal): void {
        Task::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $deal->getKey(),
            'title' => 'Late, and nobody’s',
            'due_date' => '2026-08-20',
            'assignee_id' => null,
        ]);

        Task::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $deal->getKey(),
            'title' => 'Still ahead',
            'due_date' => '2026-09-20',
        ]);
    });

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats.overdueTasks', 1));
});

it('counts what is due inside the fortnight, and nothing beyond it', function (): void {
    $this->freezeAt('2026-08-25 12:00:00');

    $soon = dashboardDeal('Due soon');
    $later = dashboardDeal('Due later');

    app(TeamContext::class)->runFor($this->team, function () use ($soon, $later): void {
        Task::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $soon->getKey(),
            'due_date' => '2026-09-01',
        ]);

        Task::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $later->getKey(),
            'due_date' => '2026-10-01',
        ]);
    });

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.dueSoon', 1)
            ->where('dueSoon.0.name', 'Due soon')
            // A day, not an instant (#165).
            ->where('dueSoon.0.nextDueDate', '2026-09-01'));
});

it('puts what nobody can move above what somebody can', function (): void {
    /*
     * Screen Inventory's hard part for S10: *"25 deals legible at once, with
     * late and blocked obvious."* A blocked deal is one nobody can move at
     * all; a late one is one somebody can. So blocked sorts first.
     */
    $this->freezeAt('2026-08-25 12:00:00');

    $blocked = dashboardDeal('Blocked deal', StageState::Blocked);
    $late = dashboardDeal('Late deal');

    app(TeamContext::class)->runFor($this->team, function () use ($late): void {
        Task::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $late->getKey(),
            'due_date' => '2026-08-01',
        ]);
    });

    unset($blocked);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $names = array_column($page->toArray()['props']['needsAttention'], 'name');

            expect($names)->toBe(['Blocked deal', 'Late deal']);
        });
});

it('leaves the panel empty when nothing is in the way', function (): void {
    dashboardDeal('Quietly progressing');

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('needsAttention', [])
            ->where('stats.activeDeals', 1));
});

it('shows another team nothing of this one', function (): void {
    dashboardDeal('Mine');

    [$otherTeam, $otherMember] = $this->teamWithMember();

    $this->actingAsPerson($otherMember, $otherTeam);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats.activeDeals', 0));
});
