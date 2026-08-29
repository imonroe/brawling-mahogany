<?php

declare(strict_types=1);

use App\Models\GateTemplate;
use App\Models\StageTemplate;
use App\Models\TaskTemplate;
use App\Models\TeamMembership;
use App\Models\WorkflowTemplate;
use App\Support\Tenancy\TeamContext;

/**
 * The writers #87 needed and S41 did not have (#11 · #154).
 *
 * `TemplateEditingTest` covers the editor as it was: add a stage, add a gate,
 * add a task, remove any of them, and the refusal that keeps a pack's template
 * uneditable all the way down. This is the half that was missing, and the
 * reason it was missing matters more than the routes:
 *
 * `task_templates.owner_role` and `stage_templates.owner_role` had a reader
 * and **no writer at all** — `InstantiateWorkflow` resolves one to a person,
 * `CopyTemplate` carries it, and nothing in `app/` could put a role there. A
 * task's `is_required`, `description` and `due_offset_days` could be set once
 * on creation and never corrected. Those are exactly the four columns #11
 * lists as missing from #154's checklist, which is why that markup pass was
 * being gathered in a GitHub comment: the screen could not take it.
 *
 * So each test here asserts a **column somebody can now change**, not a route
 * that answers 302.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();

    // `templates.manage` belongs to Team Owner, not Team Member — IA §7's
    // separation showing up in the seeded roles.
    $owner = app(TeamContext::class)->runFor($this->team, fn (): TeamMembership => TeamMembership::query()
        ->whereHas('roles', fn ($query) => $query->where('roles.key', 'team_owner'))
        ->sole());

    $this->actingAsPerson($this->enrollTwoFactor($owner->person), $this->team);
});

function authoredTemplate(): WorkflowTemplate
{
    $team = test()->team;

    return app(TeamContext::class)->runFor($team, function () use ($team): WorkflowTemplate {
        $template = WorkflowTemplate::factory()->create([
            'team_id' => $team->getKey(),
            'name' => 'Buyer Representation',
        ]);

        StageTemplate::factory()->create([
            'workflow_template_id' => $template->getKey(),
            'name' => 'Under Contract',
            'sort_order' => 0,
        ]);

        return $template;
    });
}

function soleStage(WorkflowTemplate $template): StageTemplate
{
    return StageTemplate::query()->where('workflow_template_id', $template->getKey())->sole();
}

it('records who owns a stage, as a role rather than a person', function (): void {
    $template = authoredTemplate();
    $stage = soleStage($template);

    $this->patch("/templates/{$template->getKey()}/stages/{$stage->getKey()}", [
        'name' => 'Under Contract',
        'owner_role' => 'Transaction coordinator',
    ])->assertRedirect();

    expect($stage->refresh()->owner_role)->toBe('Transaction coordinator');
});

it('edits a task in place, including the flag that decides whether it gates an advance', function (): void {
    $template = authoredTemplate();
    $stage = soleStage($template);

    $this->post("/templates/{$template->getKey()}/stages/{$stage->getKey()}/tasks", [
        'title' => 'Confirm loan application completed with lender',
    ])->assertRedirect();

    $task = TaskTemplate::query()->where('stage_template_id', $stage->getKey())->sole();

    // `is_required` defaults to false, which is the column's own default and
    // the one a pack must not guess differently: most tasks are reminders.
    expect($task->is_required)->toBeFalse();

    $this->patch(
        "/templates/{$template->getKey()}/stages/{$stage->getKey()}/tasks/{$task->getKey()}",
        [
            'title' => 'Confirm loan application completed with lender',
            'description' => 'Ask for it in writing.',
            'owner_role' => 'Transaction coordinator',
            'is_required' => true,
            'due_offset_days' => -3,
        ],
    )->assertRedirect();

    $task->refresh();

    expect($task->is_required)->toBeTrue()
        ->and($task->owner_role)->toBe('Transaction coordinator')
        ->and($task->description)->toBe('Ask for it in writing.')
        ->and($task->due_offset_days)->toBe(-3);
});

it('keeps a task in its place when it is edited', function (): void {
    /*
     * The property the edit route exists for. Correcting one flag used to mean
     * deleting the task and adding it back — and a re-added task goes to the
     * end of the list, so a markup pass over ninety of them reversed the order
     * of every one it touched.
     */
    $template = authoredTemplate();
    $stage = soleStage($template);

    foreach (['First', 'Second', 'Third'] as $title) {
        $this->post("/templates/{$template->getKey()}/stages/{$stage->getKey()}/tasks", ['title' => $title]);
    }

    $second = TaskTemplate::query()
        ->where('stage_template_id', $stage->getKey())
        ->where('title', 'Second')
        ->sole();

    $this->patch(
        "/templates/{$template->getKey()}/stages/{$stage->getKey()}/tasks/{$second->getKey()}",
        ['title' => 'Second, corrected', 'is_required' => true],
    )->assertRedirect();

    expect(TaskTemplate::query()
        ->where('stage_template_id', $stage->getKey())
        ->orderBy('sort_order')
        ->pluck('title')
        ->all())->toBe(['First', 'Second, corrected', 'Third']);
});

