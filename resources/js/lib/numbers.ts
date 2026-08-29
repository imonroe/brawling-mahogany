/**
 * A text box holding a number that may be no number at all (#87, #107).
 *
 * Its own module for the reason `reorder.ts` is, in this same change: three
 * dialogs now hold an optional integer as a string — a stage's expected
 * duration, a task's due offset — and `calendarNavigation.test.ts` records what
 * happens when a guard keeps its own copy of the arithmetic instead of
 * importing it. *"A copy stays green after the original is fixed."*
 *
 * The rule these exist for is one CLAUDE.md already records as a shipped bug:
 * **`Number('')` is `0`**. On #107 that meant clearing a numeric field *added*
 * a zero rather than emptying it; here it would mean a task with no deadline
 * silently acquiring one due on the day its stage starts.
 */

/**
 * A stored value, into the string a text input can hold.
 *
 * `String(null)` is `'null'` and `Number(null)` is `0`; both put an answer in a
 * field nobody answered.
 */
export function fromNullableInteger(value: number | null | undefined): string {
    return value === null || value === undefined ? '' : String(value);
}

/**
 * A typed value, back into what the server should receive.
 *
 * Empty is **no answer**, so it is null — trimmed and tested *before* `Number`
 * ever sees it.
 *
 * Anything that is not an integer comes back as **the string it was**, not as
 * `NaN`: `JSON.stringify(NaN)` is `null`, which passes a `nullable` rule and
 * would save *no answer* over somebody's typo. The raw string fails the
 * server's `integer` rule instead, which is a message on the field.
 */
export function toNullableInteger(value: string): number | string | null {
    const trimmed = value.trim();

    if (trimmed === '') {
        return null;
    }

    const parsed = Number(trimmed);

    return Number.isInteger(parsed) ? parsed : trimmed;
}
