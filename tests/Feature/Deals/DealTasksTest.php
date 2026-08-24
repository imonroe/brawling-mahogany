<?php

declare(strict_types=1);

use App\Enums\PersonLifecycleState;
use App\Enums\StageState;
use App\Enums\SystemRole;
use App\Enums\TaskSource;
use App\Enums\WorkflowState;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\Gate;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Stage;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Support\Tenancy\TeamContext;

/**
 * S17, S27 — a deal's tasks (PRD §4.4 F4.10 · §7.10 · issue #71).
 *
 * #71's key states organise most of this file — empty, grouped by stage,
 * overdue, unassigned — and three properties are worth more than the rest:
 *
 * - **Completing the last required task on a stage clears its gate**, which is
 *   the Definition of Done's own sentence and the reason this screen is P0.
 *   Until it existed the only way past `required_tasks_complete` was an
 *   override, which IA §7 reserves for the case where the condition should
 *   have been met and was not.
 * - **Nothing here advances anything.** Clearing the requirement makes the
 *   advance possible; somebody still presses Advance.
 * - **Completion is idempotent.** Two people on the same checkbox must not put
 *   the work in the feed twice.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();

    $this->actingAsPerson($this->member, $this->team);
});

/**
 * A deal with one running workflow and `$count` stages, the first active.
 *
 * Built directly rather than through `InstantiateWorkflow`, for the reason
 * `DealTimelineTest` gives: a failure here should be telling you about the
 * task list.
 *
 * @return array{0: Deal, 1: Workflow, 2: Illuminate\Support\Collection<int, Stage>}
 */
function taskDeal(int $count = 3, string $workflowName = 'Listing to Close'): array
{
    $team = test()->team;

    $type = DealType::factory()->create(['team_id' => $team->getKey()]);

    $deal = Deal::factory()->create([
        'team_id' => $team->getKey(),
        'deal_type_id' => $type->getKey(),
    ]);

    $workflow = Workflow::factory()->create([
        'team_id' => $team->getKey(),
        'deal_id' => $deal->getKey(),
        'name' => $workflowName,
        'state' => WorkflowState::Active,
    ]);

    $stages = collect(range(0, $count - 1))->map(function (int $index) use ($team, $workflow): Stage {
        $factory = Stage::factory();

        return ($index === 0 ? $factory->active() : $factory)->create([
            'team_id' => $team->getKey(),
            'workflow_id' => $workflow->getKey(),
            'name' => "Stage {$index}",
            'sort_order' => $index,
        ]);
    });

    $workflow->forceFill(['current_stage_id' => $stages->first()->getKey()])->save();

    return [$deal, $workflow->fresh(), $stages];
}

/** A task on a deal, optionally under a stage. */
function taskOn(Deal $deal, ?Stage $stage, array $attributes = []): Task
{
    return Task::factory()->create([
        'team_id' => $deal->team_id,
        'deal_id' => $deal->getKey(),
        'stage_id' => $stage?->getKey(),
        ...$attributes,
    ]);
}

/** Somebody in this team who may read a deal and may not change one. */
function readOnlyMember(Team $team): Person
{
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
            'key' => 'reads_deals',
            'name' => 'Reads Deals',
        ]);

        $role->permissions()->sync(
            Permission::query()->where('key', 'deals.view')->pluck('id')->all(),
        );

        $membership->roles()->attach($role->getKey());
    });

    return $person;
}

