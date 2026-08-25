<?php

declare(strict_types=1);

use App\Enums\DealState;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\Person;
use App\Models\Stage;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * S10's query budget (PRD §9 · #79, #89).
 *
 * PRD §9: *"dashboard and deal pages render under 400ms server-side at p95,
 * with 25 active deals and 500 past clients per team, and 2,000 activity
 * events."* This file used to hold an absolute ceiling of 14 and say the real
 * budget *"cannot be measured until Slice 2 puts deals behind it"*. Slice 2
 * has, so it measures the thing that actually loses that budget instead.
 *
 * **The same page, five times the deals, the same number of queries.**
 * `toBe()`, never a factor — a fivefold N+1 fits comfortably inside a doubling
 * budget, which is how an earlier version of one of these passed while being
 * wrong.
 *
 * The fixture grows every axis the page fans out over: deals, the workflows
 * and stages under them, the tasks whose dates and states three of the four
 * tiles are made of, and the activity events the rail renders. G8's twenty-five
 * is the number this has to survive, so the large fixture is twenty-five.
 */

/**
 * @return array{0: App\Models\Team, 1: Person}
 */
function dashboardBudgetFixture(int $deals): array
{
    [$team, $member] = test()->teamWithMember();

    app(TeamContext::class)->runFor($team, function () use ($team, $deals): void {
        for ($i = 0; $i < $deals; $i++) {
            $deal = Deal::factory()->create([
                'team_id' => $team->getKey(),
                'name' => "Deal {$i}",
                'state' => DealState::Active,
            ]);

            $workflow = Workflow::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => $deal->getKey(),
            ]);

            // Two stages each, so the blocked count has something to walk.
            foreach ([0, 1] as $order) {
                Stage::factory()->create([
                    'team_id' => $team->getKey(),
                    'workflow_id' => $workflow->getKey(),
                    'name' => "Stage {$order}",
                    'sort_order' => $order,
                ]);
            }

            /*
             * A different person per deal, because the activity rail resolves
             * actor names — the axis that caught an N+1 on S17's tab, where
             * one distinct person per row is what made it visible.
             */
            $actor = Person::factory()->create();

            TeamMembership::query()->create([
                'team_id' => $team->getKey(),
                'person_id' => $actor->getKey(),
                'first_name' => "Colleague {$i}",
                'joined_at' => now(),
            ]);

            Task::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => $deal->getKey(),
                'due_date' => now()->addDays($i + 1),
                'assignee_id' => $actor->getKey(),
            ]);

            ActivityEvent::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => $deal->getKey(),
                'subject_type' => $deal->getMorphClass(),
                'subject_id' => $deal->getKey(),
                'actor_person_id' => $actor->getKey(),
            ]);
        }
    });

    return [$team, $member];
}

function countDashboardQueries(Closure $callback): int
{
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $callback();

    return $queries;
}

it('does not grow its query count with the deals behind it', function (): void {
    // Both fixtures built before anything is counted: seeding inside the
    // counted closure measures the seed.
    [$smallTeam, $smallMember] = dashboardBudgetFixture(5);
    [$largeTeam, $largeMember] = dashboardBudgetFixture(25);

    $small = countDashboardQueries(function () use ($smallMember, $smallTeam): void {
        $this->actingAsPerson($smallMember, $smallTeam);
        $this->get('/dashboard')->assertOk();
    });

    $large = countDashboardQueries(function () use ($largeMember, $largeTeam): void {
        $this->actingAsPerson($largeMember, $largeTeam);
        $this->get('/dashboard')->assertOk();
    });

    expect($large)->toBe($small);
});

it('has G8’s twenty-five deals in the large fixture', function (): void {
    /*
     * The control. A budget test over an empty page passes for the wrong
     * reason — the failure this codebase keeps recording is a scan that
     * matched nothing looking exactly like a clean result.
     */
    [$team, $member] = dashboardBudgetFixture(25);

    $this->actingAsPerson($member, $team);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats.activeDeals', 25));
});
