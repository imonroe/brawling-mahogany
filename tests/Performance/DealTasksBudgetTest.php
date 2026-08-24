<?php

declare(strict_types=1);

use App\Enums\PersonLifecycleState;
use App\Enums\SystemRole;
use App\Enums\WorkflowState;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\Person;
use App\Models\Role;
use App\Models\Stage;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * S17's query budget (issue #71).
 *
 * The house standard: *"the same page, ten times the rows, the same number of
 * queries."* `toBe()`, not a factor — #148 shipped a budget loose enough for a
 * tenfold N+1 to fit inside it.
 *
 * ## What this screen fans out along that no other one does
 *
 * **People.** Every task names an assignee and a completer, and both live in
 * `people` while the name a team uses lives on `team_memberships` (#140). The
 * obvious spelling — `Person::displayNameWithin($team)` inside the row map —
 * costs two queries *per task*, which is exactly the defect #81 found on the
 * activity feed and fixed with `ActorDirectory`. This screen reuses it, and
 * this is the test that says so.
 *
 * So the fixture **populates** both columns rather than leaving them null.
 * CLAUDE.md's lesson from #78 applies to a budget as much as to an eager-load:
 * a relation nothing seeds is a relation nothing measures, and a per-row
 * lookup behind a null check costs nothing until somebody fills the column in.
 *
 * Both fixtures are built **before** either is counted. Seeding inside the
 * counted closure measures the seed, which is what #61's first version of this
 * did.
 */
function tasksBudgetFixture(int $size, bool $withTasks = true): array
{
    [$team, $member] = test()->teamWithMember();

    $deal = app(TeamContext::class)->runFor($team, function () use ($team, $size, $withTasks): Deal {
        $type = DealType::factory()->create(['team_id' => $team->getKey()]);

        $deal = Deal::factory()->create([
            'team_id' => $team->getKey(),
            'deal_type_id' => $type->getKey(),
        ]);

        /*
         * A different colleague per size, so the number of *distinct* people
         * on the page grows too. A directory that resolved one name per query
         * would pass a fixture where every task is assigned to the same
         * person.
         */
        $colleagues = collect(range(0, $size))->map(function (int $index) use ($team): TeamMembership {
            $membership = TeamMembership::query()->create([
                'team_id' => $team->getKey(),
                'person_id' => Person::factory()->create()->getKey(),
                'first_name' => 'Colleague',
                'last_name' => "Number {$index}",
                'status' => PersonLifecycleState::Active,
                'joined_at' => now(),
            ]);

            $membership->roles()->attach(
                Role::query()->whereNull('team_id')
                    ->where('key', SystemRole::TeamMember->value)->sole()->getKey(),
            );

            return $membership;
        });

        for ($w = 0; $w < $size; $w++) {
            $workflow = Workflow::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => $deal->getKey(),
                'name' => "Workflow {$w}",
                'state' => WorkflowState::Active,
            ]);

            $active = null;

            for ($position = 0; $position < $size * 2; $position++) {
                $stage = Stage::factory()
                    ->when($position === 0, fn ($factory) => $factory->active())
                    ->create([
                        'team_id' => $team->getKey(),
                        'workflow_id' => $workflow->getKey(),
                        'name' => "Stage {$position}",
                        'sort_order' => $position,
                    ]);

                $active ??= $stage;

                for ($t = 0; $withTasks && $t < $size; $t++) {
                    $assignee = $colleagues[$t % $colleagues->count()];
                    $completer = $colleagues[($t + 1) % $colleagues->count()];

                    Task::factory()
                        // Half of them complete, so the completion
                        // attribution — a second person per row — is on the
                        // page rather than skipped by a null.
                        ->when($t % 2 === 0, fn ($factory) => $factory->state([
                            'completed_at' => now(),
                            'completed_by' => $completer->person_id,
                        ]))
                        ->create([
                            'team_id' => $team->getKey(),
                            'deal_id' => $deal->getKey(),
                            'stage_id' => $stage->getKey(),
                            'title' => "Task {$position}-{$t}",
                            'assignee_id' => $assignee->person_id,
                            'due_date' => now()->addDays($t),
                            'sort_order' => $t,
                        ]);
                }
            }

            $workflow->forceFill(['current_stage_id' => $active->getKey()])->save();
        }

        // One that belongs to no stage, so the unstaged group is measured too.
        if ($withTasks) {
            Task::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => $deal->getKey(),
                'stage_id' => null,
                'title' => 'Chase the survey',
            ]);
        }

        return $deal;
    });

    // The deal travels with it, so a test that wants to assert *about the
    // fixture* does not have to take a URL apart to find it.
    return [$team, $member, "/deals/{$deal->getKey()}/tasks", $deal];
}

