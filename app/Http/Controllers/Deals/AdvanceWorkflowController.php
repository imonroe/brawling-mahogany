<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\AdvanceWorkflowRequest;
use App\Models\Deal;
use App\Models\Person;
use App\Models\Stage;
use App\Models\Workflow;
use App\Support\Workflow\AdvancePreview;
use App\Support\Workflow\AdvanceWorkflow;
use App\Support\Workflow\DescribeBlockers;
use Illuminate\Http\JsonResponse;
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
 * ## Override and skip
 *
 * F4.9's override arrived with S23 (#77, #69) and lives on
 * `OverrideGateController`, which hands to `AdvanceWorkflow::override()` —
 * inside the service, never here. F4.12's skip is a third verb with a
 * different audit meaning (IA §7) and is still #70's work.
 */
class AdvanceWorkflowController extends Controller
{
    /**
     * Everything S23 shows before the click (issue #77).
     *
     * JSON rather than an Inertia page, because S23 is a **modal** reachable
     * from all eight deal tabs — Design System §8.4 puts Advance in the header
     * — and a page prop would exist on the one tab that thought to build it.
     * `AttachWorkflowDialog` fetches its templates the same way.
     *
     * Read fresh at the moment the dialog opens rather than served off the
     * page. The whole value of this screen is that its refusal is current: a
     * gate a colleague cleared two minutes ago must not still be listed as
     * what is stopping the deal, and an overridden gate has to come back
     * cleared without a full page reload.
     *
     * Nothing here writes. `DescribeBlockers` re-runs the evaluators and does
     * not touch `stages.state` or the `gates.is_met` cache, which is what lets
     * a person open and close this dialog all afternoon without changing the
     * record it describes.
     */
    public function show(Deal $deal, Workflow $workflow, DescribeBlockers $blockers): JsonResponse
    {
        $this->authorize('advance', $workflow);

        $workflow->load('stages.gates');
        $workflow->setRelation('deal', $deal);

        foreach ($workflow->stages as $stage) {
            $stage->setRelation('workflow', $workflow);
        }

        $stage = $workflow->activeStage();

        /*
         * A dialog opened on a workflow with nowhere to go gets a sentence,
         * not a 404. `handle()` throws `NothingToAdvance` here because a
         * *post* means a screen offered a button it should not have — but a
         * read is how a screen finds that out, and the honest answer to "what
         * would advancing do" on a finished workflow is "nothing, and here is
         * why".
         */
        if (! $workflow->isRunning() || ! $stage instanceof Stage) {
            return response()->json([
                'stage' => null,
                'refusal' => $workflow->isRunning()
                    ? 'This workflow has no stage in progress, so there is nothing to advance.'
                    : $workflow->state->advanceRefusal(),
            ]);
        }

        return response()->json([
            ...AdvancePreview::for($workflow, $stage, $blockers->forStage($stage)),
            'refusal' => null,
        ]);
    }

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
