/**
 * The sidebar's contents — Information Architecture §5.1.
 *
 * The order is deliberate: the two screens Heather opens every morning sit at
 * the top, and configuration sits at the bottom, where it is found once and
 * rarely revisited. Groups are separated by dividers in the shell.
 *
 * A section the user lacks permission for is hidden, never disabled.
 */
import {
    Activity,
    Briefcase,
    CalendarClock,
    CalendarDays,
    FileText,
    Heart,
    House,
    LayoutDashboard,
    LayoutTemplate,
    Mail,
    ListChecks,
    Settings,
    Users,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';

export interface NavEntry {
    label: string;
    href: string;
    icon: LucideIcon;
    /** When set, the entry renders only if the person holds this permission. */
    permission?: string;
    /** Key into the shell's counts prop — My Work carries a count. */
    countKey?: 'myWork' | 'pendingMessages';
}

export const NAV_GROUPS: NavEntry[][] = [
    [
        { label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
        {
            label: 'My Work',
            href: '/work',
            icon: ListChecks,
            countKey: 'myWork',
        },
        {
            label: 'Deals',
            href: '/deals',
            icon: Briefcase,
            permission: 'deals.view',
        },
    ],
    [
        {
            label: 'People',
            href: '/people',
            icon: Users,
            permission: 'people.view',
        },
        {
            label: 'Properties',
            href: '/properties',
            icon: House,
            permission: 'properties.view',
        },
        /*
         * S50 (#98). Beside Properties rather than under Deals, because the
         * question it answers — "where is that disclosure" — is asked from a
         * standing start, without a deal already in mind.
         *
         * **Ungated**, and that is not an oversight. `DocumentPolicy::viewAny()`
         * admits either subject permission and the query scopes each row to
         * what the person may see, so gating the link on `deals.view` hid the
         * screen from somebody holding `properties.view` who has documents to
         * find there. A nav rule narrower than the policy is a screen nobody
         * can reach; one wider is a 403 they can read.
         */
        {
            label: 'Documents',
            href: '/documents',
            icon: FileText,
        },
        {
            label: 'Calendar',
            href: '/calendar',
            icon: CalendarDays,
            permission: 'calendar.view',
        },
        /*
         * S59 (#107). Beside Calendar rather than under Deals, because the
         * question it answers — *"what is this week's exposure"* — is asked
         * from a standing start with no deal in mind, which is the same reason
         * Documents sits beside Properties.
         *
         * Gated on `deals.view`, not `calendar.view`: every row is a deadline
         * on a deal and carries the deal's name, so this is reading deals.
         * `KeyDatePolicy` asks the same key, and a nav rule narrower or wider
         * than the policy is either a screen nobody can reach or a 403 they
         * can read.
         *
         * **Dates & Deadlines**, Emily's phrase (IA §2, §11). Never "Key
         * dates", which is the code name, and never Milestone — that word
         * means a moment on a stage now, and nothing else.
         */
        {
            label: 'Dates & Deadlines',
            href: '/dates',
            icon: CalendarClock,
            permission: 'deals.view',
        },
        {
            label: 'Keep in Touch',
            href: '/keep-in-touch',
            icon: Heart,
            permission: 'nurture.manage',
        },
        /*
         * S12. Last in the group deliberately: it answers "what has everyone
         * been doing", which is a question asked at the end of a day rather
         * than at the start of one — the top of the sidebar belongs to the two
         * screens Heather opens every morning.
         *
         * IA §11 names it **Activity**, not Feed, History, or Audit. *Audit* is
         * the security log, which lives in Settings behind its own permission.
         */
        {
            label: 'Activity',
            href: '/activity',
            icon: Activity,
            permission: 'people.view',
        },
    ],
    [
        /*
         * S47 (#93). Above Templates, because it is a queue somebody has to
         * clear rather than a place to go and build something — and the count
         * beside it is the only thing on the shell that says a client message
         * is waiting on a person. PRD §4.5 makes the queue a launch blocker;
         * a queue nobody is told about holds messages that silently never go.
         *
         * Gated on `deals.view` rather than `message.approve`: reading the
         * queue is the wider permission, and a screen that showed nothing to
         * somebody who can see every one of these messages on the deal itself
         * would be hiding a fact rather than protecting one.
         */
        {
            label: 'Messages',
            href: '/messages',
            icon: Mail,
            permission: 'deals.view',
            countKey: 'pendingMessages',
        },
        {
            label: 'Templates',
            href: '/templates',
            icon: LayoutTemplate,
            permission: 'templates.manage',
        },
        {
            label: 'Settings',
            href: '/settings',
            icon: Settings,
            permission: 'settings.manage',
        },
    ],
];

/**
 * The four destinations the bottom tab bar carries on a phone (IA §5.3).
 * Everything else lives behind the "More" sheet.
 */
export const MOBILE_TAB_HREFS = ['/dashboard', '/work', '/deals', '/calendar'];

export function navEntries(): NavEntry[] {
    return NAV_GROUPS.flat();
}

export function mobileTabs(): NavEntry[] {
    const byHref = new Map(navEntries().map((entry) => [entry.href, entry]));

    return MOBILE_TAB_HREFS.map((href) => byHref.get(href)).filter(
        (entry): entry is NavEntry => Boolean(entry),
    );
}

export function moreEntries(): NavEntry[] {
    return navEntries().filter(
        (entry) => !MOBILE_TAB_HREFS.includes(entry.href),
    );
}
