<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\AttachWorkflowRequest;
use App\Models\Deal;
use App\Models\StageTemplate;
use App\Models\TemplatePack;
use App\Models\Workflow;
use App\Models\WorkflowTemplate;
use App\Support\Tenancy\TeamContext;
use App\Support\Workflow\InstantiateWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Attach a workflow (Screen Inventory S28 · PRD §4.4 F4.7 · issue #74).
 *
 * ## Why this is separate from creating the deal
 *
 * F4.7 allows several workflows per deal, added at different times. Issue #74
 * gives the example that makes it concrete: the *Under Contract* workflow
 * attaches when the offer is accepted, weeks after the deal was created. A
 * wizard step alone would mean a team either guessing every process at the
 * start or never adding the second one.
 *
 * ## The preview is the feature
 *
 * *"The preview shows what will be created before it is created. Attaching is
 * not undoable in a tidy way, and a wrong template on a live deal is a mess."*
 *
 * That is why `preview()` returns the stage names rather than a count.
 * Instantiating copies the whole tree into the runtime tables and activates
 * the first stage (#66) — undoing it means deleting real stages, gates and
 * tasks somebody may already have ticked. Showing the names costs one query
 * and is the difference between a considered choice and a surprise.
 *
 * ## Already attached is a state, not an error
 *
 * The same template twice on one deal is legal and occasionally meant — a
 * team running two rounds of pre-listing improvements — so S28 marks it rather
 * than refusing it. `InstantiateWorkflow`'s own docblock is explicit:
 * *"instantiating twice is allowed and means two workflows."*
 */
class WorkflowAttachmentController extends Controller
{
    /**
     * The templates on offer, with what each would create.
     *
     * JSON rather than an Inertia page: S28 is a modal on a deal screen, and
     * the pack filter is a control inside it.
     */
    public function index(Request $request, Deal $deal, TeamContext $teams): JsonResponse
    {
        $this->authorize('create', [Workflow::class, $deal]);

        $pack = trim((string) $request->query('pack', ''));

        $query = WorkflowTemplate::query()
            ->visibleTo($teams->requireId(WorkflowTemplate::class))
            ->where('is_active', true)
            ->with(['templatePack:id,name,slug'])
            /*
             * The preview, eager-loaded. One query for every template's stage
             * names rather than one per row — the same shape S76 shipped wrong
             * and #61's budget test now holds elsewhere.
             */
            ->with(['stageTemplates:id,workflow_template_id,name,sort_order,is_milestone'])
            ->when($pack !== '' && $pack !== 'all', fn ($templates) => $templates
                ->whereHas('templatePack', fn ($packs) => $packs->where('slug', $pack)));

        /*
         * Which are already on this deal. One query, keyed — asking per
         * template would be the N+1 this endpoint is most likely to grow.
         */
        $attached = $deal->workflows()
            ->whereNotNull('workflow_template_id')
            ->pluck('workflow_template_id')
            ->all();

        /*
         * Only the packs this team can actually filter by. The whole catalogue
         * offered choices that select nothing — a pack another team installed
         * has no template visible here, so choosing it emptied the list and
         * read as a bug.
         *
         * Derived from the templates rather than asked of the packs, because
         * `visibleTo()` is a scope on `WorkflowTemplate` and reaching it
         * through `whereHas()` loses the model type.
         */
        $packIds = WorkflowTemplate::query()
            ->visibleTo($teams->requireId(WorkflowTemplate::class))
            ->where('is_active', true)
            ->whereNotNull('template_pack_id')
            ->distinct()
            ->pluck('template_pack_id')
            ->all();

        return response()->json([
            'templates' => $query->orderBy('name')->get()->map(fn (WorkflowTemplate $template): array => [
                'id' => $template->getKey(),
                'name' => $template->name,
                'description' => $template->description,
                'packName' => $template->templatePack?->name,
                'isSystem' => $template->isSystem(),
                'isAttached' => in_array($template->getKey(), $attached, true),
                // The preview: what attaching would create, by name.
                'stages' => $template->stageTemplates->map(fn (StageTemplate $stage): array => [
                    'name' => $stage->name,
                    'isMilestone' => (bool) $stage->is_milestone,
                ])->values()->all(),
            ])->values()->all(),
            'packs' => TemplatePack::query()
                ->whereKey($packIds)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
                ->map(fn (TemplatePack $each): array => ['slug' => $each->slug, 'name' => $each->name])
                ->values()->all(),
        ]);
    }

    public function store(AttachWorkflowRequest $request, Deal $deal, InstantiateWorkflow $workflows): RedirectResponse
    {
        /** @var WorkflowTemplate $template */
        $template = WorkflowTemplate::query()->whereKey($request->validated('workflow_template_id'))->firstOrFail();

        $startingOn = $request->validated('starting_on');

        $workflows->handle(
            deal: $deal,
            template: $template,
            startingOn: is_string($startingOn) ? Carbon::parse($startingOn) : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workflow attached.')]);

        return back();
    }
}
