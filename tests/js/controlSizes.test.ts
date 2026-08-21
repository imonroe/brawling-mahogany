import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';

/**
 * Design System §4.2 measures every control in the built designs, and shadcn's
 * generated components ship different numbers. These are the project's, and
 * they are pinned here so the next screen inherits the spec rather than the
 * upstream default.
 */
describe('control sizes', () => {
    it('gives the primary button §7.2’s height, padding, and weight', () => {
        const button = mount(AppButton, {
            slots: { default: 'Advance Stage' },
        });

        expect(button.classes()).toContain('md:h-9');
        expect(button.classes()).toContain('px-3.5');
        // 600, not 500: §7.2 is explicit, and a table full of 500 reads flat.
        expect(button.classes()).toContain('font-semibold');
        // 44px on a phone (§11), the measured height on a pointer device.
        expect(button.classes()).toContain('min-h-11');
    });

    it('gives the ghost and compact sizes their measured padding', () => {
        expect(
            mount(AppButton, {
                props: { variant: 'ghost', size: 'ghost' },
            }).classes(),
        ).toEqual(expect.arrayContaining(['md:h-8', 'px-2.5', 'font-medium']));

        // The compact size shadcn has no equivalent for — card headers and
        // dialog footers need it (§7.2, §8.9).
        expect(
            mount(AppButton, { props: { size: 'compact' } }).classes(),
        ).toEqual(expect.arrayContaining(['md:h-7', 'px-2.5', 'text-xs']));
    });

    it('mutes a disabled primary rather than fading it', () => {
        // §7.2: the blocked Advance button in S23 is bg-muted, not an opacity.
        const button = mount(AppButton, { props: { disabled: true } });

        expect(button.classes()).toContain('bg-muted');
        expect(button.classes()).not.toContain('bg-primary');
    });

    it('gives the form control §4.2’s 40px height, and 44px on a phone', () => {
        const input = mount(AppInput);

        expect(input.classes()).toContain('md:h-10');
        expect(input.classes()).toContain('px-3');
        // §11's minimum has no exceptions, and a field is a touch target too.
        expect(input.classes()).toContain('min-h-11');
        // Never text-13: that is for rows, not for anything typed into (§3.3).
        expect(input.classes()).toContain('md:text-sm');
        expect(input.classes()).not.toContain('text-13');
        // 16px on a phone, or iOS Safari zooms the page when the field takes
        // focus. The shadcn input this replaces carries the same pair.
        expect(input.classes()).toContain('text-base');
    });

    it('keeps the compact weight at 600 whatever the fill', () => {
        // §7.2 specifies compact as 12/600, and a secondary or ghost fill
        // supplies font-medium — so the size has to win.
        for (const variant of ['primary', 'secondary', 'ghost'] as const) {
            const button = mount(AppButton, {
                props: { variant, size: 'compact' },
            });

            expect(button.classes(), variant).toContain('font-semibold');
            expect(button.classes(), variant).not.toContain('font-medium');
        }
    });

    it('sizes a ghost button as a ghost button without being told twice', () => {
        // §7.2's ghost is 32px. `variant="ghost"` alone used to render a
        // ghost-coloured primary at 36px.
        const button = mount(AppButton, { props: { variant: 'ghost' } });

        expect(button.classes()).toContain('md:h-8');
        expect(button.classes()).toContain('px-2.5');
    });

    it('never renders a disabled link, which would still navigate', () => {
        // `disabled:pointer-events-none` does not match an anchor, and
        // aria-disabled does not stop a click. A disabled control is a button.
        const button = mount(AppButton, {
            props: { href: '/deals', disabled: true },
            global: {
                stubs: { Link: { template: '<a href="/deals"><slot /></a>' } },
            },
        });

        expect(button.element.tagName).toBe('BUTTON');
        expect(button.attributes('disabled')).toBeDefined();
    });

    it('renders as a link when given an href, so navigation matches actions', () => {
        // The stub takes `href` so the assertion is that the prop *arrives* at
        // the Link. Asserting on the rendered `<a>` alone would pass even if
        // the href were dropped, since a bare anchor is still an anchor.
        const button = mount(AppButton, {
            props: { href: '/deals' },
            global: {
                stubs: {
                    Link: {
                        props: ['href'],
                        template: '<a :href="href"><slot /></a>',
                    },
                },
            },
        });

        expect(button.element.tagName).toBe('A');
        expect(button.attributes('href')).toBe('/deals');
    });
});
