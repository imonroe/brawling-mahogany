<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * What a buyer thinks of a candidate property (PRD §6.3, §4.3 F3.5 · #62).
 *
 * **An opinion, not a position.** PRD §7.11 threw "Undergoing improvements"
 * and "Staged" out of property status for being workflow positions rather than
 * market status, and the same line runs here: "Viewing scheduled" and "Offer
 * made" are tempting fifth and sixth values and both are facts held elsewhere
 * — a showing is a task on a stage, and an offer is a row in `offers` (F3.6).
 * A lookup that grew either would be a second, worse copy of a record the
 * product already keeps, and the two would disagree within a month.
 *
 * Four values, which is what issue #62 asks for: *"keep the vocabulary small
 * and let it be reordered"*. Ranking is `deal_properties.sort_order`, and it
 * carries the nuance a longer list would try to: an agent with nine candidates
 * needs an order far more than a fifth adjective.
 */
enum PropertyInterest: string implements HasLabel
{
    use ProvidesOptions;

    case Interested = 'interested';
    case Shortlisted = 'shortlisted';
    case Passed = 'passed';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Interested => 'Interested',
            self::Shortlisted => 'Shortlisted',
            self::Passed => 'Passed',
            self::Other => 'Other',
        };
    }
}
