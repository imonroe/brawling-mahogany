<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A repeat, described rather than expanded (S58 · issue #105).
 *
 * One row plus a rule. A weekly open house with no end date expanded into rows
 * would be an unbounded INSERT, and editing the series would mean finding
 * every row it had produced — so the grid expands the rule for the window it
 * is drawing.
 *
 * ## The `.ics` feed expands too, rather than sending an `RRULE`
 *
 * The obvious alternative, and the one three docblocks in this codebase used
 * to claim was happening. Handing a client the rule is fewer bytes and it
 * makes the *client* responsible for a computation this file has already got
 * right — including the parts `occurrence()` below argues about, where a
 * monthly series that began on the 31st must come back to the 31st, and where
 * a floating `UNTIL` against a zoned `DTSTART` is the one thing Apple and
 * Google are known to read differently. A series that runs a week too long in
 * one client and not the other is a bug nobody can reproduce.
 *
 * The feed serves a bounded window (90 days back, a year forward), so
 * expanding costs a known number of `VEVENT`s and every subscriber sees the
 * days this product would show them. `toRrule()` existed for the other
 * approach, was never called by anything, and is gone: a method with a
 * careful docblock and no caller is the *"reader with no writer"* shape
 * `teams.logo_path` is recorded for.
 *
 * ## Deliberately a small subset of RFC 5545
 *
 * Daily, weekly and monthly, with an interval and an end. That covers what a
 * real estate week actually contains — a Saturday open house for a month, a
 * weekly contractor visit, a monthly HOA meeting — and stops short of `BYDAY`
 * lists, `BYSETPOS`, and the rest of the standard's long tail. Every one of
 * those is a feature somebody has to *understand* in the modal, and S58 is a
 * modal that also has to do attendees and a deal link.
 *
 * Where a team needs something this cannot say, they add the occurrences by
 * hand, which is honest. What this must never do is quietly approximate one —
 * *"the third Tuesday"* rendered as *"every 21 days"* is a meeting somebody
 * misses in the second month.
 */
final readonly class Recurrence
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    /**
     * How many occurrences a single expansion will ever return.
     *
     * A backstop, not a product rule: the window is what bounds an ordinary
     * expansion, and this catches an interval of zero or a rule whose `until`
     * is a decade out from a caller that forgot to pass a window. A loop that
     * can run forever inside a web request is the failure worth one comparison
     * per iteration to make impossible.
     */
    public const MAX_OCCURRENCES = 1000;

    private function __construct(
        public string $frequency,
        public int $interval,
        public ?CarbonImmutable $until,
    ) {}

    /**
     * @param  array<string, mixed>|null  $value
     */
    public static function fromArray(?array $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $frequency = is_string($value['frequency'] ?? null) ? $value['frequency'] : '';

        if (! in_array($frequency, [self::DAILY, self::WEEKLY, self::MONTHLY], true)) {
            return null;
        }

        /*
         * An interval below one would make the expansion below never advance.
         * Clamped rather than refused, because the row is already saved by the
         * time anything reads it and a calendar that throws is a calendar
         * nobody can open to fix the event.
         */
        $interval = is_numeric($value['interval'] ?? null) ? max(1, (int) $value['interval']) : 1;

        $until = is_string($value['until'] ?? null) && trim($value['until']) !== ''
            ? CarbonImmutable::parse($value['until'])->endOfDay()
            : null;

        return new self($frequency, $interval, $until);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'frequency' => $this->frequency,
            'interval' => $this->interval,
            'until' => $this->until?->toDateString(),
        ];
    }

    /**
     * Every start instant this rule produces inside a window.
     *
     * ## It skips to the window rather than walking to it
     *
     * The obvious spelling steps from the first occurrence one interval at a
     * time until it arrives. That is correct and it does not survive contact
     * with a real series: a daily rule that began three years ago needs 1,095
     * steps to reach today, which is past {@see self::MAX_OCCURRENCES} — so
     * the guard meant to stop an infinite loop would instead return **nothing**
     * for the window somebody is looking at, silently. A recurring event
     * vanishing from the grid once the series is old enough is the kind of
     * defect that gets reported as *"the calendar is missing things"* months
     * later.
     *
     * So the first step is estimated in closed form and then walked back two,
     * which absorbs the rounding a short month introduces without needing the
     * estimate to be exact.
     *
     * ## But it still steps from the series start, not by arithmetic
     *
     * Jumping straight to a date works for a daily rule and is wrong for a
     * monthly one: *"the 31st"* has no February, and any closed-form skip has
     * to decide what that means before it can count. Every occurrence is
     * therefore an offset from the **origin**, which is also the rule RFC 5545
     * gives a client computing from an `RRULE` — so a subscriber who ever gets
     * one sees the same days this does.
     *
     * @return list<CarbonImmutable>
     */
    public function occurrencesBetween(
        CarbonInterface $seriesStart,
        CarbonInterface $from,
        CarbonInterface $to,
    ): array {
        $origin = CarbonImmutable::instance($seriesStart);
        $windowStart = CarbonImmutable::instance($from);

        $end = $this->until instanceof CarbonImmutable && $this->until->lessThan($to)
            ? $this->until
            : CarbonImmutable::instance($to);

        $occurrences = [];

        $step = $this->firstStepNear($origin, $windowStart);

        for ($taken = 0; $taken < self::MAX_OCCURRENCES; $taken++, $step++) {
            $cursor = $this->occurrence($origin, $step);

            if ($cursor->greaterThan($end)) {
                break;
            }

            if ($cursor->greaterThanOrEqualTo($windowStart)) {
                $occurrences[] = $cursor;
            }
        }

        return $occurrences;
    }

    /**
     * How S58 says it: *"Repeats every 2 weeks until Sep 30"*.
     *
     * Written out rather than assembled from a plural helper, because *"every
     * 1 weeks"* is the sentence a generic one produces and a modal is exactly
     * where somebody reads it closely enough to notice.
     */
    public function sentence(): string
    {
        $unit = match ($this->frequency) {
            self::DAILY => $this->interval === 1 ? 'day' : 'days',
            self::WEEKLY => $this->interval === 1 ? 'week' : 'weeks',
            default => $this->interval === 1 ? 'month' : 'months',
        };

        $every = $this->interval === 1 ? 'Repeats every '.$unit : 'Repeats every '.$this->interval.' '.$unit;

        return $this->until instanceof CarbonImmutable
            ? $every.' until '.$this->until->format('M j, Y')
            : $every;
    }

    /**
     * The occurrence `$step` intervals after the series start.
     *
     * Computed from the **origin** rather than from the previous occurrence,
     * so a monthly series that began on the 31st comes back to the 31st after
     * passing February rather than being permanently pulled to the 28th.
     * Carbon clamps a short month, and clamping a moving cursor compounds;
     * clamping a fresh offset from the origin does not.
     */
    private function occurrence(CarbonImmutable $origin, int $step): CarbonImmutable
    {
        $offset = $step * $this->interval;

        return match ($this->frequency) {
            self::DAILY => $origin->addDays($offset),
            self::WEEKLY => $origin->addWeeks($offset),
            default => $origin->addMonthsNoOverflow($offset),
        };
    }

    /**
     * A step index at or just before the window opens.
     *
     * Estimated, then walked back two and floored at zero. Two rather than one
     * because a monthly estimate can be off by a whole step when the origin is
     * near the end of a month, and because the cost of starting early is one
     * comparison per skipped occurrence while the cost of starting late is a
     * missing event.
     */
    private function firstStepNear(CarbonImmutable $origin, CarbonImmutable $windowStart): int
    {
        if ($windowStart->lessThanOrEqualTo($origin)) {
            return 0;
        }

        $elapsed = match ($this->frequency) {
            self::DAILY => $origin->diffInDays($windowStart),
            self::WEEKLY => $origin->diffInWeeks($windowStart),
            default => $origin->diffInMonths($windowStart),
        };

        return max(0, (int) floor($elapsed / $this->interval) - 2);
    }
}
