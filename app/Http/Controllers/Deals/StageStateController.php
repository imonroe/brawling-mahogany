<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\SkipStageRequest;
use App\Models\Deal;
use App\Models\Person;
use App\Models\Stage;
use App\Models\Workflow;
use App\Support\Workflow\AdvanceWorkflow;
use App\Support\Workflow\StageChangeResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The two stage verbs that are not Advance (PRD §4.4 F4.12 · S16 · #70).
 *
 * **Skip** marks a stage not applicable to this deal; **Reopen** undoes the
 * last thing the workflow finished with. Both write through
 * `AdvanceWorkflow`, which is where every write to `stages.state` lives —
 * `SingleMutationPathTest` reads the source of `app/` and fails a controller
 * that writes it directly, and that guard is the reason the audit entry and
 * the timeline marker cannot be forgotten by one caller and remembered by
 * another.
 *
 * Two methods on one controller rather than two controllers, because they are
 * the same act read in two directions and share every piece of plumbing. Two
 * **routes**, though, and never one with a mode flag: IA §7 calls conflating
 * these verbs legally material, and a shared endpoint is that conflation in
 * URL form.
 */
class StageStateController extends Controller
{
    public function skip(
        SkipStageRequest $request,
        Deal $deal,
        Workflow $workflow,
        Stage $stage,
        AdvanceWorkflow $advance,
    ): RedirectResponse {
        // Authorized by `SkipStageRequest` against `stage.skip`.
        /** @var Person $person */
        $person = $request->user();

        return $this->respond(
            $advance->skip($workflow, $stage, $person, $request->reason()),
            $deal,
            __('Stage skipped.'),
        );
    }

    /**
     * Reopen is authorized as an **advance**, deliberately.
     *
     * It is the inverse of one — "undo the last advance" — and it waives
     * nothing and marks nothing inapplicable, so it does not belong behind
     * `stage.skip`, and inventing `stage.reopen` would put a fourth verb in a
     * catalogue IA §7 keeps at three. Somebody trusted to move a deal forward
     * is trusted to move it back one, and the audit entry names them.
     */
    public function reopen(
        Request $request,
        Deal $deal,
        Workflow $workflow,
        Stage $stage,
        AdvanceWorkflow $advance,
    ): RedirectResponse {
        $this->authorize('advance', $workflow);

        /** @var Person $person */
        $person = $request->user();

        return $this->respond(
            $advance->reopen($workflow, $stage, $person),
            $deal,
            __('Stage reopened.'),
        );
    }

    /**
     * One reply for both, and a refusal is not an error.
     *
     * The same `advance` flash key the other two controllers use, because what
     * a reader needs is one place on the screen that says why nothing moved —
     * and every refusal here is something somebody else did on purpose while
     * this modal was open.
     */
    private function respond(StageChangeResult $result, Deal $deal, string $message): RedirectResponse
    {
        if (! $result->changed) {
            Inertia::flash('advance', [
                'refused' => true,
                'reasons' => [$result->refusal],
            ]);

            return back(fallback: route('deals.show', $deal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back(fallback: route('deals.show', $deal));
    }
}
