<?php

declare(strict_types=1);

namespace App\Support\Deals;

use App\Enums\DealDraftStep;
use App\Models\DealDraft;
use App\Models\Person;
use App\Support\Tenancy\TeamContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Start, find, and add to a half-finished deal (S14 · issue #74).
 *
 * The half of the wizard that is not creating anything yet. Kept out of the
 * controller for the reason every service in this codebase is: #62 added a
 * second caller to almost every rule #61 wrote into one, and S28 plus the
 * eventual deal overview will do the same here.
 */
final class RecordDealDraft
{
    public function __construct(private readonly TeamContext $teams) {}

    /**
     * The draft this person already has open, if any, without starting one.
     *
     * `open()` creates when it finds nothing, which is right for the wizard's
     * own screens and wrong for anything that only wants to act on an existing
     * draft — abandoning is the case, and creating a row in order to delete it
     * is a strange thing for a 403 to leave behind.
     */
    public function existing(Person $person): ?DealDraft
    {
        return DealDraft::query()
            ->open()
            ->where('created_by_person_id', $person->getKey())
            ->latest('updated_at')
            ->first();
    }

    /**
     * The draft this person left, or a new one.
     *
     * **Per person, not per team.** Two agents starting deals at the same time
     * are doing two different things, and resuming into a colleague's
     * half-typed address would be worse than losing your own.
     */
    public function open(Person $person): DealDraft
    {
        $existing = $this->existing($person);

        if ($existing instanceof DealDraft) {
            return $existing;
        }

        $draft = new DealDraft;
        $draft->forceFill([
            'team_id' => $this->teams->requireId(DealDraft::class),
            'created_by_person_id' => $person->getKey(),
            'step' => DealDraftStep::Type->value,
            'payload' => [],
        ]);

        /*
         * Two tabs opening the wizard at once both find nothing and both
         * insert, and `deal_drafts_one_open_per_person` refuses the second.
         * That is not an error worth showing anybody — they wanted *the*
         * draft, and the winner is it.
         *
         * Re-read rather than retried: a constraint violation aborts the
         * transaction in Postgres, so this deliberately runs outside one.
         */
        try {
            $draft->save();
        } catch (UniqueConstraintViolationException) {
            return DealDraft::query()
                ->open()
                ->where('created_by_person_id', $person->getKey())
                ->latest('updated_at')
                ->firstOrFail();
        }

        return $draft;
    }

    /**
     * Merge one step's answers into the payload.
     *
     * **Merged, never replaced.** A wizard's Back button re-submits an earlier
     * step, and a payload that took whatever arrived would let step two erase
     * steps three and four — the thing a resumable form must not do. Only the
     * keys a step owns are touched, which is why the caller passes them
     * explicitly rather than handing over `validated()`.
     *
     * A `null` value is kept rather than dropped: "I cleared the property" is
     * an answer, and distinguishing it from "I did not reach that step" is the
     * same presence-versus-value rule this codebase has now paid for twice.
     *
     * @param  array<string, mixed>  $answers
     */
    public function record(DealDraft $draft, array $answers, ?DealDraftStep $step = null): DealDraft
    {
        $payload = [...($draft->payload ?? []), ...$answers];

        $draft->forceFill(['payload' => $this->invalidateDerived($draft, $answers, $payload)]);

        if ($step instanceof DealDraftStep) {
            /*
             * The furthest step reached, not the last one submitted. Pressing
             * Back and re-saving step one must not throw away the fact that
             * steps two and three are answered — otherwise a dropped
             * connection at that moment resumes somebody to the beginning.
             */
            $draft->forceFill([
                'step' => $step->position() > $draft->step->position() ? $step : $draft->step,
            ]);
        }

        $draft->save();

        return $draft;
    }

    /**
     * Answers that only made sense under the old deal type.
     *
     * Two of the four steps are *derived* from step one, and changing it left
     * both stale in a way the screen actively lied about:
     *
     * - **The client's role.** `CreateDealFromDraft` prefers a chosen role
     *   over the implied one — correct, because a rental has no implied one to
     *   fall back on. But a role chosen under a Rental then survived a switch
     *   to a Sale, so the screen said "they'll be added as the Seller" and the
     *   deal got `other`, with S19 warning about a missing Seller the moment
     *   it was created.
     * - **The workflow template.** The picker is filtered by the deal type's
     *   associations, so after a switch it offered "Buying a Property" while
     *   the draft still held "Selling a Property" — and the last button
     *   attached the one nothing on screen had named.
     *
     * Cleared rather than re-derived, because clearing sends somebody back to
     * a question they can answer and guessing does not. `participant_role` is
     * dropped entirely rather than set to null, so the step's `requiredIf`
     * sees an unanswered question rather than a refused one.
     *
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function invalidateDerived(DealDraft $draft, array $answers, array $payload): array
    {
        if (! array_key_exists('deal_type_id', $answers)) {
            return $payload;
        }

        $previous = $draft->text('deal_type_id');

        if ($previous === null || $previous === $answers['deal_type_id']) {
            return $payload;
        }

        unset($payload['participant_role'], $payload['workflow_template_id']);

        return $payload;
    }

    /**
     * Give up on it.
     *
     * Soft, so `records:purge` sweeps it on the house schedule (PRD §9) — and
     * so the partial unique index frees the slot immediately, which is what
     * lets somebody abandon a draft and start a new one in the same breath.
     */
    public function abandon(DealDraft $draft): void
    {
        DB::transaction(fn () => $draft->delete());
    }
}