function countDealTaskQueries(Closure $callback): int
{
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $callback();

    return $queries;
}

it('does not grow its query count with the tasks, stages or people on a deal', function (): void {
    [$smallTeam, $smallMember, $smallUrl] = tasksBudgetFixture(1);
    [$largeTeam, $largeMember, $largeUrl] = tasksBudgetFixture(5);

    $small = countDealTaskQueries(function () use ($smallMember, $smallTeam, $smallUrl): void {
        $this->actingAsPerson($smallMember, $smallTeam);
        $this->get($smallUrl)->assertOk();
    });

    $large = countDealTaskQueries(function () use ($largeMember, $largeTeam, $largeUrl): void {
        $this->actingAsPerson($largeMember, $largeTeam);
        $this->get($largeUrl)->assertOk();
    });

    expect($large)->toBe($small);
});

/**
 * The other half of #78's lesson: *"a relation nothing renders is a relation
 * nothing thinks to seed."*
 *
 * The deal header carries an open-task count on **every** deal tab, and every
 * one of those tabs loads a different set of relations — so the count either
 * reads a loaded relation or pays a `loadCount`, and which of the two happens
 * depends on the screen. The growth test above never exercises the second
 * branch, because the tasks tab always has the relation loaded.
 *
 * **Two fixtures of the same size, differing only in whether the deal has any
 * tasks**, measured through a tab that does not load them. The first version
 * of this built sizes 1 and 5 — both populated — and called them `$empty` and
 * `$full`, which is the shape of test that cannot fail for the reason its own
 * docblock gives. Review on #71 caught it.
 */
it('costs the same on a tab that does not load tasks, with tasks or without', function (): void {
    [$emptyTeam, $emptyMember, $emptyUrl] = tasksBudgetFixture(3, withTasks: false);
    [$fullTeam, $fullMember, $fullUrl] = tasksBudgetFixture(3, withTasks: true);

    $empty = countDealTaskQueries(function () use ($emptyMember, $emptyTeam, $emptyUrl): void {
        $this->actingAsPerson($emptyMember, $emptyTeam);
        $this->get(str_replace('/tasks', '/people', $emptyUrl))->assertOk();
    });

    $full = countDealTaskQueries(function () use ($fullMember, $fullTeam, $fullUrl): void {
        $this->actingAsPerson($fullMember, $fullTeam);
        $this->get(str_replace('/tasks', '/people', $fullUrl))->assertOk();
    });

    expect($full)->toBe($empty);
});

/**
 * And the control that makes the pair above mean something: the deal with no
 * tasks really is empty, and the one with tasks really is not.
 *
 * Without this, both fixtures could quietly seed nothing — which is exactly
 * how the first version passed.
 */
it('builds one fixture with tasks and one without', function (): void {
    [$emptyTeam, , , $emptyDeal] = tasksBudgetFixture(3, withTasks: false);
    [$fullTeam, , , $fullDeal] = tasksBudgetFixture(3, withTasks: true);

    $countFor = fn ($team, Deal $deal): int => app(TeamContext::class)->runFor(
        $team,
        fn (): int => Task::query()->where('deal_id', $deal->getKey())->count(),
    );

    expect($countFor($emptyTeam, $emptyDeal))->toBe(0)
        ->and($countFor($fullTeam, $fullDeal))->toBeGreaterThan(20);
});
