<?php

declare(strict_types=1);

use App\Models\Deal;
use App\Models\Person;
use App\Models\Stage;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Support\Tenancy\TeamContext;

/**
 * S11 — My Work (PRD F9.2 · #80).
 *
 * *"Every task assigned to me across all deals, ordered by urgency."* The
 * three things this screen can get wrong are whose tasks it shows, what order
 * it shows them in, and which day it thinks it is — so those are what is
 * pinned here.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);
});

/** A task on a new deal, assigned to somebody. */
function workTask(array $attributes = [], ?Person $assignee = null, ?string $dealName = null): Task
{
    $team = test()->team;

    return app(TeamContext::class)->runFor($team, function () use ($team, $attributes, $assignee, $dealName): Task {
        $deal = Deal::factory()->create([
            'team_id' => $team->getKey(),
            'name' => $dealName ?? 'A deal',
        ]);

        $workflow = Workflow::factory()->create([
            'team_id' => $team->getKey(),
            'deal_id' => $deal->getKey(),
        ]);

        $stage = Stage::factory()->active()->create([
            'team_id' => $team->getKey(),
            'workflow_id' => $workflow->getKey(),
        ]);

        return Task::factory()->create([
            'team_id' => $team->getKey(),
            'deal_id' => $deal->getKey(),
            'stage_id' => $stage->getKey(),
            'assignee_id' => ($assignee ?? test()->member)->getKey(),
            ...$attributes,
        ]);
    });
}

it('shows only the tasks assigned to the person reading it', function (): void {
    $somebodyElse = Person::factory()->create();

    workTask(['title' => 'Mine'], dealName: 'My deal');
    workTask(['title' => 'Theirs'], assignee: $somebodyElse, dealName: 'Their deal');

    $this->get('/work')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $titles = collect($page->toArray()['props']['groups'])
                ->flatMap(fn (array $group): array => array_column($group['tasks'], 'title'));

            expect($titles)->toContain('Mine')->not->toContain('Theirs');
        });
});

it('puts the soonest thing first, and the undated below the dated', function (): void {
    /*
     * A task with no date is not urgent — it is **unscheduled**, which is a
     * different thing and belongs under the dated ones rather than at the top
     * where a null would sort it. The same comparator S17's tab uses.
     */
    workTask(['title' => 'Someday', 'due_date' => null], dealName: 'One deal');
    workTask(['title' => 'Next week', 'due_date' => now()->addWeek()], dealName: 'One deal');
    workTask(['title' => 'Tomorrow', 'due_date' => now()->addDay()], dealName: 'One deal');

    $this->get('/work')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $titles = collect($page->toArray()['props']['groups'])
                ->flatMap(fn (array $group): array => array_column($group['tasks'], 'title'))
                ->all();

            expect($titles)->toBe(['Tomorrow', 'Next week', 'Someday']);
        });
});

it('puts the deal holding the most urgent thing at the top', function (): void {
    // Grouping by deal and ordering by urgency are not in tension: sort the
    // rows once and the groups fall out in that order.
    workTask(['title' => 'Later', 'due_date' => now()->addMonth()], dealName: 'Quiet deal');
    workTask(['title' => 'Today', 'due_date' => now()], dealName: 'Urgent deal');

    $this->get('/work')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $names = array_column($page->toArray()['props']['groups'], 'dealName');

            expect($names[0])->toBe('Urgent deal');
        });
});

it('counts what is open, overdue, and how many deals it is spread across', function (): void {
    $this->freezeAt('2026-08-25 12:00:00');

    workTask(['title' => 'Late', 'due_date' => '2026-08-20'], dealName: 'One');
    workTask(['title' => 'Ahead', 'due_date' => '2026-09-20'], dealName: 'Two');
    workTask(['title' => 'Done', 'completed_at' => now()], dealName: 'Three');

    $this->get('/work')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('counts.open', 2)
            ->where('counts.overdue', 1)
            ->where('counts.all', 3)
            ->where('counts.deals', 2));
});

it('narrows to the overdue when asked, and hides them from nobody by default', function (): void {
    $this->freezeAt('2026-08-25 12:00:00');

    workTask(['title' => 'Late', 'due_date' => '2026-08-20'], dealName: 'One');
    workTask(['title' => 'Ahead', 'due_date' => '2026-09-20'], dealName: 'Two');

    $this->get('/work?segment=overdue')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $titles = collect($page->toArray()['props']['groups'])
                ->flatMap(fn (array $group): array => array_column($group['tasks'], 'title'));

            expect($titles)->toContain('Late')->not->toContain('Ahead');
        });

    // A stale bookmark is not an attack: an unknown segment falls back to the
    // question the screen exists to answer.
    $this->get('/work?segment=nonsense')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('segment', 'open'));
});

it('reads today in the team’s calendar, not the server’s', function (): void {
    /*
     * The one screen that cannot be wrong about which day it is is the one
     * Heather opens first. 01:00 UTC on the 25th is still the 24th in Denver,
     * so a task due the 24th is due **today** — and today is not overdue.
     */
    $this->team->forceFill(['timezone' => 'America/Denver'])->save();
    $this->freezeAt('2026-08-25 01:00:00');

    workTask(['title' => 'Due today in Denver', 'due_date' => '2026-08-24'], dealName: 'One');

    $this->get('/work')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('counts.overdue', 0));
});

it('sends a due date as a day', function (): void {
    // #165: a `date` column is a day. Sent as one, and read as one.
    workTask(['title' => 'Inspection', 'due_date' => '2026-09-01'], dealName: 'One');

    $this->get('/work')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('groups.0.tasks.0.dueDate', '2026-09-01'));
});

it('carries the count the whole shell renders', function (): void {
    /*
     * Design System §10.4 puts this beside the My Work link on **every**
     * screen, so it is shared from the middleware rather than supplied by this
     * page — a number that is only right on `/work` is wrong everywhere else,
     * which is worse than no number.
     */
    workTask(['title' => 'Open one'], dealName: 'One');
    workTask(['title' => 'Done one', 'completed_at' => now()], dealName: 'Two');

    $this->get('/deals')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('counts.myWork', 1));
});

it('keeps another team’s tasks out of it', function (): void {
    [$otherTeam] = $this->teamWithMember();

    app(TeamContext::class)->runFor($otherTeam, function () use ($otherTeam): void {
        $deal = Deal::factory()->create(['team_id' => $otherTeam->getKey()]);

        $membership = TeamMembership::query()->create([
            'team_id' => $otherTeam->getKey(),
            'person_id' => $this->member->getKey(),
            'first_name' => 'Same',
            'last_name' => 'Person',
            'joined_at' => now(),
        ]);

        Task::factory()->create([
            'team_id' => $otherTeam->getKey(),
            'deal_id' => $deal->getKey(),
            'title' => 'Another team’s work',
            'assignee_id' => $this->member->getKey(),
        ]);

        unset($membership);
    });

    workTask(['title' => 'Mine here'], dealName: 'Mine');

    $this->get('/work')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $titles = collect($page->toArray()['props']['groups'])
                ->flatMap(fn (array $group): array => array_column($group['tasks'], 'title'));

            expect($titles)->toContain('Mine here')->not->toContain('Another team’s work');
        });
});
