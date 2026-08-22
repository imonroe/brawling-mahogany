<?php

declare(strict_types=1);

namespace App\Support\Deals;

use App\Enums\DealSide;

/**
 * The deal name IA §10 specifies (F3.2 · issue #59).
 *
 * > | Deal names | Subject property street address, falling back to client
 * > surname | `123 Main St · Bosart Purchase` |
 *
 * The example carries more than the sentence does: the canonical name has
 * **both** halves, and the "falling back" describes what happens when the
 * address is missing rather than an either/or. So:
 *
 * | Address | Surname | Result |
 * |---|---|---|
 * | yes | yes | `123 Main St · Bosart Purchase` |
 * | no | yes | `Bosart Purchase` |
 * | yes | no | `123 Main St` |
 * | no | no | null — the caller keeps whatever it had |
 *
 * ## Why the side word is part of the name
 *
 * "Bosart" alone does not distinguish the Bosarts buying from the Bosarts
 * selling, and a team that represents both sides of one family's move will
 * have exactly that. The word is derived from the deal type's side, which
 * cannot vary within a type, so it never goes stale.
 *
 * IA §13.4 leaves one question open against this rule: a screen of ten
 * buyer-side deals with no property yet reads "Bosart Purchase", "Neal
 * Purchase", "Kim Purchase" — *"acceptable, but check with Heather once she
 * has ten of them on one screen."* Worth revisiting once S13 is real; the
 * shape of the fix would be a date or a neighbourhood, and both are facts this
 * value object can grow.
 */
final class GenerateDealName
{
    /** IA §10's separator. A middle dot, not a hyphen — hyphens live in addresses. */
    private const SEPARATOR = ' · ';

    public function from(DealNameFacts $facts): ?string
    {
        if ($facts->areEmpty()) {
            return null;
        }

        $parts = array_values(array_filter([
            $facts->address(),
            $this->clientPart($facts),
        ]));

        return $parts === [] ? null : implode(self::SEPARATOR, $parts);
    }

    /**
     * "Bosart Purchase", or just "Bosart" when the side is unknown.
     *
     * An unknown side is possible: `DealSide::Other` exists, and a deal type
     * can be missing while a form is half-filled. Appending nothing beats
     * appending a word that might be wrong — a purchase labelled "Sale" is a
     * worse name than one labelled nothing.
     */
    private function clientPart(DealNameFacts $facts): ?string
    {
        $surname = $facts->surname();

        if ($surname === null) {
            return null;
        }

        $word = match ($facts->side) {
            DealSide::Buy => 'Purchase',
            DealSide::Sell => 'Sale',
            DealSide::Rent => 'Rental',
            DealSide::Other, null => null,
        };

        return $word === null ? $surname : $surname.' '.$word;
    }
}
