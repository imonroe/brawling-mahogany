<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\ConfirmGateRequest;
use App\Models\Deal;
use App\Models\Person;
use App\Models\Workflow;
use App\Support\Workflow\AdvanceWorkflow;
use App\Support\Workflow\ConfirmResult;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Tick a manual gate, and untick one (PRD §4.4 F4.8 · S23).
 *
 * ## Why this controller had to exist
 *
 * `ManualConfirmationEvaluator` reads `gates.is_met`, and nothing wrote it but
 * the cache refresh inside `AdvanceWorkflow` — which reads the evaluator. So
 * the most common gate type in the product could only be got past by
 * **overriding** it, which IA §7 reserves for a condition that should have
 * been met and was not. `GatePolicy::update` was already there, with the
 * docblock *"Ticking a manual gate is ordinary deal work"*, asked for by no
 * route. `CLAUDE.md` names the shape from S17: a row nothing can reach is a
 * rule nobody is following.
 *
 * ## Confirming is a separate verb from editing, the way completing is
 *
 * `POST` and `DELETE` on `.../confirmation`, exactly as tasks have `POST` and
 * `DELETE` on `.../completion`. A boolean inside an edit would make *"I fixed
 * the wording of this requirement"* and *"the survey came back"* the same
 * request, and only one of them writes a timeline entry, is counted by an
 * advance, and happens forty times a deal from a checkbox.
 *
 * ## It writes nothing itself
 *
 * `is_met` is written inside `AdvanceWorkflow` alongside `overridden`, for the
 * reason the single mutation path exists at all: the two columns are the
 * distinction IA §8 insists on, and a controller that wrote one of them would
 * be the place they start to drift.
 */
class ConfirmGateController extends Controller
{
    public function store(
        ConfirmGateRequest $request,
        Deal $deal,
        Workflow $workflow,
        AdvanceWorkflow $advance,
    ): RedirectResponse {
        /** @var Person $person */
        $person = $request->user();

        return $this->respond(
            $advance->confirm($workflow, $request->gate(), $person),
            $deal,
            confirmed: true,
        );
    }

    public function destroy(
        ConfirmGateRequest $request,
        Deal $deal,
        Workflow $workflow,
        AdvanceWorkflow $advance,
    ): RedirectResponse {
        /** @var Person $person */
        $person = $request->user();

        return $this->respond(
            $advance->unconfirm($workflow, $request->gate(), $person),
            $deal,
            confirmed: false,
        );
    }

    private function respond(ConfirmResult $result, Deal $deal, bool $confirmed): RedirectResponse
    {
        if (! $result->changed) {
            /*
             * The same flash key the advance and the override use, so there is
             * one place on the screen that says why nothing moved. `refused`,
             * because every one of these is something somebody else did on
             * purpose — a colleague ticking it first, a stage that has since
             * advanced — rather than a gate to go and chase.
             */
            Inertia::flash('advance', [
                'refused' => true,
                'reasons' => [$result->refusal],
            ]);

            return back(fallback: route('deals.show', $deal));
        }

        Inertia::flash('toast', [
            'type' => 'success',
            /*
             * It says what did *not* happen, because the natural reading of a
             * cleared requirement is that the deal moved. Overriding needs the
             * same sentence and for the same reason: clearing one of three
             * blockers moves nothing until somebody presses Advance.
             */
            'message' => $confirmed
                ? __('Confirmed. Advance the stage when everything else is clear.')
                : __('Confirmation taken back.'),
        ]);

        return back(fallback: route('deals.show', $deal));
    }
}
