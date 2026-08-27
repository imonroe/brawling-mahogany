<?php

declare(strict_types=1);

namespace App\Support\Formatting;

use App\Models\Property;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

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
 *
 * **Dates arrived with Slice 4**, exactly as this docblock said they would:
 * F5.6's `next_deadline` merge field, S88's reminder email, and the client
 * status page all render one without a browser. Nothing else has been added —
 * the rule about keeping this file to what a message needs still holds.
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
     * IA §10, internal: *"Thu, Aug 20"*. The mirror of `formatDate()`.
     *
     * Formatted from the **wall date**, with no timezone conversion. Every
     * caller here holds either a `date` column — a day, which has no zone to
     * be read in — or an instant a caller has already localised. Converting
     * again is how a closing on the 20th renders as the 19th for a team west
     * of UTC, which is the bug `Task::state()` records twice over.
     */
    public static function date(CarbonInterface|string|null $value): string
    {
        $date = self::day($value);

        return $date instanceof CarbonInterface ? $date->format('D, M j') : '';
    }

    /**
     * IA §10, client-facing: *"Thursday, August 20"*, with the year only when
     * it differs from the year the client is reading in. The mirror of
     * `formatDateForClient()`.
     *
     * The year rule is not decoration. A status page and a reminder email are
     * read in the same week they are sent, so a year on every date is noise —
     * and a closing that has slipped into January is the one case where its
     * absence would actually mislead.
     */
    public static function clientDate(
        CarbonInterface|string|null $value,
        CarbonInterface|string|null $now = null,
    ): string {
        $date = self::day($value);

        if (! $date instanceof CarbonInterface) {
            return '';
        }

        $reference = self::day($now) ?? CarbonImmutable::now();

        return $date->year === $reference->year
            ? $date->format('l, F j')
            : $date->format('l, F j, Y');
    }

    /**
     * IA §10: relative only within seven days, then absolute.
     *
     * *"today"*, *"tomorrow"*, *"in 3 days"*, *"5 days ago"*, then *"Aug 30"*.
     * The mirror of `formatRelativeDate()`, and the boundary is the same seven
     * days: past that, a relative phrase makes somebody do arithmetic to find
     * out what day it actually is.
     */
    public static function relativeDate(
        CarbonInterface|string|null $value,
        CarbonInterface|string|null $now = null,
    ): string {
        $date = self::day($value);

        if (! $date instanceof CarbonInterface) {
            return '';
        }

        $reference = self::day($now) ?? CarbonImmutable::now();

        $days = (int) $reference->startOfDay()->diffInDays($date->startOfDay(), false);

        return match (true) {
            $days === 0 => 'today',
            $days === 1 => 'tomorrow',
            $days === -1 => 'yesterday',
            $days > 1 && $days <= 7 => 'in '.$days.' days',
            $days < -1 && $days >= -7 => abs($days).' days ago',
            default => $date->format('M j'),
        };
    }

    /**
     * IA §10: numeral plus noun, pluralised. *"3 deals"*, *"1 task"*.
     *
     * The mirror of `formatCount()`, and it exists here for the reminder
     * email's subject line — *"2 deadlines this week"* — which is composed on
     * the server and read in an inbox.
     */
    public static function count(int $value, string $singular, ?string $plural = null): string
    {
        return $value.' '.($value === 1 ? $singular : ($plural ?? $singular.'s'));
    }

    /**
     * A wall date, however the caller holds it.
     *
     * A `date` cast hydrates as a `CarbonImmutable` at midnight; a string is
     * either `YYYY-MM-DD` or something Carbon can read. Both end up as a day,
     * because every caller in this file is formatting a day.
     *
     * Types against `CarbonInterface`, never `Illuminate\Support\Carbon`:
     * this project's dates hydrate immutable, and an `instanceof Carbon` check
     * is false for every row in the database.
     */
    private static function day(CarbonInterface|string|null $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value->startOfDay();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }
}
