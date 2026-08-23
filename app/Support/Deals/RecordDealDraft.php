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
     * The draft this person left, or a new one.
     *
     * **Per person, not per team.** Two agents starting deals at the same time
     * are doing two different things, and resuming into a colleague's
     * half-typed address would be worse than losing your own.
     */
    public function open(Person $person): DealDraft
    {
        $existing = DealDraft::query()
            ->open()
            ->where('created_by_person_id', $person->getKey())
            ->latest('updated_at')
            ->first();

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
        $draft->forceFill([
            'payload' => [...($draft->payload ?? []), ...$answers],
        ]);

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
