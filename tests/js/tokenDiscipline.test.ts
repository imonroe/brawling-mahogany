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
 * `components/ui/` is checked too, but against an explicit allowlist rather
 * than a blanket exclusion. Rule 4 forbids hand-editing generated files, so
 * where a shadcn default disagrees with a token the correction goes elsewhere:
 * the dialog and sheet scrims are overridden at the token layer in `app.css`
 * (Design System §5.2). What remains is listed below with a reason, so it is
 * visible and cannot grow silently.
 */

const ROOTS = [
    'resources/js/components/app',
    'resources/js/components/forms',
    'resources/js/layouts',
    'resources/js/pages',
];

const PALETTES = [
    'slate',
    'gray',
    'zinc',
    'neutral',
    'stone',
    'red',
    'orange',
    'amber',
    'yellow',
    'lime',
    'green',
    'emerald',
    'teal',
    'cyan',
    'sky',
    'blue',
    'indigo',
    'violet',
    'purple',
    'fuchsia',
    'pink',
    'rose',
    'black',
    'white',
];

const UTILITIES = [
    'bg',
    'text',
    'border',
    'ring',
    'fill',
    'stroke',
    'from',
    'to',
    'via',
    'placeholder',
    'divide',
    'outline',
    'decoration',
    'accent',
    'caret',
    'shadow',
];

const PALETTE_CLASS = new RegExp(
    `\\b(?:${UTILITIES.join('|')})-(?:${PALETTES.join('|')})(?:-\\d{2,3})?\\b`,
    'g',
);

const HEX_COLOUR = /#[0-9a-fA-F]{3,8}\b/g;

/**
 * Palette classes that survive in generated shadcn source, with the reason
 * each is tolerated. Anything not on this list fails the build.
 */
const UI_ALLOWED = new Map<string, string[]>([
    // `--destructive-foreground` is the token equivalent and is visually
    // identical. Correcting it means editing generated source, so it is
    // corrected at the call site when a destructive control is next built.
    ['resources/js/components/ui/button/index.ts', ['text-white']],
    ['resources/js/components/ui/badge/index.ts', ['text-white']],
    // Overridden in app.css to the §5.2 scrim. The override selects on
    // `data-slot`, so each of these files must carry it — asserted below,
    // because "the class is inert" is only true while that holds.
    ['resources/js/components/ui/dialog/DialogOverlay.vue', ['bg-black']],
    ['resources/js/components/ui/dialog/DialogScrollContent.vue', ['bg-black']],
    ['resources/js/components/ui/sheet/SheetOverlay.vue', ['bg-black']],
]);

/**
 * A file's **code**, with its commentary removed.
 *
 * A colour that is not applied cannot ship, and prose is where a rule gets
 * explained: this very repository quotes `#3B5C8F` and `bg-blue-500` in
 * `CLAUDE.md` and the Design System while forbidding both. The same argument
 * `routeTargets.test.ts` makes for stripping comments before it looks for a
 * dead link — *"a `/tasks` in a comment cannot 404"* — and for the same
 * reason: a scan that flags an explanation of the rule teaches people to stop
 * writing explanations.
 *
 * **What forced it.** `HEX_COLOUR` is `#[0-9a-fA-F]{3,8}`, and an issue
 * reference is a `#` followed by digits — so `(#162)` in a comment is
 * indistinguishable from the colour `#162`, which is a real one. Every issue
 * number in this repository is three digits now, and every one of them is
 * hexadecimal, so without this the next comment citing an issue fails the
 * build for talking about it. Rewording that one comment would have left the
 * trap for the next person, who finds it in CI rather than locally.
 *
 * The rule itself is untouched: a raw colour anywhere in code still fails.
 */
