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
    /**
     * IA §8's lifecycle, or **null** where it does not apply (#162).
     *
     * Null is not "unknown": it is *this person has no place on the client
     * lifecycle*, which is what a colleague holds and what a former colleague
     * holds until the team says what they are now. A screen with nothing to
     * say about somebody says nothing.
     */
    status: string | null;
    /** Whether the role badges apply — team access, revoked or not (#162). */
    carriesAccess: boolean;
    /** `carriesAccess` and not revoked: whether the lifecycle is theirs to set. */
    isColleague: boolean;
    /** What the team calls them, when they are on it. Empty for a contact. */
    roles: string[];
    isVendor: boolean;
    /**
     * The three cells S34's rows draw, and null for everybody else (#83).
     *
     * `lastUsedAt` is **derived** from `deal_participants` and never stored —
     * F2.6 calls it the most useful column and the one most likely to be stale
     * if duplicated.
     */
    vendor: VendorSummary | null;
    /** Most of this directory has none, and S31 says so rather than implying one. */
    hasLogin: boolean;
    isRevoked: boolean;
};

/**
 * What a vendor row draws (S34 · #83): what they do, where, how they rated,
 * and when this team last engaged them.
 */
export type VendorSummary = {
    specialties: string[];
    rating: number | null;
    serviceArea: string | null;
    /** Derived from `deal_participants`, never stored — F2.6. */
    lastUsedAt: string | null;
};

/** The full record S31 edits — the row's summary, plus what only it shows. */
export type VendorFields = VendorSummary & {
    /** Integer cents, never dollars (ADR 0001). Pass it to `formatCurrency` as-is. */
    typicalCost: number | null;
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
