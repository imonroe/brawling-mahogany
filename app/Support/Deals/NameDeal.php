<?php

declare(strict_types=1);

namespace App\Support\Deals;

use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\DealProperty;
use App\Models\Property;

/**
 * Keep a deal's derived name true (F3.2 · IA §10 · issue #59, #61).
 *
 * `GenerateDealName` is the rule — *"subject property street address, falling
 * back to client surname"* — and it is a pure function over
 * `DealNameFacts`. This is the half that was missing: something that gathers
 * the facts off a live deal and writes the column.
 *
 * ## Why it lands in #61 rather than #59
 *
 * Because #61 is the first issue that can produce a fact. `GenerateDealName`
 * shipped with #59 and had no call site anywhere, because a deal had no
 * property to be named after — and the moment `PropertyDeals::link()` sets
 * `is_subject`, the whole argument for that flag ("a deal with one property
 * and no subject cannot be named") is only true if something acts on it.
 * Without this, linking a house to a fresh deal set the flag and left S36's
 * own panel rendering *Untitled deal* next to the address.
 *
 * ## `generated_name` only, never `name`
 *
 * Issue #59 is explicit: *"editing the name does not stop `generated_name`
 * from updating when the property changes."* Two columns exist so that a typed
 * name survives every one of these passes, and `Deal::displayName()` is what
 * decides which one a screen sees.
 *
 * ## Nothing to build from leaves what was there
 *
 * `refresh()` declines to write in two cases, and they are the same case
 * reached twice: `DealNameFacts::areEmpty()` is asked first, and
 * `GenerateDealName::from()` returns null only when the facts were empty
 * anyway. The early return is the readable one; the null check behind it is
 * a belt that no input reaches today and would matter the moment the rule
 * grew a case that produced no name from real facts.
 *
 * So removing a fact usually **renames**: a deal that was "1420 Pearl St ·
 * Bosart Sale" becomes "Bosart Sale" when the property comes off, because the
 * surname is still a fact. It keeps what it had only when the *last* fact
 * goes — and then the old name survives rather than the column being blanked,
 * because a stale name is far better than a list of "Untitled deal".
 */
final class NameDeal
{
    public function __construct(private readonly GenerateDealName $names) {}

    public function refresh(Deal $deal): Deal
    {
        $facts = $this->factsFor($deal);

        if ($facts->areEmpty()) {
            return $deal;
        }

        $generated = $this->names->from($facts);

        if ($generated === null || $generated === $deal->generated_name) {
            return $deal;
        }

        /*
         * `forceFill`, because `generated_name` is derived and deliberately
         * absent from `#[Fillable]` — a request body must not choose it any
         * more than it may choose a tenant.
         */
        $deal->forceFill(['generated_name' => $generated])->save();

        return $deal;
    }

    private function factsFor(Deal $deal): DealNameFacts
    {
        $deal->loadMissing('dealType');

        return new DealNameFacts(
            streetAddress: $this->subjectStreet($deal),
            clientSurname: $this->clientSurname($deal),
            side: $deal->dealType->side,
        );
    }

    /**
     * The subject property's street, and only the street.
     *
     * IA §10's example is `123 Main St`, not the whole address: a deal name
     * appears in a list beside twenty others, and "123 Main St, Denver, CO
     * 80202 · Bosart Sale" is a name nobody can scan.
     */
    private function subjectStreet(Deal $deal): ?string
    {
        $link = DealProperty::query()
            ->where('deal_id', $deal->getKey())
            ->where('is_subject', true)
            ->with('property')
            ->first();

        if (! $link instanceof DealProperty || ! $link->property instanceof Property) {
            return null;
        }

        return $link->property->street;
    }

    /**
     * The client's surname, where the deal type says who the client is.
     *
     * `DealRoster::expectedRoles()` already answers "which participant is the
     * client on a deal of this side" — Seller on a sale, Buyer on a purchase —
     * so this reads that rather than restating it. A rental or an "other"
     * deal expects neither, and gets no surname, which is the honest answer
     * rather than a guess at which of thirteen roles was meant.
     *
     * The primary one, because `deal_participants` allows several in a role
     * and `is_primary` is the column that says which is *the* one. Two spouses
     * both sell; the deal is named after the one who takes the calls.
     */
    private function clientSurname(Deal $deal): ?string
    {
        $roles = array_column(DealRoster::expectedRoles($deal), 'value');

        if ($roles === []) {
            return null;
        }

        $participant = DealParticipant::query()
            ->where('deal_id', $deal->getKey())
            ->whereIn('participant_role', $roles)
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->with('membership')
            ->first();

        return $participant?->membership->last_name;
    }
}
