<?php

declare(strict_types=1);

namespace App\Support\Properties;

use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\Property;
use App\Support\Activity\RecordActivity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    public function __construct(private readonly RecordActivity $activity) {}

    /**
     * Put a property on a deal.
     *
     * ## Why the first one becomes the subject
     *
     * A deal's generated name is *"subject property street address"* (IA §10),
     * and `GenerateDealName` has no other way to be given one. A deal with
     * exactly one property and no subject is a deal that cannot be named, and
     * nobody linking their only property means "and this is not the one the
     * deal is about."
     *
     * A deal that already has a subject keeps it. Choosing between two is a
     * decision with an interaction attached, and that interaction is #62 —
     * silently re-pointing the name at whichever property was linked most
     * recently would be the wrong default in both directions.
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

            $link = new DealProperty;
            $link->forceFill([
                'team_id' => $deal->team_id,
                'deal_id' => $deal->getKey(),
                'property_id' => $property->getKey(),
                'is_subject' => ! $hasSubject,
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

            $this->activity->record(
                subject: $link->deal,
                eventType: 'property.unlinked',
                summary: 'Removed '.$link->property->displayName().' from this deal',
            );
        });
    }
}
