/**
 * No screen links to a route that does not exist.
 *
 * S15's `linkFor()` mapped an unmet tasks gate to `/deals/{deal}/tasks` — S17
 * is unbuilt, there is no such route, and `DealHeader` already draws that tab
 * inert for exactly that reason. So the hub rendered "Go and clear it" over a
 * 404, three lines under its own comment saying a dead link is worse than a
 * sentence.
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
 * `DealHeader` names all five, as `segment: 'tasks'` and friends — bare, with
 * no leading slash, because it draws them inert rather than linking them. The
 * slash is what separates a tab's name from a path to it.
 */
const UNBUILT_DEAL_TABS = ['tasks', 'dates', 'documents', 'offers', 'timeline'];

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

describe('route targets', () => {
    it('never builds a deal URL for a tab that has no route', () => {
        const files = sourceFiles('resources/js');

        expect(files.length).toBeGreaterThan(40);

        const offenders: string[] = [];
        let codeChars = 0;

        for (const file of files) {
            const code = withoutComments(
                readFileSync(resolve(process.cwd(), file), 'utf8'),
            );

            codeChars += code.length;

            for (const segment of UNBUILT_DEAL_TABS) {
                /*
                 * The segment as a path component, however the URL was built:
                 * `/deals/${id}/tasks`, '/tasks' concatenated onto a base, or
                 * a query string hung off the end. The trailing guard keeps
                 * `/dates-and-deadlines` and `/documentsUpload` out.
                 */
                const match = new RegExp(`\\S*/${segment}(?![\\w-])\\S*`).exec(
                    code,
                );

                if (match !== null) {
                    offenders.push(`${file}: ${match[0]}`);
                }
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
