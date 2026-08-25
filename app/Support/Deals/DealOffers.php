<?php

declare(strict_types=1);

namespace App\Support\Deals;

use App\Enums\ActivitySource;
use App\Enums\OfferStatus;
use App\Models\Deal;
use App\Models\Offer;
use App\Models\Person;
use App\Support\Activity\RecordActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that writes `offers` (PRD §4.3 F3.6 · S22 · issue #73).
 *
 * The same rule `DealTasks`, `DealRoster` and `PropertyDeals` follow: a
 * controller that wrote the row and forgot the activity event would look like
 * it worked. Here it also has to demote the other offers, which is the part a
 * second caller would certainly forget.
 *
 * ## Accepting one rejects the rest, in the same transaction
 *
 * A deal with two accepted offers is a deal whose closing-date chain has two
 * answers, and Slice 4 reads exactly that chain. The partial unique index on
 * `offers` makes it impossible at the database as well, which is the backstop
 * rather than the mechanism — `deal_properties` puts the same pair under its
 * subject flag.
 *
 * The others become `rejected` and not `withdrawn`: withdrawing is something
 * the offeror does, and this is the team choosing somebody else.
 */
final class DealOffers
{
    public function __construct(private readonly RecordActivity $activity) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function add(Deal $deal, array $attributes, ?Person $actor = null): Offer
    {
        return DB::transaction(function () use ($deal, $attributes, $actor): Offer {
            $offer = new Offer;
            $offer->fill($attributes);
            $offer->forceFill([
                'deal_id' => $deal->getKey(),
                'property_id' => $attributes['property_id'] ?? null,
            ]);

            /*
             * Recording one **as accepted** is accepting it, and the demotion
             * has to happen here as well as in `edit()`.
             *
             * The create form offers every status, because an offer is
             * frequently recorded after the fact — Emily enters last Tuesday's
             * accepted offer on Thursday. Without this, that entry hit the
             * partial unique index behind `offers_one_accepted` and answered
             * with a 500; and had the index not been there it would have left
             * the deal with two accepted offers and two closing-date chains,
             * which is the thing Slice 4 reads.
             *
             * **Before the save**, which is the half a first pass got wrong:
             * the index refuses the *insert*, so demoting afterwards never
             * runs. Inside the transaction, so a failure leaves neither the
             * new row nor the demotions.
             */
            if ($offer->status === OfferStatus::Accepted) {
                $this->rejectOthers($deal, $offer, $actor);
            }

            $offer->save();

            $this->activity->record(
                subject: $deal,
                eventType: 'offer.added',
                summary: $this->summary($offer, 'Recorded'),
                source: ActivitySource::Manual,
                actor: $actor,
            );

            return $offer;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function edit(Deal $deal, Offer $offer, array $attributes, ?Person $actor = null): Offer
    {
        return DB::transaction(function () use ($deal, $offer, $attributes, $actor): Offer {
            $wasStatus = $offer->status;

            $offer->fill($attributes);

            if (array_key_exists('property_id', $attributes)) {
                $offer->forceFill(['property_id' => $attributes['property_id']]);
            }

            /*
             * Accepting through an edit is still accepting. The status is an
             * ordinary field on this form, so the demotion cannot live only in
             * a dedicated `accept()` that a form does not call.
             *
             * And it runs **before** the save for the reason `add()` does: the
             * partial index refuses the write, so a demotion after it is a
             * demotion that never happens. It only looked fine because the
             * first test to cover this had no *other* accepted offer to
             * collide with.
             */
            if ($offer->status === OfferStatus::Accepted && $wasStatus !== OfferStatus::Accepted) {
                $this->rejectOthers($deal, $offer, $actor);
            }

            $offer->save();

            if ($offer->status !== $wasStatus) {
                $this->activity->record(
                    subject: $deal,
                    eventType: 'offer.status_changed',
                    summary: $this->summary($offer, $offer->status->label()),
                    source: ActivitySource::Manual,
                    actor: $actor,
                );
            }

            return $offer;
        });
    }

    public function remove(Deal $deal, Offer $offer, ?Person $actor = null): void
    {
        DB::transaction(function () use ($deal, $offer, $actor): void {
            $summary = $this->summary($offer, 'Removed');

            $offer->delete();

            $this->activity->record(
                subject: $deal,
                eventType: 'offer.removed',
                summary: $summary,
                source: ActivitySource::Manual,
                actor: $actor,
            );
        });
    }

    /**
     * Everything else on the deal that was still live, rejected.
     *
     * `open()` rather than everything: an offer already withdrawn or expired
     * has an answer, and overwriting it would lose why it ended.
     *
     * **Plus any standing accepted one**, which `open()` deliberately excludes
     * — an accepted offer is resolved, not live. It still has to be demoted
     * here, because the whole reason for this method is that a deal may hold
     * exactly one, and the partial index enforces it: an offer *recorded* as
     * accepted collided with the one already there and answered with a 500.
     * Superseding an acceptance is the team choosing somebody else, which is
     * `rejected` by the same argument the docblock above makes.
     */
    private function rejectOthers(Deal $deal, Offer $accepted, ?Person $actor = null): void
    {
        /*
         * Read before the update, so each demotion can be recorded.
         *
         * A mass update wrote nothing to the timeline, which was tolerable
         * while it only reached *open* offers — nobody had been told those
         * were going anywhere. It stopped being tolerable when it started
         * reaching a **standing accepted** one: accepting writes an entry, and
         * silently un-accepting it left the feed saying an offer was accepted
         * and never saying it was not.
         */
        $demoted = Offer::query()
            ->where('deal_id', $deal->getKey())
            ->when(
                $accepted->exists,
                fn (Builder $query): Builder => $query->whereKeyNot($accepted->getKey()),
            )
            ->where(fn (Builder $query): Builder => $query
                ->open()
                ->orWhere('status', OfferStatus::Accepted))
            ->get();

        if ($demoted->isEmpty()) {
            return;
        }

        Offer::query()->whereKey($demoted->modelKeys())
            ->update(['status' => OfferStatus::Rejected]);

        foreach ($demoted as $offer) {
            $offer->status = OfferStatus::Rejected;

            $this->activity->record(
                subject: $deal,
                eventType: 'offer.status_changed',
                summary: $this->summary($offer, $offer->status->label()),
                source: ActivitySource::Manual,
                actor: $actor,
            );
        }
    }

    /**
     * The summary never carries the amount.
     *
     * A deal's timeline is read by anyone who can open the deal, and what
     * somebody offered is the most commercially sensitive number on it. The
     * row is on the Offers tab for anybody who needs it.
     */
    private function summary(Offer $offer, string $verb): string
    {
        return "{$verb} an offer ({$offer->direction->label()})";
    }
}
