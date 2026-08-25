<?php

declare(strict_types=1);

use App\Models\Deal;
use App\Models\Person;
use App\Models\Stage;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * S11's query budget (#80).
 *
 * The house standard: **the same page, ten times the rows, the same number of
 * queries.** `toBe()`, never "within a factor of two" — a tenfold N+1 fits
 * comfortably inside a doubling budget, which is how an earlier version of one
 * of these passed while being wrong.
 *
 * My Work is where an N+1 is most likely and least visible: the rows come from
 * one table and every one of them belongs to a **different deal**, so the
 * naive `$task->deal->displayName()` is a query per row on the screen PRD §3.4
 * has somebody opening on a phone. The fixture therefore grows the deals, not
 * just the tasks.
 */

/**
 * `$size` deals, each with a workflow, a stage and two tasks assigned to the
 * same person — which is what My Work looks like from the inside.
 *
 * @return array{0: App\Models\Team, 1: Person}
 */
function myWorkBudgetFixture(int $size): array
{
    [$team, $member] = test()->teamWithMember();

    app(TeamContext::class)->runFor($team, function () use ($team, $member, $size): void {
        for ($i = 0; $i < $size; $i++) {
            $deal = Deal::factory()->create([
                'team_id' => $team->getKey(),
                'name' => "Deal {$i}",
            ]);

            $workflow = Workflow::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => $deal->getKey(),
            ]);

            $stage = Stage::factory()->active()->create([
                'team_id' => $team->getKey(),
                'workflow_id' => $workflow->getKey(),
            ]);

            foreach ([1, 2] as $number) {
                Task::factory()->create([
                    'team_id' => $team->getKey(),
                    'deal_id' => $deal->getKey(),
                    'stage_id' => $stage->getKey(),
                    'assignee_id' => $member->getKey(),
                    'title' => "Task {$i}.{$number}",
                    'due_date' => now()->addDays($i + $number),
                ]);
            }
        }
    });

    return [$team, $member];
}

function countMyWorkQueries(Closure $callback): int
{
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $callback();

    return $queries;
}

it('does not grow its query count with the deals its tasks are spread across', function (): void {
    // Both fixtures built **before** anything is counted. Seeding inside the
    // counted closure measures the seed.
    [$smallTeam, $smallMember] = myWorkBudgetFixture(1);
    [$largeTeam, $largeMember] = myWorkBudgetFixture(10);

    $small = countMyWorkQueries(function () use ($smallMember, $smallTeam): void {
        $this->actingAsPerson($smallMember, $smallTeam);
        $this->get('/work')->assertOk();
    });

    $large = countMyWorkQueries(function () use ($largeMember, $largeTeam): void {
        $this->actingAsPerson($largeMember, $largeTeam);
        $this->get('/work')->assertOk();
    });

    expect($large)->toBe($small);
});

it('has enough rows in the large fixture to catch one', function (): void {
    /*
     * The control. A budget test over an empty page passes for the wrong
     * reason, and the failure mode this codebase keeps recording is a scan
     * that matched nothing looking exactly like a clean result.
     */
    [$team, $member] = myWorkBudgetFixture(10);

    $this->actingAsPerson($member, $team);

    $this->get('/work')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $groups = $page->toArray()['props']['groups'];

            expect($groups)->toHaveCount(10)
                ->and($page->toArray()['props']['counts']['open'])->toBe(20);
        });
});

it('does not pay for a person nothing on this screen names', function (): void {
    /*
     * §7.3 hides the assignee avatar on My Work, *"where it is always the
     * current user"* — so nothing here renders a person's name, and nothing
     * here should be resolving one. Two fixtures of the **same size**,
     * differing only in how many distinct people are involved: the same rule
     * that caught S13 eager-loading a relation no cell read.
     */
    [$plainTeam, $plainMember] = myWorkBudgetFixture(4);
    [$crowdedTeam, $crowdedMember] = myWorkBudgetFixture(4);

    app(TeamContext::class)->runFor($crowdedTeam, function () use ($crowdedTeam): void {
        // Ten more colleagues, each completing nothing and assigned nothing —
        // present in the team and absent from this screen.
        for ($i = 0; $i < 10; $i++) {
            $person = Person::factory()->create();

            TeamMembership::query()->create([
                'team_id' => $crowdedTeam->getKey(),
                'person_id' => $person->getKey(),
                'first_name' => "Colleague {$i}",
                'joined_at' => now(),
            ]);
        }
    });

    $plain = countMyWorkQueries(function () use ($plainMember, $plainTeam): void {
        $this->actingAsPerson($plainMember, $plainTeam);
        $this->get('/work')->assertOk();
    });

    $crowded = countMyWorkQueries(function () use ($crowdedMember, $crowdedTeam): void {
        $this->actingAsPerson($crowdedMember, $crowdedTeam);
        $this->get('/work')->assertOk();
    });

    expect($crowded)->toBe($plain);
});
