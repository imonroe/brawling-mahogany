<?php

declare(strict_types=1);

use App\Enums\PersonLifecycleState;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\Person;
use App\Models\Stage;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Support\Tenancy\TeamContext;

/**
 * The tenant boundary around S17 (issue #71 · ADR 0002).
 *
 * `tasks` sits inside all five layers, so the routes mostly confirm rather
 * than enforce. Two things here are genuine enforcement, and both are places
 * the layers do not reach:
 *
 * - **The nesting.** Two tasks in one team both pass the global scope and both
 *   satisfy the policy; only `Route::scopeBindings()` answers *whose deal*.
 * - **`assignee_id`.** It points at `people`, which carries **no `team_id`** —
 *   the table holds credentials and nothing a team types (CLAUDE.md, *"the
 *   hole the layers do not cover"*). So the global scope protects every other
 *   foreign key on the row and not this one, and the only thing between the
 *   column and another team's person id is the rule in
 *   `ResolvesTaskFields`.
 *
 * Every refusal is paired with the same actor succeeding on their own row. A
 * 404 proved without that control passes whether or not the check exists.
 */
beforeEach(function (): void {
    [$this->teamA, $this->memberA] = $this->teamWithMember();
    [$this->teamB, $this->memberB] = $this->teamWithMember();
});

/** A deal with one stage and one task, in the given team. */
function taskFixtureFor(Team $team): array
{
    return app(TeamContext::class)->runFor($team, function () use ($team): array {
        $type = DealType::factory()->create(['team_id' => $team->getKey()]);

        $deal = Deal::factory()->create([
            'team_id' => $team->getKey(),
            'deal_type_id' => $type->getKey(),
        ]);

        $workflow = Workflow::factory()->create([
            'team_id' => $team->getKey(),
            'deal_id' => $deal->getKey(),
        ]);

        $stage = Stage::factory()->create([
            'team_id' => $team->getKey(),
            'workflow_id' => $workflow->getKey(),
            'sort_order' => 0,
        ]);

        $task = Task::factory()->create([
            'team_id' => $team->getKey(),
            'deal_id' => $deal->getKey(),
            'stage_id' => $stage->getKey(),
            'title' => 'Order the sign',
        ]);

        return [$deal, $stage, $task];
    });
}

