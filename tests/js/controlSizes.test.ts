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

    it('gives the form control §4.2’s 40px height', () => {
        const input = mount(AppInput);

        expect(input.classes()).toContain('h-10');
        expect(input.classes()).toContain('px-3');
        // Never text-13: that is for rows, not for anything typed into (§3.3).
        expect(input.classes()).toContain('text-sm');
        expect(input.classes()).not.toContain('text-13');
    });

    it('renders as a link when given an href, so navigation matches actions', () => {
        const button = mount(AppButton, {
            props: { href: '/deals' },
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });

        expect(button.html()).toContain('<a');
    });
});
