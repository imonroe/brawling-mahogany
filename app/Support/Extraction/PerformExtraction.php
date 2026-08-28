<?php

declare(strict_types=1);

namespace App\Support\Extraction;

use App\Enums\ExtractedFieldReviewState;
use App\Enums\ExtractionState;
use App\Enums\NotificationType;
use App\Logging\Redactor as LogRedactor;
use App\Models\ExtractedField;
use App\Models\Extraction;
use App\Models\Team;
use App\Support\Documents\DocumentStorage;
use App\Support\Documents\ReadableText;
use App\Support\Extraction\Redaction\Redactor;
use App\Support\Extraction\Redaction\RedactionFailed;
use App\Support\Notifications\NotificationAudience;
use App\Support\Notifications\Notify;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The worker body: redact, call, write (PRD §8.4 step 4 · issue #115).
 *
 * ## The claim is a conditional UPDATE, not a read followed by a write
 *
 * `message_key`'s pattern, one table over, and for the same reason: two workers
 * that both read `queued` both proceed, and the cost of that here is a
 * duplicate provider call and a review screen with every proposal twice. So the
 * transition is `UPDATE … WHERE state = 'queued'` and the worker that changed
 * no rows stands down **silently** — it did not lose a race it should complain
 * about, it arrived second, which is the ordinary case.
 *
 * ## Redaction is not a step this method could skip
 *
 * It could not skip it even if it tried: `ExtractionProvider::extract()` takes
 * a `RedactedDocument`, and the only thing that produces one is `Redactor`.
 * That is #114's *"enforced structurally, not by convention"*, and the sequence
 * below is a consequence of the types rather than a discipline this file keeps.
 *
 * ## Failure is a state
 *
 * #115: *"Failure is a state, not an exception the user meets as a 500."* Every
 * throw below lands on the row with a sentence a person can read and an
 * enumerated code an operator can grep. The one thing that is deliberately
 * **re-thrown** is a retryable provider failure, because the queue's four
 * attempts are the right handling for a transport outage and swallowing it here
 * would spend the retry budget on nothing.
 */
final class PerformExtraction
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly PromptRegistry $prompts,
        private readonly Redactor $redactor,
        private readonly ReadProposals $reader,
        private readonly SpendLedger $ledger,
        private readonly DocumentStorage $storage,
        private readonly Notify $notify,
        private readonly NotificationAudience $audience,
    ) {}

    public function perform(Extraction $extraction, Team $team): void
    {
        if (! $this->claim($extraction)) {
            return;
        }

        try {
            $this->run($extraction, $team);
        } catch (ProviderFailed $failure) {
            if ($failure->isRetryable) {
                /*
                 * Back to `queued` and re-thrown, so the queue's own four
                 * attempts do the retrying.
                 *
                 * **No notification here**, and that is the whole reason this
                 * branch is separate. Telling the team on every attempt would
                 * send four emails about one provider blip, which is how a
                 * notification type earns a filter rule and stops being read.
                 * The row keeps the error so S65 can say what is happening
                 * while the next attempt is pending, and the team is told once
                 * — by `RunDocumentExtraction::failed()`, when the attempts are
                 * actually exhausted.
                 */
                $extraction->forceFill([
                    'state' => ExtractionState::Queued->value,
                    'error' => $failure->getMessage(),
                    'error_code' => $failure->reasonCode,
                ])->save();

                throw $failure;
            }

            $this->fail($extraction, $team, $failure->reasonCode, $failure->getMessage());
        } catch (RedactionFailed $failure) {
            /*
             * Nothing left. `Redactor` throws rather than returning what it
             * could not finish, so this branch is reached with the document
             * unsent — which is the only acceptable outcome of a redactor that
             * could not do its job (PRD §9).
             */
            $this->fail(
                $extraction,
                $team,
                'redaction_failed',
                'This document could not be prepared safely, so nothing was sent. It will need reading by hand.',
            );
        }
    }

    /**
     * Take the row, or find somebody already has it.
     *
     * @return bool whether this worker owns the extraction
     */
    private function claim(Extraction $extraction): bool
    {
        $claimed = Extraction::query()
            ->whereKey($extraction->getKey())
            ->where('state', ExtractionState::Queued->value)
            ->update([
                'state' => ExtractionState::Processing->value,
                'started_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $extraction->refresh();

        return true;
    }

    /**
     * @throws ProviderFailed
     * @throws RedactionFailed
     */
    private function run(Extraction $extraction, Team $team): void
    {
        /*
         * The cap, immediately before the money is spent. `StartExtraction`
         * asked too, and its answer is stale by however long this sat in a
         * queue — which on a busy morning is long enough for the rest of the
         * month's budget to have gone.
         */
        $decision = $this->ledger->decide($team);

        if (! $decision->allowed) {
            $this->block($extraction, $team, $decision);

            return;
        }

        $document = $extraction->document;
        $text = ReadableText::from($this->storage->contents($document), $document->mime_type);

        if ($text === null || trim($text) === '') {
            $this->fail(
                $extraction,
                $team,
                'document_has_no_text',
                'There are no words in this file to read, so nothing was sent.',
            );

            return;
        }

        $redacted = $this->redactor->redact($text);

        $extraction->forceFill([
            /*
             * Written *before* the call, not after.
             *
             * #114 asks that what was disclosed be knowable after the fact, and
             * a record written only on success answers that question for every
             * case except the one where somebody asks it — a call that timed
             * out may still have been received, so the text may still have
             * left. Recording it first makes the answer true either way.
             */
            'redacted_text' => $redacted->text,
            'redaction_report' => $redacted->report->toArray(),
        ])->save();

        $prompt = $this->prompts->for($extraction->kind);
        $provider = $this->providers->provider();

        $result = $provider->extract($redacted, $prompt);

        if ($result->costMicros === 0) {
            /*
             * The model ran and nothing was charged, which means
             * `config('extraction.pricing')` has no rates for it. Not fatal —
             * refusing to run an unpriced model would make trying a new one a
             * code change, which is the friction #118's harness exists to
             * remove — but it silently discounts the spend cap, so it is said
             * out loud. `reason_code`, never `reason`: `Redactor`'s
             * `SENSITIVE_KEY_PARTS` holds the second and would deliver this to
             * the operator as `[redacted]`.
             */
            Log::warning('extraction.pricing_missing', LogRedactor::context([
                'reason_code' => 'pricing_missing',
                'model' => $result->model,
                'extraction_id' => $extraction->getKey(),
            ]));
        }

        $proposals = $this->reader->from($result->raw, $extraction->kind);

        DB::transaction(function () use ($extraction, $result, $proposals): void {
            $extraction->forceFill([
                'state' => ExtractionState::Complete->value,
                'provider' => $result->provider,
                'model' => $result->model,
                'model_version' => $result->modelVersion,
                'prompt_version' => $this->prompts->for($extraction->kind)->version(),
                'raw_response' => $result->raw,
                'cost_micros' => $result->costMicros,
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'latency_ms' => $result->latencyMs,
                'completed_at' => now(),
                'error' => null,
                'error_code' => null,
            ])->save();

            foreach ($proposals as $index => $proposal) {
                $field = new ExtractedField;

                $field->forceFill([
                    'extraction_id' => $extraction->getKey(),
                    'field_type' => $proposal->type->value,
                    'label' => mb_substr($proposal->label, 0, 255),
                    'proposed_value' => $proposal->value,
                    'confidence' => $proposal->confidence,
                    'source_page' => $proposal->sourcePage,
                    'source_snippet' => $proposal->sourceSnippet,
                    'review_state' => ExtractedFieldReviewState::Pending->value,
                    'payload' => $proposal->payload === [] ? null : $proposal->payload,
                    'sort_order' => $index,
                ])->save();
            }
        });
    }

    private function block(Extraction $extraction, Team $team, SpendDecision $decision): void
    {
        $extraction->forceFill([
            'state' => ExtractionState::Blocked->value,
            'error' => $decision->message,
            'error_code' => $decision->reasonCode,
            'completed_at' => now(),
        ])->save();

        $this->tell(
            $team,
            NotificationType::ExtractionCapReached,
            (string) $decision->message,
            ['extractionId' => $extraction->getKey(), 'reasonCode' => $decision->reasonCode],
        );
    }

    /**
     * Record a permanent failure and tell the people who can act on it.
     *
     * Public because `RunDocumentExtraction::failed()` calls it: a retryable
     * failure is recorded but not announced until the queue has given up, and
     * the only place that knows the attempts are exhausted is the job.
     */
    public function fail(Extraction $extraction, Team $team, string $code, string $message): void
    {
        $extraction->forceFill([
            'state' => ExtractionState::Failed->value,
            'error' => $message,
            'error_code' => $code,
            'completed_at' => now(),
        ])->save();

        $this->tell(
            $team,
            NotificationType::ExtractionFailed,
            $message,
            ['extractionId' => $extraction->getKey(), 'reasonCode' => $code],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function tell(Team $team, NotificationType $type, string $summary, array $data): void
    {
        /*
         * `Notify` is the only writer of `notifications`, held by
         * `SingleNotificationWriterTest` — a caller says what happened and who
         * should know, and decides no channels of its own.
         *
         * Who should know is whoever can do something about it: on a failure,
         * the people who could start it again; on a cap, the same people, since
         * an owner is the one who can raise it. Not the requester alone — a
         * transaction coordinator who uploaded a contract before a day off
         * should not be the only person who finds out it never got read.
         */
        $this->notify->send(
            type: $type,
            people: $this->audience->holding($team, Permissions::CONFIRM_EXTRACTION),
            team: $team,
            summary: $summary,
            data: $data,
        );
    }
}
