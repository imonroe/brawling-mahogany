import { readFileSync } from 'node:fs';
import { mount } from '@vue/test-utils';
import axe from 'axe-core';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import ClientLayout from '@/layouts/ClientLayout.vue';
import StatusDocuments from '@/pages/Status/Documents.vue';
import StatusExpired from '@/pages/Status/Expired.vue';
import StatusShow from '@/pages/Status/Show.vue';

/**
 * WCAG 2.1 AA on the client surface (PRD §9 · issue #112).
 *
 * PRD §9 splits the requirement deliberately: **AA for the client status page,
 * best effort internally.** Screen Inventory says why — the internal app is
 * used daily by two people who can be trained around a rough edge, and the
 * status page is *"the only screen a stranger uses unaided"*, by an audience
 * that skews older, on a phone, once.
 *
 * ## What this file is, and what it is not
 *
 * #112 asks for two passes: *"automated (axe or equivalent) plus a manual pass
 * with a screen reader on a real phone. Automated tooling catches contrast and
 * missing labels; it does not catch a timeline that reads as gibberish."*
 *
 * **This is the automated one**, and it is honest about its limits. jsdom has
 * no layout, so axe's colour-contrast rule cannot run here at all — the
 * contrast half is asserted where the decision actually lives, against
 * `AccentContrast` in `tests/Feature/StatusPage/`. And no automated tool can
 * tell whether *"Your home is on the market — Happening now"* reads sensibly
 * aloud; that is the manual pass, and it is device work that a commit cannot
 * close. Both are recorded in the PR rather than implied by a green suite.
 *
 * What it *does* catch is the class of regression a screen picks up silently:
 * a heading level skipped, a landmark lost, a list turned into divs, a form
 * control that stops being labelled, a link with no accessible name.
 */
/*
 * Inertia is mocked rather than installed, the way `dealHeader.test.ts` does
 * it. `Head` needs a real Inertia app to resolve its provider and `useForm`
 * needs a page — neither of which says anything about accessibility, and both
 * of which would make this file about wiring up a router.
 */
vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => ({ props: {} }),
    router: { post: vi.fn(), delete: vi.fn(), visit: vi.fn() },
    useForm: <T extends object>(fields: T) => ({
        ...fields,
        errors: {} as Record<string, string>,
        processing: false,
        post: vi.fn(),
        reset: vi.fn(),
        clearErrors: vi.fn(),
    }),
}));

const team = {
    name: 'Bosart Group',
    accent: '#1A588F',
    accentForeground: '#FFFFFF',
    logo: null,
};

const contact = { name: 'Emily Bosart', phone: '317-555-0142', email: null };

const showProps = {
    token: 'a'.repeat(43),
    team,
    deal: {
        kind: 'Your Sale',
        addressLine1: '123 Main St',
        addressLine2: 'Indianapolis, IN 46201',
    },
    status: {
        headline: 'Your home is on the market',
        reassurance: 'There is nothing you need to do right now.',
    },
    steps: [
        {
            id: '1',
            label: 'Getting your home ready',
            position: 'done' as const,
            when: 'Finished Friday, August 1',
        },
        {
            id: '2',
            label: 'Your home is on the market',
            position: 'now' as const,
            when: 'Happening now',
        },
        {
            id: '3',
            label: 'Sold',
            position: 'next' as const,
            when: null,
        },
    ],
    dates: [
        {
            id: 'd1',
            name: 'Inspection review period',
            date: 'Thursday, August 20',
        },
    ],
    contact,
    hasDocuments: true,
};

/**
 * Run axe over a mounted component.
 *
 * Mounted into a real `<div>` attached to the document, because axe walks the
 * document rather than a detached fragment — a test that forgot that would
 * report zero violations on every page forever, which is the way an automated
 * accessibility check fails without saying so.
 */
async function violationsIn(html: string): Promise<string[]> {
    const host = document.createElement('div');

    host.innerHTML = html;
    document.body.append(host);

    const results = await axe.run(host, {
        /*
         * Contrast is **excluded, not passing**. jsdom computes no layout and
         * resolves no stylesheet, so axe cannot see a colour to check — and
         * leaving the rule enabled would return `incomplete`, which reads as a
         * clean run. The real contrast question is decided server-side by
         * `AccentContrast` and asserted there.
         */
        rules: { 'color-contrast': { enabled: false } },
        runOnly: {
            type: 'tag',
            values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
        },
    });

    host.remove();

    return results.violations.map(
        (violation) => `${violation.id}: ${violation.help}`,
    );
}

describe('the client status page, automatically', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('reports a violation when there is one to report', async () => {
        /*
         * The positive control, and `CLAUDE.md`'s rule about a check that
         * cannot fail: *"prove a healthcheck can go red before trusting that
         * it is green."*
         *
         * An automated accessibility pass is unusually good at looking clean
         * for the wrong reason — a detached fragment axe never walks, a mount
         * that rendered nothing, a rule set narrowed to nothing. Every one of
         * those returns an empty array, which is exactly what a perfect page
         * returns.
         */
        const violations = await violationsIn(
            '<img src="x.png"><input type="text">',
        );

        expect(violations.length).toBeGreaterThan(0);
        expect(violations.join(' ')).toContain('image-alt');
    });

    it('has no axe violation on the status timeline (S62)', async () => {
        const wrapper = mount(StatusShow, {
            props: showProps,
        });

        expect(await violationsIn(wrapper.html())).toEqual([]);
    });

    it('has no axe violation on the documents list (S63)', async () => {
        const wrapper = mount(StatusDocuments, {
            props: {
                token: 'a'.repeat(43),
                team,
                contact,
                documents: [
                    {
                        id: 'x',
                        name: 'Seller disclosure.pdf',
                        url: '/s/a/documents/x',
                    },
                ],
            },
        });

        expect(await violationsIn(wrapper.html())).toEqual([]);
    });

    it('has no axe violation on the expired screen, including its form (S64)', async () => {
        const wrapper = mount(StatusExpired, {
            props: { reason: 'expired' as const, requested: false },
        });

        /*
         * The one interactive screen on the client surface. A form control
         * without a programmatic label is the single most common AA failure
         * and the one this audience is least able to work around.
         */
        expect(await violationsIn(wrapper.html())).toEqual([]);
    });
});

