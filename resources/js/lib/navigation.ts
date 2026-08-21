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
    Briefcase,
    CalendarDays,
    Heart,
    House,
    LayoutDashboard,
    LayoutTemplate,
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
    countKey?: 'myWork';
}

export const NAV_GROUPS: NavEntry[][] = [
    [
        { label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
        { label: 'My Work', href: '/work', icon: ListChecks, countKey: 'myWork' },
        { label: 'Deals', href: '/deals', icon: Briefcase, permission: 'deals.view' },
    ],
    [
        { label: 'People', href: '/people', icon: Users, permission: 'people.view' },
        { label: 'Properties', href: '/properties', icon: House, permission: 'properties.view' },
        { label: 'Calendar', href: '/calendar', icon: CalendarDays, permission: 'calendar.view' },
        { label: 'Keep in Touch', href: '/keep-in-touch', icon: Heart, permission: 'nurture.manage' },
    ],
    [
        { label: 'Templates', href: '/templates', icon: LayoutTemplate, permission: 'templates.manage' },
        { label: 'Settings', href: '/settings', icon: Settings, permission: 'settings.manage' },
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
    return navEntries().filter((entry) => !MOBILE_TAB_HREFS.includes(entry.href));
}
