import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Design System §2.1, made mechanical.
 *
 *   "A component never contains a raw hex value or a Tailwind palette class.
 *    No bg-blue-500, no #3B5C8F, ever. If a color is needed and no token
 *    expresses it, the answer is a new token, not a one-off."
 *
 * §13.2 calls this "the one worth being pedantic about in review". A review
 * catches it sometimes; this catches it every time.
 *
 * `components/ui/` is excluded on purpose: it is shadcn CLI output and rule 4
 * forbids hand-editing it. Where a shadcn default disagrees with a token — the
 * dialog scrim is the known case — the fix is a wrapper in `components/app/`,
 * not an edit to the generated file.
 */

const ROOTS = ['resources/js/components/app', 'resources/js/components/forms', 'resources/js/layouts', 'resources/js/pages'];

const PALETTES = [
    'slate', 'gray', 'zinc', 'neutral', 'stone', 'red', 'orange', 'amber', 'yellow',
    'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet',
    'purple', 'fuchsia', 'pink', 'rose', 'black', 'white',
];

const UTILITIES = [
    'bg', 'text', 'border', 'ring', 'fill', 'stroke', 'from', 'to', 'via', 'placeholder',
    'divide', 'outline', 'decoration', 'accent', 'caret', 'shadow',
];

const PALETTE_CLASS = new RegExp(
    `\\b(?:${UTILITIES.join('|')})-(?:${PALETTES.join('|')})(?:-\\d{2,3})?\\b`,
    'g',
);

const HEX_COLOUR = /#[0-9a-fA-F]{3,8}\b/g;

function sourceFiles(directory: string): string[] {
    const absolute = resolve(process.cwd(), directory);

    return readdirSync(absolute).flatMap((entry) => {
        const path = join(absolute, entry);

        if (statSync(path).isDirectory()) {
            return sourceFiles(join(directory, entry));
        }

        return /\.(vue|ts)$/.test(entry) ? [join(directory, entry)] : [];
    });
}

describe('token discipline', () => {
    const files = ROOTS.flatMap(sourceFiles);

    it('has components to check', () => {
        expect(files.length).toBeGreaterThan(20);
    });

    it.each(ROOTS)('uses no Tailwind palette class in %s', (root) => {
        const offenders = sourceFiles(root)
            .map((file) => ({ file, matches: readFileSync(file, 'utf8').match(PALETTE_CLASS) }))
            .filter((result) => result.matches !== null)
            .map((result) => `${result.file}: ${result.matches!.join(', ')}`);

        expect(offenders).toEqual([]);
    });

    it.each(ROOTS)('uses no raw hex colour in %s', (root) => {
        const offenders = sourceFiles(root)
            .map((file) => ({ file, matches: readFileSync(file, 'utf8').match(HEX_COLOUR) }))
            .filter((result) => result.matches !== null)
            .map((result) => `${result.file}: ${result.matches!.join(', ')}`);

        expect(offenders).toEqual([]);
    });
});
