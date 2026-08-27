/**
 * S08's panel fetches when it opens, and dismisses in one request (#101).
 *
 * Both of these are client-side mechanisms with no server half to test
 * through: what matters is what reaches `router.reload` and `router.post`,
 * which is the last honest place to look before it becomes a visit. The two
 * defects below were found by review against the installed router and neither
 * is visible from PHP.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';

const routerReload = vi.fn();
const routerPost = vi.fn();

let pageProps: Record<string, unknown> = {};

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: () => () => null }),
    router: { reload: routerReload, post: routerPost, on: vi.fn() },
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { href: props.href }, slots.default?.()),
    }),
    usePage: () => ({ props: pageProps }),
}));

const NotificationsMenu = (
    await import('@/components/app/NotificationsMenu.vue')
).default;

function group(overrides: Record<string, unknown> = {}) {
    return {
        id: 'n1',
        type: 'task_assigned',
        summary: '3 tasks were assigned to you',
        dealId: 'd1',
        dealName: '14 Elm St',
        teamId: 't1',
        teamName: 'Bosart Group',
        url: '/notifications/n1/open',
        occurredAt: '2026-08-27T09:00:00+00:00',
        count: 3,
        unread: 3,
        ids: ['n1', 'n2', 'n3'],
        ...overrides,
    };
}

beforeEach(() => {
    routerReload.mockReset();
    routerPost.mockReset();
    pageProps = { counts: { notifications: 3 } };
});

/**
 * Everything the panel has rendered, wherever it was teleported to.
 *
 * `DropdownMenuContent` portals into `document.body`, so `wrapper.text()` is
 * empty whichever branch drew — which is a test that cannot fail.
 */
function panelText(): string {
    return document.body.textContent ?? '';
}

/** Open the menu by driving the state the trigger drives. */
async function openMenu(wrapper: ReturnType<typeof mount>) {
    (wrapper.vm as unknown as { open: boolean }).open = true;
    await nextTick();
}

describe('opening the panel', () => {
    it('fetches every time it opens, not only the first', async () => {
        /*
         * The blocking defect. `notifications` is an `Inertia::optional` prop,
         * so it is absent from every ordinary page load — and `AppLayout` is
         * persistent, so this component is never remounted. A `loaded` latch
         * therefore meant: fetch once, then render `[]` forever, with the bell
         * still carrying its unread dot. A second open has to refetch.
         */
        const wrapper = mount(NotificationsMenu);

        await openMenu(wrapper);
        expect(routerReload).toHaveBeenCalledTimes(1);

        (wrapper.vm as unknown as { open: boolean }).open = false;
        await nextTick();

        await openMenu(wrapper);
        expect(routerReload).toHaveBeenCalledTimes(2);

        expect(routerReload.mock.calls[0][0]).toMatchObject({
            only: ['notifications'],
        });
    });

    it('says nothing rather than “Nothing yet.” while the fetch is in flight', async () => {
        /*
         * The visible half of the same defect: with the prop absent, an empty
         * branch that does not know a request is in flight tells somebody they
         * have no notifications while the ones they do have are on the wire.
         *
         * Read off the menu's own content rather than off `wrapper.text()`:
         * `DropdownMenuContent` teleports to `document.body`, so a wrapper
         * assertion is empty either way and would pass against both branches.
         */
        const wrapper = mount(NotificationsMenu, { attachTo: document.body });

        await openMenu(wrapper);
        await nextTick();

        expect(panelText()).toContain('Loading');
        expect(panelText()).not.toContain('Nothing yet');

        wrapper.unmount();
    });

    it('says so once a fetch comes back empty', async () => {
        routerReload.mockImplementation((options: { onFinish?: () => void }) =>
            options.onFinish?.(),
        );

        const wrapper = mount(NotificationsMenu, { attachTo: document.body });

        await openMenu(wrapper);
        await nextTick();

        expect(panelText()).toContain('Nothing yet');

        wrapper.unmount();
    });
});

describe('dismissing a line', () => {
    it('sends one request naming every id the line folded', async () => {
        /*
         * The first version looped `router.post` once per id. Inertia's sync
         * stream is `maxConcurrent: 1, interruptible: true`, so each visit
         * aborted the last — measured at 11 of 12 cancelled, with each
         * survivor re-running the whole feed.
         */
        pageProps = {
            counts: { notifications: 3 },
            notifications: [group()],
        };

        const wrapper = mount(NotificationsMenu, {
            global: { stubs: { teleport: true } },
        });

        await openMenu(wrapper);

        (
            wrapper.vm as unknown as {
                markRead: (g: ReturnType<typeof group>) => void;
            }
        ).markRead(group());

        expect(routerPost).toHaveBeenCalledTimes(1);
        expect(routerPost.mock.calls[0][1]).toEqual({
            notifications: ['n1', 'n2', 'n3'],
        });
    });

    it('marks read on a stream that cannot interrupt the click’s own navigation', async () => {
        /*
         * Without `async`, dismissing a line as part of *clicking* it cancels
         * the click's own visit: the notification is marked read and the
         * person stays where they were, which is the opposite of what the help
         * article promises. `async` moves the visit to Inertia's other stream.
         */
        pageProps = {
            counts: { notifications: 3 },
            notifications: [group()],
        };

        const wrapper = mount(NotificationsMenu, {
            global: { stubs: { teleport: true } },
        });

        await openMenu(wrapper);

        (
            wrapper.vm as unknown as {
                markRead: (g: ReturnType<typeof group>) => void;
            }
        ).markRead(group());
        (wrapper.vm as unknown as { markAllRead: () => void }).markAllRead();

        for (const call of routerPost.mock.calls) {
            expect(call[2]).toMatchObject({ async: true });
        }
    });

    it('does not post for a line that is already read', async () => {
        pageProps = {
            counts: { notifications: 0 },
            notifications: [group({ unread: 0 })],
        };

        const wrapper = mount(NotificationsMenu, {
            global: { stubs: { teleport: true } },
        });

        await openMenu(wrapper);

        (
            wrapper.vm as unknown as {
                markRead: (g: ReturnType<typeof group>) => void;
            }
        ).markRead(group({ unread: 0 }));

        expect(routerPost).not.toHaveBeenCalled();
    });
});