/** Another colleague, so assignment has somebody to name. */
function colleague(string $first, string $last): TeamMembership
{
    return app(TeamContext::class)->runFor(test()->team, function () use ($first, $last): TeamMembership {
        $membership = TeamMembership::query()->create([
            'team_id' => test()->team->getKey(),
            'person_id' => Person::factory()->create()->getKey(),
            'first_name' => $first,
            'last_name' => $last,
            'status' => PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        $membership->roles()->attach(
            Role::query()->whereNull('team_id')
                ->where('key', SystemRole::TeamMember->value)->sole()->getKey(),
        );

        return $membership;
    });
}

/* -------------------------------------------------------------------------
 * The screen (#71's key states)
 * ---------------------------------------------------------------------- */

it('says what belongs here when a deal has no tasks at all', function (): void {
    [$deal] = taskDeal();

    $this->get("/deals/{$deal->getKey()}/tasks")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Deals/Tasks')
            ->has('groups', 0)
            ->where('counts.open', 0)
            ->where('counts.all', 0));
});

it('groups tasks by stage, in workflow order', function (): void {
    [$deal, , $stages] = taskDeal(3);

    taskOn($deal, $stages[2], ['title' => 'Third']);
    taskOn($deal, $stages[0], ['title' => 'First']);

    $this->get("/deals/{$deal->getKey()}/tasks")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Stage 1 has nothing on it and is not a group: eighteen empty
            // headers is a page somebody scrolls past to find the two that
            // matter.
            ->has('groups', 2)
            ->where('groups.0.stageName', 'Stage 0')
            ->where('groups.0.tasks.0.title', 'First')
            ->where('groups.1.stageName', 'Stage 2')
            // Named on every group, because F4.7 makes two workflows ordinary
            // and a stage name means different things under each.
            ->where('groups.0.workflowName', 'Listing to Close')
            // A record fact — where the workflow is — not an evaluation.
            ->where('groups.0.isCurrent', true)
            ->where('groups.1.isCurrent', false));
});

it('puts the tasks that belong to no stage in their own group, last', function (): void {
    [$deal, , $stages] = taskDeal(2);

    taskOn($deal, null, ['title' => 'Chase the survey']);
    taskOn($deal, $stages[0], ['title' => 'Order the sign']);

    $this->get("/deals/{$deal->getKey()}/tasks")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('groups', 2)
            ->where('groups.0.stageName', 'Stage 0')
            // PRD §6.4 makes `stage_id` nullable so an ad-hoc job can live on
            // the deal outside any stage. It has no place in the sequence, so
            // it goes after it.
            ->where('groups.1.key', 'unstaged')
            ->where('groups.1.stageName', null)
            ->where('groups.1.tasks.0.title', 'Chase the survey'));
});

it('sorts a group by urgency, with the undated below the dated', function (): void {
    [$deal, , $stages] = taskDeal(1);

    taskOn($deal, $stages[0], ['title' => 'Someday', 'due_date' => null, 'sort_order' => 0]);
    taskOn($deal, $stages[0], ['title' => 'Next week', 'due_date' => now()->addWeek(), 'sort_order' => 1]);
    taskOn($deal, $stages[0], ['title' => 'Late', 'due_date' => now()->subDay(), 'sort_order' => 2]);
    taskOn($deal, $stages[0], [
        'title' => 'Done',
        'due_date' => now()->subWeek(),
        'sort_order' => 3,
        'completed_at' => now(),
    ]);

    $this->get("/deals/{$deal->getKey()}/tasks")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('groups.0.tasks.0.title', 'Late')
            ->where('groups.0.tasks.1.title', 'Next week')
            // Undated is not urgent, it is unscheduled — a different thing,
            // and it belongs under the dated work rather than above it where
            // a null would sort.
            ->where('groups.0.tasks.2.title', 'Someday')
            ->where('groups.0.tasks.3.title', 'Done'));
});

it('derives overdue rather than storing it', function (): void {
    [$deal, , $stages] = taskDeal(1);

    taskOn($deal, $stages[0], ['title' => 'Late', 'due_date' => now()->subDay()]);
    taskOn($deal, $stages[0], ['title' => 'Soon', 'due_date' => now()->addDay()]);
    // Completed a week after it was due. Late, and not *overdue*: there is
    // nothing left to do, and `Task::state()` says the same.
    taskOn($deal, $stages[0], [
        'title' => 'Late but done',
        'due_date' => now()->subWeek(),
        'completed_at' => now(),
    ]);

    $this->get("/deals/{$deal->getKey()}/tasks")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('counts.overdue', 1)
            ->where('groups.0.tasks.0.state', 'overdue')
            ->where('groups.0.tasks.1.state', 'open')
            ->where('groups.0.tasks.2.state', 'completed'));
});

