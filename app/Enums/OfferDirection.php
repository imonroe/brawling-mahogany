<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whose offer this is (PRD §6.2 · S22 · issue #73).
 *
 * *"`direction` distinguishes an offer our buyer made from an offer our seller
 * received."* Not a property of the deal type: a listing can receive five and
 * a buyer deal can make three, and the same team screen shows both — so it is
 * a column on the offer rather than something inferred from `deals.side`,
 * which would guess wrong the moment a deal is both.
 */
enum OfferDirection: string implements HasLabel
{
    use Concerns\ProvidesOptions;

    case Made = 'made';
    case Received = 'received';

    public function label(): string
    {
        return match ($this) {
            self::Made => 'We made',
            self::Received => 'We received',
        };
    }
}
