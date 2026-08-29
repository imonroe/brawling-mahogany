<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\RunsForTeam;
use App\Models\Extraction;
use App\Models\Team;
use App\Support\Extraction\PerformExtraction;
use App\Support\Extraction\ProviderFailed;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Read one document (PRD §8.4 · issue #115).
 *
 * ## Four attempts, and what each one is for
 *
 * A provider outage is the case worth retrying, and it is the only one:
 * `PerformExtraction` re-throws exactly the failures `ProviderFailed` marks
 * retryable and swallows the rest onto the row. So the attempts are spent on
 * transport and nothing else — an unparseable answer or a document with no
 * words in it fails once, visibly, and costs one call rather than four.
 *
 * That number is the same as `RunAutomation`'s and for the same reason: after
 * four, the row's own record of the failure stands and a person decides.
 *
 * ## `ShouldBeUnique` is the cheap first door, not the lock
 *
 * The lock is `PerformExtraction::claim()`, a conditional `UPDATE … WHERE state
 * = 'queued'`. This interface narrows the window; it does not close it, and
 * treating it as a guarantee is how two workers end up charging twice for one
 * contract.
 *
 * ## The constructor takes an id, never the model
 *
 * House style, and here it earns it twice over: the row is re-read so the
 * spend cap and the claim both see current state, and a serialised `Extraction`
 * would carry `raw_response` and `redacted_text` — the document's own words —
 * through the queue's payload store.
 */
class RunDocumentExtraction implements ShouldBeUnique, ShouldQueue
{
    use Queueable, RunsForTeam;

    public int $tries = 4;

    /**
     * Longer than the provider call it wraps, which it was not.
     *
     * Horizon's supervisor runs workers at `timeout` **60**, and
     * `config('extraction.anthropic.timeout')` is **180** — *"generous, because
     * this runs in a queue worker rather than in front of somebody"*. So every
     * read that took over a minute was killed by the worker two minutes before
     * the provider gave up, and the generosity in that config value bought
     * nothing at all. Worse than nothing: the money is spent by then, and a
     * SIGALRM at the sixtieth second leaves a row `processing` with no outcome.
     *
     * `Worker::timeoutForJob()` prefers a job's own value over the worker's, so
     * this is the number that applies. It is derived rather than written down,
     * because the two drifting apart is exactly the defect above — and the
     * margin is for the work either side of the call (redaction on the way out,
     * `ReadProposals` on the way back), which the provider's own timeout does
     * not cover.
     *
     * `tests/Unit/Extraction/ExtractionTimeoutsTest.php` holds the three
     * numbers in order, including `queue.connections.redis.retry_after` — a
     * `retry_after` below this timeout hands the same row to a second worker
     * while the first is still reading it.
     */
    public int $timeout;

    public function __construct(public readonly string $extractionId)
    {
        $this->timeout = (int) config('extraction.anthropic.timeout', 180) + 60;
    }

    public function uniqueId(): string
    {
        return $this->extractionId;
    }

    /**
     * The attempts are spent, so say so — once.
     *
     * ## This method is the other half of a rule stated in `PerformExtraction`
     *
     * That class deliberately writes **no notification** on a retryable
     * failure: four attempts at one provider blip would be four emails about
     * one outage, which is how a notification type earns a filter rule and
     * stops being read. It puts the row back to `queued` and re-throws, and its
     * docblock says the team is told once, here, when the queue gives up.
     *
     * It was written before this method existed, which made it exactly the
     * thing CLAUDE.md warns about — *a rule stated in a docblock is not a rule
     * the code follows*. Without this handler a provider outage that burned all
     * four attempts left the row **`queued` forever**, carrying an error string
     * that no screen treats as final and that nobody was told about. Found in
     * review, and it is worth noting how: not by a test of this class, but by
     * somebody reading the two docblocks against each other.
     *
     * `$this->teamId` is set by `forTeam()` at dispatch and survives
     * serialisation, so the team context is re-established the same way
     * `handle()` does it.
     */
    public function failed(?Throwable $exception): void
    {
        $this->withinTeam(function (Team $team) use ($exception): void {
            $extraction = Extraction::query()->find($this->extractionId);

            if (! $extraction instanceof Extraction || $extraction->state->isFinal()) {
                /*
                 * Purged, or a later attempt already recorded an outcome.
                 * Stand down silently rather than overwriting it — the
                 * `SendDecision::standDown()` rule one subsystem over: "not
                 * mine" and "broken" are different refusals.
                 */
                return;
            }

            app(PerformExtraction::class)->fail(
                $extraction,
                $team,
                $exception instanceof ProviderFailed ? $exception->reasonCode : 'extraction_gave_up',
                /*
                 * A **fresh** sentence, never the row's own.
                 *
                 * The obvious version reused `$extraction->error`, on the
                 * reasoning that the last attempt had already written something
                 * for a person to read. It had — and on the one path that
                 * reaches here it says *"This will be tried again."*
                 * `ProviderFailed::unavailable()` is the retryable case, so it
                 * is precisely the message on the row when the retries run out,
                 * and the `??` fallback beside it was unreachable for the
                 * failure it was written for. A terminal state promising
                 * another attempt is worse than no message: somebody waits.
                 *
                 * An exception's own message is not used either — it is written
                 * for a log and can carry whatever the provider put in a
                 * response body.
                 */
                'This document could not be read after several attempts. '
                    .'Its dates and tasks will need entering by hand.',
            );
        });
    }

    public function handle(PerformExtraction $extractions): void
    {
        $this->withinTeam(function ($team) use ($extractions): void {
            $extraction = Extraction::query()->find($this->extractionId);

            if (! $extraction instanceof Extraction) {
                // Purged, or the deal was deleted under it. The absence of the
                // row is the record of what happened.
                return;
            }

            $extractions->perform($extraction, $team);
        });
    }
}
