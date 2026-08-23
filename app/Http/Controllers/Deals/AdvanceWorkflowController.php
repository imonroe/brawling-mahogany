<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\AdvanceWorkflowRequest;
use App\Models\Deal;
use App\Models\Person;
use App\Models\Workflow;
use App\Support\Workflow\AdvanceWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Advance a workflow (PRD §4.4 F4.8 · issue #75, and the endpoint S23 reuses).
 *
 * ## The first HTTP caller `AdvanceWorkflow` has ever had
 *
 * The service has existed since #68 with no route in front of it — reachable
 * only from tests. Its own docblock says that is the arrangement it wanted:
 * *"Authorisation is the caller's job, not this service's"*, and it takes no
 * `Request` and returns no response. So this controller authorises, hands
 * over, and translates the result. It decides nothing about gates, and it
 * writes no workflow state — `tests/Unit/SingleMutationPathTest.php` is the
 * thing that would catch it trying.
 *
 * ## Every reason, not the first
 *
 * `AdvanceResult::reasons()` returns all of them, for the reason its docblock
 * gives: told about one gate, somebody clears it, clicks again, and is told
 * about the next. They are flashed as a list rather than pushed through the
 * validation error bag, because Inertia's error resolution keeps only the
 * first message per key — an error bag would have silently reintroduced
 * exactly the behaviour #68 wrote the result object to avoid.
 *
 * ## A workflow with nothing to advance is still a 500, deliberately
 *
 * `NothingToAdvance` is *"a programming error rather than a refusal … a screen
 * offered a button it should not have"*. Catching it here to render a polite
 * sentence would hide that bug behind a shrug, so it is not caught.
 * `DealHeader::advanceTarget()` and the Overview's cards both offer the button
 * only when there is an active stage, which is what keeps it unreachable.
 *
 * ## Override and skip are deliberately absent
 *
 * F4.9's override and F4.12's skip are S23/S24 (#77, #69, #70), and both have
 * to go **inside** `AdvanceWorkflow` rather than here. Three of the seven gate
 * types cannot clear on their own in Slice 2 — document present, action
 * completed and date reached each return `GateVerdict::notYetWired()`, whose
 * own sentence tells the reader to override — so override is load-bearing
 * rather than optional, and #77 follows this closely rather than eventually.
 */
class AdvanceWorkflowController extends Controller
{
    public function store(
        AdvanceWorkflowRequest $request,
        Deal $deal,
        Workflow $workflow,
        AdvanceWorkflow $advance,
    ): RedirectResponse {
        // Authorized by `AdvanceWorkflowRequest`, the way
        // `WorkflowAttachmentController::store()` is by its own request —
        // `AuthorizationCoverageTest` reads either spelling.
        /** @var Person $person */
        $person = $request->user();

        $result = $advance->handle(
            workflow: $workflow,
            actor: $person,
            /*
             * The stage the screen was looking at when the button was pressed.
             * `AdvanceWorkflow` refuses when it no longer matches, which is
             * the difference between "advance the stage I read" and "advance
             * whatever is current now" — on a two-agent team the second is how
             * somebody skips a stage they never saw.
             */
            expectedStageId: $request->expectedStageId(),
        );

        if ($result->wasBlocked()) {
            Inertia::flash('advance', [
                // `wasRefused()` separates "the workflow itself will not move"
                // — a hold, a cancellation, a race — from "a gate is unmet".
                // They need different affordances: one names something
                // somebody did on purpose, the other names something to chase.
                'refused' => $result->wasRefused(),
                /*
                 * Distinct sentences — which, since `reasons()` names each
                 * gate, now means distinct *gates*.
                 *
                 * The dedupe used to hide them instead. Three unmet
                 * manual-confirmation gates produced "Nobody has confirmed
                 * this yet." three times, this line collapsed them to one, and
                 * the reader was told about one blocker when there were three.
                 * That is the failure issue #68 wrote `reasons()` to avoid:
                 * clear it, click again, be told about the next.
                 *
                 * It stays because two gates can still legitimately produce
                 * the same sentence — the same label asking for the same
                 * thing — and repeating that tells the reader nothing.
                 */
                'reasons' => array_values(array_unique($result->reasons())),
            ]);

            return $this->backToDeal($deal);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            /*
             * The milestone sentence when the stage had one. IA §9: it is
             * written for a person, and the internal stage name never stands
             * in for it. Slice 3 turns the same sentence into a message to the
             * client; here it is what the team is told happened.
             */
            'message' => $result->milestoneAnnouncement ?? __('Stage advanced.'),
        ]);

        return $this->backToDeal($deal);
    }

    /**
     * Back to whichever deal tab pressed the button.
     *
     * §8.4 puts Advance in the header, which every one of the eight tabs
     * carries, so there is no single right destination — the person was
     * standing somewhere and should stay there. `DealLayout` renders the
     * flashed reasons, so they are seen whichever tab that was.
     *
     * The fallback is the deal rather than `/`, which is what a bare `back()`
     * would give a POST arriving without a referer.
     */
    private function backToDeal(Deal $deal): RedirectResponse
    {
        return back(fallback: route('deals.show', $deal));
    }
}
