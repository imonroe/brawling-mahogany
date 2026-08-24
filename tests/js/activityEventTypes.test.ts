import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
    ACTIVITY_FALLBACK,
    activityDescriptor,
    mappedActivityEventTypes,
    mappedContactTypes,
} from '@/lib/activity';

/**
 * `lib/activity.ts` cannot silently stop covering the product (issue #81).
 *
 * Unlike `resolveState`, this table does not throw on a miss — Design System
 * §7.3 *specifies* the fallback ("everything else `state-neutral`"), so an
 * unmapped event type renders correctly rather than wrongly. Which means
 * nothing at runtime ever says an event type was forgotten: it simply gets a
 * generic icon forever.
 *
 * So the PHP source is read for the event types the application writes, the
 * same trick `tokenDiscipline` uses on the token layer.
 */

function phpFiles(directory: string): string[] {
    const found: string[] = [];

    for (const entry of readdirSync(directory)) {
        const path = join(directory, entry);

        if (statSync(path).isDirectory()) {
            found.push(...phpFiles(path));
            continue;
        }

        if (path.endsWith('.php')) {
            found.push(path);
        }
    }

    return found;
}

function recordedEventTypes(): string[] {
    const types = new Set<string>();
    const pattern = /eventType:\s*'([a-z_]+\.[a-z_]+)'/g;

    for (const file of phpFiles(resolve('app'))) {
        const source = readFileSync(file, 'utf8');

        for (const match of source.matchAll(pattern)) {
            types.add(match[1]);
        }
    }

    return [...types].sort();
}

/** PRD §6.3's contact types, read out of the enum that owns them. */
function documentedContactTypes(): string[] {
    const source = readFileSync(resolve('app/Enums/ContactType.php'), 'utf8');

    return [...source.matchAll(/case\s+\w+\s*=\s*'([a-z_]+)';/g)].map(
        (match) => match[1],
    );
}

describe('activity event types', () => {
    it('finds the event types the application writes', () => {
        // The guard on the guard. A regex that stopped matching would make the
        // assertion below pass over an empty list — which is exactly the
        // failure mode this whole file exists to prevent one layer up.
        const types = recordedEventTypes();

        expect(types).toContain('contact.logged');
        expect(types).toContain('stage.advanced');
        expect(types.length).toBeGreaterThan(10);
    });

    it('gives every one of them an icon rather than the fallback', () => {
        const mapped = new Set(mappedActivityEventTypes());
        const missing = recordedEventTypes().filter(
            (type) => !mapped.has(type),
        );

        expect(missing).toEqual([]);
    });

    it('falls back rather than throwing on an event type it has never seen', () => {
        // Design System §7.3's own rule: "everything else state-neutral". A
        // throw here would take the whole feed down over one unmapped row.
        expect(activityDescriptor({ eventType: 'invented.thing' })).toEqual(
            ACTIVITY_FALLBACK,
        );
    });

    it('tints only what Design System §7.3 names', () => {
        // "completion state-success, message sent state-info, override
        // state-warning, everything else state-neutral." Nothing here may
        // invent a sixth rule.
        expect(activityDescriptor({ eventType: 'stage.advanced' }).tone).toBe(
            'success',
        );
        expect(
            activityDescriptor({ eventType: 'workflow.completed' }).tone,
        ).toBe('success');
        expect(activityDescriptor({ eventType: 'person.added' }).tone).toBe(
            'neutral',
        );
        /*
         * The override, which this test quoted the rule for and then did not
         * assert. It is the one tone §7.3 names that is not a completion or a
         * message, and the one an override most needs: `gate.overridden`
         * dropping to `neutral` left a waived gate reading like any other
         * timeline row, on the feed where "somebody decided to proceed anyway"
         * is the entry a reader is scanning for.
         */
        expect(activityDescriptor({ eventType: 'gate.overridden' }).tone).toBe(
            'warning',
        );
    });

    it('gives every PRD §6.3 contact type its own glyph', () => {
        const documented = documentedContactTypes();

        // The guard on the guard again: a regex that stopped matching would
        // make the comparison below trivially true.
        expect(documented).toContain('phone_call');
        expect(documented).toContain('showing');

        // A type with no icon falls back to a generic one, silently — and the
        // whole reason the tile carries an icon is that the label is not what
        // a thumb aims at.
        expect(
            documented.filter((type) => !mappedContactTypes().includes(type)),
        ).toEqual([]);
    });

    it('picks the icon from the contact type on a logged contact', () => {
        // A phone and an envelope are legible at a glance in a way "Phone
        // call" and "Email" at 14px are not.
        const phone = activityDescriptor({
            eventType: 'contact.logged',
            contactType: 'phone_call',
        });
        const email = activityDescriptor({
            eventType: 'contact.logged',
            contactType: 'email',
        });

        expect(phone.icon).not.toBe(email.icon);

        // And the contact type never leaks onto an event that is not one.
        expect(
            activityDescriptor({
                eventType: 'person.added',
                contactType: 'phone_call',
            }).icon,
        ).toBe(activityDescriptor({ eventType: 'person.added' }).icon);
    });
});
