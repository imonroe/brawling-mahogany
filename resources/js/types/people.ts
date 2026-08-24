/**
 * The People module's shapes (Screen Inventory S30–S33).
 *
 * A row is a **membership**, not a person: the shared `people` record carries
 * the human's name and contact details, and everything a team knows privately
 * about them — the lifecycle status, the notes, the vendor assessment — is on
 * the membership (PRD §6.2). `id` here is the membership's, which is what the
 * routes bind to.
 */
export type PersonRow = {
    id: string;
    firstName: string;
    lastName: string | null;
    email: string | null;
    phone: string | null;
    status: string;
    /**
     * Whether `status` describes this person at all (#162).
     *
     * `status` is a **client** lifecycle — Lead, Client, Past Client,
     * Archived — and a colleague has no honest value in it: their membership
     * holds `active` because something had to be written, and `active` reads
     * as *Client*. A screen draws the lifecycle badge only when this is false,
     * and the person's roles when it is true.
     *
     * Access **and not revoked**: revocation ends being a colleague, and what
     * is left is a person the team knows — which is what the lifecycle is for.
     * They keep `roles` until somebody tidies up, so `isRevoked` is what the
     * screen says beside them.
     */
    isColleague: boolean;
    /** What the team calls them, when they are on it. Empty for a contact. */
    roles: string[];
    isVendor: boolean;
    /** Most of this directory has none, and S31 says so rather than implying one. */
    hasLogin: boolean;
    isRevoked: boolean;
};

export type VendorFields = {
    specialties: string[];
    /** Integer cents, never dollars (ADR 0001). Pass it to `formatCurrency` as-is. */
    typicalCost: number | null;
    serviceArea: string | null;
    rating: number | null;
    notes: string | null;
};

export type PersonDetail = PersonRow & {
    notes: string | null;
    vendor: VendorFields;
    joinedAt: string | null;
    revokedAt: string | null;
};

/** Laravel's paginator, as it reaches a page. */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

/**
 * One entry on the timeline, as `App\Queries\ActivityFeed::rows()` shapes it
 * (IA §2 — **Activity**, never History, Log, or Audit).
 *
 * One type for the feed (S12) and the person record (S31), because both are
 * rendered from that one method. The screen decides which parts it shows — the
 * person page knows whose timeline it is and does not repeat the subject — and
 * the shape stays the same, so the two cannot drift into disagreeing about
 * whether a row carries the deal it was attached to.
 */
export type ActivityFeedRow = {
    id: string;
    eventType: string;
    summary: string;
    source: string;
    occurredAt: string;
    actorName: string | null;
    /** The thing it happened to, linked when a screen for it exists. */
    subject: { label: string; url: string | null } | null;
    /** The deal it belongs on, when it belongs on one (PRD F2.5). */
    deal: { label: string; url: string } | null;
    note: string | null;
    /** PRD §6.3 contact type, on a logged contact only. */
    contactType: string | null;
};

/** A deal the log-contact modal can attach an entry to (S26). */
export type LoggableDeal = {
    id: string;
    name: string;
};
