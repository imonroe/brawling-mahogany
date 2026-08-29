<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Extraction;
use App\Queries\ExtractionHistory;
use App\Support\Tenancy\TeamContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S68 — extraction history (PRD §4.10 F10.4, §12.3 · Screen Inventory S68 · #118).
 *
 * One screen answering three questions, which is why it is a screen rather
 * than a panel on S72. #118 names them: **audit** (*who confirmed this date,
 * on what date?* — asked when something has gone wrong), **cost** (*what is
 * this team costing, and are we under $2 per deal?* — asked monthly), and
 * **quality** (*what is the model getting wrong, and has a version change made
 * it worse?* — asked continuously). Three readers, three regions, one payload
 * composed by `App\Queries\ExtractionHistory`.
 *
 * ## Read-only, and gated on `settings.manage` rather than `extraction.confirm`
 *
 * `extraction.confirm` is the key for *accepting a model's date into a live
 * contingency calendar*, and Slice 5 deliberately moved it down to Team Member
 * because S66's user is the transaction coordinator (see
 * `Permissions::forSystemRoles()`). This screen is a different question asked
 * by a different person: it shows **the team's spend against a monthly ceiling**
 * and **who confirmed what**, which is the owner's business, not the
 * coordinator's. Gating it on the confirm key would put the bill in front of
 * everybody who does the reviewing.
 *
 * So it authorises **`viewHistory`**, an ability of its own on
 * `ExtractionPolicy` carrying `Permissions::MANAGE_SETTINGS`.
 *
 * It was `TeamPolicy::update` for a round — the right permission behind the
 * wrong verb, on a page that writes nothing — and review was right that a
 * docblock arguing for the wart was worse than the wart: it stopped the next
 * reader fixing a two-line problem. `TeamPolicy::view` was never the answer in
 * the other direction, being *any live membership*, which is far too wide for
 * spend and audit.
 *
 * `tests/Feature/AuthorizationCoverageTest.php` enumerates the route table and
 * fails an action that never asks, which is what makes the sentence above a
 * fact rather than an intention.
 *
 * ## Nothing here is a write, including the numbers
 *
 * The same rule the workflow engine states as *"reading is not advancing"*:
 * this controller composes a read model and renders it. It never starts an
 * extraction, never re-scores one, and never touches `extractions.state` —
 * a settings screen that could re-run the model would be a way to spend the
 * team's money from a page that exists to report what has been spent.
 */
class ExtractionHistoryController extends Controller
{
    public function index(TeamContext $teams, ExtractionHistory $history): Response
    {
        $team = $teams->get();

        $this->authorize('viewHistory', [Extraction::class, $team]);

        $edits = $history->edits($team);
        $attempts = $history->attempts($team);

        return Inertia::render('Settings/Extractions', [
            'scorecard' => $history->scorecard(),
            'spend' => $history->spend($team),
            'versions' => $history->versions(),

            /*
             * F10.4's *"what the human changed"*, sent as its own prop rather
             * than as a column on `attempts`. #118 calls it *"the valuable one —
             * simultaneously the audit trail, the quality metric, and the input
             * to improving the prompt"*, and a payload shape is the only thing
             * that stops it drifting into a cell somebody has to scroll to.
             */
            'edits' => $edits['rows'],
            'editsTotal' => $edits['total'],

            'attempts' => $attempts['rows'],
            'attemptsTotal' => $attempts['total'],
        ]);
    }
}
