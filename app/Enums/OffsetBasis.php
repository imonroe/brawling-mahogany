<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;
use Carbon\CarbonInterface;

/**
 * How a derived date counts its offset (PRD §4.8 F8.2 · issue #106).
 *
 * #106: *"a derived date is `anchor + offset_days`, where `offset_basis`
 * distinguishes calendar days from business days."* Contracts use both, in the
 * same document — an inspection objection deadline is usually calendar days
 * from mutual acceptance, and a lender's delivery obligation is usually
 * business days — so guessing one basis for the product would be wrong on
 * roughly half of every deal.
 *
 * ## Weekends only, deliberately not holidays
 *
 * A business-day count that skipped public holidays would need a holiday
 * calendar, and a holiday calendar is a jurisdiction, an observance rule, and
 * a yearly maintenance obligation this product has no way to keep current. A
 * date that is silently one day out on the third Monday in January is worse
 * than one that is honestly counted in weekdays, because nobody would know to
 * check it.
 *
 * So the rule is stated where a person can read it (S18 shows the basis beside
 * the offset) rather than approximated. If a contract counts holidays, the
 * date is typed in.
 */
enum OffsetBasis: string implements HasLabel
{
    use ProvidesOptions;

    case Calendar = 'calendar';
    case Business = 'business';

    public function label(): string
    {
        return match ($this) {
            self::Calendar => 'Calendar days',
            self::Business => 'Business days',
        };
    }

    /**
     * How S18 says it in a sentence: *"10 calendar days after Mutual acceptance"*.
     *
     * Lower case and without the noun, because the screen supplies the number
     * and the direction. `label()` is the picker's word; this is the prose one,
     * and keeping them apart is what stops *"10 Calendar days"* reaching a
     * screen.
     */
    public function phrase(int $days): string
    {
        $unit = match ($this) {
            self::Calendar => abs($days) === 1 ? 'calendar day' : 'calendar days',
            self::Business => abs($days) === 1 ? 'business day' : 'business days',
        };

        return abs($days).' '.$unit.' '.($days < 0 ? 'before' : 'after');
    }

    /**
     * Apply an offset to a day.
     *
     * ## The zero case is the anchor itself, in both bases
     *
     * *"The day of closing"* is a real offset somebody writes, and it means
     * the anchor — including when the anchor lands on a Saturday. Rounding a
     * zero-offset business date forward to Monday would move a date nobody
     * asked to move, and the anchor is the one value in the chain that was
     * given rather than derived.
     *
     * ## A business-day count counts *days*, not the days between
     *
     * Two business days after a Thursday is the following Monday: Friday is
     * one, Monday is two. Saturday and Sunday are not days that can be
     * counted, so they are stepped over rather than consumed. Counting
     * backwards is the mirror of it, which is what a *"three business days
     * before closing"* obligation means.
     *
     * Takes and returns a `CarbonInterface` — this project's dates hydrate as
     * `CarbonImmutable`, and typing against `Illuminate\Support\Carbon` is an
     * `instanceof` that is false for every row in the database.
     */
    public function apply(CarbonInterface $anchor, int $days): CarbonInterface
    {
        $date = $anchor->startOfDay();

        if ($days === 0) {
            return $date;
        }

        if ($this === self::Calendar) {
            return $date->addDays($days);
        }

        $step = $days > 0 ? 1 : -1;
        $remaining = abs($days);

        while ($remaining > 0) {
            $date = $date->addDays($step);

            if (! $date->isWeekend()) {
                $remaining--;
            }
        }

        return $date;
    }
}
