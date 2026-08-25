<?php

declare(strict_types=1);

namespace App\Http\Controllers\Templates;

use App\Http\Controllers\Controller;
use App\Models\GateTemplate;
use App\Models\StageTemplate;
use App\Models\TaskTemplate;
use App\Models\WorkflowTemplate;
use App\Support\Workflow\Gates\GateRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * S42, S43 — the stage template editor and the gate editor (issue #86).
 *
 * All of it authorized against the **workflow template**, because that is what
 * `WorkflowTemplatePolicy` decides and a system template must be uneditable
 * all the way down: a policy that guarded the workflow row and let somebody
 * add a gate to one of its stages would be a guard with a door beside it.
 *
 * ## Order is sent whole, the way S38's gallery does
 *
 * A reorder is one intention. Two adjacent swaps racing each other produce an
 * order neither person chose, and the Design System's note on the sortable
 * library records the same argument for the same reason.
 */
class StageTemplateController extends Controller
{
    public function store(Request $request, WorkflowTemplate $template): RedirectResponse
    {
        $this->authorize('update', $template);

        $validated = $request->validate($this->stageRules());

        $stage = new StageTemplate;

        $stage->fill($validated);
        $stage->forceFill([
            'workflow_template_id' => $template->getKey(),
            'sort_order' => (int) StageTemplate::query()
                ->where('workflow_template_id', $template->getKey())
                ->max('sort_order') + 1,
        ])->save();

        return back(fallback: route('templates.show', $template));
    }

    public function update(
        Request $request,
        WorkflowTemplate $template,
        StageTemplate $stageTemplate,
    ): RedirectResponse {
        $this->authorize('update', $template);

        $stageTemplate->fill($request->validate($this->stageRules()))->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stage saved.')]);

        return back(fallback: route('templates.show', $template));
    }

    public function destroy(WorkflowTemplate $template, StageTemplate $stageTemplate): RedirectResponse
    {
        $this->authorize('update', $template);

        /*
         * Deals already running on this template keep their stage. The
         * template/instance split is what makes that true — `stages` was
         * snapshotted at instantiation and holds no pointer back here.
         */
        $stageTemplate->delete();

        return back(fallback: route('templates.show', $template));
    }

    public function reorder(Request $request, WorkflowTemplate $template): RedirectResponse
    {
        $this->authorize('update', $template);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['string'],
        ]);

        DB::transaction(function () use ($template, $validated): void {
            $stages = StageTemplate::query()
                ->where('workflow_template_id', $template->getKey())
                ->get()
                ->keyBy(fn (StageTemplate $one): string => (string) $one->getKey());

            $position = 0;

            foreach ($validated['ids'] as $id) {
                $stage = $stages->get($id);

                if ($stage instanceof StageTemplate) {
                    $stage->forceFill(['sort_order' => $position++])->save();
                }
            }
        });

        return back(fallback: route('templates.show', $template));
    }

    public function addGate(
        Request $request,
        WorkflowTemplate $template,
        StageTemplate $stageTemplate,
    ): RedirectResponse {
        $this->authorize('update', $template);

        $validated = $request->validate([
            /*
             * The gate **type** decides which evaluator runs, and the
             * registry is the list of them — *"adding a gate type means adding
             * a class, not touching advancement logic"*, so the allowed values
             * are read from the registry rather than repeated here. An eighth
             * evaluator becomes selectable by existing.
             */
            'gate_type' => ['required', Rule::in(GateRegistry::types())],
            'label' => ['required', 'string', 'max:120'],
            'is_blocking' => ['boolean'],
        ]);

        $gate = new GateTemplate;

        $gate->fill($validated);
        $gate->forceFill([
            'stage_template_id' => $stageTemplate->getKey(),
            'sort_order' => (int) GateTemplate::query()
                ->where('stage_template_id', $stageTemplate->getKey())
                ->max('sort_order') + 1,
        ])->save();

        return back(fallback: route('templates.show', $template));
    }

    public function removeGate(
        WorkflowTemplate $template,
        StageTemplate $stageTemplate,
        GateTemplate $gateTemplate,
    ): RedirectResponse {
        $this->authorize('update', $template);

        abort_unless($gateTemplate->stage_template_id === $stageTemplate->getKey(), 404);

        $gateTemplate->delete();

        return back(fallback: route('templates.show', $template));
    }

    public function addTask(
        Request $request,
        WorkflowTemplate $template,
        StageTemplate $stageTemplate,
    ): RedirectResponse {
        $this->authorize('update', $template);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'is_required' => ['boolean'],
            /*
             * Days from the stage's start, and it may be negative: *"chase the
             * survey three days before the stage is due to end"* is an
             * ordinary instruction and the column is signed for it.
             */
            'due_offset_days' => ['nullable', 'integer', 'between:-365,365'],
        ]);

        $task = new TaskTemplate;

        $task->fill($validated);
        $task->forceFill([
            'stage_template_id' => $stageTemplate->getKey(),
            'sort_order' => (int) TaskTemplate::query()
                ->where('stage_template_id', $stageTemplate->getKey())
                ->max('sort_order') + 1,
        ])->save();

        return back(fallback: route('templates.show', $template));
    }

    public function removeTask(
        WorkflowTemplate $template,
        StageTemplate $stageTemplate,
        TaskTemplate $taskTemplate,
    ): RedirectResponse {
        $this->authorize('update', $template);

        abort_unless($taskTemplate->stage_template_id === $stageTemplate->getKey(), 404);

        $taskTemplate->delete();

        return back(fallback: route('templates.show', $template));
    }

    /**
     * @return array<string, mixed>
     */
    private function stageRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'expected_duration_days' => ['nullable', 'integer', 'between:0,365'],
            'is_milestone' => ['boolean'],
            /*
             * IA §3: a milestone is a **moment** worth telling a client about,
             * and this is the sentence they are told. IA §9 governs its
             * wording — no internal stage names, no gate language.
             */
            'client_facing_label' => ['nullable', 'string', 'max:160'],
        ];
    }
}
