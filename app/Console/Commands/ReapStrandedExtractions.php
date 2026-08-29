<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ExtractionState;
use App\Jobs\RunDocumentExtraction;
use App\Models\Extraction;
use App\Support\Extraction\PerformExtraction;
use App\Support\Tenancy\TeamContext;
use Illuminate\Console\Command;

/**
 * A read nobody came back from, and the document it holds hostage (#115).
 *
 * ## The row is not the problem; the index is
 *
 * `extractions_one_running` is a partial unique index over
 * `(team_id, document_id)` where the state is `queued` or `processing`, and it
 * is there for a real reason: two workers reading one contract produce two
 * review screens proposing the same eleven dates, and somebody confirms both.
 *
 * The cost of that guarantee is that a row which stops moving takes the
 * document with it. `StartExtraction` refuses a second attempt while a sibling
 * is running, and a `processing` row left behind by a worker that was killed —
 * an OOM, a `queue:restart`, a container eviction, a deploy — is *"running"*
 * as far as both the index and that check can tell, forever. The team's only
 * remaining move is to upload the same file again under a different id.
 *
 * `RunDocumentExtraction::failed()` covers the ordinary end of that: a job that
 * exhausts its attempts writes a terminal state and tells somebody. It cannot
 * cover a worker that is killed without running its own shutdown, which is
 * precisely the case that strands a row.
 *
 * ## Two states, two different answers
 *
 * - **`processing`** is failed, never retried. The claim was taken, so the
 *   provider may have been called and the money may already be spent —
 *   `automations:reap-unconfirmed`'s rule one subsystem over, for the same
 *   reason: nobody knows what happened, so a person is told rather than a
 *   second call being guessed at.
 * - **`queued`** is re-dispatched. Nothing was claimed and nothing was spent,
 *   so the honest repair is to run it. This is the window
 *   `automations:dispatch-due` sweeps for automations — a web process that died
 *   between the commit and the dispatch leaves a perfectly good row with no job
 *   coming for it. `ShouldBeUnique` makes a redundant dispatch a no-op, so a
 *   row that really is still in the queue costs nothing.
 *
 * ## The threshold is distance, not a guess about liveness
 *
 * `ReapUnconfirmedSends` argues this at length and the argument holds here: a
 * claim's age cannot distinguish a dead worker from a live sibling, because the
 * second delivery happens *because* the claim aged past the visibility window.
 * So the default is hours rather than minutes — far enough out that there is no
 * live read left to contradict, and generous against the one legitimate long
 * case (a scanned contract through a vision model, at a provider timeout this
 * command deliberately does not try to track).
 *
 * ## Unscoped, like every sweep
 *
 * A scheduled run has no session. The question — which rows have stopped moving
 * — spans every team, and each row is then handled inside `runFor()` on its own
 * team so the state write and the notification land under the right tenant.
 */
class ReapStrandedExtractions extends Command
{
    protected $signature = 'extractions:reap-stranded {--hours=3} {--limit=500}';

    protected $description = 'Release documents held by an extraction that stopped moving';

    public function handle(PerformExtraction $extractions, TeamContext $teams): int
    {
        $olderThan = now()->subHours(max(1, (int) $this->option('hours')));
        $limit = max(1, (int) $this->option('limit'));

        $failed = 0;
        $requeued = 0;

        Extraction::withoutTeamScope()
            ->whereIn('state', [ExtractionState::Queued->value, ExtractionState::Processing->value])
            ->where('updated_at', '<=', $olderThan)
            /*
             * A row whose team is gone is excluded by the query rather than
             * skipped in the loop — `ReapUnconfirmedSends`'s reason, which is
             * about the *limit* rather than about correctness: skipped rows
             * match again on every run, and enough of them at the head of the
             * id order consume the whole page forever.
             */
            ->whereHas('team')
            ->orderBy('id')
            ->limit($limit)
            ->eachById(function (Extraction $extraction) use ($extractions, $teams, &$failed, &$requeued): void {
                $team = $extraction->team;

                if ($team === null) {
                    return;
                }

                if ($extraction->state === ExtractionState::Queued) {
                    /*
                     * Dispatched exactly as `StartExtraction` does it, carrying
                     * the team id — `RunsForTeam` throws rather than running
                     * unscoped, so a hand-off that forgot this would fail
                     * loudly rather than quietly reading somebody else's rows.
                     * No `runFor()` around it: nothing here reads a scoped row,
                     * and the worker establishes its own context.
                     */
                    dispatch(
                        (new RunDocumentExtraction($extraction->getKey()))
                            ->forTeam($extraction->team_id),
                    );

                    $requeued++;

                    return;
                }

                $teams->runFor($team, fn () => $extractions->fail(
                    $extraction,
                    $team,
                    'extraction_stranded',
                    /*
                     * What happened, and what it costs the reader — stated in
                     * the ambiguity it actually has. *"It did not finish"* is
                     * the honest claim; *"it failed"* would assert something
                     * nobody knows, and this row may have been charged for.
                     */
                    'Reading this document stopped part-way through and did not come back. '
                        .'Nothing was written to the deal. You can extract it again from Documents.',
                ));

                $failed++;
            });

        $this->info("Released {$failed} stranded extraction(s) and re-queued {$requeued}.");

        return self::SUCCESS;
    }
}
