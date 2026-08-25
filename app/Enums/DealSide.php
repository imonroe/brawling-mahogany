<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * Which side of a transaction a deal type sits on (PRD §6.3 · issue #58).
 *
 * It is a property of the *type*, not of the deal, because it never varies
 * within a type: every Buyer Representation is a buy. Two things downstream
 * read it — which workflow templates are offered, and whether the Offers tab
 * exists at all (IA §5.2: *"hidden when empty and the deal type has no
 * offers"*).
 *
 * `Rent` is tenant placement only. PRD §2.2 puts ongoing rental and property
 * management out permanently, for a licensing reason rather than a scheduling
 * one — Emily's brokerage does not manage rentals and *"a lot of us aren't
 * allowed to."* A value here that implied otherwise would be an invitation to
 * build it.
 */
enum DealSide: string implements HasLabel
{
    use ProvidesOptions;

    case Buy = 'buy';
    case Sell = 'sell';
    case Rent = 'rent';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Buy => 'Buy',
            self::Sell => 'Sell',
            self::Rent => 'Rent',
            self::Other => 'Other',
        };
    }

    /**
     * Whether a deal of this side has offers at all (IA §5.2 · S22 · #73).
     *
     * *"The Offers tab is hidden when empty and the deal type has no offers."*
     * A rental placement does not grow an empty Offers tab — a landlord and a
     * tenant sign a lease, and nobody makes an offer on one.
     *
     * Read off the **side** rather than a `has_offers` column on `deal_types`,
     * because the side already answers it and a second place to record the
     * same fact is a second place for it to be wrong. `Other` is true: an
     * unclassified type showing a tab nobody uses costs a tab, and hiding one
     * somebody needs costs them the feature with no way to ask for it.
     */
    public function hasOffers(): bool
    {
        return $this !== self::Rent;
    }
}
