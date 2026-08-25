<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Enums\OfferDirection;
use App\Enums\OfferStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\StoreOfferRequest;
use App\Http\Requests\Deals\UpdateOfferRequest;
use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\Offer;
use App\Models\Person;
use App\Support\Deals\DealHeader;
use App\Support\Deals\DealOffers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S22 — a deal's offers (PRD §4.3 F3.6, §7.9 · issue #73).
 *
 * The team's own working record of terms and dates. **Not the contract**: PRD
 * §10 leaves the executed document in CTM, and nothing here uploads one.
 *
 * Writes go through `DealOffers`, which owns the table — accepting one offer
 * rejects the others in the same transaction, and a controller that wrote the
 * status and forgot the demotion would look like it worked.
 */
class OfferController extends Controller
{
    public function index(Deal $deal): Response
    {
        $this->authorize('view', $deal);

        $offers = Offer::query()
            ->where('deal_id', $deal->getKey())
            ->with('property')
            ->orderByDesc('submitted_on')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Deals/Offers', [
            'dealHeader' => DealHeader::for($deal),
            'dealUrl' => '/deals/'.$deal->getKey(),
            'offers' => $offers->map(self::row(...))->values()->all(),
            'directions' => OfferDirection::options(),
            'statuses' => OfferStatus::options(),
            /*
             * Only the properties already on the deal, because that is what
             * `OfferRules` will accept — a picker offering more than the
             * server takes is a 422 the screen invited.
             */
            'properties' => $deal->propertyLinks()
                ->with('property')
                ->get()
                ->map(fn (DealProperty $link): array => [
                    'id' => $link->property_id,
                    'label' => $link->property?->displayName() ?? 'Untitled property',
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(StoreOfferRequest $request, Deal $deal, DealOffers $offers): RedirectResponse
    {
        /** @var Person $person */
        $person = $request->user();

        $offers->add($deal, $request->attributesForOffer(), $person);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Offer recorded.')]);

        return back(fallback: route('deals.offers.index', $deal));
    }

    public function update(
        UpdateOfferRequest $request,
        Deal $deal,
        Offer $offer,
        DealOffers $offers,
    ): RedirectResponse {
        /** @var Person $person */
        $person = $request->user();

        $offers->edit($deal, $offer, $request->attributesForOffer(), $person);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Offer updated.')]);

        return back(fallback: route('deals.offers.index', $deal));
    }

    public function destroy(Request $request, Deal $deal, Offer $offer, DealOffers $offers): RedirectResponse
    {
        $this->authorize('update', $deal);

        /** @var Person $person */
        $person = $request->user();

        $offers->remove($deal, $offer, $person);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Offer removed.')]);

        return back(fallback: route('deals.offers.index', $deal));
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(Offer $offer): array
    {
        return [
            'id' => $offer->getKey(),
            'direction' => $offer->direction->value,
            'directionLabel' => $offer->direction->label(),
            /*
             * The **displayed** status, which folds in an expiry the database
             * has not been told about. Derived rather than stored, so an offer
             * that lapsed overnight reads as lapsed at 9am rather than
             * whenever a job next runs.
             */
            'status' => $offer->displayStatus()->value,
            'statusLabel' => $offer->displayStatus()->label(),
            // Integer cents (ADR 0001) — `lib/formatters.ts` renders them.
            'amount' => $offer->amount,
            'earnestMoney' => $offer->earnest_money,
            'terms' => $offer->terms,
            'contingencies' => $offer->contingencies ?? [],
            // Days, not instants (#165).
            'submittedAt' => $offer->submitted_on?->toDateString(),
            'expiresAt' => $offer->expires_on?->toDateString(),
            'propertyId' => $offer->property_id,
            'propertyLabel' => $offer->property?->displayName(),
        ];
    }
}
