<?php

declare(strict_types=1);

use App\Enums\StageState;
use App\Enums\TaskSource;
use App\Enums\WorkflowState;
use App\Models\Deal;
use App\Models\GateTemplate;
use App\Models\Stage;
use App\Models\StageTemplate;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\Workflow;
use App\Models\WorkflowTemplate;
use App\Support\Workflow\InstantiateWorkflow;
use Illuminate\Database\Eloquent\Model;

/**
 * Template instantiation and the snapshot guarantee (PRD F4.5 · issue #66).
 *
 * `CLAUDE.md`: *"Instantiating a template snapshots it — later template edits
 * must never rewrite an in-flight deal."*
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

/** A three-stage template with a gate and two tasks on the middle stage. */
function listingTemplate(): WorkflowTemplate
{
    $template = WorkflowTemplate::factory()->create(['name' => 'Listing']);

    $prep = StageTemplate::factory()->create([
        'workflow_template_id' => $template->getKey(),
        'name' => 'Listing Preparation',
        'sort_order' => 0,
        'expected_duration_days' => 5,
    ]);

    $live = StageTemplate::factory()->milestone('Your home is on the market')->create([
        'workflow_template_id' => $template->getKey(),
        'name' => 'Go Live',
        'sort_order' => 1,
        'expected_duration_days' => 2,
    ]);

    StageTemplate::factory()->create([
        'workflow_template_id' => $template->getKey(),
        'name' => 'Under Contract',
        'sort_order' => 2,
        'expected_duration_days' => 30,
    ]);

    GateTemplate::factory()->create([
        'stage_template_id' => $live->getKey(),
        'gate_type' => 'required_tasks_complete',
        'label' => 'Photography is done',
    ]);

    TaskTemplate::factory()->required()->create([
        'stage_template_id' => $live->getKey(),
        'title' => 'Book the photographer',
        'due_offset_days' => 1,
    ]);

    TaskTemplate::factory()->create([
        'stage_template_id' => $live->getKey(),
        'title' => 'Write the listing copy',
        // Negative: before the stage opens. "Order the survey three days
        // early" is the shape this exists for.
        'due_offset_days' => -3,
    ]);

    unset($prep);

    return $template;
}

it('copies the whole tree into the runtime tables', function (): void {
    $workflow = app(InstantiateWorkflow::class)->handle($this->deal, listingTemplate());

    expect($workflow->stages)->toHaveCount(3)
        ->and($workflow->stages->pluck('name')->all())
        ->toBe(['Listing Preparation', 'Go Live', 'Under Contract']);

    $live = $workflow->stages->firstWhere('name', 'Go Live');

    expect($live->gates)->toHaveCount(1)
        ->and($live->tasks)->toHaveCount(2)
        ->and($live->is_milestone)->toBeTrue()
        ->and($live->milestone_label)->toBe('Your home is on the market');
});

it('activates the first stage and nothing else', function (): void {
    $workflow = app(InstantiateWorkflow::class)->handle($this->deal, listingTemplate());

    expect($workflow->state)->toBe(WorkflowState::Active)
        ->and($workflow->actual_start)->not->toBeNull()
        ->and($workflow->stages->pluck('state')->all())->toBe([
            StageState::Active,
            StageState::Pending,
            StageState::Pending,
        ])
        ->and($workflow->current_stage_id)->toBe($workflow->stages->first()->getKey());
});

it('computes planned dates from the expected durations', function (): void {
    $this->freezeAt('2026-03-02 09:00:00');

    $workflow = app(InstantiateWorkflow::class)->handle($this->deal, listingTemplate());

    $stages = $workflow->stages;

    // 5 days, then 2, then 30 — each stage starting where the last one ended.
    expect($stages[0]->planned_start->toDateString())->toBe('2026-03-02')
        ->and($stages[0]->planned_end->toDateString())->toBe('2026-03-07')
        ->and($stages[1]->planned_start->toDateString())->toBe('2026-03-07')
        ->and($stages[1]->planned_end->toDateString())->toBe('2026-03-09')
        ->and($stages[2]->planned_end->toDateString())->toBe('2026-04-08')
        ->and($workflow->planned_end->toDateString())->toBe('2026-04-08');
});

it('reads a task due offset as signed, so a task can fall before its stage', function (): void {
    $this->freezeAt('2026-03-02 09:00:00');

    $workflow = app(InstantiateWorkflow::class)->handle($this->deal, listingTemplate());

    $live = $workflow->stages->firstWhere('name', 'Go Live');

    $book = $live->tasks->firstWhere('title', 'Book the photographer');
    $copy = $live->tasks->firstWhere('title', 'Write the listing copy');

    // The stage opens on the 7th.
    expect($book->due_date->toDateString())->toBe('2026-03-08')
        ->and($copy->due_date->toDateString())->toBe('2026-03-04');
});

it('marks every copied task as coming from the workflow', function (): void {
    $workflow = app(InstantiateWorkflow::class)->handle($this->deal, listingTemplate());

    expect(Task::query()->where('deal_id', $this->deal->getKey())->get())
        ->each(fn ($task) => $task->source->toBe(TaskSource::Template));
});

/**
 * The test issue #66 asks for by name.
 *
 * > Instantiate a workflow, then edit the template heavily — rename stages,
 * > reorder them, delete one, add a gate. Assert the in-flight instance is
 * > byte-identical to what it was.
 */