it('counts what is unassigned, and names who is assigned', function (): void {
    [$deal, , $stages] = taskDeal(1);

    $heather = colleague('Heather', 'Nguyen');

    taskOn($deal, $stages[0], ['title' => 'Mine', 'assignee_id' => $heather->person_id]);
    taskOn($deal, $stages[0], ['title' => 'Nobody’s']);

    $this->get("/deals/{$deal->getKey()}/tasks")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // #71: unassigned is a visible state, not a silent default.
            ->where('counts.unassigned', 1)
            ->where('groups.0.tasks.0.assigneeName', 'Heather Nguyen')
            ->where('groups.0.tasks.1.assigneeName', null));
});

it('offers only colleagues who still work here as assignees', function (): void {
    [$deal] = taskDeal(1);

    $heather = colleague('Heather', 'Nguyen');
    colleague('Gone', 'Away')->revoke();

    /*
     * `tasks.assignee_id` points at `people`, which carries no `team_id`, so
     * nothing else in the stack narrows this list — see `App\Queries\
     * TaskAssignees`. A revoked colleague keeps the tasks already assigned to
     * them and cannot be given a new one.
     */
    $this->get("/deals/{$deal->getKey()}/tasks")
        ->assertOk()
        ->assertInertia(function ($page) use ($heather): void {
            $names = collect($page->toArray()['props']['assignees'])->pluck('name');

            expect($names)->toContain('Heather Nguyen')
                ->not->toContain('Gone Away');

            expect(collect($page->toArray()['props']['assignees'])->pluck('id'))
                ->toContain((string) $heather->person_id);
        });
});

/* -------------------------------------------------------------------------
 * Adding, editing, deleting
 * ---------------------------------------------------------------------- */

it('adds a task to a stage, at the end of its checklist', function (): void {
    [$deal, , $stages] = taskDeal(1);

    taskOn($deal, $stages[0], ['title' => 'Already here', 'sort_order' => 4]);

    $this->post("/deals/{$deal->getKey()}/tasks", [
        'title' => 'Order the sign',
        'stage_id' => $stages[0]->getKey(),
        'is_required' => true,
    ])->assertRedirect("/deals/{$deal->getKey()}/tasks");

    $task = Task::query()->where('title', 'Order the sign')->sole();

    expect($task->stage_id)->toBe($stages[0]->getKey())
        ->and($task->is_required)->toBeTrue()
        // Typed by a person, and the column exists to be able to say so.
        ->and($task->source)->toBe(TaskSource::Manual)
        // A checklist is a sequence: a task added by hand goes on the end
        // rather than into the middle of somebody's procedure.
        ->and($task->sort_order)->toBe(5);

    expect(ActivityEvent::query()->where('event_type', 'task.added')->count())->toBe(1);
});

it('adds a task that belongs to no stage', function (): void {
    [$deal] = taskDeal(1);

    $this->post("/deals/{$deal->getKey()}/tasks", [
        'title' => 'Chase the survey',
        'stage_id' => '',
    ])->assertRedirect("/deals/{$deal->getKey()}/tasks");

    expect(Task::query()->sole()->stage_id)->toBeNull();
});

it('refuses a stage that belongs to another deal in the same team', function (): void {
    [$deal] = taskDeal(1);
    [, , $otherStages] = taskDeal(1);

    /*
     * Both stages are in the acting team, so the global scope has no
     * objection: only asking "whose deal" refuses this.
     */
    $this->post("/deals/{$deal->getKey()}/tasks", [
        'title' => 'Somebody else’s work',
        'stage_id' => $otherStages[0]->getKey(),
    ])->assertSessionHasErrors('stage_id');

    expect(Task::query()->count())->toBe(0);
});

it('refuses an assignee who is not somebody this team can hand work to', function (): void {
    [$deal] = taskDeal(1);

    // A contact: a person the team knows, with no access to the application.
    $contact = app(TeamContext::class)->runFor($this->team, function (): TeamMembership {
        return TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => Person::factory()->create()->getKey(),
            'first_name' => 'Client',
            'last_name' => 'Person',
            'status' => PersonLifecycleState::Active,
        ]);
    });

    $this->post("/deals/{$deal->getKey()}/tasks", [
        'title' => 'Not theirs to do',
        'assignee_id' => $contact->person_id,
    ])->assertSessionHasErrors('assignee_id');
});

