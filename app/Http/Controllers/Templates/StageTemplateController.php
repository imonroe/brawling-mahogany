<?php

declare(strict_types=1);

namespace App\Http\Controllers\Templates;

use App\Http\Controllers\Controller;
use App\Models\GateTemplate;
use App\Models\StageTemplate;
use App\Models\TaskTemplate;
use App\Models\WorkflowTemplate;
use App\Support\Workflow\Gates\GateRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

        $this->applyOrder(
            StageTemplate::query()->where('workflow_template_id', $template->getKey()),
            $request,
        );

        return back(fallback: route('templates.show', $template));
    }

    public function addGate(
        Request $request,
        WorkflowTemplate $template,
        StageTemplate $stageTemplate,
    ): RedirectResponse {
        $this->authorize('update', $template);

        $validated = $request->validate($this->gateRules($request), $this->gateMessages());

        $gate = new GateTemplate;

        /*
         * `config` is fillable on `GateTemplate`, so the validated nested key
         * arrives with the rest — and `is_blocking` keeps its column default
         * when the form does not send one, which a `forceFill` would have
         * quietly overwritten.
         */
        $gate->fill($validated);
        $gate->forceFill([
            'stage_template_id' => $stageTemplate->getKey(),
            'sort_order' => (int) GateTemplate::query()
                ->where('stage_template_id', $stageTemplate->getKey())
                ->max('sort_order') + 1,
        ])->save();

        return back(fallback: route('templates.show', $template));
    }

    /**
     * Edit a gate in place (#87).
     *
     * The same rules as adding one, deliberately — the type may change, and
     * with it whether a key date is required, which is exactly the pairing
     * `addGate` already refuses to save half of. Two rule lists would be two
     * answers to the same question, and the one nobody looked at again would
     * be the one that let a `date_reached` gate through with no date.
     *
     * Editing rather than remove-and-re-add, because a pack file is 90 lines
     * long and a markup pass over it is a hundred small corrections. Deleting
     * a gate to change one word also loses its place in the order, which a
     * re-add puts at the end.
     */
    public function updateGate(
        Request $request,
        WorkflowTemplate $template,
        StageTemplate $stageTemplate,
        GateTemplate $gateTemplate,
    ): RedirectResponse {
        $this->authorize('update', $template);

        abort_unless($gateTemplate->stage_template_id === $stageTemplate->getKey(), 404);

        $validated = $request->validate($this->gateRules($request, $gateTemplate), $this->gateMessages());

        /*
         * Cleared when the **type changes**, and only then.
         *
         * A gate changed from `date_reached` to `manual_confirmation` keeps no
         * key date: `Rule::excludeIf` drops the key from the validated set
         * rather than nulling it, so a plain `fill` would leave the old
         * configuration on a type that never reads it — invisible until
         * somebody changed the type back and found a date they did not type.
         *
         * Clearing *unconditionally* was worse, and only a pack made it
         * reachable: a `document_present` gate imported from a file carries a
         * `category` this editor has no field for, so saving a corrected label
         * on it silently emptied the configuration the gate runs on.
         */
        if (($validated['gate_type'] ?? null) !== $gateTemplate->gate_type) {
            $gateTemplate->forceFill(['config' => null]);
        }

        $gateTemplate->fill($validated)->save();

        // "Gate", not "Requirement": IA §11 allows the softer word only in the
        // deal view, and this is the editor.
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Gate saved.')]);

        return back(fallback: route('templates.show', $template));
    }

    public function reorderGates(
        Request $request,
        WorkflowTemplate $template,
        StageTemplate $stageTemplate,
    ): RedirectResponse {
        $this->authorize('update', $template);

        $this->applyOrder(
            GateTemplate::query()->where('stage_template_id', $stageTemplate->getKey()),
            $request,
        );

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

        $validated = $request->validate($this->taskRules());

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

    /**
     * Edit a task in place (#87).
     *
     * The gap this closes is the one that made #11's markup pass impossible to
     * do in the product: `is_required`, `due_offset_days` and `owner_role` are
     * exactly the four columns #11 lists as missing from #154's checklist, and
     * until now the only way to change one was to delete the task and add it
     * again — ninety times, losing the order each time. The metadata was
     * gathered in a GitHub comment because the screen could not take it.
     */
    public function updateTask(
        Request $request,
        WorkflowTemplate $template,
        StageTemplate $stageTemplate,
        TaskTemplate $taskTemplate,
    ): RedirectResponse {
        $this->authorize('update', $template);

        abort_unless($taskTemplate->stage_template_id === $stageTemplate->getKey(), 404);

        $taskTemplate->fill($request->validate($this->taskRules()))->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Task saved.')]);

        return back(fallback: route('templates.show', $template));
    }

    public function reorderTasks(
        Request $request,
        WorkflowTemplate $template,
        StageTemplate $stageTemplate,
    ): RedirectResponse {
        $this->authorize('update', $template);

        $this->applyOrder(
            TaskTemplate::query()->where('stage_template_id', $stageTemplate->getKey()),
            $request,
        );

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
     * Renumber one set of siblings from the order a screen sent.
     *
     * Three callers now — stages, gates and tasks — and one implementation,
     * because the argument the class docblock makes about *stage* order is
     * about ordering rather than about stages: a reorder is one intention, and
     * two adjacent swaps racing each other produce an order neither person
     * chose. A second copy of it is a second place for that to stop being
     * true.
     *
     * Scoped by the caller's own query, so an id belonging to another stage
     * (or another team's template) simply is not in the set and is skipped —
     * the renumber cannot reach outside the parent it was called for.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $siblings
     */
    private function applyOrder(Builder $siblings, Request $request): void
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['string', 'distinct'],
        ]);

        DB::transaction(function () use ($siblings, $validated): void {
            $rows = $siblings->get()->keyBy(fn (Model $one): string => (string) $one->getKey());

            $known = array_values(array_filter(
                $validated['ids'],
                fn (mixed $id): bool => is_string($id) && $rows->has($id),
            ));

            /*
             * The whole set or nothing.
             *
             * Ignoring an id from elsewhere is right — the renumber must not
             * reach outside the parent it was called for. Accepting a list
             * that names *fewer* rows than exist is not: renumbering from zero
             * over a subset leaves the untouched rows holding the numbers it
             * just handed out, and `orderBy('sort_order')` then returns an
             * order nobody chose. A reorder is one intention, which is a
             * reason to refuse half of one rather than only to filter it.
             */
            if (count($known) !== $rows->count()) {
                /*
                 * A validation failure, not a bare `abort(422)`.
                 *
                 * Inertia turns a plain 422 into an error modal over the page;
                 * a validation response it folds into `errors` and leaves the
                 * screen alone. The ordinary way to reach this is a list the
                 * page drew before a colleague added a row, which is a stale
                 * page rather than a broken request — so the screen reloads
                 * and the next move works.
                 */
                throw ValidationException::withMessages([
                    'ids' => __('This list has changed since the page was drawn. It has been refreshed — try the move again.'),
                ]);
            }

            $position = 0;

            foreach ($known as $id) {
                $row = $rows->get($id);

                if ($row instanceof Model) {
                    $row->forceFill(['sort_order' => $position++])->save();
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            /*
             * The same free-text role a stage carries, and the same reason —
             * see `stageRules()`. #154's *"TC (transaction coordinator)
             * Tasks"* block is the case it exists for.
             */
            'owner_role' => ['nullable', 'string', 'max:120'],
            /*
             * What feeds `required_tasks_complete`, so this is the flag that
             * decides which tasks actually **gate** an advance. #11 puts it
             * first among the four columns #154 does not supply, with the
             * example that settles it: *"Bring client gift for inspection"*
             * and *"Confirm loan application completed with lender"* cannot
             * both be blocking.
             */
            'is_required' => ['boolean'],
            /*
             * Days from the stage's start, and it may be negative: *"chase the
             * survey three days before the stage is due to end"* is an
             * ordinary instruction and the column is signed for it.
             *
             * Stage-relative is the only anchor there is. #154's dated items
             * are mostly *"5–7 days before closing"*, which is a **key date**
             * offset — `task_templates` has no anchor for one, and #11 records
             * the choice: narrow the ask to stage-relative rather than put a
             * migration and a cascade on #87's critical path.
             */
            'due_offset_days' => ['nullable', 'integer', 'between:-365,365'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gateRules(Request $request, ?GateTemplate $editing = null): array
    {
        return [
            /*
             * The gate **type** decides which evaluator runs, and the
             * registry is the list of them — *"adding a gate type means adding
             * a class, not touching advancement logic"*, so the allowed values
             * are read from the registry rather than repeated here.
             *
             * `selectableOptions()` and not `types()`: five of the seven
             * evaluators read a `configuration` this editor cannot yet ask
             * for, and a gate composed without one is a gate no evaluator can
             * ever answer — a stage only an **override** could pass, built in
             * two clicks. Validating against the narrower list means a request
             * naming one is refused rather than quietly accepted, which is the
             * same argument the permission validation on S75 makes.
             *
             * A pack **file** is held to the wider `types()` instead, and the
             * registry's own docblock asks for exactly that split: this is
             * what a person choosing from a dropdown may pick, and a file is
             * written by somebody who can supply the configuration.
             */
            'gate_type' => [
                'required',
                /*
                 * The selectable list, **plus whatever this gate already is**.
                 *
                 * A pack file may carry any type the registry knows (#87), so
                 * a team can end up holding a `document_present` gate the
                 * picker cannot compose. Validating an edit against the narrow
                 * list alone meant its Edit button opened a form whose Save
                 * was refused for a value nobody had touched — and the only
                 * way out was changing the type, which used to wipe the
                 * configuration too. Keeping the stored value valid lets its
                 * label and its blocking flag be corrected without letting
                 * anybody *choose* a type this editor cannot fully specify.
                 */
                Rule::in(array_values(array_unique(array_filter([
                    ...array_keys(GateRegistry::selectableOptions()),
                    $editing?->gate_type,
                ])))),
            ],
            'label' => ['required', 'string', 'max:120'],
            'is_blocking' => ['boolean'],
            /*
             * `date_reached`'s whole configuration (#109), and the first thing
             * this editor has ever asked for beyond a label — which is what
             * moved the type from `types()` into `selectableOptions()`.
             *
             * Free text, and it has to be: a gate lives on a **template**, and
             * a template has never met the deal it will run on, so there is no
             * `key_dates` row to point at. The two sides meet on the word the
             * team uses, folded for case and whitespace by the evaluator.
             *
             * `required_if` rather than `required_with`, so a request naming
             * the type and omitting the date is refused rather than saving a
             * gate only an override could pass — the state
             * `selectableOptions()` exists to keep out of the product.
             */
            'config.keyDateName' => [
                Rule::requiredIf(fn (): bool => GateRegistry::needsKeyDate(
                    (string) $request->input('gate_type'),
                )),
                Rule::excludeIf(fn (): bool => ! GateRegistry::needsKeyDate(
                    (string) $request->input('gate_type'),
                )),
                'string',
                'max:120',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function gateMessages(): array
    {
        return [
            'config.keyDateName.required' => 'Name the date this waits for — '
                .'the same name the deal uses for it on Dates & Deadlines.',
        ];
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
            /*
             * A role and never a person (#64), and until now a column with a
             * reader and no writer: `InstantiateWorkflow` resolves it to a
             * human through the assignments a deal supplies, `CopyTemplate`
             * carries it, and nothing anywhere could set it. #154's checklist
             * is the reason it matters — Emily's list already separates the
             * agent's work from the transaction coordinator's, and that
             * distinction is the one piece of ownership metadata #11 says
             * arrived with the content rather than needing to be asked for.
             *
             * Free text, because a template has never met the team it will run
             * in: `roles` is per-team and a pack ships between teams, so the
             * two sides meet on the word rather than on a foreign key. Same
             * argument `date_reached`'s key date name makes one method up.
             */
            'owner_role' => ['nullable', 'string', 'max:120'],
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
