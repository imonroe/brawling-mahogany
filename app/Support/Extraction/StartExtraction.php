<?php

declare(strict_types=1);

namespace App\Support\Extraction;

use App\Enums\ExtractionKind;
use App\Enums\ExtractionState;
use App\Jobs\RunDocumentExtraction;
use App\Models\Deal;
use App\Models\Document;
use App\Models\Extraction;
use App\Models\Person;
use App\Support\Audit\AuditLogger;
use App\Support\Documents\DocumentStorage;
use App\Support\Documents\ReadableText;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Put a document in the queue to be read (PRD §8.4 step 3 · issue #115).
 *
 * PRD §8.4 opens with the rule the whole slice inherits: **never synchronous,
 * never trusted.** This class is the "never synchronous" half — it does the
 * three checks that are cheap and answerable now, writes one row, and hands the
 * rest to a worker.
 *
 * ## Why the checks happen here and again in the worker
 *
 * The spend cap in particular is checked twice, and that is not belt-and-braces
 * for its own sake. Here it is **courtesy**: somebody pressing the button is
 * told immediately rather than watching a job queue and fail. In
 * `PerformExtraction`, immediately before the provider call, it is the
 * **control** — a queue can hold a job long enough for twenty siblings to spend
 * what was left, and only the check adjacent to the spending decides anything.
 *
 * ## Row inside the transaction, job after the commit
 *
 * `AdvanceWorkflow::dispatchRaised()`'s rule, and it holds here for both of its
 * reasons. A row written outside the transaction can survive a rollback and sit
 * in a review queue for an extraction that never happened; a job dispatched
 * inside can be picked up by a worker before the commit lands and find nothing.
 */
final class StartExtraction
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly SpendLedger $ledger,
        private readonly DocumentStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ExtractionRefused
     */
    public function start(
        Document $document,
        Deal $deal,
        ExtractionKind $kind,
        Person $actor,
    ): Extraction {
        if (! $this->providers->isAvailable()) {
            throw ExtractionRefused::notAvailable();
        }

        /*
         * One at a time per document. Not an optimisation — two workers reading
         * the same contract produce two review screens proposing the same
         * eleven dates, and somebody confirms both.
         */
        $running = Extraction::query()
            ->where('document_id', $document->getKey())
            ->whereIn('state', [ExtractionState::Queued->value, ExtractionState::Processing->value])
            ->exists();

        if ($running) {
            throw ExtractionRefused::alreadyRunning();
        }

        /*
         * Read the words *before* writing a row.
         *
         * A file with no text layer produces nothing however good the model is
         * — this product has no OCR, and PRD A10 flags reading a scanned
         * contract as unverified. Finding that out here costs one read of a
         * file already on disk; finding it out in the worker costs a provider
         * call and leaves a failed row that reads like the model's fault.
         */
        $text = ReadableText::from($this->storage->contents($document), $document->mime_type);

        if ($text === null || trim($text) === '') {
            throw ExtractionRefused::unreadable();
        }

        $decision = $this->ledger->decide($deal->team);

        if (! $decision->allowed) {
            /*
             * The refusal is recorded, and it is a row rather than only a
             * thrown exception. #113: hitting the cap *"does not silently
             * degrade"* — and a cap that stopped work while leaving no trace
             * would be indistinguishable, a month later, from a feature nobody
             * used. S68 shows these.
             */
            $this->write($document, $deal, $kind, $actor, ExtractionState::Blocked, $decision);

            throw ExtractionRefused::capped($decision);
        }

        try {
            $extraction = DB::transaction(fn (): Extraction => $this->write(
                $document,
                $deal,
                $kind,
                $actor,
                ExtractionState::Queued,
                null,
            ));
        } catch (UniqueConstraintViolationException) {
            /*
             * `extractions_one_running` caught what the check above raced with.
             *
             * The `exists()` two screens up is a read followed by a write, so
             * two presses on a slow connection both see "nothing running" and
             * both queue — two provider calls for one contract, and a review
             * screen showing every proposal twice. The index is what actually
             * decides it; this branch is only how the loser is told, and it is
             * told the same ordinary sentence as the caller who lost by a
             * second rather than by a millisecond.
             */
            throw ExtractionRefused::alreadyRunning();
        }

        dispatch((new RunDocumentExtraction($extraction->getKey()))->forTeam($extraction->team_id));

        return $extraction;
    }

    private function write(
        Document $document,
        Deal $deal,
        ExtractionKind $kind,
        Person $actor,
        ExtractionState $state,
        ?SpendDecision $decision,
    ): Extraction {
        $extraction = new Extraction;

        $extraction->forceFill([
            'document_id' => $document->getKey(),
            'deal_id' => $deal->getKey(),
            'kind' => $kind->value,
            'state' => $state->value,
            'requested_by' => $actor->getKey(),
            'error' => $decision?->message,
            'error_code' => $decision?->reasonCode,
            'completed_at' => $state === ExtractionState::Blocked ? now() : null,
        ])->save();

        /*
         * PRD §9 puts document access in the audit log, and this is an access:
         * the bytes were read and are about to be sent to a third party. The
         * entry names the document and the kind, and carries no content — the
         * `redacted_text` column on the row is where "what was disclosed"
         * lives, under the team's own retention.
         */
        $this->audit->record(
            action: 'extraction.started',
            auditable: $extraction,
            actorPersonId: $actor->getKey(),
            after: [
                'document_id' => $document->getKey(),
                'deal_id' => $deal->getKey(),
                'kind' => $kind->value,
                'state' => $state->value,
            ],
        );

        return $extraction;
    }
}
