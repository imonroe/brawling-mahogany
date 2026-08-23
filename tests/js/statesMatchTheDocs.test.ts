import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { STATES } from '@/lib/states';
import type { StateDomain, Tone } from '@/lib/states';

/**
 * The front-end state table cannot drift from the documents either.
 *
 * `tests/Unit/DocumentedVocabularyTest.php` binds the PHP enums to PRD §6.3
 * and IA §8. This is the other half: IA §8 owns the code values and the UI
 * labels, Design System §2.4 owns the tone each state carries, and both are
 * markdown in this repository — so both are read here and compared to
 * `lib/states.ts`.
 *
 * Editing a document without editing the code, or the reverse, fails the
 * build. That is the point.
 */

function doc(name: string): string {
    return readFileSync(resolve(process.cwd(), `docs/${name}.md`), 'utf8');
}

/** Code → UI label, from an Information Architecture §8 sub-section. */
function iaStates(heading: string): Record<string, string> {
    // Clip at the next top-level heading: the last sub-section would
    // otherwise run to the end of the document.
    const vocabulary = (
        doc('Information Architecture').split('## 8. State vocabulary')[1] ?? ''
    ).split('\n## ')[0];
    const section = (vocabulary.split(`### ${heading}`)[1] ?? '').split(
        '### ',
    )[0];

    expect(section, `IA §8 has no "${heading}" sub-section`).not.toBe('');

    const pairs: Record<string, string> = {};

    for (const line of section.split('\n')) {
        const trimmed = line.trim();

        if (trimmed.startsWith('|')) {
            const cells = trimmed
                .split('|')
                .slice(1, -1)
                .map((cell) => cell.trim());
            const code = cells[0]?.match(/^`([a-z_]+)`$/);

            if (code && cells[1]) {
                pairs[code[1]] = cells[1];
            }

            continue;
        }

        if (trimmed.includes('→')) {
            for (const pair of trimmed.split('·')) {
                const match = pair.match(/`([a-z_]+)`\s*→\s*([^(·]+)/);

                if (match) {
                    pairs[match[1]] = match[2].trim();
                }
            }
        }
    }

    return pairs;
}

/** The values column of a PRD §6.3 lookup row, split on commas. */
function prdLookupValues(lookup: string): string[] {
    const section = (
        doc('Product Requirements Document').split(
            '### 6.3 Lookup values',
        )[1] ?? ''
    ).split('\n---')[0];

    expect(section, 'PRD §6.3 Lookup values is missing').not.toBe('');

    for (const line of section.split('\n')) {
        if (!line.trim().startsWith('|')) {
            continue;
        }

        const cells = line
            .split('|')
            .slice(1, -1)
            .map((cell) => cell.trim());

        if (cells.length < 2) {
            continue;
        }

        if (
            cells[0].replace(/\*/g, '').trim().toLowerCase() !==
            lookup.toLowerCase()
        ) {
            continue;
        }

        return cells[1]
            .split(',')
            .map((value) => value.trim())
            .filter(Boolean);
    }

    throw new Error(`PRD §6.3 has no row for "${lookup}".`);
}

/** UI label → tone, from the Design System §2.4 table. */
function designSystemTones(entity: string): Record<string, Tone> {
    const table =
        doc('Design System')
            .split('### 2.4 State mapping')[1]
            ?.split('###')[0] ?? '';

    expect(table, 'Design System §2.4 is missing').not.toBe('');

    const tones: Record<string, Tone> = {};
    let current = '';

    for (const line of table.split('\n')) {
        if (!line.trim().startsWith('|')) {
            continue;
        }

        const cells = line
            .split('|')
            .slice(1, -1)
            .map((cell) => cell.trim());

        if (cells.length < 3 || cells[0].startsWith('---')) {
            continue;
        }

        // A row either names its entity or continues the one above it.
        const name = cells[0].replace(/\*/g, '').trim();

        if (name !== '') {
            current = name;
        }

        const tone = cells[2].replace(/`/g, '').trim();

        if (
            current.toLowerCase() === entity.toLowerCase() &&
            /^(neutral|info|success|warning|danger)$/.test(tone)
        ) {
            tones[cells[1]] = tone as Tone;
        }
    }

    return tones;
}

const DOMAINS: Array<{
    domain: StateDomain;
    ia: string;
    designSystem: string;
}> = [
    { domain: 'deal', ia: 'Deal', designSystem: 'Deal' },
    { domain: 'workflow', ia: 'Workflow', designSystem: 'Workflow' },
    { domain: 'stage', ia: 'Stage', designSystem: 'Stage' },
    { domain: 'task', ia: 'Task', designSystem: 'Task' },
    { domain: 'gate', ia: 'Gate', designSystem: 'Gate' },
    { domain: 'person', ia: 'Person lifecycle', designSystem: 'Person' },
    {
        domain: 'automation',
        ia: 'Automation / message',
        designSystem: 'Message',
    },
    {
        domain: 'extractedField',
        ia: 'Extracted field',
        designSystem: 'Extracted field',
    },
];

describe('the state table matches the documents', () => {
    it.each(DOMAINS)(
        'has IA §8’s codes and labels for $domain',
        ({ domain, ia }) => {
            const documented = iaStates(ia);
            const implemented = Object.fromEntries(
                Object.entries(STATES[domain]).map(([code, descriptor]) => [
                    code,
                    descriptor.label,
                ]),
            );

            expect(implemented).toEqual(documented);
        },
    );

    it.each(DOMAINS)(
        'has Design System §2.4’s tone for $domain',
        ({ domain, designSystem }) => {
            const documented = designSystemTones(designSystem);

            expect(
                Object.keys(documented).length,
                `no §2.4 rows for ${designSystem}`,
            ).toBeGreaterThan(0);

            for (const descriptor of Object.values(STATES[domain])) {
                expect(
                    documented[descriptor.label],
                    `${designSystem} · ${descriptor.label}`,
                ).toBe(descriptor.tone);
            }
        },
    );

    /**
     * Property status is the one domain whose values are a **lookup**, not a
     * state machine, so IA §8 has no sub-section for it and this reads PRD
     * §6.3 instead. `tests/Unit/DocumentedVocabularyTest.php` holds the PHP
     * enum against the same row; this holds the badge table against it, so the
     * two sides of the wire cannot drift from each other or from the document.
     */
    it('has PRD §6.3’s property status values, in order', () => {
        expect(
            Object.values(STATES.property).map(
                (descriptor) => descriptor.label,
            ),
        ).toEqual(prdLookupValues('Property status'));
    });

    it('has Design System §2.4’s tone for every property status', () => {
        const documented = designSystemTones('Property');

        expect(
            Object.keys(documented).length,
            'no §2.4 rows for Property',
        ).toBeGreaterThan(0);

        for (const descriptor of Object.values(STATES.property)) {
            expect(
                documented[descriptor.label],
                `Property · ${descriptor.label}`,
            ).toBe(descriptor.tone);
        }
    });

    it('carries the document state from Design System §2.4', () => {
        // The only document state with a badge is the refusal (PRD §4.6 F6.2).
        const documented = designSystemTones('Document');

        expect(
            Object.entries(STATES.document).map(([, d]) => [d.label, d.tone]),
        ).toEqual(Object.entries(documented));
    });
});
