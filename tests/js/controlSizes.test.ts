import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import AppSelect from '@/components/app/AppSelect.vue';
import AppTextarea from '@/components/app/AppTextarea.vue';

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

    it('gives the filter control §4.2’s 12px, and 16px on a phone', () => {
        // This size had no test at all, which is how it came to render 14px
        // above `md` — a base-string `md:text-sm` is a different
        // tailwind-merge group from a size's `text-xs`, so both survived.
        const input = mount(AppInput, { props: { size: 'filter' } });

        expect(input.classes()).toContain('md:h-8');
        // §8.6's FilterBar is a desktop surface: 12px is the size that counts.
        expect(input.classes()).toContain('md:text-xs');
        expect(input.classes()).not.toContain('md:text-sm');
        // 16px on a phone, or iOS Safari zooms — a filter in a Sheet (§6.1)
        // is still typed into.
        expect(input.classes()).toContain('text-base');
        // §11's minimum has no exceptions, filters included.
        expect(input.classes()).toContain('min-h-11');
    });

    it('gives AppTextarea §10’s padding, height and type size', () => {
        /*
         * §10, in full: "Textarea: `rounded-md border p-[11px]` at 13–14px,
         * roughly 86px tall for a reason field." The reason field it names is
         * S24's, which is the first form in the product to need one — and the
         * padding is `p-[11px]` rather than the input's `px-3` because prose
         * does not centre itself vertically the way one line does.
         */
        const textarea = mount(AppTextarea);

        expect(textarea.classes()).toContain('p-[11px]');
        expect(textarea.classes()).toContain('min-h-[86px]');
        expect(textarea.classes()).toContain('rounded-md');
        // 16px on a phone or iOS Safari zooms on focus; 14px above `md`.
        // Never text-13, which §3.3 reserves for rows (§4.2).
        expect(textarea.classes()).toContain('text-base');
        expect(textarea.classes()).toContain('md:text-sm');
        expect(textarea.classes()).not.toContain('text-13');
    });

    it('gives AppSelect the same measured sizes as AppInput', () => {
        /*
         * The third sanctioned control, and the reason it exists at all: a
         * hand-written 32px `<select>` had been transcribed into four screens
         * and every one of them was under §11's 44px floor on a phone. Pinning
         * it here is what stops the variant drifting away from `AppInput`,
         * which is the failure the filter-size case above records.
         */
        const select = mount(AppSelect, {
            props: { modelValue: null, options: { a: 'A' } },
        });

        expect(select.classes()).toContain('min-h-11');
        expect(select.classes()).toContain('md:h-8');
        expect(select.classes()).toContain('md:text-xs');
    });

    it('maps AppSelect’s empty option to null, and back', () => {
        /*
         * The only behaviour this component has that is not a class string,
         * and the one its docblock argues for at length: `''` in the DOM is
         * how a native select says *unanswered*, and null is what that means
         * to the server. S20 depends on the distinction — "nobody has said" is
         * a different fact from "Interested".
         */
        const select = mount(AppSelect, {
            props: {
                modelValue: null,
                options: { interested: 'Interested' },
                placeholder: 'Not said',
            },
        });

        /*
         * The placeholder option itself, not the rendered value.
         *
         * `expect(element.value).toBe('')` for a null model cannot fail —
         * Vue coerces a null `:value` to `''` and a select with no matching
         * option reports `''` anyway, so it passed whatever the component
         * did. What can fail is whether the empty option is rendered at all,
         * which is the `v-if` the null mapping depends on.
         */
        expect(select.findAll('option')[0].attributes('value')).toBe('');
        expect(select.findAll('option')).toHaveLength(2);

        /*
         * `find('select')`, not the component wrapper.
         * `VueWrapper.setValue()` emits `update:modelValue` itself and never
         * reaches the DOM handler — so it would report back whatever it was
         * handed and pass no matter what the component does. Going through the
         * element fires the real `change`.
         */
        const element = select.find('select');

        element.setValue('interested');
        expect(select.emitted('update:modelValue')?.[0]).toEqual([
            'interested',
        ]);

        element.setValue('');
        expect(select.emitted('update:modelValue')?.[1]).toEqual([null]);
    });

    it('keeps a disabled ghost’s hover tone whole, not half', () => {
        // §13.2 rule 9: background and foreground move together. The disabled
        // variant overrides `hover:bg-*`; without the `hover:text-*` sibling a
        // disabled ghost would still lighten its label on hover.
        const button = mount(AppButton, {
            props: { variant: 'ghost', disabled: true },
        });

        expect(button.classes()).toContain('hover:text-muted-foreground');
        expect(button.classes()).not.toContain('hover:text-accent-foreground');
    });

    it('keeps an icon from becoming the click’s target', () => {
        // A leading icon inside a button is `event.target` without this, which
        // breaks any handler reading it. shadcn's base carries it; ours has to.
        expect(mount(AppButton).classes()).toContain(
            '[&_svg]:pointer-events-none',
        );
    });

    it('gives every button size §7.2’s 16px leading icon', () => {
        // "All share rounded-md gap-1.5 and a 16px leading icon" — every size,
        // including compact, which was 14px until this test existed.
        for (const size of ['default', 'ghost', 'compact'] as const) {
            expect(
                mount(AppButton, { props: { size } }).classes(),
                size,
            ).toContain('[&_svg]:size-4');
        }
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
