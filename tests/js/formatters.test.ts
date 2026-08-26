import { beforeEach, describe, expect, it } from 'vitest';
import {
    calendarDaysBetween,
    formatAddress,
    formatCount,
    formatCurrency,
    formatDate,
    formatDateForClient,
    formatDateShort,
    formatDateTime,
    formatDealName,
    formatFileSize,
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

    it('draws a day as that day, in any zone (issue 165)', () => {
        /*
         * A `date` column carries a **day**, and `new Date('2026-08-25')` reads
         * a bare date string as UTC midnight — which in Denver is the evening
         * of the 24th. So a task due the 25th drew as *"Aug 24"*, and
         * `DateChip` gave it the danger tone a day early, while the badge
         * beside it (fixed in #164, and asking the team's calendar) still said
         * Open. The two halves of this fix are the wire sending `2026-08-25`
         * and this reading it as a day rather than an instant.
         */
        expect(formatDate('2026-08-25')).toBe('Tue, Aug 25');
        expect(formatDateShort('2026-08-25')).toBe('Aug 25');

        // Every zone, because a day has none. Tokyo is the other side of the
        // same mistake: +9 would land a UTC-midnight instant on the 25th and
        // an evening instant on the 26th.
        setTeamTimeZone('Asia/Tokyo');
        expect(formatDate('2026-08-25')).toBe('Tue, Aug 25');
        setTeamTimeZone('Pacific/Honolulu');
        expect(formatDate('2026-08-25')).toBe('Tue, Aug 25');

        // The control: a real instant still reads in the team's zone, because
        // it has one. 01:00 UTC on the 25th is the evening of the 24th here.
        setTeamTimeZone('America/Denver');
        expect(formatDate('2026-08-25T01:00:00Z')).toBe('Mon, Aug 24');
    });

    it('counts a day as today when it is today for the team', () => {
        // 01:00 UTC on the 25th is still the 24th in Denver, so a task due the
        // 24th is due *today* — the relative chip and the state badge have to
        // agree about which day it is.
        const now = '2026-08-25T01:00:00Z';

        expect(formatRelativeDate('2026-08-24', { now })).toBe('today');
        expect(formatRelativeDate('2026-08-25', { now })).toBe('tomorrow');
        expect(formatRelativeDate('2026-08-23', { now })).toBe('yesterday');
        expect(calendarDaysBetween(now, '2026-08-27')).toBe(3);
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

describe('formatFileSize', () => {
    it('counts bytes as bytes below a kilobyte', () => {
        expect(formatFileSize(0)).toBe('0 bytes');
        expect(formatFileSize(1)).toBe('1 byte');
        expect(formatFileSize(840)).toBe('840 bytes');
    });

    it('uses 1024, because that is what every operating system shows', () => {
        // Not 1 KB at 1000, which is what a disk manufacturer would say.
        expect(formatFileSize(1000)).toBe('1,000 bytes');
        expect(formatFileSize(1024)).toBe('1 KB');
    });

    it('drops the decimal below a megabyte and keeps one above', () => {
        // "17.0 KB" is noise; "1.4 MB" is a size somebody is deciding about.
        expect(formatFileSize(17 * 1024)).toBe('17 KB');
        expect(formatFileSize(Math.round(1.4 * 1024 * 1024))).toBe('1.4 MB');
        expect(formatFileSize(15 * 1024 * 1024)).toBe('15.0 MB');
    });

    it('says nothing rather than guessing at a size it cannot read', () => {
        expect(formatFileSize(-1)).toBe('—');
        expect(formatFileSize(Number.NaN)).toBe('—');
    });
});
