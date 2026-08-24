<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\OverrideGateRequest;
use App\Models\Deal;
use App\Models\Person;
use App\Models\Workflow;
use App\Support\Workflow\AdvanceWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Force past one unmet gate, with a reason (PRD §4.4 F4.9 · §5.5 · S24 · #69).
 *
 * ## What this controller is not allowed to do
 *
 * Write anything. F4.9 is four artefacts that have to happen together — the
 * flag on the gate, an immutable audit entry naming who/when/which gate/why, a
 * distinct timeline marker, and an auto-created follow-up task — and
 * `AdvanceWorkflow::override()` is where all four live. A controller that
 * wrote the flag and remembered three of the four would look like it worked,
 * and `SingleMutationPathTest` would not catch it, because `gates.overridden`
 * is not `stages.state`.
 *
 * ## And it does not advance
 *
 * Overriding one of three blocking gates must not move the deal past the other
 * two. The person is returned to the deal, the modal reopens onto the
 * refreshed checklist, and Advance is a second, deliberate press that
 * re-evaluates every gate under its own lock. PRD §5.5 reads as one motion;
 * it is two acts with two audit entries, which is what makes the record
 * readable six weeks later.
 *
 * ## Why it is a POST on the workflow rather than on the gate
 *
 * The gate id travels in the body and `OverrideGateRequest` holds it to the
 * gates on this workflow. A `{gate}` route parameter would have to be resolved
 * through `{workflow}`, and scoped binding reaches a gate only through a
 * `stages` join — where `where('id', …)` is ambiguous in Postgres. The check
 * belongs in the service either way, and this keeps one spelling of it.
 */
class OverrideGateController extends Controller
{
    public function store(
        OverrideGateRequest $request,
        Deal $deal,
        Workflow $workflow,
        AdvanceWorkflow $advance,
    ): RedirectResponse {
        // Authorized by `OverrideGateRequest`, which asks the policy for
        // `override` rather than `advance` — `AuthorizationCoverageTest` reads
        // either spelling.
        /** @var Person $person */
        $person = $request->user();

        $result = $advance->override(
            workflow: $workflow,
            gate: $request->gate(),
            actor: $person,
            reason: $request->reason(),
        );

        if (! $result->overridden) {
            /*
             * The same flash key the advance uses, and deliberately so: what
             * the reader needs is one place on the screen that says why
             * nothing moved. `refused: true` because every one of these is
             * something somebody or something else did on purpose — a hold, a
             * colleague clearing the gate first, an advisory that was never in
             * the way — rather than a gate to go and chase.
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
             * The toast names the follow-up rather than the override, because
             * the override is the part the person just typed and the task is
             * the part they did not ask for. #69: *"an override defers an
             * obligation; it does not delete one"* — and a person who does not
             * know the task exists is a person who will not do it.
             */
            'message' => __('Overridden. A follow-up task is on your list, due today.'),
        ]);

        return back(fallback: route('deals.show', $deal));
    }
}