function stripCommentary(source: string): string {
    return source
        .replace(/\/\*[\s\S]*?\*\//g, ' ')
        .replace(/<!--[\s\S]*?-->/g, ' ')
        .replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

function codeIn(file: string): string {
    return stripCommentary(readFileSync(file, 'utf8'));
}

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

    it('has no palette class in generated shadcn source beyond the recorded ones', () => {
        const unexpected = sourceFiles('resources/js/components/ui')
            .map((file) => {
                const allowed = UI_ALLOWED.get(file) ?? [];
                const matches = (
                    codeIn(file).match(PALETTE_CLASS) ?? []
                ).filter((match) => !allowed.includes(match));

                return { file, matches };
            })
            .filter((result) => result.matches.length > 0)
            .map(
                (result) =>
                    `${result.file}: ${[...new Set(result.matches)].join(', ')}`,
            );

        expect(unexpected).toEqual([]);
    });

    it('has components to check', () => {
        expect(files.length).toBeGreaterThan(20);
    });

    it('overrides every scrim the allowlist calls inert', () => {
        /*
         * `bg-black/80` is tolerated in generated source only because
         * app.css repaints it to the §5.2 scrim, and that override selects on
         * `data-slot`. reka-ui's own DialogOverlay does not set one, so a file
         * that renders it directly needs the attribute — DialogScrollContent
         * did not, and shipped an 80% black scrim.
         */
        const css = readFileSync(
            resolve(process.cwd(), 'resources/css/app.css'),
            'utf8',
        );
        const overridden = [
            ...css.matchAll(/\[data-slot='([a-z-]+)'\]\[data-state\]/g),
        ].map((match) => match[1]);

        expect(overridden).toContain('dialog-overlay');
        expect(overridden).toContain('sheet-overlay');

        const scrims = [...UI_ALLOWED.entries()].filter(([, classes]) =>
            classes.includes('bg-black'),
        );

        expect(scrims.length).toBeGreaterThan(0);

        for (const [file] of scrims) {
            const source = readFileSync(resolve(process.cwd(), file), 'utf8');
            const slot = source.match(/data-slot="([a-z-]+overlay)"/);

            expect(
                slot?.[1],
                `${file} needs a data-slot the override selects`,
            ).toBeDefined();
            expect(overridden).toContain(slot![1]);
        }
    });

    /*
     * The positive control for `codeIn()`, and the reason it is a test rather
     * than something checked by hand once.
     *
     * A scan that matches nothing looks exactly like a clean codebase — the
     * failure mode `routeTargets.test.ts` records twice over. Stripping
     * comments is one edit away from stripping everything, so both halves are
     * pinned here: what must survive the strip, and what must not.
     *
     * Against the real `stripCommentary`, not a copy of it. Review on #162
     * found this asserting about an inline duplicate, which cannot fail for
     * the edit it exists to catch.
     */
    it('drops commentary and keeps code', () => {
        const stripped = stripCommentary;

        // Prose about the rule, and an issue reference, are not colours.
        expect(
            stripped('/* never write #3B5C8F or bg-blue-500 */'),
        ).not.toMatch(HEX_COLOUR);
        expect(stripped('<!-- the badge, since (#162) -->')).not.toMatch(
            HEX_COLOUR,
        );
        expect(stripped('// bg-blue-500 is banned')).not.toMatch(PALETTE_CLASS);

        // Code is code, however it is written.
        expect(stripped('const c = "#3B5C8F";')).toMatch(HEX_COLOUR);
        expect(stripped('<div class="bg-blue-500" />')).toMatch(PALETTE_CLASS);
        // A URL is not a line comment — over-stripping here would blank the
        // file and pass everything.
        expect(
            stripped('const u = "https://x.test"; const c = "#abc";'),
        ).toMatch(HEX_COLOUR);
    });

    it.each(ROOTS)('uses no Tailwind palette class in %s', (root) => {
        const offenders = sourceFiles(root)
            .map((file) => ({
                file,
                matches: codeIn(file).match(PALETTE_CLASS),
            }))
            .filter((result) => result.matches !== null)
            .map((result) => `${result.file}: ${result.matches!.join(', ')}`);

        expect(offenders).toEqual([]);
    });

    it.each(ROOTS)('uses no raw hex colour in %s', (root) => {
        const offenders = sourceFiles(root)
            .map((file) => ({
                file,
                matches: codeIn(file).match(HEX_COLOUR),
            }))
            .filter((result) => result.matches !== null)
            .map((result) => `${result.file}: ${result.matches!.join(', ')}`);

        expect(offenders).toEqual([]);
    });
});
