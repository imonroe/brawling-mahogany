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
     *
     * Refused as a **validation** failure rather than a bare 422: Inertia
     * turns a plain 422 into an error modal over the page, and the ordinary
     * way to reach this is a list the page drew before somebody else added a
     * row — a stale page, not a broken request.
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
    ])->assertSessionHasErrors('ids');

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

it('lets a team correct a gate whose type the picker cannot offer', function (): void {
    /*
     * A pack file may carry any type the registry knows, so a team can end up
     * holding a `document_present` gate S43 could never have composed. Both
     * halves of this were wrong before the review:
     *
     *  - the Edit button opened a form whose Save was refused, for a
     *    `gate_type` nobody had touched;
     *  - and the only way past that — changing the type — went through a
     *    `forceFill(['config' => null])` that ran unconditionally, so saving a
     *    corrected **label** emptied the configuration the gate runs on.
     */
    $template = authoredTemplate();
    $stage = soleStage($template);

    $gate = app(TeamContext::class)->runFor($this->team, fn (): GateTemplate => GateTemplate::factory()->create([
        'stage_template_id' => $stage->getKey(),
        'gate_type' => 'document_present',
        'label' => 'Inspection report is on file',
        'config' => ['category' => 'inspection_report'],
    ]));

    $this->patch(
        "/templates/{$template->getKey()}/stages/{$stage->getKey()}/gates/{$gate->getKey()}",
        ['gate_type' => 'document_present', 'label' => 'Inspection report received', 'is_blocking' => true],
    )->assertRedirect();

    $gate->refresh();

    expect($gate->label)->toBe('Inspection report received')
        // The configuration survived a save that did not change the type.
        ->and($gate->config)->toBe(['category' => 'inspection_report']);
});

it('does not let one gate’s type unlock it for another', function (): void {
    /*
     * The widened rule is per-row — it admits the type **this** gate already
     * is, and nothing else. Worth its own test rather than a comment: the
     * alternative reading of that fix is a picker-wide hole.
     */
    $template = authoredTemplate();
    $stage = soleStage($template);

    app(TeamContext::class)->runFor($this->team, fn (): GateTemplate => GateTemplate::factory()->create([
        'stage_template_id' => $stage->getKey(),
        'gate_type' => 'document_present',
        'label' => 'Inspection report is on file',
    ]));

    $ordinary = app(TeamContext::class)->runFor($this->team, fn (): GateTemplate => GateTemplate::factory()->create([
        'stage_template_id' => $stage->getKey(),
        'gate_type' => 'manual_confirmation',
        'label' => 'Seller signed',
        'sort_order' => 1,
    ]));

    $this->patch(
        "/templates/{$template->getKey()}/stages/{$stage->getKey()}/gates/{$ordinary->getKey()}",
        ['gate_type' => 'document_present', 'label' => 'Seller signed'],
    )->assertSessionHasErrors('gate_type');

    expect($ordinary->refresh()->gate_type)->toBe('manual_confirmation');
});

it('drops a configuration the new type cannot read, and only then', function (): void {
    $template = authoredTemplate();
    $stage = soleStage($template);

    $gate = app(TeamContext::class)->runFor($this->team, fn (): GateTemplate => GateTemplate::factory()->create([
        'stage_template_id' => $stage->getKey(),
        'gate_type' => 'document_present',
        'label' => 'Inspection report is on file',
        'config' => ['category' => 'inspection_report'],
    ]));

    $this->patch(
        "/templates/{$template->getKey()}/stages/{$stage->getKey()}/gates/{$gate->getKey()}",
        ['gate_type' => 'manual_confirmation', 'label' => 'Inspection report received'],
    )->assertRedirect();

    expect($gate->refresh()->config)->toBeNull();
});

