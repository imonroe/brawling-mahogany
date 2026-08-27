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
 *
 * ## It imports the function the screen calls, and the first version did not
 *
 * That version held a **copy** of `shift()`'s body, so what it asserted was
 * that the copy was right: deleting the fix from `Calendar/Index.vue` left this
 * file green. `CLAUDE.md` names the shape — *"a test that cannot fail is worse
 * than no check"* — and a guard that reports green over the bug it was written
 * for is the reason nobody looks again. The arithmetic moved to
 * `lib/calendarNavigation.ts` so there is one definition for both.
 */
import { describe, expect, it } from 'vitest';
import { shiftFocus, stepFor } from '@/lib/calendarNavigation';

/** The month arrow, which is the case that overflowed. */
function shiftMonth(focus: string, direction: -1 | 1): string {
    return shiftFocus(focus, direction, 'month');
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

    it('shifts week and agenda by exact days, month end included', () => {
        // The month branch is the one that normalises; these must not, or
        // stepping a week from the 31st would land on the 1st.
        expect(stepFor('week')).toBe(7);
        expect(stepFor('agenda')).toBe(14);

        expect(shiftFocus('2026-08-31', 1, 'week')).toBe('2026-09-07');
        expect(shiftFocus('2026-08-31', -1, 'week')).toBe('2026-08-24');
        expect(shiftFocus('2026-08-31', 1, 'agenda')).toBe('2026-09-14');
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
