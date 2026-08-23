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
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/** Segments under `/deals/{deal}/…` whose screens have not been built. */
const UNBUILT_DEAL_TABS = ['tasks', 'dates', 'documents', 'offers', 'timeline'];

function vueFiles(directory: string): string[] {
    const absolute = resolve(process.cwd(), directory);

    return readdirSync(absolute).flatMap((entry) => {
        const path = join(absolute, entry);

        if (statSync(path).isDirectory()) {
            return vueFiles(join(directory, entry));
        }

        return entry.endsWith('.vue') ? [join(directory, entry)] : [];
    });
}

describe('route targets', () => {
    it('never builds a deal URL for a tab that has no route', () => {
        const files = vueFiles('resources/js');

        expect(files.length).toBeGreaterThan(40);

        const offenders: string[] = [];
        let scanned = 0;

        for (const file of files) {
            const source = readFileSync(resolve(process.cwd(), file), 'utf8');

            for (const segment of UNBUILT_DEAL_TABS) {
                scanned++;

                /*
                 * A template literal or string ending in the segment, built
                 * from something deal-shaped. `DealHeader` names these
                 * segments too — as labels and as `built: false` entries — so
                 * the pattern requires the slash-prefixed URL form.
                 */
                const pattern = new RegExp(
                    `(?:dealUrl[^\`'"\\n]*|/deals/\\$\\{[^}]+\\}|/deals/'\\s*\\+[^\`'"\\n]*)/${segment}\\b`,
                );

                if (pattern.test(source)) {
                    offenders.push(`${file}: /${segment}`);
                }
            }
        }

        // A floor on the scan, not on what it found: a pattern that stopped
        // matching would otherwise read exactly like a clean codebase.
        expect(scanned).toBeGreaterThan(200);

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
