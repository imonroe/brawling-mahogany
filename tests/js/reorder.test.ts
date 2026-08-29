import { describe, expect, it } from 'vitest';
import { moveWithin } from '@/lib/reorder';

/**
 * The arithmetic three screens send to the server (#86, #87).
 *
 * Imported rather than restated. `calendarNavigation.test.ts` is the reason:
 * it *"held a copy of the component's arithmetic and stayed green with the fix
 * deleted"*, and a template's stages, a stage's gates and a stage's tasks now
 * all move through this one function.
 */
describe('moveWithin', () => {
    const ids = ['a', 'b', 'c'];

    it('moves a row up', () => {
        expect(moveWithin(ids, 1, -1)).toEqual(['b', 'a', 'c']);
    });

    it('moves a row down', () => {
        expect(moveWithin(ids, 1, 1)).toEqual(['a', 'c', 'b']);
    });

    it('leaves the source list alone', () => {
        moveWithin(ids, 0, 1);

        expect(ids).toEqual(['a', 'b', 'c']);
    });

    it('returns null rather than an unchanged list at either end', () => {
        /*
         * Null and not the same array, so a caller cannot post a no-op it
         * believes did something. The buttons at the ends are disabled, and a
         * keyboard or a stale render still reaches here.
         */
        expect(moveWithin(ids, 0, -1)).toBeNull();
        expect(moveWithin(ids, 2, 1)).toBeNull();
    });

    it('returns null for an index that is not in the list', () => {
        expect(moveWithin(ids, 9, -1)).toBeNull();
        expect(moveWithin([], 0, 1)).toBeNull();
    });
});
