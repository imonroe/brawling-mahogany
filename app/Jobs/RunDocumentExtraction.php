<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\RunsForTeam;
use App\Models\Extraction;
use App\Support\Extraction\PerformExtraction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

    public function __construct(public readonly string $extractionId) {}

    public function uniqueId(): string
    {
        return $this->extractionId;
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