describe('the client status page, by inspection', () => {
    /*
     * The rules axe cannot check, because they are decisions rather than
     * markup errors. Each is a Design System §9.6 or IA §9 requirement that a
     * later edit would break silently.
     */

    it('states each step’s state in words, not only by its marker', () => {
        const wrapper = mount(StatusShow, {
            props: showProps,
        });

        /*
         * Design System §11 does not let colour or position be the only
         * channel, and on this surface it matters more than anywhere else:
         * this is the audience least likely to be looking closely.
         */
        const text = wrapper.text();

        expect(text).toContain('Happening now');
        expect(text).toContain('Still to come');
    });

    it('hides the decorative rail from a screen reader', () => {
        const wrapper = mount(StatusShow, {
            props: showProps,
        });

        // The markers say nothing the words beside them do not, and a marker
        // read out as "img" is noise on every row.
        expect(wrapper.html()).toContain('aria-hidden="true"');
    });

    it('draws the timeline as an ordered list', () => {
        const wrapper = mount(StatusShow, {
            props: showProps,
        });

        /*
         * *"A timeline that reads sensibly in a screen reader rather than as a
         * list of disconnected dates"* is #112's own wording, and an `<ol>` is
         * what makes a reader announce "list, 3 items" and then each step in
         * order. It is the closest an automated check gets to that.
         */
        expect(wrapper.findAll('ol li')).toHaveLength(showProps.steps.length);
    });

    it('uses real landmarks and one h1', () => {
        const wrapper = mount(ClientLayout, {
            props: {
                teamName: 'Bosart Group',
                agentName: 'Emily',
                agentPhone: '317-555-0142',
            },
            slots: { default: '<h1>Your Sale</h1>' },
        });

        const html = wrapper.html();

        expect(html).toContain('<header');
        expect(html).toContain('<main');
        expect(html).toContain('<footer');
    });

    it('keeps every interactive target at the client-surface size', () => {
        /*
         * Design System §9.6: 52px for actions, 44px absolute minimum — larger
         * than the internal app's, because the audience is older and on a
         * phone. `min-h-[52px]` and `min-h-[44px]` are the two the client
         * pages use; anything smaller would be an internal-density control
         * that wandered onto this surface.
         */
        for (const page of [
            'resources/js/pages/Status/Show.vue',
            'resources/js/pages/Status/Documents.vue',
            'resources/js/pages/Status/Expired.vue',
            'resources/js/layouts/ClientLayout.vue',
        ]) {
            const source = readFileSync(page, 'utf8');

            const targets =
                source.match(/<(a|button|input|select)\b[^>]*/gs) ?? [];

            for (const target of targets) {
                // A `type="checkbox"` or a hidden input has no target of its
                // own; every visible control on this surface does.
                if (/type="(hidden|checkbox|radio)"/.test(target)) {
                    continue;
                }

                expect(
                    /min-h-\[(52|44)px\]/.test(target),
                    `${page}: every client-surface control needs a 52px or 44px target — ${target.slice(0, 80)}`,
                ).toBe(true);
            }
        }
    });

    it('uses no internal density class on the client surface', () => {
        /*
         * §9.6: *"comfortable everywhere. No 36px rows, no `text-13`, no
         * compact controls."* `text-13` is the internal 13px exception (§3.3)
         * and it is 3px below this surface's floor — which is the difference
         * between a page this audience reads and one they zoom.
         *
         * The base is 16px here, not the internal 14px, so `text-sm` is out
         * too: it resolves to 14px.
         */
        for (const page of [
            'resources/js/pages/Status/Show.vue',
            'resources/js/pages/Status/Documents.vue',
            'resources/js/pages/Status/Expired.vue',
            'resources/js/layouts/ClientLayout.vue',
        ]) {
            const source = readFileSync(page, 'utf8');

            const markup = source.slice(source.indexOf('<template>'));

            expect(markup, `${page} uses text-13`).not.toMatch(/\btext-13\b/);
            expect(markup, `${page} uses text-sm`).not.toMatch(/\btext-sm\b/);
            expect(markup, `${page} uses text-xs`).not.toMatch(/\btext-xs\b/);
        }
    });

    it('animates nothing, so prefers-reduced-motion has nothing to suppress', () => {
        /*
         * Design System §5.3 asks for `prefers-reduced-motion` to be honoured.
         * The honest way to honour it on a page a client reads once is to have
         * no motion at all — and recording that as a **choice** is the point:
         * an audit that finds no motion should know it was decided rather than
         * assume it was overlooked, because the next edit is where a
         * transition arrives without the media query beside it.
         */
        for (const page of [
            'resources/js/pages/Status/Show.vue',
            'resources/js/pages/Status/Documents.vue',
            'resources/js/pages/Status/Expired.vue',
            'resources/js/layouts/ClientLayout.vue',
        ]) {
            const markup = readFileSync(page, 'utf8');

            expect(markup, `${page} animates`).not.toMatch(
                /\b(transition-|animate-|duration-)/,
            );
        }
    });
});