it('reorders tasks from a whole order rather than a swap', function (): void {
    $template = authoredTemplate();
    $stage = soleStage($template);

    foreach (['First', 'Second', 'Third'] as $title) {
        $this->post("/templates/{$template->getKey()}/stages/{$stage->getKey()}/tasks", ['title' => $title]);
    }

    $ids = TaskTemplate::query()
        ->where('stage_template_id', $stage->getKey())
        ->orderBy('sort_order')
        ->pluck('id')
        ->all();

    $this->patch("/templates/{$template->getKey()}/stages/{$stage->getKey()}/tasks", [
        'ids' => [$ids[2], $ids[0], $ids[1]],
    ])->assertRedirect();

    expect(TaskTemplate::query()
        ->where('stage_template_id', $stage->getKey())
        ->orderBy('sort_order')
        ->pluck('title')
        ->all())->toBe(['Third', 'First', 'Second']);
});

it('refuses a reorder that does not name this stage’s whole set', function (): void {
    /*
     * Two properties in one, and the second is the one worth having.
     *
     * The renumber runs over the caller's own query, so an id from elsewhere
     * is never in the set — that is what keeps the write inside the parent it
     * was called for. But *ignoring* the foreign id and renumbering what is
     * left is not enough: renumbering a subset from zero leaves the untouched
     * rows holding the numbers it just handed out, and `orderBy('sort_order')`
     * then returns an order nobody chose. A reorder is one intention, so half
     * of one is refused rather than half-applied.
     */
    $template = authoredTemplate();
    $stage = soleStage($template);

    $other = app(TeamContext::class)->runFor($this->team, fn (): StageTemplate => StageTemplate::factory()->create([
        'workflow_template_id' => $template->getKey(),
        'name' => 'Pre Contract',
        'sort_order' => 1,
    ]));

    $this->post("/templates/{$template->getKey()}/stages/{$stage->getKey()}/tasks", ['title' => 'Mine']);
    $this->post("/templates/{$template->getKey()}/stages/{$other->getKey()}/tasks", ['title' => 'Theirs']);

    $theirs = TaskTemplate::query()->where('stage_template_id', $other->getKey())->sole();
    $mine = TaskTemplate::query()->where('stage_template_id', $stage->getKey())->sole();

    $theirsBefore = $theirs->sort_order;
    $mineBefore = $mine->sort_order;

    $this->patch("/templates/{$template->getKey()}/stages/{$stage->getKey()}/tasks", [
        'ids' => [$theirs->getKey()],
    ])->assertStatus(422);

    // Neither row moved: not the one from another stage, and — the half a
    // filter alone would have got wrong — not this stage's own either.
    expect($theirs->refresh()->sort_order)->toBe($theirsBefore)
        ->and($mine->refresh()->sort_order)->toBe($mineBefore);
});

it('edits a gate, and drops a configuration the new type does not read', function (): void {
    $template = authoredTemplate();
    $stage = soleStage($template);

    $this->post("/templates/{$template->getKey()}/stages/{$stage->getKey()}/gates", [
        'gate_type' => 'date_reached',
        'label' => 'Inspection objection has passed',
        'config' => ['keyDateName' => 'Inspection objection'],
    ])->assertRedirect();

    $gate = GateTemplate::query()->where('stage_template_id', $stage->getKey())->sole();

    expect($gate->config)->toBe(['keyDateName' => 'Inspection objection']);

    $this->patch(
        "/templates/{$template->getKey()}/stages/{$stage->getKey()}/gates/{$gate->getKey()}",
        ['gate_type' => 'manual_confirmation', 'label' => 'Inspection is complete'],
    )->assertRedirect();

    $gate->refresh();

    /*
     * The stale-configuration case. `Rule::excludeIf` drops the key from the
     * validated set rather than nulling it, so a plain `fill` would leave the
     * old key date on a type that never reads it — invisible until somebody
     * changed the type back and found a date they did not type.
     */
    expect($gate->gate_type)->toBe('manual_confirmation')
        ->and($gate->label)->toBe('Inspection is complete')
        ->and($gate->config)->toBeNull();
});

