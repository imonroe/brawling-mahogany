import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { contrastOfOklch } from './support/color';
import { TONES, tokens } from './support/tokens';

/**
 * The token layer holds itself to Design System §2 and §11.
 *
 * Two of these checks close open questions the Design System flagged: the
 * warning and success badge pairs carry 11px text and had never been measured
 * (§11, "Two contrast checks still outstanding").
 */
describe('design tokens', () => {
    it('defines every colour token in both themes', () => {
        // Design System §13.2 rule 8: adding a token means adding it to both
        // blocks, even though dark ships after v1. Non-colour tokens
        // (--radius) are theme-independent and live in :root alone.
        const isColour = ([, value]: [string, string]) =>
            value.startsWith('oklch(') || value.startsWith('var(');

        const light = Object.entries(tokens('light'))
            .filter(isColour)
            .map(([key]) => key)
            .sort();
        const dark = Object.entries(tokens('dark'))
            .filter(isColour)
            .map(([key]) => key)
            .sort();

        expect(dark).toEqual(light);
    });

    it.each(TONES)('keeps the %s badge pair readable in light mode', (tone) => {
        const light = tokens('light');

        expect(
            contrastOfOklch(
                light[`--state-${tone}`],
                light[`--state-${tone}-bg`],
            ),
        ).toBeGreaterThanOrEqual(4.5);
        // The same text also sits on a card, in a table's state column.
        expect(
            contrastOfOklch(light[`--state-${tone}`], light['--card']),
        ).toBeGreaterThanOrEqual(4.5);
    });

    it.each(TONES)('keeps the %s badge pair readable in dark mode', (tone) => {
        const dark = tokens('dark');

        expect(
            contrastOfOklch(
                dark[`--state-${tone}`],
                dark[`--state-${tone}-bg`],
            ),
        ).toBeGreaterThanOrEqual(4.5);
        expect(
            contrastOfOklch(dark[`--state-${tone}`], dark['--card']),
        ).toBeGreaterThanOrEqual(4.5);
    });

    it('keeps body and muted text readable in both themes', () => {
        for (const theme of ['light', 'dark'] as const) {
            const t = tokens(theme);

            expect(
                contrastOfOklch(t['--foreground'], t['--background']),
            ).toBeGreaterThanOrEqual(4.5);
            expect(
                contrastOfOklch(t['--muted-foreground'], t['--background']),
            ).toBeGreaterThanOrEqual(4.5);
            expect(
                contrastOfOklch(t['--muted-foreground'], t['--muted']),
            ).toBeGreaterThanOrEqual(4.5);
            expect(
                contrastOfOklch(t['--primary-foreground'], t['--primary']),
            ).toBeGreaterThanOrEqual(4.5);
            // Active nav items are primary text on the accent fill.
            expect(
                contrastOfOklch(t['--primary'], t['--accent']),
            ).toBeGreaterThanOrEqual(4.5);
        }
    });

    it('keeps the pre-paint background in the blade file matching the tokens', () => {
        // resources/views/app.blade.php paints the page background before the
        // stylesheet loads, so it duplicates two token values by hand. This is
        // the only place that is allowed to, and it has to stay in step.
        const blade = readFileSync(
            resolve(process.cwd(), 'resources/views/app.blade.php'),
            'utf8',
        );
        const painted = [
            ...blade.matchAll(/background-color:\s*(oklch\([^)]*\));/g),
        ].map((match) => match[1]);

        expect(painted).toEqual([
            tokens('light')['--background'],
            tokens('dark')['--background'],
        ]);
    });

    it('carries no raw hex in the token blocks', () => {
        // Values are oklch; the hex in the Design System exists only because
        // the design file cannot store oklch (§2.2). Comments are stripped.
        for (const theme of ['light', 'dark'] as const) {
            for (const value of Object.values(tokens(theme))) {
                expect(value).not.toMatch(/#[0-9a-fA-F]{3,8}\b/);
            }
        }
    });
});
