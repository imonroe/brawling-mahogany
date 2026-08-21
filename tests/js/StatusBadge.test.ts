import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import StatusBadge from '@/components/app/StatusBadge.vue';

describe('StatusBadge', () => {
    it('renders the label and the dot for a state', () => {
        const badge = mount(StatusBadge, { props: { domain: 'stage', state: 'blocked' } });

        expect(badge.text()).toBe('Blocked');
        // A tone is three properties: container, dot, and label move together.
        expect(badge.classes()).toContain('bg-state-warning-bg');
        expect(badge.classes()).toContain('text-state-warning');
        expect(badge.find('span.rounded-full.bg-state-warning').exists()).toBe(true);
    });

    it('drops the dot for a count or a terminal status', () => {
        const badge = mount(StatusBadge, {
            props: { tone: 'warning', dotless: true, label: '4' },
        });

        expect(badge.text()).toBe('4');
        expect(badge.find('span span').exists()).toBe(false);
    });

    it('throws on an unknown state rather than rendering an unstyled badge', () => {
        expect(() =>
            mount(StatusBadge, { props: { domain: 'stage', state: 'in_progress' } }),
        ).toThrow(/Unknown stage state/);
    });

    it('refuses to render with neither a state nor a tone', () => {
        expect(() => mount(StatusBadge, { props: {} })).toThrow(/needs either/);
    });
});