it('404s another team’s deal on every S17 route', function (): void {
    [$foreignDeal, , $foreignTask] = taskFixtureFor($this->teamB);
    [$ownDeal, $ownStage, $ownTask] = taskFixtureFor($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    // The control: their own deal answers all of this.
    $this->get("/deals/{$ownDeal->getKey()}/tasks")->assertOk();
    $this->post("/deals/{$ownDeal->getKey()}/tasks", [
        'title' => 'Theirs to do',
        'stage_id' => $ownStage->getKey(),
    ])->assertRedirect();
    $this->post("/deals/{$ownDeal->getKey()}/tasks/{$ownTask->getKey()}/completion")->assertRedirect();

    /*
     * 404, not 403. ADR 0002 layer 3: a 403 confirms the record exists, which
     * is a disclosure in itself.
     */
    $this->get("/deals/{$foreignDeal->getKey()}/tasks")->assertNotFound();
    $this->post("/deals/{$foreignDeal->getKey()}/tasks", ['title' => 'Not mine'])->assertNotFound();
    $this->patch("/deals/{$foreignDeal->getKey()}/tasks/{$foreignTask->getKey()}", [
        'title' => 'Renamed',
    ])->assertNotFound();
    $this->post("/deals/{$foreignDeal->getKey()}/tasks/{$foreignTask->getKey()}/completion")
        ->assertNotFound();
    $this->delete("/deals/{$foreignDeal->getKey()}/tasks/{$foreignTask->getKey()}/completion")
        ->assertNotFound();
    $this->delete("/deals/{$foreignDeal->getKey()}/tasks/{$foreignTask->getKey()}")->assertNotFound();

    // Untouched by any of it.
    $foreignTask->refresh();

    expect($foreignTask->title)->toBe('Order the sign')
        ->and($foreignTask->isComplete())->toBeFalse()
        ->and($foreignTask->trashed())->toBeFalse();
});

it('404s a task reached through the wrong deal in the same team', function (): void {
    [$dealOne, , $taskOne] = taskFixtureFor($this->teamA);
    [$dealTwo] = taskFixtureFor($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    // The control: through its own deal, the same task answers.
    $this->post("/deals/{$dealOne->getKey()}/tasks/{$taskOne->getKey()}/completion")->assertRedirect();

    /*
     * Both rows are in the acting team, so the global scope has no objection
     * and `TaskPolicy` is asked about a task that really does belong to the
     * team. Only the nesting refuses this.
     */
    $this->delete("/deals/{$dealTwo->getKey()}/tasks/{$taskOne->getKey()}/completion")->assertNotFound();
    $this->patch("/deals/{$dealTwo->getKey()}/tasks/{$taskOne->getKey()}", ['title' => 'Renamed'])
        ->assertNotFound();
    $this->delete("/deals/{$dealTwo->getKey()}/tasks/{$taskOne->getKey()}")->assertNotFound();

    expect($taskOne->refresh()->isComplete())->toBeTrue()
        ->and($taskOne->title)->toBe('Order the sign');
});

it('refuses a stage from another team, and one from another deal', function (): void {
    [, $foreignStage] = taskFixtureFor($this->teamB);
    [$ownDeal, $ownStage] = taskFixtureFor($this->teamA);
    [, $siblingStage] = taskFixtureFor($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->post("/deals/{$ownDeal->getKey()}/tasks", [
        'title' => 'Fine',
        'stage_id' => $ownStage->getKey(),
    ])->assertRedirect();

    foreach ([$foreignStage, $siblingStage] as $stage) {
        $this->post("/deals/{$ownDeal->getKey()}/tasks", [
            'title' => 'Filed somewhere else',
            'stage_id' => $stage->getKey(),
        ])->assertSessionHasErrors('stage_id');
    }

    expect(Task::query()->where('deal_id', $ownDeal->getKey())->count())->toBe(2);
});

it('refuses an assignee from another team, on the column no scope protects', function (): void {
    [$ownDeal] = taskFixtureFor($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    // The control: a colleague in this team is assignable.
    $this->post("/deals/{$ownDeal->getKey()}/tasks", [
        'title' => 'Ours',
        'assignee_id' => $this->memberA->getKey(),
    ])->assertRedirect();

    /*
     * `people` has no `team_id`, so this id is a perfectly valid row and the
     * database would take it. What refuses it is the rule that asks
     * `team_memberships` — the table where the five layers do reach.
     */
    $this->post("/deals/{$ownDeal->getKey()}/tasks", [
        'title' => 'Theirs',
        'assignee_id' => $this->memberB->getKey(),
    ])->assertSessionHasErrors('assignee_id');

    expect(Task::query()->where('assignee_id', $this->memberB->getKey())->count())->toBe(0);
});

it('does not name another team’s people in the assignee picker', function (): void {
    [$ownDeal] = taskFixtureFor($this->teamA);

    app(TeamContext::class)->runFor($this->teamB, fn () => TeamMembership::query()->create([
        'team_id' => $this->teamB->getKey(),
        'person_id' => Person::factory()->create()->getKey(),
        'first_name' => 'Secret',
        'last_name' => 'Colleague',
        'status' => PersonLifecycleState::Active,
        'joined_at' => now(),
    ]));

    $this->actingAsPerson($this->memberA, $this->teamA);

    /*
     * A name is customer data. The picker is a list of names, so it is a
     * disclosure surface like any list screen — and this one is assembled from
     * `team_memberships`, which is where the global scope answers.
     */
    $this->get("/deals/{$ownDeal->getKey()}/tasks")
        ->assertOk()
        ->assertInertia(fn ($page) => expect(
            collect($page->toArray()['props']['assignees'])->pluck('name'),
        )->not->toContain('Secret Colleague'));
});
