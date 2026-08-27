/**
 * What a timeline entry looks like: its icon, and the tone that tints it.
 *
 * Design System §7.3 fixes the tint by event type — *"completion
 * `state-success`, message sent `state-info`, override `state-warning`,
 * everything else `state-neutral`"* — so the mapping lives here rather than in
 * `ActivityItem`'s call sites. Three screens render this list already (S12,
 * S31, and the deal timeline that follows), and three copies of a colour rule
 * disagree within a month.
 *
 * ## Why this does not throw the way `resolveState` does
 *
 * `lib/states.ts` throws on an unknown state, because a badge with no tone is
 * an unstyled badge that ships. Here the Design System *specifies* the
 * fallback — "everything else `state-neutral`" — so an unmapped event type
 * renders correctly rather than wrongly, and throwing would take a screen down
 * over an event a later slice added.
 *
 * What that costs is silence: an event type nobody mapped gets the default and
 * nothing says so. `tests/js/activityEventTypes.test.ts` closes that by reading
 * every `eventType:` literal out of `app/` and failing when one is missing
 * here — the same trick `tokenDiscipline` uses, for the same reason.
 */
import {
    Activity,
    ArrowRight,
    CalendarClock,
    CalendarPlus,
    CircleCheck,
    CircleSlash,
    Download,
    Eye,
    FileSignature,
    Flag,
    GitBranch,
    House,
    Import,
    Link2,
    Link2Off,
    ListChecks,
    ListPlus,
    ListX,
    Mail,
    MailCheck,
    MailWarning,
    MailX,
    MessageSquare,
    PenLine,
    Phone,
    RotateCcw,
    Scale,
    ShieldAlert,
    Star,
    Trash2,
    UserMinus,
    UserPen,
    UserPlus,
    Users,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import type { Tone } from '@/lib/states';

export interface ActivityDescriptor {
    icon: LucideIcon;
    tone: Tone;
}

/**
 * PRD §6.3's contact types, each with the icon that says which without
 * reading. The label itself always comes from the server — this is only the
 * glyph.
 */
const CONTACT_ICONS: Record<string, LucideIcon> = {
    phone_call: Phone,
    email: Mail,
    text: MessageSquare,
    meeting: Users,
    showing: House,
    other: MessageSquare,
};

/**
 * Every event type `app/` emits.
 *
 * Only four tones are ever assigned here, and they are the four Design
 * System §7.3 names: a completion is `success`, a message the product sent is
 * `info`, an override or a sandbox redirect is `warning`, a message that
 * failed to send is `danger`. Everything else is `neutral` and is listed
 * anyway, so the icon is chosen rather than defaulted.
 */
const EVENT_TYPES: Record<string, ActivityDescriptor> = {
    // Completions.
    'stage.advanced': { icon: ArrowRight, tone: 'success' },
    /*
     * A completed task is a completion, so §7.3's rule gives it `success` —
     * the same tone as an advance, which is right: on a checklist-driven deal
     * these are the entries that say work got done.
     */
    'task.completed': { icon: CircleCheck, tone: 'success' },
    'milestone.reached': { icon: Flag, tone: 'success' },
    'workflow.completed': { icon: Flag, tone: 'success' },

    /*
     * The override marker (Design System §7.3, §7.4 · PRD F4.9 · #69).
     *
     * The only `warning` in the table, and the only entry whose tone is fixed
     * by name in the spec: *"override `state-warning`"*. §7.4's stage rail
     * gives the same event a `shield-alert` marker, so the glyph matches the
     * one S16 will draw beside the stage.
     *
     * It has to be recognisable without reading the summary — a bypassed gate
     * that looks like every other row is a bypassed gate nobody finds.
     */
    'gate.overridden': { icon: ShieldAlert, tone: 'warning' },

    /*
     * The messages an automation sent, or did not (§7.3 · #92, #93).
     *
     * `message.sent` is the only `info` in the table, and the only tone §7.3
     * reserved for something that had not been built yet — *"message sent
     * `state-info`"* has been in the Design System since Slice 0.
     *
     * The other two are the ones worth arguing. **Failed** is `danger`, which
     * §7.3 gained for this row: every other entry on this feed records
     * something that happened, and this one records something the product
     * tried and could not do. PRD §1.1's second question is "has the client
     * been told?", and a failed client email rendered in the same grey as a
     * renamed task is that question answered wrongly.
     *
     * **Redirected** is `warning` rather than `info`, because sandbox mode
     * sent it to the team and not to the client. Reading it as a send is the
     * exact misunderstanding the sandbox creates, and the one the banner in
     * the email itself also exists to prevent.
     */
    'message.sent': { icon: Mail, tone: 'info' },
    'message.redirected': { icon: MailWarning, tone: 'warning' },
    'message.failed': { icon: MailX, tone: 'danger' },
    /*
     * What the provider said afterwards (#95 · F5.8), and both are `danger`
     * for the same reason `message.failed` is: the row records a client who
     * was **not** told, and the product's second question is whether they
     * were.
     *
     * Their own event types rather than more `message.failed` rows, because
     * they are a different kind of not-arriving and a team acts on them
     * differently. A `message.failed` is ours — a merge field, a credential,
     * a rail — and is fixed here. A bounce is theirs: the address is wrong and
     * somebody has to ring the client and ask for the right one. Collapsing
     * the two would put "check your SES key" and "check your seller's email"
     * behind the same word.
     *
     * `message.complained` uses the same icon deliberately. §7.3 has no tone
     * above `danger` and inventing one for a row a team may see twice a year
     * would cost the scale its meaning; the summary carries the difference.
     */
    'message.bounced': { icon: MailX, tone: 'danger' },
    'message.complained': { icon: MailX, tone: 'danger' },
    /*
     * Neutral, both of them, and deliberately quieter than the send.
     *
     * Approving is a person doing their job on a queue, and #93 is precise
     * about what the queue must not become: a list somebody clears without
     * reading. A tinted row for every approval on a busy team is how the tint
     * stops meaning anything, which costs the `danger` above its whole value.
     */
    'message.approved': { icon: MailCheck, tone: 'neutral' },
    'message.cancelled': { icon: MailX, tone: 'neutral' },
    'message.action_done': { icon: CircleCheck, tone: 'neutral' },
    /*
     * Ticking a manual gate. **Neutral, not success** — §7.3 tints a
     * *completion*, and clearing one of three blockers completes nothing. It
     * is also the row that has to read as plainly different from the
     * `state-warning` override directly above it: IA §8 keeps met and
     * overridden apart, and a feed that tinted them alike would undo that
     * distinction at exactly the point somebody is scanning for it.
     */
    'gate.confirmed': { icon: CircleCheck, tone: 'neutral' },
    'gate.unconfirmed': { icon: CircleSlash, tone: 'neutral' },

    /*
     * Skip and Reopen (PRD F4.12 · IA §7 · #70), and both are **neutral**.
     *
     * The override above earns amber because something that should have
     * happened did not. Neither of these is that. A skipped stage did not
     * apply to this deal at all — §7.4 badges it neutral and IA §8 hides it
     * from the client entirely — and tinting it like an override on the feed
     * would rebuild, in colour, exactly the conflation IA §7 calls legally
     * material. Reopening is somebody correcting themselves, which the product
     * would rather encourage than mark.
     *
     * Distinct glyphs, though: both are unusual enough to be worth finding
     * without reading the summary.
     */
    /*
     * A note somebody wrote (F4.11 · #72). Neutral, and `PenLine` because it
     * is the one row on the feed whose text a person typed rather than the
     * product describing something that happened.
     */
    'note.added': { icon: PenLine, tone: 'neutral' },

    /*
     * Offers (S22, #73). All three neutral, including the one that records an
     * acceptance: §7.3 tints a **completion**, and an accepted offer is not
     * the deal completing — it is the moment the deal acquires its dates. The
     * completion on this feed is the closing, which is a stage advance.
     */
    'offer.added': { icon: FileSignature, tone: 'neutral' },
    'offer.status_changed': { icon: Scale, tone: 'neutral' },
    'offer.removed': { icon: Trash2, tone: 'neutral' },

    /*
     * Dates & Deadlines (S18 · #106, #107). All four **neutral**, including
     * the cascade — §7.3 tints a *completion*, a message the product sent, and
     * an override, and a date moving is none of them. It is a fact about the
     * contract, and the row that matters most (`key_date.cascaded`) is
     * findable by its glyph rather than by its colour.
     *
     * `GitBranch` for the cascade rather than a second flag: it is the one row
     * on the feed that describes *several* dates moving because one did, and
     * the shape says so without the summary.
     */
    'key_date.added': { icon: Flag, tone: 'neutral' },
    'key_date.moved': { icon: CalendarClock, tone: 'neutral' },
    'key_date.cascaded': { icon: GitBranch, tone: 'neutral' },
    'key_date.removed': { icon: Trash2, tone: 'neutral' },

    /*
     * The calendar (S57, S58 · #105). Neutral for the same reason, and
     * `CalendarPlus` / `CalendarClock` keep *added* and *moved* apart: the
     * question somebody chases six weeks later is *"when did the inspection
     * get pushed?"*, and burying that under a generic "edited" is how the
     * answer stops being findable.
     */
    'event.added': { icon: CalendarPlus, tone: 'neutral' },
    'event.moved': { icon: CalendarClock, tone: 'neutral' },
    'event.edited': { icon: PenLine, tone: 'neutral' },
    'event.removed': { icon: Trash2, tone: 'neutral' },

    /*
     * The client's own page (#110, #111). Neutral, all four: §7.3 tints a
     * completion, a message the product sent, and an override — and a client
     * opening their status page is none of them. It is on the feed because
     * *"has the client looked?"* is a question an agent asks, and the audit
     * log is not a screen they work from (IA §11 keeps the two apart).
     *
     * `Link2Off` for the revoke, matching `property.unlinked` — access taken
     * away is the same shape of event as a link removed.
     */
    'status_page.link_issued': { icon: Link2, tone: 'neutral' },
    'status_page.opened': { icon: Eye, tone: 'neutral' },
    'status_page.document_downloaded': { icon: Download, tone: 'neutral' },
    'status_page.link_revoked': { icon: Link2Off, tone: 'neutral' },

    'stage.skipped': { icon: CircleSlash, tone: 'neutral' },
    'stage.reopened': { icon: RotateCcw, tone: 'neutral' },

    // The rest, neutral by Design System §7.3's own rule.
    'workflow.started': { icon: Activity, tone: 'neutral' },
    /*
     * The other three halves of a task's life (S17, #71). Neutral by §7.3's
     * own rule — only a completion, a message the product sent, and an
     * override are tinted.
     *
     * `task.reopened` exists so the feed cannot go on asserting something the
     * team has since decided is not true: a completion is already in it.
     */
    'task.added': { icon: ListPlus, tone: 'neutral' },
    /*
     * The other way past a blocking gate (#71, found in review). Neutral
     * rather than `warning`: §7.3 gives `warning` to an override, and this is
     * a team changing what it decided the obligation is rather than somebody
     * waiving one that stands. It is on the feed at all so that the change is
     * not silent, which is what it was.
     */
    'task.required_changed': { icon: ListChecks, tone: 'neutral' },
    /*
     * The same bypass one control higher up the form: a
     * `required_tasks_complete` gate counts the required tasks on **one
     * stage**, so moving a task off that stage clears it exactly as unticking
     * the flag does. Round 2 of #71's review proved it, after round 1 had
     * fixed only the half it named first.
     */
    'task.moved': { icon: ArrowRight, tone: 'neutral' },
    'task.reopened': { icon: ListChecks, tone: 'neutral' },
    'task.deleted': { icon: ListX, tone: 'neutral' },
    'contact.logged': { icon: MessageSquare, tone: 'neutral' },
    'person.added': { icon: UserPlus, tone: 'neutral' },
    'person.imported': { icon: Import, tone: 'neutral' },
    'person.status_changed': { icon: UserPen, tone: 'neutral' },
    'participant.added': { icon: UserPlus, tone: 'neutral' },
    'participant.role_changed': { icon: UserPen, tone: 'neutral' },
    'participant.removed': { icon: UserMinus, tone: 'neutral' },
    'property.added': { icon: House, tone: 'neutral' },
    'property.linked': { icon: Link2, tone: 'neutral' },
    'property.unlinked': { icon: Link2Off, tone: 'neutral' },
    'property.promoted': { icon: Star, tone: 'neutral' },
    'property.interest_recorded': { icon: PenLine, tone: 'neutral' },
    'property.status_changed': { icon: PenLine, tone: 'neutral' },
    'property.deleted': { icon: Trash2, tone: 'neutral' },
};

export const ACTIVITY_FALLBACK: ActivityDescriptor = {
    icon: Activity,
    tone: 'neutral',
};

/** Exposed for the test that holds this table against `app/`. */
export function mappedActivityEventTypes(): string[] {
    return Object.keys(EVENT_TYPES);
}

/** Exposed for the test that holds it against `App\Enums\ContactType`. */
export function mappedContactTypes(): string[] {
    return Object.keys(CONTACT_ICONS);
}

export function activityDescriptor(event: {
    eventType: string;
    /** PRD §6.3 contact type, present only on `contact.logged`. */
    contactType?: string | null;
}): ActivityDescriptor {
    const descriptor = EVENT_TYPES[event.eventType] ?? ACTIVITY_FALLBACK;

    if (event.eventType !== 'contact.logged' || !event.contactType) {
        return descriptor;
    }

    return { ...descriptor, icon: contactTypeIcon(event.contactType) };
}

/** The glyph for one contact type, for the log-contact modal's type tiles. */
export function contactTypeIcon(contactType: string): LucideIcon {
    return CONTACT_ICONS[contactType] ?? ACTIVITY_FALLBACK.icon;
}
