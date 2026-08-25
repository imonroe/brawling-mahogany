<?php

declare(strict_types=1);

use App\Enums\DealSide;
use App\Enums\OfferStatus;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\Offer;
use App\Support\Tenancy\TeamContext;

/**
 * S22 — offers (PRD F3.6, §7.9 · #73).
 *
 * PRD §7.9: *"Nothing covers offers or the chain of dates governing a live
 * transaction."* What is pinned here is the part a second caller would get
 * wrong — accepting one offer has to end the others — and the two things the
 * product must never claim: that this is the contract, and that a rental
 * placement has offers.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);
});

function offerDeal(DealSide $side = DealSide::Sell): Deal
{
    $team = test()->team;

    return app(TeamContext::class)->runFor($team, function () use ($team, $side): Deal {
        $type = DealType::factory()->create([
            'team_id' => $team->getKey(),
            'side' => $side,
        ]);

        return Deal::factory()->create([
            'team_id' => $team->getKey(),
            'deal_type_id' => $type->getKey(),
        ]);
    });
}

it('records an offer in cents, and says so on the deal', function (): void {
    $deal = offerDeal();

    $this->post("/deals/{$deal->getKey()}/offers", [
        'direction' => 'received',
        'status' => 'submitted',
        // Integer cents (ADR 0001): $485,000.
        'amount' => 48_500_000,
        'earnest_money' => 500_000,
        'expires_on' => '2026-09-10',
    ])->assertRedirect();

    $offer = Offer::query()->sole();

    expect($offer->amount)->toBe(48_500_000)
        ->and($offer->deal_id)->toBe($deal->getKey())
        ->and($offer->status)->toBe(OfferStatus::Submitted);

    $event = ActivityEvent::query()->where('event_type', 'offer.added')->sole();

    // The summary never carries the amount: a deal's timeline is read by
    // anyone who can open the deal, and this is the most commercially
    // sensitive number on it.
    expect($event->summary)->not->toContain('485')
        ->and($event->deal_id)->toBe($deal->getKey());
});

it('ends the other live offers when one is accepted', function (): void {
    /*
     * A deal with two accepted offers is a deal whose closing-date chain has
     * two answers, and Slice 4 reads exactly that chain. The partial unique
     * index is the backstop; this is the mechanism.
     */
    $deal = offerDeal();

    $first = Offer::factory()->create(['team_id' => $this->team->getKey(), 'deal_id' => $deal->getKey()]);
    $second = Offer::factory()->create(['team_id' => $this->team->getKey(), 'deal_id' => $deal->getKey()]);
    $withdrawn = Offer::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
        'status' => OfferStatus::Withdrawn,
    ]);

    $this->patch("/deals/{$deal->getKey()}/offers/{$first->getKey()}", [
        'direction' => 'received',
        'status' => 'accepted',
        'amount' => $first->amount,
    ])->assertRedirect();

    expect($first->refresh()->status)->toBe(OfferStatus::Accepted)
        ->and($second->refresh()->status)->toBe(OfferStatus::Rejected)
        /*
         * An offer already withdrawn has an answer, and overwriting it would
         * lose why it ended — withdrawing is something the offeror did, and
         * rejecting is the team choosing somebody else.
         */
        ->and($withdrawn->refresh()->status)->toBe(OfferStatus::Withdrawn);
});

it('keeps a countered offer rather than replacing it', function (): void {
    // #73 asks for "multiple offers per deal, including counters". A counter
    // that overwrote the row it answered would lose the negotiation.
    $deal = offerDeal();

    $theirs = Offer::factory()->create(['team_id' => $this->team->getKey(), 'deal_id' => $deal->getKey()]);

    $this->patch("/deals/{$deal->getKey()}/offers/{$theirs->getKey()}", [
        'direction' => 'received',
        'status' => 'countered',
        'amount' => $theirs->amount,
    ])->assertRedirect();

    $this->post("/deals/{$deal->getKey()}/offers", [
        'direction' => 'made',
        'status' => 'submitted',
        'amount' => 49_500_000,
    ])->assertRedirect();

    expect(Offer::query()->count())->toBe(2)
        ->and($theirs->refresh()->status)->toBe(OfferStatus::Countered);
});

