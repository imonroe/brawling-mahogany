/**
 * Where the calendar's arrows go (S57 · issue #105).
 *
 * A module rather than a function inside `Calendar/Index.vue`, and the reason
 * is the test. The first version of this lived in the component and its test
 * held a **copy** of the arithmetic — so deleting the fix from the component
 * left the test green, which `CLAUDE.md` names exactly: *"a test that cannot
 * tell the fix from the defect is not a test."* One definition, imported by
 * both.
 */

/** How far one press moves, per view. `null` means a whole month. */
export function stepFor(view: string): number | null {
    return view === 'agenda' ? 14 : view === 'week' ? 7 : null;
}

/**
 * The day to focus after pressing an arrow.
 *
 * Parsed at UTC noon so the arithmetic is whole days and no timezone the
 * browser happens to be in can push it onto the day before — the same anchor
 * `CalendarMonth`'s `eachDay()` uses, for the same reason.
 *
 * **To the first of the month before adding a month.** `setUTCMonth` overflows:
 * the 31st of August plus one month is the 31st of September, which is the 1st
 * of October. The focus really is the exact day — the controller echoes it back
 * unnormalised — so on the seven months with a 31st, pressing next skipped a
 * month, and from March, May and October pressing previous landed inside the
 * month already on screen, which reads as a dead button.
 *
 * The grid a month draws is decided by the server from whichever day this
 * sends, so the 1st is as good a day as any and the only one that cannot
 * overflow.
 */
export function shiftFocus(
    focus: string,
    direction: -1 | 1,
    view: string,
): string {
    const days = stepFor(view);
    const date = new Date(`${focus}T12:00:00Z`);

    if (days === null) {
        date.setUTCDate(1);
        date.setUTCMonth(date.getUTCMonth() + direction);
    } else {
        date.setUTCDate(date.getUTCDate() + direction * days);
    }

    return date.toISOString().slice(0, 10);
}
