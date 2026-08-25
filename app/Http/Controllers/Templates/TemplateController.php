<?php

declare(strict_types=1);

namespace App\Http\Controllers\Templates;

use App\Http\Controllers\Controller;
use App\Models\GateTemplate;
use App\Models\StageTemplate;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\TemplatePack;
use App\Models\WorkflowTemplate;
use App\Support\Templates\CopyTemplate;
use App\Support\Tenancy\TeamContext;
use App\Support\Workflow\Gates\GateRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S39, S40, S41 — the templates index, the pack browser, and the editor
 * (PRD §4.4 F4.1 · issues #84, #85).
 *
 * ## Two lists, because they are two different things
 *
 * A team's own templates are editable; a **pack's** are not — one pack is
 * shared by every team, and `WorkflowTemplatePolicy::update()` refuses a
 * system template outright. So the way a team customises Emily's listing pack
 * is to take a copy, and the pack browser's only verb is *Use a copy*.
 * Showing both in one list with some rows quietly read-only would be the
 * shape of confusion §4.3 of the Frontend conventions warns about.
 *
 * ## Editing a template cannot reach a running deal
 *
 * PRD §7.1 calls the template/instance split *"the highest-impact
 * correction"* in the document, and `InstantiateWorkflow` snapshots at the
 * moment a workflow starts. Nothing here writes to the runtime layer, and
 * `TemplateEditingTest` proves the property rather than this comment
 * asserting it.
 */
class TemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', WorkflowTemplate::class);

        $teamId = app(TeamContext::class)->requireId(WorkflowTemplate::class);

        $mine = WorkflowTemplate::query()
            ->where('team_id', $teamId)
            ->withCount('stageTemplates')
            ->orderBy('name')
            ->get();

        $packs = TemplatePack::query()
            ->with(['workflowTemplates' => fn ($query) => $query->withCount('stageTemplates')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('Templates/Index', [
            'templates' => $mine->map(self::row(...))->values()->all(),
            'packs' => $packs->map(fn (TemplatePack $pack): array => [
                'id' => $pack->getKey(),
                'name' => $pack->name,
                'description' => $pack->description,
                'templates' => $pack->workflowTemplates->map(self::row(...))->values()->all(),
            ])->values()->all(),
            'can' => [
                'manage' => $request->user()?->can('create', WorkflowTemplate::class) ?? false,
            ],
        ]);
    }

    public function show(Request $request, WorkflowTemplate $template): Response
    {
        $this->authorize('view', $template);

        $template->load([
            'stageTemplates' => fn ($query) => $query->orderBy('sort_order'),
            'stageTemplates.gateTemplates' => fn ($query) => $query->orderBy('sort_order'),
            'stageTemplates.taskTemplates' => fn ($query) => $query->orderBy('sort_order'),
        ]);

        return Inertia::render('Templates/Show', [
            'template' => [
                ...self::row($template),
                /*
                 * The count **before** the edit, not a refusal after it.
                 * Editing is the point and the snapshot makes it safe — what
                 * somebody needs to know is that the twelve deals already
                 * running on this template will *not* change with it, which is
                 * the opposite of what a warning usually means and is exactly
                 * why the number is worth showing.
                 */
                'inUse' => $template->inUseCount(),
                'stages' => $template->stageTemplates->map(fn (StageTemplate $stage): array => [
                    'id' => $stage->getKey(),
                    'name' => $stage->name,
                    'description' => $stage->description,
                    'sortOrder' => $stage->sort_order,
                    'expectedDurationDays' => $stage->expected_duration_days,
                    'isMilestone' => $stage->is_milestone,
                    'clientFacingLabel' => $stage->client_facing_label,
                    'gates' => $stage->gateTemplates->map(fn (GateTemplate $gate): array => [
                        'id' => $gate->getKey(),
                        'gateType' => $gate->gate_type,
                        'label' => $gate->label,
                        'isBlocking' => $gate->is_blocking,
                    ])->values()->all(),
                    'tasks' => $stage->taskTemplates->map(fn (TaskTemplate $task): array => [
                        'id' => $task->getKey(),
                        'title' => $task->title,
                        'isRequired' => $task->is_required,
                        'dueOffsetDays' => $task->due_offset_days,
                    ])->values()->all(),
                ])->values()->all(),
            ],
            /*
             * The picker's options, from the registry rather than a list in
             * the page — PRD §8.3's *"adding a gate type means adding a
             * class"* extended to the editor, so an eighth evaluator is
             * selectable by existing.
             */
            'gateTypes' => GateRegistry::selectableOptions(),
            /*
             * The full list too, so a gate a pack carries renders with its
             * name rather than its key. Reading and composing are different
             * questions: everything is legible, and only what S43 can fully
             * specify is offerable.
             */
            'gateTypeLabels' => GateRegistry::options(),
            'can' => [
                // A system template is readable and never editable: one pack
                // is shared by every team.
                'update' => $request->user()?->can('update', $template) ?? false,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', WorkflowTemplate::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $teamId = app(TeamContext::class)->requireId(WorkflowTemplate::class);

        $template = new WorkflowTemplate;

        $template->fill($validated);
        $template->forceFill(['team_id' => $teamId, 'version' => 1, 'is_active' => true])->save();

        return to_route('templates.show', $template);
    }

    public function update(Request $request, WorkflowTemplate $template): RedirectResponse
    {
        $this->authorize('update', $template);

        $template->fill($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]))->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template saved.')]);

        return back(fallback: route('templates.show', $template));
    }

    /**
     * Take a copy of a template this team may read but not edit (#84).
     *
     * The pack browser's only verb. A team that wants Emily's listing process
     * with three stages removed gets their own copy of it, and the pack stays
     * exactly as every other team has it.
     */
    public function copy(WorkflowTemplate $template, CopyTemplate $copier): RedirectResponse
    {
        $this->authorize('view', $template);
        $this->authorize('create', WorkflowTemplate::class);

        $team = app(TeamContext::class)->get();

        abort_unless($team instanceof Team, 404);

        $copy = $copier->into($template, $team);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Copied. This one is yours to change — the pack is untouched.'),
        ]);

        return to_route('templates.show', $copy);
    }

    public function destroy(WorkflowTemplate $template): RedirectResponse
    {
        $this->authorize('delete', $template);

        /*
         * Soft, and PRD §9's window covers a mistake. **Deals already running
         * on it are unaffected** — `InstantiateWorkflow` snapshotted, so the
         * runtime rows have no pointer back here to break.
         */
        $template->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template removed.')]);

        return to_route('templates.index');
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(WorkflowTemplate $template): array
    {
        return [
            'id' => $template->getKey(),
            'name' => $template->name,
            'description' => $template->description,
            'isActive' => $template->is_active,
            'isSystem' => $template->isSystem(),
            'stageCount' => (int) ($template->getAttribute('stage_templates_count') ?? 0),
            'url' => '/templates/'.$template->getKey(),
        ];
    }
}
