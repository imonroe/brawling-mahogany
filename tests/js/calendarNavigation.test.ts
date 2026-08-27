/**
 * The month arrows, on the seven months with a 31st (#105).
 *
 * `setUTCMonth` overflows — the 31st of August plus one month is the 31st of
 * September, which is the 1st of October — and the focus really is the exact
 * day, because the controller echoes it back without normalising. So pressing
 * **›** skipped a month, and from March, May and October the **‹** arrow
 * landed inside the month already on screen, which reads as a dead button.
 *
 * Here rather than in a feature test because the arithmetic is the browser's:
 * the server sees only whichever day it is sent, and would answer correctly
 * for the wrong one.
 */
import { describe, expect, it } from 'vitest';

/** What `shift()` computes, extracted so the arithmetic can be asserted. */
function shiftMonth(focus: string, direction: -1 | 1): string {
    const date = new Date(`${focus}T12:00:00Z`);

    date.setUTCDate(1);
    date.setUTCMonth(date.getUTCMonth() + direction);

    return date.toISOString().slice(0, 10);
}

/** The month a day belongs to, which is what the arrows have to step by one. */
function monthOf(day: string): string {
    return day.slice(0, 7);
}

describe('the month arrows', () => {
    const thirtyFirsts = [
        '2026-01-31',
        '2026-03-31',
        '2026-05-31',
        '2026-07-31',
        '2026-08-31',
        '2026-10-31',
        '2026-12-31',
    ];

    it('steps forward exactly one month from a 31st', () => {
        const stepped = thirtyFirsts.map((day) => [
            monthOf(day),
            monthOf(shiftMonth(day, 1)),
        ]);

        expect(stepped).toEqual([
            ['2026-01', '2026-02'],
            ['2026-03', '2026-04'],
            ['2026-05', '2026-06'],
            ['2026-07', '2026-08'],
            ['2026-08', '2026-09'],
            ['2026-10', '2026-11'],
            ['2026-12', '2027-01'],
        ]);
    });

    it('steps back exactly one month from a 31st', () => {
        const stepped = thirtyFirsts.map((day) => [
            monthOf(day),
            monthOf(shiftMonth(day, -1)),
        ]);

        expect(stepped).toEqual([
            ['2026-01', '2025-12'],
            ['2026-03', '2026-02'],
            ['2026-05', '2026-04'],
            ['2026-07', '2026-06'],
            ['2026-08', '2026-07'],
            ['2026-10', '2026-09'],
            ['2026-12', '2026-11'],
        ]);
    });

    it('is reversible from every day of a month, which is the property that failed', () => {
        // Forward then back lands on the month it started in — true of the
        // 1st before the fix and false of the 31st.
        for (let day = 1; day <= 31; day += 1) {
            const start = `2026-08-${String(day).padStart(2, '0')}`;

            expect(monthOf(shiftMonth(shiftMonth(start, 1), -1))).toBe(
                '2026-08',
            );
        }
    });
});
