/**
 * No screen links to a route that does not exist.
 *
 * S15's `linkFor()` mapped an unmet tasks gate to `/deals/{deal}/tasks` when
 * S17 was unbuilt, there was no such route, and `DealHeader` drew that tab
 * inert for exactly that reason. So the hub rendered "Go and clear it" over a
 * 404, three lines under its own comment saying a dead link is worse than a
 * sentence. (S17 shipped in #71; the list below is what moved.)
 *
 * Held by reading the source rather than by a mount, for the reason
 * `boundControls.test.ts` gives: the mistake is not in one component's
 * behaviour, it is a rule the next screen can break as easily as this one did.
 * A page-level render test would have covered only the page it was written for.
 *
 * ## Why it strips comments and then matches plainly
 *
 * Two earlier versions each caught one spelling and missed others.
 *
 * The first pattern-matched *deal-shaped expression followed by the segment*.
 * `dealUrl.value + '/tasks'` walked straight past it, because the quote in the
 * middle ended the pattern's character class.
 *
 * The second tokenised string literals and searched those. That missed
 * `` `${dealUrl}/tasks` `` — not because the pattern was wrong, but because a
 * plain apostrophe in the prose above it (*"the deal's tabs"*) opened a
 * spurious literal that swallowed the real one. Source full of English is
 * source where quote-pairing does not mean what it means in code.
 *
 * So: take the comments out, then look for the segment as a path component
 * anywhere in what is left. A `/tasks` in code is a link however it was
 * assembled; a `/tasks` in a comment cannot 404. Nothing has to be guessed
 * about which construction was used.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Segments under `/deals/{deal}/…` whose screens have not been built.
 *
 * `timeline` left this list when S16 landed (#76), `tasks` when S17 did (#71),
 * and `documents` when S21 did (#98) — all three are routes now, and a link to
 * one is a link. The list shrinks one entry per slice; what it protects is the
 * ones still unbuilt.
 *
 * `tasks` leaving is the case the test was written for: PRD §5.4 asks that
 * *"each unmet gate links directly to the thing that clears it"*, and
 * `required_tasks_complete` is the gate a deal meets most often. The link
 * could not be written until the screen existed, and the day it did, this line
 * is what had to change for it to be written.
 *
 * `DealHeader` names the rest, as `segment: 'tasks'` and friends — bare, with
 * no leading slash, because it draws them inert rather than linking them. The
 * slash is what separates a tab's name from a path to it.
 *
 * The scan does not check that the slash belongs to a *deal* URL, so a future
 * `import … from '@/lib/dates'` would be reported as a dead deal tab. Nothing
 * in `resources/js/lib` is named for one of these today. If you are reading
 * this because the test failed on an import, that is the pattern being blunt
 * rather than your code being wrong — narrow the pattern, do not delete it.
 */
const UNBUILT_DEAL_TABS = ['dates'];

function sourceFiles(directory: string): string[] {
    const absolute = resolve(process.cwd(), directory);

    return readdirSync(absolute).flatMap((entry) => {
        const path = join(absolute, entry);

        if (statSync(path).isDirectory()) {
            // Generated: Wayfinder writes these from the route table, so they
            // describe routes that exist by construction.
            return ['routes', 'actions', 'wayfinder'].includes(entry)
                ? []
                : sourceFiles(join(directory, entry));
        }

        return entry.endsWith('.vue') || entry.endsWith('.ts')
            ? [join(directory, entry)]
            : [];
    });
}

/**
 * The file with its commentary removed: block comments, HTML comments, and
 * line comments.
 *
 * `//` is only treated as a line comment when it is not preceded by `:`, so
 * the `//` in `https://example.test` does not truncate the line it is on.
 * Over-stripping here would be a false negative, which is the failure mode
 * this whole test exists to avoid.
 */
function withoutComments(source: string): string {
    return source
        .replace(/\/\*[\s\S]*?\*\//g, ' ')
        .replace(/<!--[\s\S]*?-->/g, ' ')
        .replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

/**
 * How much code the scan expects to be left holding, less a wide margin.
 *
 * A floor on what survived the strip, not on what was matched. Two earlier
 * floors were useless: one counted `files × segments`, so a pattern matching
 * nothing still cleared it, and a literal count rose with the spurious matches
 * that were the bug. Characters of comment-free source is the quantity that
 * actually goes to zero when the scan stops working.
 */
const EXPECTED_CODE_CHARS = 100_000;

/**
 * The scan itself, over one file's contents.
 *
 * The segment as a path component, however the URL was built:
 * `/deals/${id}/tasks`, '/tasks' concatenated onto a base, or a query string
 * hung off the end. The trailing guard keeps `/dates-and-deadlines` and
 * `/documentsUpload` out.
 */
function deadLinksIn(source: string): string[] {
    const code = withoutComments(source);

    return UNBUILT_DEAL_TABS.flatMap((segment) => {
        const match = new RegExp(`\\S*/${segment}(?![\\w-])\\S*`).exec(code);

        return match === null ? [] : [match[0]];
    });
}

describe('route targets', () => {
    /*
     * The positive control, and the reason it is a test rather than something
     * checked by hand once.
     *
     * Two earlier drafts of this scan each matched one spelling of the dead
     * link and missed others — a pattern whose character class ended at a
     * quote, then a literal-tokeniser that an apostrophe in prose walked past.
     * Both times the file stayed green, because a scan that matches nothing
     * looks exactly like a clean codebase. These are the forms the link has
     * actually taken, and the near-misses that must not trip it.
     */
    it('recognises a dead link however it was assembled', () => {
        const dead = [
            'return `${dealUrl.value}/dates`;',
            "return dealUrl.value + '/dates';",
            "return '/deals/' + deal.id + '/dates';",
            'return `/deals/${deal.id}/dates?filter=all`;',
            'const u = dealUrl.value; return u + "/dates";',
        ];

        for (const source of dead) {
            expect(deadLinksIn(source), source).toHaveLength(1);
        }

        const fine = [
            "const label = 'Dates';",
            "{ segment: 'dates', arrivesWith: 'S18' }",
            "router.visit('/dates-and-deadlines');",
            "fetch('/deals/1/datesPicker');",
            '// the deal has no /dates route yet',
            // Built, so a link to it is a link rather than an offence.
            'return `${dealUrl}/tasks`;',
            // Offers left the list with S22 (#73).
            'form.post(`${dealUrl}/offers`,',
            // Documents left it with S21 (#98).
            'form.post(`${dealUrl}/documents`,',
        ];

        for (const source of fine) {
            expect(deadLinksIn(source), source).toEqual([]);
        }
    });

    it('never builds a deal URL for a tab that has no route', () => {
        const files = sourceFiles('resources/js');

        expect(files.length).toBeGreaterThan(40);

        const offenders: string[] = [];
        let codeChars = 0;

        for (const file of files) {
            const source = readFileSync(resolve(process.cwd(), file), 'utf8');

            codeChars += withoutComments(source).length;

            for (const found of deadLinksIn(source)) {
                offenders.push(`${file}: ${found}`);
            }
        }

        expect(
            codeChars,
            'Almost nothing survived comment-stripping, which means the scan ' +
                'is not reading what it thinks it is reading.',
        ).toBeGreaterThan(EXPECTED_CODE_CHARS);

        expect(
            offenders,
            [
                'A screen links to a deal tab that has no route yet.',
                'DealHeader draws these tabs inert because their slice has not',
                'shipped; a link to one is a 404 the user finds instead of the fix.',
                ...offenders,
            ].join('\n'),
        ).toEqual([]);
    });
});
