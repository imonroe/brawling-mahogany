import { describe, expect, it } from 'vitest';
import {
    clientStageName,
    clientStateLabel,
    resolveState,
    STATES,
    stateLabel,
    stateTone,
} from '@/lib/states';
import type { StateDomain } from '@/lib/states';

describe('state vocabulary', () => {
    it('resolves a documented state', () => {
        expect(stateLabel('stage', 'blocked')).toBe('Blocked');
        // Blocked is amber, not red: it usually means a checkbox is unticked.
        expect(stateTone('stage', 'blocked')).toBe('warning');
    });

    it('throws on an unknown state rather than rendering it unstyled', () => {
        expect(() => resolveState('stage', 'in_progress')).toThrow(
            /Unknown stage state/,
        );
        expect(() => resolveState('nonsense' as StateDomain, 'active')).toThrow(
            /Unknown state domain/,
        );
    });

    it('gives every state a tone and a label', () => {
        for (const [domain, table] of Object.entries(STATES)) {
            for (const [code, descriptor] of Object.entries(table)) {
                expect(code, `${domain}.${code} is snake_case`).toMatch(
                    /^[a-z][a-z0-9_]*$/,
                );
                expect(descriptor.label).not.toBe('');
                expect([
                    'neutral',
                    'info',
                    'success',
                    'warning',
                    'danger',
                ]).toContain(descriptor.tone);
            }
        }
    });

    it('never shows a client the word blocked', () => {
        // IA §8: a client reads "Blocked" as "something has gone wrong".
        expect(clientStateLabel('stage', 'blocked')).toBe('In Progress');

        for (const [domain, table] of Object.entries(STATES)) {
            for (const [code, descriptor] of Object.entries(table)) {
                if (descriptor.clientLabel === null) {
                    continue;
                }

                expect(
                    descriptor.clientLabel.toLowerCase(),
                    `${domain}.${code} client label`,
                ).not.toMatch(
                    /blocked|failed|overdue|error|gate|override|workflow/,
                );
            }
        }
    });

    it('hides skipped stages from the client and never leaks the internal name', () => {
        expect(
            clientStageName({ state: 'skipped', milestone_label: 'Anything' }),
        ).toBeNull();
        expect(
            clientStageName({
                state: 'complete',
                milestone_label: 'Your home is on the market',
            }),
        ).toBe('Your home is on the market');
        // No milestone_label means nothing to say — not the internal name.
        expect(clientStageName({ state: 'complete' })).toBeNull();
    });
});
