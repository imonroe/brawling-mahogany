import { describe, expect, it } from 'vitest';
import { fromNullableInteger, toNullableInteger } from '@/lib/numbers';

/**
 * The optional-integer rule the template dialogs submit through (#87, #107).
 *
 * Imported by both dialogs rather than copied into each, and tested here
 * rather than in neither — which is what it was: two hand-written copies of a
 * guard CLAUDE.md records as a **shipped bug**, in the same change that
 * extracted `reorder.ts` on the argument that a copy stays green after the
 * original is fixed.
 */
describe('toNullableInteger', () => {
    it('reads an empty box as no answer, not as zero', () => {
        /*
         * `Number('')` is `0`. On #107 that meant clearing a numeric field
         * *added* a zero; here it would mean a task with no deadline quietly
         * acquiring one, due on the day its stage starts.
         */
        expect(toNullableInteger('')).toBeNull();
        expect(toNullableInteger('   ')).toBeNull();
    });

    it('keeps a real number, including a negative one', () => {
        // Negative is "before the stage starts", which is an ordinary
        // instruction rather than an edge case.
        expect(toNullableInteger('14')).toBe(14);
        expect(toNullableInteger('-3')).toBe(-3);
        expect(toNullableInteger(' 7 ')).toBe(7);
        expect(toNullableInteger('0')).toBe(0);
    });

    it('hands a typo back as the string it was, never as NaN', () => {
        /*
         * `JSON.stringify(NaN)` is `null`, which passes a `nullable` rule — so
         * "ten" would have saved *no answer* over somebody's typo. The raw
         * string fails the server's `integer` rule instead, which is a message
         * on the field.
         */
        expect(toNullableInteger('ten')).toBe('ten');
        expect(toNullableInteger('3.5')).toBe('3.5');
    });
});

describe('fromNullableInteger', () => {
    it('shows an unset value as an empty box', () => {
        // `String(null)` is `'null'`; both of these would put an answer in a
        // field nobody answered.
        expect(fromNullableInteger(null)).toBe('');
        expect(fromNullableInteger(undefined)).toBe('');
    });

    it('shows a stored number, including zero', () => {
        expect(fromNullableInteger(0)).toBe('0');
        expect(fromNullableInteger(-3)).toBe('-3');
    });

    it('round-trips whatever it produced', () => {
        for (const value of [null, 0, -365, 365]) {
            expect(toNullableInteger(fromNullableInteger(value))).toBe(value);
        }
    });
});