it('calls an offer expired the day after it lapses, without being told', function (): void {
    /*
     * Derived, never stored — the same rule `Task::state()` follows. An expiry
     * written by a nightly job is wrong until the job runs, and a team looking
     * at an offer at 9am needs it right then.
     */
    $this->freezeAt('2026-09-11 12:00:00');

    $deal = offerDeal();

    Offer::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
        'expires_on' => '2026-09-10',
    ]);

    $this->get("/deals/{$deal->getKey()}/offers")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('offers.0.status', 'expired')
            // And the column still says what the team last set, so nothing has
            // been rewritten behind their back.
            ->where('offers.0.expiresAt', '2026-09-10'));

    expect(Offer::query()->sole()->status)->toBe(OfferStatus::Submitted);
});

it('does not grow an Offers tab on a rental placement', function (): void {
    // IA §5.2: *"hidden when empty and the deal type has no offers."* A
    // landlord and a tenant sign a lease; nobody makes an offer on one.
    $rental = offerDeal(DealSide::Rent);

    $this->get("/deals/{$rental->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('dealHeader.hasOffers', false));

    $sale = offerDeal(DealSide::Sell);

    $this->get("/deals/{$sale->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('dealHeader.hasOffers', true));
});

it('refuses a property that is not on this deal', function (): void {
    /*
     * Two deals in the same team pass the tenancy layers and the policy alike.
     * Only the link answers whose deal a property is on.
     */
    $deal = offerDeal();
    $other = offerDeal();

    $property = app(TeamContext::class)->runFor($this->team, fn () => App\Models\Property::factory()->create([
        'team_id' => $this->team->getKey(),
    ]));

    $this->post("/deals/{$deal->getKey()}/offers", [
        'direction' => 'received',
        'status' => 'submitted',
        'amount' => 1_000_00,
        'property_id' => $property->getKey(),
    ])->assertSessionHasErrors('property_id');

    unset($other);

    expect(Offer::query()->count())->toBe(0);
});

it('keeps another team’s offers out of it', function (): void {
    $deal = offerDeal();

    [$otherTeam] = $this->teamWithMember();

    $theirs = app(TeamContext::class)->runFor($otherTeam, function () use ($otherTeam): Deal {
        return Deal::factory()->create(['team_id' => $otherTeam->getKey()]);
    });

    $this->get("/deals/{$theirs->getKey()}/offers")->assertNotFound();

    unset($deal);
});

it('ends the other live offers when one is recorded as accepted', function (): void {
    /*
     * **Recording one as accepted is accepting it**, and the demotion has to
     * happen on create as well as on edit — it lived only in `edit()`, while
     * the create form offers every status.
     *
     * An offer is frequently recorded after the fact: Emily enters last
     * Tuesday's accepted offer on Thursday. That entry hit the partial unique
     * index behind `offers_one_accepted` and answered with a 500; without the
     * index it would have left the deal with two accepted offers and two
     * closing-date chains, which is the thing Slice 4 reads.
     */
    $deal = offerDeal();

    $standing = Offer::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
        'status' => OfferStatus::Accepted,
    ]);

    $this->post("/deals/{$deal->getKey()}/offers", [
        'direction' => 'received',
        'status' => 'accepted',
        'amount' => 725000,
    ])->assertRedirect();

    $recorded = Offer::query()->whereKeyNot($standing->getKey())->sole();

    expect($recorded->status)->toBe(OfferStatus::Accepted)
        ->and($standing->refresh()->status)->toBe(OfferStatus::Rejected)
        ->and(Offer::query()->where('deal_id', $deal->getKey())
            ->where('status', OfferStatus::Accepted)->count())->toBe(1);
});