it('refuses a second gate on a stage with the same label, and nothing else', function (): void {
    /*
     * Refused where it is authored, because the consequence is a layer away
     * and a week later: a `gate_cleared` automation names its gate by label in
     * a pack file, so two gates answering to one name make an export nobody
     * can import — and the person told about it is whoever runs the deploy,
     * not the person who could rename it.
     */
    $template = authoredTemplate();
    $stage = soleStage($template);
    $base = "/templates/{$template->getKey()}/stages/{$stage->getKey()}/gates";

    $this->post($base, ['gate_type' => 'manual_confirmation', 'label' => 'Appraisal received'])
        ->assertRedirect();

    // Folded, the way the database folds it — a name that differs only in case
    // is the same name to a person reading a list.
    $this->post($base, ['gate_type' => 'manual_confirmation', 'label' => 'appraisal RECEIVED'])
        ->assertSessionHasErrors('label');

    // And an ordinary add with a free label still works, which is the control:
    // a rule refusing everything would satisfy the line above.
    $this->post($base, ['gate_type' => 'manual_confirmation', 'label' => 'Survey received'])
        ->assertSessionHasNoErrors();

    expect(GateTemplate::query()->where('stage_template_id', $stage->getKey())->count())->toBe(2);

    // The same label on a *different* stage is fine: the ambiguity only exists
    // among siblings, which is the only place an automation can name one.
    $other = app(TeamContext::class)->runFor($this->team, fn (): StageTemplate => StageTemplate::factory()->create([
        'workflow_template_id' => $template->getKey(),
        'name' => 'Closing',
        'sort_order' => 1,
    ]));

    $this->post("/templates/{$template->getKey()}/stages/{$other->getKey()}/gates", [
        'gate_type' => 'manual_confirmation',
        'label' => 'Appraisal received',
    ])->assertSessionHasNoErrors();

    /*
     * The count, not just the redirect. `assertRedirect()` is satisfied by a
     * **validation failure**, so this half of the rule passed against a check
     * with no stage scoping at all — the fourth round running in which a
     * fix's own test could not fail for what it names.
     */
    expect(GateTemplate::query()->where('stage_template_id', $other->getKey())->count())->toBe(1);

    // And editing a gate does not collide with itself.
    $gate = GateTemplate::query()
        ->where('stage_template_id', $stage->getKey())
        ->where('label', 'Appraisal received')
        ->sole();

    $this->patch("{$base}/{$gate->getKey()}", [
        'gate_type' => 'manual_confirmation',
        'label' => 'Appraisal received',
        'is_blocking' => false,
    ])->assertRedirect();

    expect($gate->refresh()->is_blocking)->toBeFalse();
});

it('lets a gate that shares a label with an older one still be edited', function (): void {
    /*
     * Two gates sharing a label was legal until #87's rule, so the rows exist.
     * Refusing an edit that changes only the blocking flag — for a label
     * nobody touched — left both of them permanently uneditable, with deleting
     * one as the only exit. The export warns about such a pair, which is what
     * it is for; making them unfixable is not how they get fixed.
     */
    $template = authoredTemplate();
    $stage = soleStage($template);

    [$first, $second] = app(TeamContext::class)->runFor($this->team, fn (): array => [
        GateTemplate::factory()->create([
            'stage_template_id' => $stage->getKey(),
            'gate_type' => 'manual_confirmation',
            'label' => 'Appraisal received',
            'sort_order' => 0,
        ]),
        GateTemplate::factory()->create([
            'stage_template_id' => $stage->getKey(),
            'gate_type' => 'manual_confirmation',
            'label' => 'Appraisal received',
            'sort_order' => 1,
        ]),
    ]);

    $base = "/templates/{$template->getKey()}/stages/{$stage->getKey()}/gates";

    $this->patch("{$base}/{$first->getKey()}", [
        'gate_type' => 'manual_confirmation',
        'label' => 'Appraisal received',
        'is_blocking' => false,
    ])->assertSessionHasNoErrors();

    expect($first->refresh()->is_blocking)->toBeFalse();

    // And renaming one to something free is how the pair gets resolved.
    $this->patch("{$base}/{$second->getKey()}", [
        'gate_type' => 'manual_confirmation',
        'label' => 'Appraisal value confirmed',
    ])->assertSessionHasNoErrors();

    expect($second->refresh()->label)->toBe('Appraisal value confirmed');

    // What is still refused: taking a label a *different* gate holds.
    $this->patch("{$base}/{$second->getKey()}", [
        'gate_type' => 'manual_confirmation',
        'label' => 'Appraisal received',
    ])->assertSessionHasErrors('label');
});
