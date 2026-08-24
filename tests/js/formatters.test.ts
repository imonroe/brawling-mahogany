import { beforeEach, describe, expect, it } from 'vitest';
import {
    calendarDaysBetween,
    formatAddress,
    formatCount,
    formatCurrency,
    formatDate,
    formatDateForClient,
    formatDateTime,
    formatDealName,
    formatLocality,
    formatPersonName,
    formatRelativeDate,
    formatTime,
    personInitials,
    personSortKey,
    setTeamTimeZone,
} from '@/lib/formatters';

/** IA §10 fixes every rule below. These are those rules, as assertions. */
describe('formatters', () => {
    beforeEach(() => {
        setTeamTimeZone('America/Denver');
    });

    it('formats people', () => {
        const person = { firstName: 'Emily', lastName: 'Bosart' };

        expect(formatPersonName(person)).toBe('Emily Bosart');
        expect(personSortKey(person)).toBe('bosart, emily');
        expect(personInitials(person)).toBe('EB');
    });

    it('names a deal after its subject property, falling back to the surname', () => {
        expect(formatDealName({ streetAddress: '123 Main St' })).toBe(
            '123 Main St',
        );
        expect(
            formatDealName({
                clientSurname: 'Bosart',
                dealTypeLabel: 'Purchase',
            }),
        ).toBe('Bosart Purchase');
    });

    it('splits an address across two lines', () => {
        expect(
            formatAddress({
                street: '123 Main St',
                city: 'Denver',
                state: 'CO',
                postalCode: '80202',
            }),
        ).toEqual({ line1: '123 Main St', line2: 'Denver, CO 80202' });
    });

    /**
     * The DealHeader meta pair (§8.4). The deal is already named after its
     * subject property's street, so repeating the street — and adding a
     * postcode — is the header saying the same thing three times.
     */
    it('reduces an address to city and state for the deal header', () => {
        expect(
            formatLocality({
                street: '123 Main St',
                city: 'Denver',
                state: 'CO',
                postalCode: '80202',
            }),
        ).toBe('Denver, CO');

        // A property entered from a parcel number has neither, and the header
        // drops the pair rather than rendering a stray comma.
        expect(formatLocality({ street: '123 Main St' })).toBe('');
        expect(formatLocality({ city: 'Denver' })).toBe('Denver');
    });

    it('formats internal dates as weekday, short month, day', () => {
        expect(formatDate('2026-08-20T18:00:00Z')).toBe('Thu, Aug 20');
    });

    it('formats client dates in full, with the year only when it differs', () => {
        expect(
            formatDateForClient('2026-08-20T18:00:00Z', {
                now: '2026-01-04T00:00:00Z',
            }),
        ).toBe('Thursday, August 20');

        expect(
            formatDateForClient('2027-08-20T18:00:00Z', {
                now: '2026-01-04T00:00:00Z',
            }),
        ).toBe('Friday, August 20, 2027');
    });

    it('formats times in the team timezone with a lowercase meridiem', () => {
        // 20:30 UTC is 2:30pm in Denver — the team's timezone, not the server's.
        expect(formatTime('2026-08-20T20:30:00Z')).toBe('2:30pm');
        expect(formatTime('2026-08-20T20:30:00Z', { timeZone: 'UTC' })).toBe(
            '8:30pm',
        );
        expect(formatDateTime('2026-08-20T20:30:00Z')).toBe(
            'Thu, Aug 20 at 2:30pm',
        );
    });

    it('goes relative only inside seven days', () => {
        const now = '2026-08-20T18:00:00Z';

        expect(formatRelativeDate('2026-08-20T23:00:00Z', { now })).toBe(
            'today',
        );
        expect(formatRelativeDate('2026-08-21T18:00:00Z', { now })).toBe(
            'tomorrow',
        );
        expect(formatRelativeDate('2026-08-23T18:00:00Z', { now })).toBe(
            'in 3 days',
        );
        expect(formatRelativeDate('2026-08-27T18:00:00Z', { now })).toBe(
            'in 7 days',
        );
        expect(formatRelativeDate('2026-08-30T18:00:00Z', { now })).toBe(
            'Aug 30',
        );
        expect(formatRelativeDate('2026-08-19T18:00:00Z', { now })).toBe(
            'yesterday',
        );
        expect(formatRelativeDate('2026-08-15T18:00:00Z', { now })).toBe(
            '5 days ago',
        );
        expect(formatRelativeDate('2026-08-01T18:00:00Z', { now })).toBe(
            'Aug 1',
        );
    });

    it('counts calendar days in the team timezone, not in 24-hour blocks', () => {
        // 04:00 UTC on the 21st is still the evening of the 20th in Denver,
        // so it is the same calendar day — not "tomorrow".
        expect(
            calendarDaysBetween('2026-08-20T23:00:00Z', '2026-08-21T04:00:00Z'),
        ).toBe(0);
        expect(
            calendarDaysBetween('2026-08-20T23:00:00Z', '2026-08-21T13:00:00Z'),
        ).toBe(1);
    });

    it('shows whole dollars above a thousand and cents below it', () => {
        expect(formatCurrency(48_500_000)).toBe('$485,000');
        expect(formatCurrency(25_050)).toBe('$250.50');
        expect(formatCurrency(25_050, { showCents: false })).toBe('$251');
    });

    it('pluralises counts', () => {
        expect(formatCount(3, 'deal')).toBe('3 deals');
        expect(formatCount(1, 'task')).toBe('1 task');
        expect(formatCount(2, 'property', 'properties')).toBe('2 properties');
    });
});
