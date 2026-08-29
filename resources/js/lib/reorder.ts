/**
 * Moving one row within an ordered list (#86, #87).
 *
 * Its own module for the reason `calendarNavigation.ts` is: three screens now
 * send a whole order to the server after a move — a template's stages, a
 * stage's gates and a stage's tasks — and `calendarNavigation.test.ts` records
 * what happens when a guard holds its own copy of the arithmetic instead of
 * importing it. That test *"held a copy of the component's arithmetic and
 * stayed green with the fix deleted."* One definition, imported by the callers
 * and by the test.
 *
 * The order is sent whole rather than as a swap, which is the argument
 * `StageTemplateController` makes at the top: a reorder is one intention, and
 * two adjacent swaps racing each other produce an order neither person chose.
 */

/**
 * The ids in their new order, or **null** when the move is off the ends.
 *
 * Null rather than the unchanged array, so a caller cannot post a no-op it
 * believes did something — the button at the top of a list is disabled, and a
 * keyboard or a stale render can still reach this.
 */
export function moveWithin<T>(ids: T[], index: number, by: number): T[] | null {
    const next = index + by;

    if (index < 0 || index >= ids.length || next < 0 || next >= ids.length) {
        return null;
    }

    const reordered = [...ids];
    const [moved] = reordered.splice(index, 1);

    reordered.splice(next, 0, moved);

    return reordered;
}
