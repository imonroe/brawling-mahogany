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
    CircleCheck,
    Flag,
    House,
    Import,
    Link2,
    Link2Off,
    ListChecks,
    ListPlus,
    ListX,
    Mail,
    MessageSquare,
    PenLine,
    Phone,
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
 * Only three tones are ever assigned here, and they are the three Design
 * System §7.3 names: a completion is `success`, a message the product sent is
 * `info`, an override is `warning`. Everything else is `neutral` and is listed
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
