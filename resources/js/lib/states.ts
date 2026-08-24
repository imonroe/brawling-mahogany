/**
 * The state vocabulary, in one place.
 *
 * Information Architecture §8 fixes the code values (snake_case) and the UI
 * labels (Title Case). Design System §2.4 fixes the tone each state carries.
 * Both tables are transcribed here so a component never has to decide either.
 *
 * Adding a state means adding it to Design System §2.4 first, then here
 * (Design System §13.2 rule 7).
 */

export type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

export type StateDomain =
    | 'deal'
    | 'property'
    | 'propertyInterest'
    | 'workflow'
    | 'stage'
    | 'task'
    | 'gate'
    | 'person'
    | 'automation'
    | 'extractedField'
    | 'document';

export interface StateDescriptor {
    /** Title Case, for the internal UI. */
    label: string;
    tone: Tone;
    /**
     * Plain language for the client status page, or null when the state is
     * never surfaced to a client (Information Architecture §9).
     */
    clientLabel: string | null;
}

type StateTable = Record<string, StateDescriptor>;

const deal: StateTable = {
    active: { label: 'Active', tone: 'info', clientLabel: 'In Progress' },
    closed: { label: 'Closed', tone: 'success', clientLabel: 'Complete' },
    nurture: { label: 'Past Client', tone: 'neutral', clientLabel: null },
    fell_through: { label: 'Fell Through', tone: 'danger', clientLabel: null },
    cancelled: { label: 'Cancelled', tone: 'neutral', clientLabel: null },
};

const workflow: StateTable = {
    not_started: { label: 'Not Started', tone: 'neutral', clientLabel: null },
    active: { label: 'Active', tone: 'info', clientLabel: null },
    on_hold: { label: 'On Hold', tone: 'warning', clientLabel: null },
    completed: { label: 'Completed', tone: 'success', clientLabel: null },
    cancelled: { label: 'Cancelled', tone: 'neutral', clientLabel: null },
};

const stage: StateTable = {
    pending: { label: 'Upcoming', tone: 'neutral', clientLabel: 'Coming Up' },
    active: { label: 'In Progress', tone: 'info', clientLabel: 'In Progress' },
    // Blocked is amber, not red: it usually means a checkbox is unticked.
    // The client never sees it — a blocked stage reads as "In Progress".
    blocked: { label: 'Blocked', tone: 'warning', clientLabel: 'In Progress' },
    complete: { label: 'Complete', tone: 'success', clientLabel: 'Done' },
    // Skipped stages are hidden from the client entirely.
    skipped: { label: 'Skipped', tone: 'neutral', clientLabel: null },
};

const task: StateTable = {
    open: { label: 'Open', tone: 'neutral', clientLabel: null },
    completed: { label: 'Completed', tone: 'success', clientLabel: null },
    // Derived from due_date, never stored.
    overdue: { label: 'Overdue', tone: 'danger', clientLabel: null },
};

const gate: StateTable = {
    met: { label: 'Met', tone: 'success', clientLabel: null },
    unmet: { label: 'Not Met', tone: 'neutral', clientLabel: null },
    overridden: { label: 'Overridden', tone: 'warning', clientLabel: null },
};

/**
 * IA §8's person lifecycle — which describes a **contact**.
 *
 * A colleague has no honest value in it: their membership holds `active`
 * because something had to be written, and `active` reads as *Client*. A
 * screen asks `carriesAccess` first and draws the person's role instead
 * (#162); this table is what a contact gets.
 */
const person: StateTable = {
    lead: { label: 'Lead', tone: 'info', clientLabel: null },
    active: { label: 'Client', tone: 'success', clientLabel: null },
    past_client: { label: 'Past Client', tone: 'neutral', clientLabel: null },
    archived: { label: 'Archived', tone: 'neutral', clientLabel: null },
};

/**
 * Market status, not a state machine (PRD §6.3, §7.11).
 *
 * The only table here whose codes come from PRD §6.3's lookup list rather than
 * IA §8's state vocabulary, because a property does not transition — a team
 * sets what is true. PRD §7.11 is what keeps it that way: "Undergoing
 * improvements" and "Staged" look like they belong and are **workflow
 * positions**, so they live on a stage and not here.
 *
 * No client label on any of them. A client reads their own deal's status page,
 * and what the market thinks of the house is the agent's to say.
 */