it('edits a task, and re-ranks it when it moves stage', function (): void {
    [$deal, , $stages] = taskDeal(2);

    $task = taskOn($deal, $stages[0], ['title' => 'Order the sign', 'sort_order' => 0]);
    taskOn($deal, $stages[1], ['title' => 'Already there', 'sort_order' => 7]);

    $this->patch("/deals/{$deal->getKey()}/tasks/{$task->getKey()}", [
        'title' => 'Order the sign and the rider',
        'stage_id' => $stages[1]->getKey(),
        'due_date' => '2026-09-01',
    ])->assertRedirect("/deals/{$deal->getKey()}/tasks");

    $task->refresh();

    expect($task->title)->toBe('Order the sign and the rider')
        ->and($task->stage_id)->toBe($stages[1]->getKey())
        ->and($task->due_date->toDateString())->toBe('2026-09-01')
        // `sort_order` is a position *within* a group, so carrying the old one
        // across lands the task in the middle of a checklist it was never on.
        ->and($task->sort_order)->toBe(8);
});

it('leaves a task where it is when an edit does not mention the stage', function (): void {
    [$deal, , $stages] = taskDeal(2);

    $task = taskOn($deal, $stages[0], ['title' => 'Order the sign']);

    $this->patch("/deals/{$deal->getKey()}/tasks/{$task->getKey()}", [
        'title' => 'Order the sign today',
    ])->assertRedirect("/deals/{$deal->getKey()}/tasks");

    expect($task->refresh()->stage_id)->toBe($stages[0]->getKey());
});

it('deletes a task softly, so PRD §9’s window covers a mistake', function (): void {
    [$deal, , $stages] = taskDeal(1);

    $task = taskOn($deal, $stages[0], ['title' => 'Not needed']);

    $this->delete("/deals/{$deal->getKey()}/tasks/{$task->getKey()}")
        ->assertRedirect("/deals/{$deal->getKey()}/tasks");

    expect(Task::query()->count())->toBe(0)
        ->and(Task::withTrashed()->count())->toBe(1);

    expect(ActivityEvent::query()->where('event_type', 'task.deleted')->count())->toBe(1);
});

/* -------------------------------------------------------------------------
 * Completing — the point of the screen
 * ---------------------------------------------------------------------- */

it('completes a task, recording who ticked it', function (): void {
    [$deal, , $stages] = taskDeal(1);

    $task = taskOn($deal, $stages[0], ['title' => 'Order the sign']);

    $this->post("/deals/{$deal->getKey()}/tasks/{$task->getKey()}/completion")
        ->assertRedirect("/deals/{$deal->getKey()}/tasks");

    $task->refresh();

    expect($task->isComplete())->toBeTrue()
        // Not the assignee: §7.3's meta line inside a deal is the completion
        // attribution, and the two are frequently different people.
        ->and($task->completed_by)->toBe($this->member->getKey());

    $event = ActivityEvent::query()->where('event_type', 'task.completed')->sole();

    expect($event->summary)->toContain('Order the sign')
        // The deal, so the feed and the deal's own timeline both find it.
        ->and($event->deal_id)->toBe($deal->getKey());
});

it('records one completion however many times the box is ticked', function (): void {
    [$deal, , $stages] = taskDeal(1);

    $task = taskOn($deal, $stages[0], ['title' => 'Order the sign']);

    $this->post("/deals/{$deal->getKey()}/tasks/{$task->getKey()}/completion");
    $this->post("/deals/{$deal->getKey()}/tasks/{$task->getKey()}/completion");

    /*
     * Two people on the same checkbox, or one person on a stale tab. Reporting
     * the work twice would attribute it to whoever was second.
     */
    expect(ActivityEvent::query()->where('event_type', 'task.completed')->count())->toBe(1);
});