it('refuses a gate edit that would leave a date gate with no date', function (): void {
    $template = authoredTemplate();
    $stage = soleStage($template);

    $this->post("/templates/{$template->getKey()}/stages/{$stage->getKey()}/gates", [
        'gate_type' => 'manual_confirmation',
        'label' => 'Inspection is complete',
    ])->assertRedirect();

    $gate = GateTemplate::query()->where('stage_template_id', $stage->getKey())->sole();

    $this->patch(
        "/templates/{$template->getKey()}/stages/{$stage->getKey()}/gates/{$gate->getKey()}",
        ['gate_type' => 'date_reached', 'label' => 'A date, unnamed'],
    )->assertSessionHasErrors('config.keyDateName');

    expect($gate->refresh()->gate_type)->toBe('manual_confirmation');
});

it('refuses to edit anything on a template every team shares', function (): void {
    /*
     * The refusal has to hold on every new door, not only on the ones that
     * existed when it was written — *"a policy that guarded the workflow row
     * and let somebody add a gate to one of its stages would be a guard with a
     * door beside it."* Three doors were added; all three are checked.
     */
    $pack = app(TeamContext::class)->runWithoutScope(function (): WorkflowTemplate {
        $template = WorkflowTemplate::factory()->create(['team_id' => null, 'name' => 'Listing pack']);

        $stage = StageTemplate::factory()->create([
            'workflow_template_id' => $template->getKey(),
            'name' => 'Pre-listing',
            'sort_order' => 0,
        ]);

        GateTemplate::factory()->create([
            'stage_template_id' => $stage->getKey(),
            'gate_type' => 'manual_confirmation',
            'label' => 'Seller signed',
        ]);

        TaskTemplate::factory()->create([
            'stage_template_id' => $stage->getKey(),
            'title' => 'Walk the property',
        ]);

        return $template;
    });

    $stage = soleStage($pack);
    $gate = GateTemplate::query()->where('stage_template_id', $stage->getKey())->sole();
    $task = TaskTemplate::query()->where('stage_template_id', $stage->getKey())->sole();

    $base = "/templates/{$pack->getKey()}/stages/{$stage->getKey()}";

    $this->patch("{$base}/tasks/{$task->getKey()}", ['title' => 'Mine now'])->assertForbidden();
    $this->patch("{$base}/gates/{$gate->getKey()}", [
        'gate_type' => 'manual_confirmation', 'label' => 'Mine now',
    ])->assertForbidden();
    $this->patch("{$base}/tasks", ['ids' => [$task->getKey()]])->assertForbidden();
    $this->patch("{$base}/gates", ['ids' => [$gate->getKey()]])->assertForbidden();

    expect($task->refresh()->title)->toBe('Walk the property')
        ->and($gate->refresh()->label)->toBe('Seller signed');
});

it('refuses to edit a task through a stage it does not belong to', function (): void {
    $template = authoredTemplate();
    $stage = soleStage($template);

    $other = app(TeamContext::class)->runFor($this->team, fn (): StageTemplate => StageTemplate::factory()->create([
        'workflow_template_id' => $template->getKey(),
        'name' => 'Closing',
        'sort_order' => 1,
    ]));

    $this->post("/templates/{$template->getKey()}/stages/{$stage->getKey()}/tasks", ['title' => 'Mine']);

    $task = TaskTemplate::query()->where('stage_template_id', $stage->getKey())->sole();

    $this->patch(
        "/templates/{$template->getKey()}/stages/{$other->getKey()}/tasks/{$task->getKey()}",
        ['title' => 'Moved by hand'],
    )->assertNotFound();

    expect($task->refresh()->title)->toBe('Mine');
});

it('sends the editor everything it can now change', function (): void {
    /*
     * A column the screen cannot see is a column the screen cannot correct,
     * and this page reopens its dialogs onto what is stored. The gate's
     * `config` is the one worth naming: without it, editing a `date_reached`
     * gate to change its label would silently clear the key date it waits on.
     */
    $template = authoredTemplate();
    $stage = soleStage($template);

    $this->post("/templates/{$template->getKey()}/stages/{$stage->getKey()}/gates", [
        'gate_type' => 'date_reached',
        'label' => 'Inspection objection has passed',
        'config' => ['keyDateName' => 'Inspection objection'],
    ]);

    $this->patch("/templates/{$template->getKey()}/stages/{$stage->getKey()}", [
        'name' => 'Under Contract',
        'owner_role' => 'Transaction coordinator',
    ]);

    $this->post("/templates/{$template->getKey()}/stages/{$stage->getKey()}/tasks", [
        'title' => 'Order the survey',
        'owner_role' => 'Agent',
        'due_offset_days' => -3,
        'is_required' => true,
    ]);

    $this->get("/templates/{$template->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('template.stages.0.ownerRole', 'Transaction coordinator')
            ->where('template.stages.0.gates.0.config', ['keyDateName' => 'Inspection objection'])
            ->where('template.stages.0.tasks.0.ownerRole', 'Agent')
            ->where('template.stages.0.tasks.0.dueOffsetDays', -3)
            ->where('template.stages.0.tasks.0.isRequired', true)
            ->etc());
});
