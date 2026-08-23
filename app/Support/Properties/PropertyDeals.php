<?php

declare(strict_types=1);

namespace App\Support\Properties;

use App\Enums\DealSide;
use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\Property;
use App\Support\Activity\RecordActivity;
use App\Support\Deals\NameDeal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The link between a property and a deal (S36 · issue #61).
 *
 * A service rather than a pivot `attach()`, because linking is more than a
 * write: the first property on a deal becomes its subject, and that decides
 * what the deal is called (IA §10).
 *
 * #62 owns the deal side — the properties tab, the interest vocabulary, and
 * the interaction that moves the subject from one property to another. This
 * is the property side and the rule underneath both.
 */
final class PropertyDeals
{
    public function __construct(
        private readonly RecordActivity $activity,
        private readonly NameDeal $names,
    ) {}

    /**
     * Put a property on a deal.
     *
     * ## Which links become the subject, and which do not
     *
     * A deal's generated name is *"subject property street address"* (IA §10),
     * and `GenerateDealName` has no other way to be given one. So on a
     * **sell-side** deal the first property becomes the subject: there is
     * exactly one house, nobody linking it means "and this is not the one the
     * deal is about", and without the flag the deal cannot be named at all.
     *
     * On a **buy-side** deal it does not, and issue #62 is explicit about
     * why: *"a buyer-side deal may have twelve candidates and no subject
     * until an offer is accepted."* The first house somebody tours is not the
     * house they are buying, and naming the deal after it would put a wrong
     * address on every screen for weeks. Nothing is lost by waiting —
     * IA §10's fallback names a buyer's deal after the client
     * ("Bosart Purchase"), which is the correct name until an offer is
     * accepted and `promote()` is called.
     *
     * A deal that already has a subject keeps it. Choosing between two is a
     * decision with an interaction attached, and that interaction is
     * `promote()`; silently re-pointing the name at whichever property was
     * linked most recently would be the wrong default in both directions.
     *
     * **A rental follows the sell-side rule, and that is a choice rather than
     * an oversight.** A tenant tours several units exactly as a buyer tours
     * several houses, so the buy-side argument arguably fits — but PRD F3.5
     * says *"Buyer-side"* in as many words, and a rental placement is usually
     * one unit being let rather than a search. Worth revisiting with Heather
     * if tenant placements turn out to look like buyer searches in practice.
     */
    public function link(Property $property, Deal $deal): DealProperty
    {
        return DB::transaction(function () use ($property, $deal): DealProperty {
            /*
             * The deal row is locked before the question is asked.
             *
             * "Does this deal have a subject yet" and "insert this link" are
             * two statements, and `deal_properties_one_subject` is what sits
             * between them: two links arriving for one subject-less deal at
             * once both read *no subject* and both claim it. Retrying the
             * loser is not an option here — Postgres aborts the whole
             * transaction on a constraint violation, so a second `save()`
             * after the catch would fail with `current transaction is
             * aborted` rather than succeed — so the race is prevented instead
             * of recovered from.
             *
             * A row lock on the deal, not a table lock: two people linking
             * properties to *different* deals never wait on each other.
             */
            Deal::query()->whereKey($deal->getKey())->lockForUpdate()->first();

            $hasSubject = DealProperty::query()
                ->where('deal_id', $deal->getKey())
                ->where('is_subject', true)
                ->exists();

            $deal->loadMissing('dealType');

            $link = new DealProperty;
            $link->forceFill([
                'team_id' => $deal->team_id,
                'deal_id' => $deal->getKey(),
                'property_id' => $property->getKey(),
                'is_subject' => ! $hasSubject && $deal->dealType->side !== DealSide::Buy,
                // After everything already on the deal. S20 lets an agent
                // reorder from there; arriving at the end is the only default
                // that does not disturb a ranking somebody made.
                'sort_order' => $this->nextRank($deal),
            ]);

            /*
             * `deal_properties_unique_pair` is still reachable, and still
             * means something somebody can act on: the property is already on
             * the deal. The rule on the way in says so first; this is the
             * sentence for the window between its `select` and this `insert`.
             */
            try {
                $link->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'deal_id' => 'This property is already on that deal.',
                ]);
            }

            /*
             * And the flag has to do something, or it is a column with an
             * argument attached.
             *
             * `is_subject` exists because IA §10 names a deal after its
             * subject property's street. Setting it and stopping left S36's
             * own "linked deals" panel rendering *Untitled deal* beside the
             * house that had just been linked to it — `GenerateDealName`
             * shipped with #59 and had no caller anywhere, because until this
             * issue there was no property to be named after.
             */
            $this->names->refresh($deal);

            /*
             * Recorded against the deal, not the property. The timeline
             * somebody reads back is the deal's — "when did this house come
             * onto it" — and a property's own history is the sum of the deals
             * it appeared on.
             */
            $this->activity->record(
                subject: $deal,
                eventType: 'property.linked',
                summary: 'Added '.$property->displayName().' to this deal',
                payload: ['is_subject' => $link->is_subject],
            );

            return $link;
        });
    }

    /**
     * Make this candidate the property the deal is about (F3.4 · S20 · #62).
     *
     * The interaction #61 deliberately left out, and the reason it needed one
     * rather than a default: **the deal's name follows**. IA §10 derives it
     * from the subject property's street, so promoting is a rename, and a
     * rename that happened silently whenever somebody linked a house would put
     * a wrong address on every screen a buyer's deal appears on.
     *
     * The incumbent is demoted in the **same transaction** as the promotion,
     * because `deal_properties_one_subject` is a partial unique index and
     * would otherwise refuse the second subject. That is the same shape
     * `DealRoster::add()` uses for a primary participant, and for the same
     * reason: there is only ever one, so setting a new one plainly means
     * replacing the old one.
     *
     * **A typed name survives this.** `NameDeal` writes `generated_name` and
     * never `name`, and `Deal::displayName()` prefers the typed one — which is
     * issue #62's own requirement: *"must not overwrite a name the user has
     * manually edited."*
     */
    public function promote(DealProperty $link): DealProperty
    {
        return DB::transaction(function () use ($link): DealProperty {
            $link->loadMissing('deal.dealType', 'property');

            $deal = $link->deal;

            if (! $deal instanceof Deal) {
                // The deal is gone. Nothing to name, and nothing to promote
                // on — the same guard `unlink()` carries, for the same reason.
                return $link;
            }

            if ($link->is_subject) {
                return $link;
            }

            /*
             * Locked, then read, then written — the pattern `link()` explains
             * in full. A promotion racing a first link would otherwise have
             * both rows claiming the subject slot, and a constraint violation
             * aborts the whole transaction in Postgres, so the loser cannot be
             * retried after the fact.
             */
            Deal::query()->whereKey($deal->getKey())->lockForUpdate()->first();

            DealProperty::query()
                ->where('deal_id', $deal->getKey())
                ->where('is_subject', true)
                ->update(['is_subject' => false]);

            $link->forceFill(['is_subject' => true])->save();

            $this->names->refresh($deal);

            $this->activity->record(
                subject: $deal,
                eventType: 'property.promoted',
                summary: ($link->property?->displayName() ?? 'A property').' is now the subject property',
            );

            return $link;
        });
    }

    /**
     * What the buyer thinks, and where it sits in their ranking (F3.5 · #62).
     *
     * Keyed on presence rather than on value, the way
     * `DealRoster::replace()` is: `interest_status: null` is an instruction —
     * clear it, nobody has said — and an absent key means leave it alone.
     * `ConvertEmptyStringsToNull` erases the difference between "sent empty"
     * and "not sent" for every scalar in a request body, so the caller is what
     * has to keep it.
     *
     * @param  array<string, mixed>  $changes  only the keys the request sent
     */
    public function describe(DealProperty $link, array $changes): DealProperty
    {
        if ($changes === []) {
            return $link;
        }

        $before = $link->interest_status;

        $link->fill($changes);

        /*
         * Checked **before** the write, not after it.
         *
         * The first version of this filled, saved, and then threw — which
         * left the refused value in the database, since nothing here runs in
         * a transaction. A guard that fires after the thing it guards is not
         * a guard.
         *
         * The buy-side rule lives here, not only in the form request.
         *
         * PRD F3.5 is one line — *"Buyer-side: per-property interest status"*
         * — and the first version of this enforced it in
         * `UpdateDealPropertyRequest` alone, where `DemoTeamSeeder` was
         * already the second caller and buy-side only by luck. That is the
         * shape this service exists to avoid: `LinkPropertyRequest`'s own
         * docblock says the rule about what becomes the subject lives in
         * `PropertyDeals` *"rather than in whichever screen was written
         * first"*, and interest was the one rule that did not get the same
         * treatment.
         *
         * The request still turns it into a named 422; this is what holds for
         * the seeder, an import, and whatever calls it next.
         */
        if ($link->interest_status !== null) {
            $link->loadMissing('deal.dealType');

            if ($link->deal?->dealType->side !== DealSide::Buy) {
                throw new InvalidArgumentException(
                    'Interest is something a buyer has; ['.$link->getKey().'] is not on a buy-side deal.',
                );
            }
        }

        $link->save();

        if ($link->interest_status !== $before) {
            /*
             * Timelined. "The buyer passed on 1420 Pearl" is half of what F3.5
             * is for, and a deal's timeline is where somebody reads back how
             * an opinion moved across showings.
             *
             * `rank()` deliberately writes nothing: a ranking is adjusted
             * repeatedly in one sitting, and an entry per drag would bury the
             * events somebody is actually looking for.
             */
            $link->loadMissing('property');

            $this->activity->record(
                subject: $link->deal,
                eventType: 'property.interest_recorded',
                summary: ($link->property?->displayName() ?? 'A property').': '
                    .($link->interest_status?->label() ?? 'no opinion recorded'),
                payload: ['from' => $before?->value, 'to' => $link->interest_status?->value],
            );
        }

        return $link;
    }

    /**
     * The agent's ranking of the candidates (#62: *"`sort_order` exists so an
     * agent can rank candidates"*).
     *
     * Ids that are not on this deal are ignored rather than refused. The list
     * comes from a drag on a screen somebody may have had open while a
     * colleague removed a row, and rejecting the whole reorder for one stale
     * id would lose the work rather than the row.
     *
     * `array_values`, because the position in the list *is* the rank and a
     * request body may key its array however it likes — the same trap `links`
     * fell into in #61, where a JSON object's key reached an integer column.
     *
     * @param  array<array-key, string>  $orderedLinkIds
     */
    public function rank(Deal $deal, array $orderedLinkIds): void
    {
        DB::transaction(function () use ($deal, $orderedLinkIds): void {
            $links = DealProperty::query()
                ->where('deal_id', $deal->getKey())
                ->get()
                ->keyBy(fn (DealProperty $link): string => (string) $link->getKey());

            /*
             * Deduplicated as well as re-indexed. `distinct` on the way in
             * names a repeat for an HTTP caller; this is the same guarantee
             * for the ones that are not that request, and without it
             * `[B, B, A]` put nothing at rank 0.
             */
            foreach (array_values(array_unique(array_values($orderedLinkIds))) as $position => $id) {
                $links->get((string) $id)?->forceFill(['sort_order' => $position])->save();
            }
        });
    }

    /**
     * Take it off again.
     *
     * IA §7: **Remove** detaches, **Delete** destroys. This detaches — the
     * property stays in the directory and the deal stays where it is.
     *
     * A removed subject leaves the deal with no subject rather than promoting
     * the next property by guess. #62's screen is where somebody says which
     * one it should be; a silent promotion would rename the deal without
     * anybody asking for it.
     */
    public function unlink(DealProperty $link): void
    {
        DB::transaction(function () use ($link): void {
            $link->loadMissing('property', 'deal');

            $link->delete();

            /*
             * Null-guarded on both sides, because both can be gone.
             *
             * `property()` and `deal()` are plain `belongsTo` relations, so a
             * soft-deleted row on either end reads as null — and deleting a
             * property is precisely when this method is called in a loop
             * (`PropertyController::destroy()`). An unlink that threw while
             * tidying up after a delete would leave the delete half done.
             */
            $deal = $link->deal;

            if ($deal === null) {
                return;
            }

            /*
             * Only the subject can have been naming anything.
             *
             * Removing a property that was never the subject recomputes a name
             * identical to the one already stored, so the refresh cannot change
             * an outcome — and deleting a property unlinks every one of its
             * deals in a loop.
             *
             * When it *was* the subject, the deal keeps the name it had:
             * `NameDeal` rewrites only when there is something to build from,
             * so a stale name survives rather than a row reading "Untitled
             * deal" in every list a moment after somebody tidied up a
             * property.
             */
            if ($link->is_subject) {
                $this->names->refresh($deal);
            }

            $this->activity->record(
                subject: $deal,
                eventType: 'property.unlinked',
                summary: 'Removed '.($link->property?->displayName() ?? 'a property').' from this deal',
            );
        });
    }

    /**
     * After everything already on the deal.
     *
     * `max + 1` rather than a count, so removing a property does not make the
     * next one collide with a rank already in use. Nothing is unique on
     * `sort_order` — two concurrent links landing on the same number is a tie
     * in a list, not a constraint violation — so this does not need the lock
     * `link()` takes for the subject slot.
     */
    private function nextRank(Deal $deal): int
    {
        $highest = DealProperty::query()->where('deal_id', $deal->getKey())->max('sort_order');

        return (int) ($highest ?? -1) + 1;
    }
}
