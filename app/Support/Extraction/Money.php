<?php

declare(strict_types=1);

namespace App\Support\Extraction;

/**
 * Micros into words, in one place.
 *
 * `extractions.cost_micros` is millionths of a dollar (see the migration for
 * why cents cannot express it), and a millionth of a dollar is not a unit
 * anybody reads. Every screen that shows a cost goes through here, so the
 * rounding rule is one rule rather than four.
 *
 * Not `resources/js/lib/formatters.ts`, and the reason is the unit rather than
 * the formatting: `formatCurrency` takes cents like the rest of the product,
 * and teaching it a second unit would put the conversion in front of every
 * caller that does not need it. What crosses to the front end is already a
 * string of words.
 */
final class Money
{
    public const MICROS_PER_DOLLAR = 1_000_000;

    /**
     * Dollars and cents, for a figure somebody is reading.
     *
     * Two decimal places once the number is over a cent, four below it. A
     * single extraction can genuinely cost less than a cent, and rendering
     * that as `$0.00` beside a spend cap is the kind of zero somebody builds a
     * wrong assumption on.
     */
    public static function words(int $micros): string
    {
        $dollars = $micros / self::MICROS_PER_DOLLAR;

        if ($micros > 0 && $dollars < 0.01) {
            return '$'.number_format($dollars, 4);
        }

        return '$'.number_format($dollars, 2);
    }

    /** For a cap written in whole dollars in config or an env var. */
    public static function fromDollars(float $dollars): int
    {
        return (int) round($dollars * self::MICROS_PER_DOLLAR);
    }
}
