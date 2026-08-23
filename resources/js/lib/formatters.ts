/**
 * Formatting, built once.
 *
 * Information Architecture §10 specifies every rule below exactly. If each
 * screen formats its own dates, ninety-one screens will disagree within a
 * month — so nothing in `components/` or `pages/` formats anything itself.
 *
 * Dates and times are formatted with `Intl.DateTimeFormat`, which is the only
 * formatter here that handles a named IANA timezone without a second library.
 * Storage is UTC; display is the team's timezone (PRD §9, Localisation).
 */

const DEFAULT_TIME_ZONE = 'UTC';

let teamTimeZone = DEFAULT_TIME_ZONE;

/** Set once at boot from the authenticated team's timezone. */
export function setTeamTimeZone(timeZone: string): void {
    teamTimeZone = timeZone;
}

export function getTeamTimeZone(): string {
    return teamTimeZone;
}

export interface FormatDateOptions {
    /** Overrides the team timezone. Tests and client emails need this. */
    timeZone?: string;
}

type DateInput = Date | string | number;

function toDate(value: DateInput): Date {
    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        throw new Error(`Invalid date: ${String(value)}`);
    }

    return date;
}

function zone(options?: FormatDateOptions): string {
    return options?.timeZone ?? teamTimeZone;
}

/* -------------------------------------------------------------------------
 * People, deals, addresses
 * ---------------------------------------------------------------------- */

export interface NameParts {
    firstName?: string | null;
    lastName?: string | null;
}

/** Display form: First Last. */
export function formatPersonName(person: NameParts): string {
    return [person.firstName, person.lastName].filter(Boolean).join(' ').trim();
}

/** Sort form: last name first, so a people list sorts the way a phone book does. */
export function personSortKey(person: NameParts): string {
    return [person.lastName, person.firstName]
        .filter(Boolean)
        .join(', ')
        .trim()
        .toLocaleLowerCase();
}

/** Initials for an avatar. Two letters at most. */
export function personInitials(person: NameParts): string {
    return [person.firstName, person.lastName]
        .filter((part): part is string => Boolean(part))
        .map((part) => part.trim().charAt(0).toLocaleUpperCase())
        .join('')
        .slice(0, 2);
}

export interface DealNameParts {
    /** The subject property's street address, when the deal has one. */
    streetAddress?: string | null;
    /** The primary client's surname, used when there is no subject property yet. */
    clientSurname?: string | null;
    /** The deal type's label — "Purchase", "Sale" — used with the surname. */
    dealTypeLabel?: string | null;
}

/**
 * Subject property street address, falling back to the client surname:
 * "123 Main St", then "Bosart Purchase".
 */
export function formatDealName(deal: DealNameParts): string {
    if (deal.streetAddress) {
        return deal.streetAddress;
    }

    const surname = deal.clientSurname?.trim();

    if (!surname) {
        return 'Untitled deal';
    }

    return [surname, deal.dealTypeLabel?.trim()].filter(Boolean).join(' ');
}

export interface AddressParts {
    street?: string | null;
    unit?: string | null;
    city?: string | null;
    state?: string | null;
    postalCode?: string | null;
}

/** Street on line one, "City, ST ZIP" on line two. */
export function formatAddress(address: AddressParts): {
    line1: string;
    line2: string;
} {
    const line1 = [address.street, address.unit]
        .filter(Boolean)
        .join(' ')
        .trim();

    const cityState = [address.city, address.state].filter(Boolean).join(', ');
    const line2 = [cityState, address.postalCode]
        .filter(Boolean)
        .join(' ')
        .trim();

    return { line1, line2 };
}

/** One string, for places that cannot carry two lines. */
export function formatAddressOneLine(address: AddressParts): string {
    const { line1, line2 } = formatAddress(address);

    return [line1, line2].filter(Boolean).join(', ');
}

/* -------------------------------------------------------------------------
 * Dates and times
 * ---------------------------------------------------------------------- */

/** Internal: "Thu, Aug 20". */
export function formatDate(
    value: DateInput,
    options?: FormatDateOptions,
): string {
    return new Intl.DateTimeFormat('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        timeZone: zone(options),
    }).format(toDate(value));
}

/** Internal, without the weekday: "Aug 20". */
export function formatDateShort(
    value: DateInput,
    options?: FormatDateOptions,
): string {
    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        timeZone: zone(options),
    }).format(toDate(value));
}

export interface ClientDateOptions extends FormatDateOptions {
    /** The date the reader is reading on. Defaults to now. */
    now?: DateInput;
}

/**
 * Client-facing: "Thursday, August 20", with the year only when it differs
 * from the year the client is reading in.
 */
export function formatDateForClient(
    value: DateInput,
    options?: ClientDateOptions,
): string {
    const date = toDate(value);
    const timeZone = zone(options);
    const reference = options?.now ? toDate(options.now) : new Date();

    const sameYear = yearIn(date, timeZone) === yearIn(reference, timeZone);

    return new Intl.DateTimeFormat('en-US', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: sameYear ? undefined : 'numeric',
        timeZone,
    }).format(date);
}

