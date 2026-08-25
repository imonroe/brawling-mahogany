<?php

declare(strict_types=1);

namespace App\Support\Formatting;

use App\Models\Property;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * IA §10's formatting rules, on the server.
 *
 * ## Why this exists at all, given `lib/formatters.ts`
 *
 * Frontend conventions §3 is emphatic that nothing formats a date, a name, an
 * address or an amount itself, because *"ninety-one screens formatting
 * independently will disagree within a month"*. Everything the product had
 * rendered until now was rendered by the browser, so one file held every rule.
 *
 * An **email** is the first surface this product renders without a browser.
 * The words leave the building and are read months later in somebody's
 * archive, so they cannot be rendered from `lib/formatters.ts` and they must
 * not be assembled inline by a mailable. This is that file's mirror, kept
 * deliberately small: **only the rules a message actually needs**, so the
 * surface that can drift is the smallest one that does the job.
 *
 * `tests/Unit/Formatting/FormatTest.php` asserts the same worked examples
 * `tests/js/formatters.test.ts` does, which is what keeps the pair honest. A
 * rule that lands here without landing there is the failure mode, and the
 * shared examples are what makes it visible.
 */
final class Format
{
    /**
     * IA §10: street on line one, `City, ST ZIP` on line two.
     *
     * The mirror of `formatAddress()`, returning the two lines rather than one
     * string so a caller that has two lines to give (an email's address block)
     * does not have to split one apart again.
     *
     * @return array{line1: string, line2: string}
     */
    public static function addressLines(?Property $property): array
    {
        if (! $property instanceof Property) {
            return ['line1' => '', 'line2' => ''];
        }

        $line1 = trim(implode(' ', array_filter([$property->street, $property->unit])));

        $cityState = implode(', ', array_filter([$property->city, $property->state_code]));
        $line2 = trim(implode(' ', array_filter([$cityState, $property->postal_code])));

        return ['line1' => $line1, 'line2' => $line2];
    }

    /** The mirror of `formatAddressOneLine()`. */
    public static function addressOneLine(?Property $property): string
    {
        $lines = self::addressLines($property);

        return implode(', ', array_filter([$lines['line1'], $lines['line2']]));
    }

    /**
     * IA §10 client date: full month and day, and the year **only when it
     * differs** from the year the reader is in.
     *
     * The mirror of `formatDateForClient()`. A client email saying "Thursday,
     * August 20" reads as this year; one about next January has to say so.
     *
     * Rendered in the team's timezone (PRD §9), which the caller passes,
     * because a message is composed by a queue worker running in UTC and
     * "tomorrow" is a calendar fact rather than a 24-hour one.
     */
    public static function dateForClient(?DateTimeInterface $value, string $timezone, ?DateTimeInterface $relativeTo = null): string
    {
        if (! $value instanceof DateTimeInterface) {
            return '';
        }

        $date = Carbon::instance($value)->setTimezone($timezone);
        $now = Carbon::instance($relativeTo ?? Carbon::now())->setTimezone($timezone);

        return $date->year === $now->year
            ? $date->format('l, F j')
            : $date->format('l, F j, Y');
    }
}
