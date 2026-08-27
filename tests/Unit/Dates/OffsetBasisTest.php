<?php

declare(strict_types=1);

use App\Enums\OffsetBasis;
use Carbon\CarbonImmutable;

/**
 * The arithmetic under the contingency calendar (PRD §4.8 F8.2 · issue #106).
 *
 * Pure logic, so it lives in Unit and touches no database. The cases below are
 * the ones a contract actually produces — a weekend landing, a zero offset, a
 * count backwards — because a business-day helper that is only tested on a
 * Tuesday-to-Thursday span is one that has not been tested.
 */
it('adds calendar days without looking at the weekend', function (): void {
    // Friday 2026-08-21 → Sunday 2026-08-23.
    $result = OffsetBasis::Calendar->apply(CarbonImmutable::parse('2026-08-21'), 2);

    expect($result->toDateString())->toBe('2026-08-23');
});

it('steps a business-day count over the weekend', function (): void {
    /*
     * Thursday plus two business days is the following Monday: Friday is one,
     * Saturday and Sunday are not days that can be counted, Monday is two.
     */
    $result = OffsetBasis::Business->apply(CarbonImmutable::parse('2026-08-20'), 2);

    expect($result->toDateString())->toBe('2026-08-24');
});

it('counts business days backwards the same way', function (): void {
    // Monday minus one business day is the Friday before it.
    $result = OffsetBasis::Business->apply(CarbonImmutable::parse('2026-08-24'), -1);

    expect($result->toDateString())->toBe('2026-08-21');
});

it('leaves a zero offset on the anchor, even on a Saturday', function (): void {
    /*
     * *"The day of closing"* is a real offset somebody writes, and it means
     * the anchor. Rounding it forward to Monday would move a date nobody asked
     * to move — and the anchor is the one value in the chain that was given
     * rather than derived.
     */
    foreach (OffsetBasis::cases() as $basis) {
        expect($basis->apply(CarbonImmutable::parse('2026-08-22'), 0)->toDateString())
            ->toBe('2026-08-22');
    }
});

it('lands on a weekday when a business count starts on a weekend', function (): void {
    // Saturday plus one business day is Monday.
    $result = OffsetBasis::Business->apply(CarbonImmutable::parse('2026-08-22'), 1);

    expect($result->toDateString())->toBe('2026-08-24');
});

it('says the offset in words S18 can print', function (): void {
    expect(OffsetBasis::Calendar->phrase(10))->toBe('10 calendar days after')
        ->and(OffsetBasis::Business->phrase(-3))->toBe('3 business days before')
        ->and(OffsetBasis::Calendar->phrase(1))->toBe('1 calendar day after')
        ->and(OffsetBasis::Business->phrase(-1))->toBe('1 business day before');
});

it('normalises the time so a date column never carries an hour', function (): void {
    $result = OffsetBasis::Calendar->apply(CarbonImmutable::parse('2026-08-21 17:45:00'), 1);

    expect($result->format('H:i:s'))->toBe('00:00:00');
});
