/**
 * The Properties module's shapes (Screen Inventory S35–S37).
 *
 * `address` is **parts**, never a formatted string. IA §10 fixes the rule —
 * street on line one, City, ST ZIP on line two — and `formatAddress()` owns
 * it; a server that sent one string would put the rule in ninety-one places
 * (docs/Frontend conventions.md §3).
 */
import type { AddressParts } from '@/lib/formatters';

export type PropertyRow = {
    id: string;
    /** Street and unit, falling back to the parcel number. */
    name: string;
    address: AddressParts;
    type: string;
    typeLabel: string;
    /** A PRD §6.3 market status — pass it to `StatusBadge` as `property`. */
    status: string;
    beds: number | null;
    /** A decimal string, because 2.5 baths is real and a float is not money-safe. */
    baths: string | null;
    sqft: number | null;
    dealCount: number;
};

export type PropertyDetail = PropertyRow & {
    parcelNumber: string | null;
    yearBuilt: number | null;
    notes: string | null;
    statusLabel: string;
    hasAddress: boolean;
};

/**
 * A link out (PRD §7.13).
 *
 * Label and URL, and deliberately nothing from the other end of it: PRD §10
 * permits the link and never the listing content behind it.
 */
export type ExternalLinkRow = {
    id: string | null;
    label: string;
    url: string;
};

/** One deal this property is on (S36's "linked deals"). */
export type LinkedDeal = {
    /** The link row's id — what the remove route binds to. */
    id: string;
    dealId: string;
    name: string;
    state: string;
    sideLabel: string;
    isSubject: boolean;
};
