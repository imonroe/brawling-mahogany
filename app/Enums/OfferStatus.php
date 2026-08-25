<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an offer stands (PRD §6.2, §7.9 · S22 · issue #73).
 *
 * IA §8's vocabulary for this row. `countered` is a state and not an event:
 * #73 asks for *"multiple offers per deal, including counters, with a clear
 * current status"*, and a counter that replaced the row it answered would lose
 * the negotiation — so a countered offer stays countered and the counter is
 * its own row.
 *
 * `withdrawn` and `expired` are different facts. One is a decision somebody
 * made and the other is a date passing, and a team reading the history six
 * weeks later needs to be able to tell them apart.
 */
enum OfferStatus: string implements HasLabel
{
    use Concerns\ProvidesOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Countered = 'countered';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Countered => 'Countered',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Withdrawn => 'Withdrawn',
            self::Expired => 'Expired',
        };
    }

    /** Whether this offer is still live — exactly one per deal can be. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::Countered], true);
    }
}