/** 12-hour, lowercase meridiem, team timezone: "2:30pm". */
export function formatTime(
    value: DateInput,
    options?: FormatDateOptions,
): string {
    const formatted = new Intl.DateTimeFormat('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
        timeZone: zone(options),
    }).format(toDate(value));

    return formatted.replace(
        /\s?([AP])M$/i,
        (_match, meridiem: string) => meridiem.toLowerCase() + 'm',
    );
}

/** "Thu, Aug 20 at 2:30pm". */
export function formatDateTime(
    value: DateInput,
    options?: FormatDateOptions,
): string {
    return `${formatDate(value, options)} at ${formatTime(value, options)}`;
}

/**
 * Relative only within seven days, then absolute (Information Architecture
 * §10): "today", "in 3 days", "5 days ago", then "Aug 30".
 */
export function formatRelativeDate(
    value: DateInput,
    options?: ClientDateOptions,
): string {
    const timeZone = zone(options);
    const target = toDate(value);
    const reference = options?.now ? toDate(options.now) : new Date();

    const days = calendarDaysBetween(reference, target, timeZone);

    if (days === 0) {
        return 'today';
    }

    if (days === 1) {
        return 'tomorrow';
    }

    if (days === -1) {
        return 'yesterday';
    }

    if (days > 1 && days <= 7) {
        return `in ${days} days`;
    }

    if (days < -1 && days >= -7) {
        return `${Math.abs(days)} days ago`;
    }

    return formatDateShort(target, { timeZone });
}

/**
 * Whole calendar days between two instants, evaluated in a given timezone,
 * so "tomorrow" means the next date on the wall calendar rather than 24
 * hours from now.
 */
export function calendarDaysBetween(
    from: DateInput,
    to: DateInput,
    timeZone?: string,
): number {
    const tz = timeZone ?? teamTimeZone;
    const fromDay = Date.parse(`${isoDateIn(toDate(from), tz)}T00:00:00Z`);
    const toDay = Date.parse(`${isoDateIn(toDate(to), tz)}T00:00:00Z`);

    return Math.round((toDay - fromDay) / 86_400_000);
}

/** The calendar date, in a timezone, as "YYYY-MM-DD". */
export function isoDateIn(value: DateInput, timeZone?: string): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        timeZone: timeZone ?? teamTimeZone,
    }).format(toDate(value));

    // en-CA gives YYYY-MM-DD.
    return parts;
}

function yearIn(value: Date, timeZone: string): string {
    return isoDateIn(value, timeZone).slice(0, 4);
}

/* -------------------------------------------------------------------------
 * Money and counts
 * ---------------------------------------------------------------------- */

/**
 * Money is stored as integer cents (see docs/adr/0001) and displayed in whole
 * dollars above $1,000: "$485,000". Below that, cents still matter — a $250.50
 * receipt is not "$251".
 */
export function formatCurrency(
    cents: number,
    options?: { showCents?: boolean },
): string {
    const dollars = cents / 100;
    const showCents = options?.showCents ?? Math.abs(cents) < 100_000;

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: showCents ? 2 : 0,
        maximumFractionDigits: showCents ? 2 : 0,
    }).format(dollars);
}

/** Numeral plus noun, pluralised: "3 deals", "1 task". */
export function formatCount(
    count: number,
    singular: string,
    plural?: string,
): string {
    const noun = count === 1 ? singular : (plural ?? `${singular}s`);

    return `${new Intl.NumberFormat('en-US').format(count)} ${noun}`;
}

export interface PropertyFacts {
    beds?: number | null;
    /** A decimal string from the server — 2.5 baths is real, and money-safe. */
    baths?: string | null;
    sqft?: number | null;
    yearBuilt?: number | null;
}

/**
 * "3 bd · 2.5 ba · 1,840 sqft · built 1962", with whichever parts are known.
 *
 * Here rather than in each screen. Three of them want this line already (S35's
 * grid, S35's list, S36's header), and the two that had it inline disagreed
 * within one file — `sqft` went through `formatNumber` and `baths` did not,
 * which made "drop the trailing zero on 2.50" a decision taken in a component.
 * Frontend conventions §3: nothing formats a number itself.
 */
export function formatPropertyFacts(property: PropertyFacts): string {
    return [
        property.beds === null || property.beds === undefined
            ? null
            : `${formatNumber(property.beds)} bd`,
        property.baths === null || property.baths === undefined
            ? null
            : `${formatNumber(Number(property.baths))} ba`,
        property.sqft === null || property.sqft === undefined
            ? null
            : `${formatNumber(property.sqft)} sqft`,
        property.yearBuilt === null || property.yearBuilt === undefined
            ? null
            : `built ${property.yearBuilt}`,
    ]
        .filter(Boolean)
        .join(' · ');
}

/** Plain integer with thousands separators: "2,431". */
export function formatNumber(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}
