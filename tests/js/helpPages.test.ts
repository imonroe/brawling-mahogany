/**
 * S92's two pages, and the `href` branch this PR added to `IconButton` (#170).
 *
 * All three shipped with no coverage at all, which review found rather than a
 * test: the manual's server side is guarded heavily — every link, every
 * vocabulary word, every navigation instruction — and the two components that
 * render it were held by nothing.
 *
 * The cases here are the decisions, not the markup: reading order, when the
 * contents list earns its place, and that a control which navigates is an
 * anchor rather than a button.
 */
import { CircleQuestionMark } from '@lucide/vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import IconButton from '@/components/app/IconButton.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: () => () => null }),
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { href: props.href }, slots.default?.()),
    }),
}));

const Show = (await import('@/pages/Help/Show.vue')).default;
const Index = (await import('@/pages/Help/Index.vue')).default;

function article(headingCount: number) {
    const headings = Array.from({ length: headingCount }, (_, index) => ({
        level: 2,
        text: `Heading ${index + 1}`,
        id: `heading-${index + 1}`,
    }));

    return {
        slug: 'tasks',
        title: 'Tasks',
        summary: 'What a task is.',
        section: 'deals',
        arrivesWith: null,
        html: headings
            .map((heading) => `<h2 id="${heading.id}">${heading.text}</h2>`)
            .join(''),
        headings,
    };
}

describe('the manual’s pages', () => {
    it('puts the contents list before the article it indexes', () => {
        /*
         * It is drawn to the *right* of the article, which is why the first
         * version emitted it second and let CSS place it. Reading order is
         * what a keyboard and a screen reader follow, so an index reached only
         * after the page it indexes is not an index. `lg:order` moves it back
         * visually.
         */
        const page = mount(Show, {
            props: { article: article(3), previous: null, next: null },
        });

        const cards = page.findAll('[data-slot="card"]');

        expect(cards).toHaveLength(2);
        expect(cards[0].text()).toContain('On this page');
        expect(cards[0].classes()).toContain('lg:order-2');
        expect(cards[1].classes()).toContain('lg:order-1');

        // And it sticks, because the longest article scrolls it out of reach
        // exactly when it starts being useful.
        expect(cards[0].classes()).toContain('lg:sticky');

        const links = page
            .findAll('a[href^="#"]')
            .map((a) => a.attributes('href'));

        expect(links).toEqual(['#heading-1', '#heading-2', '#heading-3']);
    });

    it('drops the contents list on a short article', () => {
        // Two headings is a list of two things next to the two things.
        const page = mount(Show, {
            props: { article: article(2), previous: null, next: null },
        });

        expect(page.text()).not.toContain('On this page');
        expect(page.findAll('[data-slot="card"]')).toHaveLength(1);
    });

    it('says up front when an article describes something unbuilt', () => {
        const page = mount(Show, {
            props: {
                article: { ...article(3), arrivesWith: 'A later release' },
                previous: null,
                next: null,
            },
        });

        expect(page.text()).toContain('Coming later');
        expect(page.text()).toContain('not built yet');
    });

    it('never truncates a summary on the index', () => {
        /*
         * Every summary is a whole sentence written to answer *"is this the
         * article I want"*, and `truncate` cuts it at one line with an
         * ellipsis — taking away the half that answers the question. Clamped
         * at two lines instead, so a long one still cannot push the rows
         * apart.
         */
        const page = mount(Index, {
            props: {
                sections: [
                    {
                        key: 'deals',
                        title: 'Running a deal',
                        blurb: 'The transaction itself.',
                        articles: [
                            {
                                slug: 'tasks',
                                title: 'Tasks',
                                summary:
                                    'A long summary that would be cut in half by a single-line truncation.',
                                arrivesWith: null,
                            },
                        ],
                    },
                ],
            },
        });

        const summary = page
            .findAll('span')
            .find((span) => span.text().startsWith('A long summary'));

        expect(summary).toBeDefined();
        expect(summary?.classes()).toContain('line-clamp-2');
        expect(summary?.classes()).not.toContain('truncate');
    });
});

describe('IconButton as a link', () => {
    it('renders an anchor when given an href', () => {
        // Middle-click, open in a new tab and "copy link" are all things
        // people do to a help icon, and a `button` does none of them.
        const link = mount(IconButton, {
            props: {
                icon: CircleQuestionMark,
                label: 'Help',
                href: '/help',
            },
        });

        expect(link.element.tagName).toBe('A');
        expect(link.attributes('href')).toBe('/help');
        expect(link.attributes('type')).toBeUndefined();
        expect(link.attributes('aria-label')).toBe('Help');

        // The responsive target is the same either way (§11).
        expect(link.classes()).toContain('size-11');
        expect(link.classes()).toContain('md:size-8');
    });

    it('stays a button when it does not navigate', () => {
        const button = mount(IconButton, {
            props: { icon: CircleQuestionMark, label: 'Help', unread: true },
        });

        expect(button.element.tagName).toBe('BUTTON');
        expect(button.attributes('type')).toBe('button');
        expect(button.attributes('href')).toBeUndefined();
    });
});