const property: StateTable = {
    pre_listing: { label: 'Pre-listing', tone: 'neutral', clientLabel: null },
    for_sale: { label: 'For Sale', tone: 'info', clientLabel: null },
    // `info`, like For Sale: the label already says which, and amber is for
    // things that need attention (Design System §2.4).
    under_contract: {
        label: 'Under Contract',
        tone: 'info',
        clientLabel: null,
    },
    sold: { label: 'Sold', tone: 'success', clientLabel: null },
    off_market: { label: 'Off Market', tone: 'neutral', clientLabel: null },
    rented: { label: 'Rented', tone: 'success', clientLabel: null },
    other: { label: 'Other', tone: 'neutral', clientLabel: null },
};

/**
 * What a buyer thinks of a candidate (PRD §6.3, §4.3 F3.5).
 *
 * The second lookup in this table rather than a state vocabulary, for the same
 * reason `property` is: PRD §6.3 owns the values, and a buyer does not
 * transition between them so much as change their mind.
 *
 * `passed` is neutral, not danger. A buyer ruling a house out is the ordinary
 * outcome of a showing — most candidates end here — and Design System §2.4
 * reserves red for things that are actually broken.
 */
const propertyInterest: StateTable = {
    interested: { label: 'Interested', tone: 'info', clientLabel: null },
    shortlisted: { label: 'Shortlisted', tone: 'success', clientLabel: null },
    passed: { label: 'Passed', tone: 'neutral', clientLabel: null },
    other: { label: 'Other', tone: 'neutral', clientLabel: null },
};

const automation: StateTable = {
    pending: { label: 'Scheduled', tone: 'neutral', clientLabel: null },
    awaiting_approval: {
        label: 'Needs Review',
        tone: 'warning',
        clientLabel: null,
    },
    sent: { label: 'Sent', tone: 'success', clientLabel: null },
    failed: { label: 'Failed', tone: 'danger', clientLabel: null },
    cancelled: { label: 'Cancelled', tone: 'neutral', clientLabel: null },
};

const extractedField: StateTable = {
    pending: { label: 'Needs Review', tone: 'warning', clientLabel: null },
    confirmed: { label: 'Confirmed', tone: 'success', clientLabel: null },
    edited: { label: 'Edited', tone: 'info', clientLabel: null },
    rejected: { label: 'Rejected', tone: 'neutral', clientLabel: null },
};

const document: StateTable = {
    // Design System §2.4 carries exactly one document state: a file the scan
    // refused. A stored document has no badge — it is simply a document.
    refused: { label: 'Refused by scan', tone: 'danger', clientLabel: null },
};

export const STATES: Record<StateDomain, StateTable> = {
    deal,
    property,
    propertyInterest,
    workflow,
    stage,
    task,
    gate,
    person,
    automation,
    extractedField,
    document,
};

/**
 * Resolve a state code to its label and tone.
 *
 * Throws on an unknown code. An unstyled badge carrying a raw snake_case
 * string is worse than a loud failure: it ships.
 */
export function resolveState(
    domain: StateDomain,
    code: string,
): StateDescriptor {
    const table = STATES[domain];

    if (!table) {
        throw new Error(`Unknown state domain "${domain}".`);
    }

    const descriptor = table[code];

    if (!descriptor) {
        throw new Error(
            `Unknown ${domain} state "${code}". Add it to Design System §2.4 and lib/states.ts first.`,
        );
    }

    return descriptor;
}

/** The Title Case label the internal UI shows. */
export function stateLabel(domain: StateDomain, code: string): string {
    return resolveState(domain, code).label;
}

/** The tone a StatusBadge (or anything else colouring by state) uses. */
export function stateTone(domain: StateDomain, code: string): Tone {
    return resolveState(domain, code).tone;
}

/**
 * The plain-language label for a client-facing surface, or null when the
 * state is never surfaced (Information Architecture §9: no jargon, no
 * alarming words, and `blocked` never reaches a client).
 */
export function clientStateLabel(
    domain: StateDomain,
    code: string,
): string | null {
    return resolveState(domain, code).clientLabel;
}

/**
 * The client-facing translation layer for a stage.
 *
 * Internal stage names never cross the boundary — the client sees the
 * stage's own `milestone_label` and, failing that, nothing at all. Passing
 * the internal name through is the bug this function exists to prevent.
 */
export function clientStageName(stage: {
    state: string;
    milestone_label?: string | null;
}): string | null {
    if (clientStateLabel('stage', stage.state) === null) {
        return null;
    }

    return stage.milestone_label ?? null;
}
