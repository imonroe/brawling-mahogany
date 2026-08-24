<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Stage;
use App\Models\Workflow;
use App\Support\Deals\DealHeader;
use App\Support\Workflow\DescribeBlockers;
use App\Support\Workflow\StageTimeline;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S16 — the deal timeline (PRD §4.4 F4.6–F4.8 · Design System §7.4 · #76).
 *
 * A read screen, and the read path writes nothing. `AdvanceWorkflow` answers
 * *"what is blocking this stage"* by attempting the advance, which caches
 * `stages.state` and `gates.is_met` on the way past; `DescribeBlockers`
 * composes the same evaluators and writes nothing, which is what lets a
 * timeline be looked at without changing the thing it describes. S15 made that
 * argument first (#75) and this screen is the one that most needs it, because
 * it evaluates the *whole* of a running workflow rather than one card of it.
 *
 * Everything the screen decides lives in `StageTimeline`. This class loads the
 * graph and hands it over.
 */
class DealTimelineController extends Controller
{
    public function index(Deal $deal, DescribeBlockers $blockers): Response
    {
        $this->authorize('view', $deal);

        /*
         * One pass for the whole screen.
         *
         * `stages.gates` and `stages.tasks` are what §7.4's expanded card
         * reads, and both are needed for every stage rather than only the
         * active one: a collapsed row's meta string counts its tasks, and its
         * marker asks whether any gate was overridden.
         *
         * `DealHeader::for()` re-declares its own relations with
         * `loadMissing`, so naming them here keeps this screen's eager-load
         * list readable in one place and costs nothing.
         */
        $deal->load([
            'dealType',
            'participants.membership',
            'propertyLinks.property',
            'workflows.stages.gates',
            'workflows.stages.tasks',
        ]);

        return Inertia::render('Deals/Timeline', [
            'dealHeader' => DealHeader::for($deal),
            'dealUrl' => "/deals/{$deal->getKey()}",
            'workflows' => $deal->workflows
                ->map(fn (Workflow $workflow): array => $this->describe($workflow, $deal, $blockers))
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Workflow $workflow, Deal $deal, DescribeBlockers $blockers): array
    {
        /*
         * The inverse links, filled in from the graph already in memory.
         *
         * `field_populated` walks `$gate->stage->workflow->deal` and
         * `required_tasks_complete` walks `$gate->stage`. Left unset, each is a
         * query per gate per render — `DealOverviewController` says the same
         * thing and its budget test is what holds it there. Same objects, so
         * nothing can disagree with itself, and nothing here writes.
         */
        if (! $workflow->relationLoaded('deal')) {
            $workflow->setRelation('deal', $deal);
        }

        foreach ($workflow->stages as $stage) {
            if (! $stage->relationLoaded('workflow')) {
                $stage->setRelation('workflow', $workflow);
            }
        }

        $active = $workflow->activeStage();

        return StageTimeline::for(
            $workflow,
            $active instanceof Stage ? $blockers->forStage($active) : null,
        );
    }
}
