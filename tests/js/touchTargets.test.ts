import { readFileSync } from 'node:fs';
import { Bell } from '@lucide/vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import IconButton from '@/components/app/IconButton.vue';

/**
 * Design System §11: "Touch targets — 44px minimum on mobile, without
 * exception." §4.3 is equally explicit that the 32px density is a desktop
 * affordance, so the two live together as a responsive pair rather than as a
 * compromise in the middle.
 */
describe('touch targets', () => {
    it('gives IconButton a 44px target that shrinks only at md', () => {
        const button = mount(IconButton, { props: { icon: Bell, label: 'Notifications' } });

        expect(button.classes()).toContain('size-11');
        expect(button.classes()).toContain('md:size-8');
    });

    it('names the control for a screen reader', () => {
        // An icon never carries meaning alone (§5.4).
        const button = mount(IconButton, { props: { icon: Bell, label: 'Notifications' } });

        expect(button.attributes('aria-label')).toBe('Notifications');
    });

    it('keeps the mobile tab bar above the minimum', () => {
        const source = readFileSync('resources/js/components/app/MobileTabBar.vue', 'utf8');

        expect(source).toContain('min-h-11');
    });
});