it('does not change an in-flight workflow when the template is edited afterwards', function (): void {
    $template = listingTemplate();

    $workflow = app(InstantiateWorkflow::class)->handle($this->deal, $template);

    $before = $workflow->stages()->with('gates', 'tasks')->get()->toArray();
    $snapshotBefore = $workflow->fresh()->template_snapshot;

    // Now rewrite the template as violently as the issue asks.
    $template->stageTemplates->each(function (StageTemplate $stage): void {
        $stage->update(['name' => 'Renamed: '.$stage->name]);
    });

    $reordered = $template->stageTemplates->first();
    $reordered->update(['sort_order' => 99]);

    $template->stageTemplates->last()->delete();

    GateTemplate::factory()->create([
        'stage_template_id' => $template->stageTemplates->first()->getKey(),
        'gate_type' => 'manual_confirmation',
        'label' => 'A gate added after the fact',
    ]);

    $template->update(['name' => 'Listing v2', 'version' => 2]);

    $after = $workflow->fresh()->stages()->with('gates', 'tasks')->get()->toArray();

    expect($after)->toEqual($before)
        ->and($workflow->fresh()->template_snapshot)->toEqual($snapshotBefore)
        ->and($workflow->fresh()->name)->toBe('Listing');
});

it('writes the definition into the snapshot so it survives the template', function (): void {
    $template = listingTemplate();

    $workflow = app(InstantiateWorkflow::class)->handle($this->deal, $template);

    $snapshot = $workflow->template_snapshot;

    expect($snapshot['workflow_template']['name'])->toBe('Listing')
        ->and($snapshot['stages'])->toHaveCount(3)
        ->and($snapshot['stages'][1]['gates'])->toHaveCount(1)
        ->and($snapshot['stages'][1]['tasks'])->toHaveCount(2);

    // Delete the template outright. The workflow still knows what it is.
    $template->stageTemplates->each->delete();
    $template->delete();

    $reloaded = $workflow->fresh();

    expect($reloaded->template_snapshot['stages'])->toHaveCount(3)
        ->and($reloaded->stages)->toHaveCount(3);
});

it('produces two independent workflows when one template is used twice', function (): void {
    $template = listingTemplate();

    $first = app(InstantiateWorkflow::class)->handle($this->deal, $template);
    $second = app(InstantiateWorkflow::class)->handle($this->deal, $template);

    expect($first->getKey())->not->toBe($second->getKey())
        ->and($this->deal->fresh()->workflows)->toHaveCount(2);

    // Advancing one must not touch the other (F4.7).
    $firstStage = $first->stages->first();
    $firstStage->forceFill(['state' => StageState::Complete->value])->save();

    expect($second->fresh()->stages->first()->state)->toBe(StageState::Active);
});

it('leaves no partial workflow behind when instantiation fails', function (): void {
    $template = listingTemplate();

    /*
     * Blow up on the **third** stage, once two are already written.
     *
     * The position is what makes this a transaction test rather than an insert
     * test: by the time this throws, the workflow row, two stages, their gates
     * and their tasks are all in the database. That is exactly the wreckage a
     * non-transactional instantiation would leave behind, and a deal carrying
     * half a process is worse than a deal carrying none — the stage rail
     * renders, the advance button works, and the missing stages are invisible.
     *
     * The failure used to be an assignee id that did not exist, which stopped
     * being a failure when the service started dropping unassignable people
     * rather than letting the foreign key refuse them. A hook is the honest
     * replacement: the test is about the transaction, not about which write
     * happens to be capable of failing this week.
     */
    Stage::creating(function (Stage $stage): bool {
        if ($stage->name === 'Under Contract') {
            throw new RuntimeException('Something failed partway through.');
        }

        return true;
    });

    expect(fn () => app(InstantiateWorkflow::class)->handle($this->deal, $template))
        ->toThrow(RuntimeException::class);

    /*
     * Both, and in this order. `flushEventListeners()` drops the hook above —
     * and every hook the model booted with it, `HasStateMachine`'s transition
     * guard included. `clearBootedModels()` makes the next test re-boot the
     * model from scratch and get them all back.
     */
    Stage::flushEventListeners();
    Model::clearBootedModels();

    expect(Workflow::query()->where('deal_id', $this->deal->getKey())->count())->toBe(0)
        ->and(Stage::query()->count())->toBe(0)
        ->and(Task::query()->count())->toBe(0);
});

it('drops a role assignment naming somebody who is not on the team', function (): void {
    // The person id in a request body is not proof of anything: a colleague
    // can leave between the picker rendering and the form being submitted.
    // Unassigned is the existing answer to "no person for this role", and it
    // shows on the stage rather than assigning the task to a stranger.
    $template = listingTemplate();

    TaskTemplate::factory()->create([
        'stage_template_id' => $template->stageTemplates->first()->getKey(),
        'title' => 'A task owned by nobody who is here',
        'owner_role' => 'coordinator',
    ]);

    app(InstantiateWorkflow::class)->handle(
        $this->deal,
        $template,
        roleAssignments: ['coordinator' => '01000000000000000000000000'],
    );

    expect(Task::query()->where('title', 'A task owned by nobody who is here')->sole()->assignee_id)
        ->toBeNull();
});

it('keeps a role assignment naming somebody who is', function (): void {
    $template = listingTemplate();

    TaskTemplate::factory()->create([
        'stage_template_id' => $template->stageTemplates->first()->getKey(),
        'title' => 'A task owned by somebody here',
        'owner_role' => 'coordinator',
    ]);

    app(InstantiateWorkflow::class)->handle(
        $this->deal,
        $template,
        roleAssignments: ['coordinator' => (string) $this->member->getKey()],
    );

    expect(Task::query()->where('title', 'A task owned by somebody here')->sole()->assignee_id)
        ->toBe((string) $this->member->getKey());
});