it('reopens a task, and says so rather than quietly', function (): void {
    [$deal, , $stages] = taskDeal(1);

    $task = taskOn($deal, $stages[0], ['title' => 'Order the sign', 'completed_at' => now()]);

    $this->delete("/deals/{$deal->getKey()}/tasks/{$task->getKey()}/completion")
        ->assertRedirect("/deals/{$deal->getKey()}/tasks");

    $task->refresh();

    expect($task->isComplete())->toBeFalse()
        ->and($task->completed_by)->toBeNull();

    /*
     * A completion is already in the feed saying the work is done. Reopening
     * silently would leave the record asserting something the team has since
     * decided is not true.
     */
    expect(ActivityEvent::query()->where('event_type', 'task.reopened')->count())->toBe(1);
});

it('clears the required-tasks gate when the last required task is completed', function (): void {
    /*
     * The Definition of Done, verbatim: *"Completing the last required task on
     * a stage causes that gate to evaluate as met."*
     *
     * Asserted through an **advance**, not by reading `gates.is_met`. The
     * column is a cache that only an advance attempt refreshes, and what the
     * team actually cares about is whether the deal can move.
     */
    [$deal, $workflow, $stages] = taskDeal(2);

    Gate::factory()->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $stages[0]->getKey(),
        'gate_type' => 'required_tasks_complete',
        'label' => 'The listing paperwork is done',
        'is_blocking' => true,
    ]);

    $required = taskOn($deal, $stages[0], ['title' => 'Sign the listing agreement', 'is_required' => true]);
    // Not required, and still open at the end: a task is work owed, a gate is
    // a condition on advancement, and F4.10 keeps them apart.
    taskOn($deal, $stages[0], ['title' => 'Post it on social', 'is_required' => false]);

    $advance = "/deals/{$deal->getKey()}/workflows/{$workflow->getKey()}/advance";

    $this->post($advance, ['stage_id' => $stages[0]->getKey()]);

    expect($workflow->refresh()->current_stage_id)->toBe($stages[0]->getKey());

    $this->post("/deals/{$deal->getKey()}/tasks/{$required->getKey()}/completion");

    // Completing does not advance. Somebody still presses Advance.
    expect($workflow->refresh()->current_stage_id)->toBe($stages[0]->getKey());

    $this->post($advance, ['stage_id' => $stages[0]->getKey()]);

    expect($workflow->refresh()->current_stage_id)->toBe($stages[1]->getKey());
    expect($stages[0]->refresh()->state)->toBe(StageState::Complete);
});

/* -------------------------------------------------------------------------
 * Permission
 * ---------------------------------------------------------------------- */

it('lets somebody who may only read a deal read the checklist', function (): void {
    [$deal, , $stages] = taskDeal(1);

    $task = taskOn($deal, $stages[0], ['title' => 'Order the sign']);

    $this->actingAsPerson(readOnlyMember($this->team), $this->team);

    // PRD §4.2 F2.2's Read Only role: a broker who watches the pipeline sees
    // the checklist and cannot tick it.
    $this->get("/deals/{$deal->getKey()}/tasks")->assertOk();

    $this->post("/deals/{$deal->getKey()}/tasks/{$task->getKey()}/completion")->assertForbidden();
    $this->post("/deals/{$deal->getKey()}/tasks", ['title' => 'Mine now'])->assertForbidden();
    $this->delete("/deals/{$deal->getKey()}/tasks/{$task->getKey()}")->assertForbidden();

    expect($task->refresh()->isComplete())->toBeFalse();
});

/* -------------------------------------------------------------------------
 * The header count
 * ---------------------------------------------------------------------- */

it('counts what is open on the Tasks tab, not what the deal holds', function (): void {
    [$deal, , $stages] = taskDeal(1);

    taskOn($deal, $stages[0], ['title' => 'Open one']);
    taskOn($deal, $stages[0], ['title' => 'Done one', 'completed_at' => now()]);

    /*
     * A seeded pack puts eighty tasks on a deal, and a tab reading `80` when
     * all eighty are done says the opposite of what happened. Asserted from
     * another tab as well, because the count comes from the shared header
     * payload and every tab has to agree.
     */
    $this->get("/deals/{$deal->getKey()}/tasks")
        ->assertInertia(fn ($page) => $page->where('dealHeader.counts.tasks', 1));

    $this->get("/deals/{$deal->getKey()}/people")
        ->assertInertia(fn ($page) => $page->where('dealHeader.counts.tasks', 1));
});
