/**
 * The Report a bug modal and the control that opens it (issue #176).
 *
 * The audience is the whole feature: agents with no GitHub accounts, mid-task,
 * looking at something broken. So what is pinned here is what makes the thing
 * findable and escapable — a control that says what it is at every width, and
 * a way out that still works when the keyboard belongs to somebody else's
 * document.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { permissions: [] } } }),
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { href: props.href }, slots.default?.()),
    }),
}));

const ReportBugDialog = (await import('@/components/app/ReportBugDialog.vue'))
    .default;
const TopBar = (await import('@/components/app/TopBar.vue')).default;

const URL = 'https://n8n.example.test/form/bugs';

/**
 * Reka mounts dialog content through a portal a macrotask later, not on the
 * next tick — the same wait `logContactDialog.test.ts` uses.
 */
function flush(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

async function openDialog() {
    const wrapper = mount(ReportBugDialog, {
        props: { open: false, url: URL },
        attachTo: document.body,
    });

    await wrapper.setProps({ open: true });
    await flush();

    return wrapper;
}

describe('the Report a bug button', () => {
    it('is absent until the form is configured', () => {
        // The prop is null when the flag is off, the URL is unusable, or
        // nobody is signed in. All three are one absence: no button.
        const bar = mount(TopBar, { props: { bugReport: false } });

        expect(bar.find('[aria-label="Report a bug"]').exists()).toBe(false);
    });

    it('says what it is at every width', async () => {
        const bar = mount(TopBar, { props: { bugReport: true } });
        const button = bar.find('[aria-label="Report a bug"]');

        expect(button.exists()).toBe(true);
        // The label is hidden below `lg`, so the accessible name cannot come
        // from the text — it comes from `aria-label`, which does not move.
        expect(button.text()).toContain('Report a bug');
        expect(button.attributes('title')).toBe('Report a bug');

        await button.trigger('click');

        expect(bar.emitted('report-bug')).toHaveLength(1);
    });

    it('keeps §11’s 44px target when the label is hidden', () => {
        // `w-11` restores the square once the text is gone; the height comes
        // from the ghost size's own `min-h-11`.
        const bar = mount(TopBar, { props: { bugReport: true } });
        const classes = bar.find('[aria-label="Report a bug"]').classes();

        expect(classes).toContain('w-11');
        expect(classes).toContain('min-h-11');
        // And the 32px square its neighbours are, at the widths where it sits
        // among them without its label.
        expect(classes).toContain('md:w-8');
        expect(classes).toContain('lg:w-auto');
    });
});

describe('the Report a bug modal', () => {
    it('does not call n8n until somebody asks for it', async () => {
        // A frame mounted with the shell would fetch a third party on every
        // page view. The dialog's portal renders nothing while it is closed.
        const wrapper = mount(ReportBugDialog, {
            props: { open: false, url: URL },
            attachTo: document.body,
        });

        await flush();

        expect(document.body.querySelector('iframe')).toBeNull();

        wrapper.unmount();
    });

    it('frames the configured form', async () => {
        const wrapper = await openDialog();
        const frame = document.body.querySelector('iframe');

        expect(frame?.getAttribute('src')).toBe(URL);
        // An accessible name, because a frame is a document and a screen
        // reader announces it as one.
        expect(frame?.getAttribute('title')).toBe('Report a bug');

        wrapper.unmount();
    });

    it('sandboxes the frame to what the form needs and no more', async () => {
        const wrapper = await openDialog();
        const sandbox = (
            document.body.querySelector('iframe')?.getAttribute('sandbox') ?? ''
        ).split(' ');

        // What a hosted form genuinely needs to work.
        expect(sandbox).toContain('allow-scripts');
        expect(sandbox).toContain('allow-forms');
        // Its *own* origin, not ours — that is what lets it keep its storage
        // and its cookies.
        expect(sandbox).toContain('allow-same-origin');

        // What it must never have: it may not navigate the application out
        // from under the person, and it may not start a download.
        expect(sandbox).not.toContain('allow-top-navigation');
        expect(sandbox).not.toContain(
            'allow-top-navigation-by-user-activation',
        );
        expect(sandbox).not.toContain('allow-downloads');
        expect(sandbox).not.toContain('allow-modals');

        wrapper.unmount();
    });

    it('sends no referrer, because the screen behind it names a deal', async () => {
        const wrapper = await openDialog();

        expect(
            document.body
                .querySelector('iframe')
                ?.getAttribute('referrerpolicy'),
        ).toBe('no-referrer');

        wrapper.unmount();
    });

    it('carries a Close button rather than relying on Escape', async () => {
        /*
         * The issue asks to be able to close it *at any time*, and Escape does
         * not satisfy that on its own: a keystroke typed inside a cross-origin
         * frame is delivered to that document and never reaches this one, so
         * Escape stops working at exactly the moment somebody is filling the
         * form in. A real button in our own chrome is the one control that
         * always works.
         */
        const wrapper = await openDialog();

        const close = Array.from(
            document.body.querySelectorAll('[data-slot="app-button"]'),
        ).find((element) => element.textContent?.trim() === 'Close');

        expect(close).toBeDefined();

        (close as HTMLElement).click();
        await flush();

        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);

        wrapper.unmount();
    });

    it('offers the same form outside the frame', async () => {
        /*
         * A form can refuse to be framed, and the refusal looks like a blank
         * rectangle from out here — a cross-origin frame tells the embedder
         * nothing about what it rendered, so there is no event to branch on.
         * The link is the way out that does not depend on framing working.
         */
        const wrapper = await openDialog();

        const link = Array.from(document.body.querySelectorAll('a')).find(
            (anchor) => anchor.textContent?.trim() === 'Open in a new tab',
        );

        expect(link?.getAttribute('href')).toBe(URL);
        expect(link?.getAttribute('target')).toBe('_blank');
        // Never a bare `target="_blank"`: the opened page gets `window.opener`
        // and can navigate the one it came from.
        expect(link?.getAttribute('rel')).toContain('noopener');

        wrapper.unmount();
    });

    it('covers the frame until it answers', async () => {
        // A blank rectangle for however long n8n takes reads as broken.
        const wrapper = await openDialog();

        expect(document.body.textContent).toContain('Loading the form…');

        document.body.querySelector('iframe')?.dispatchEvent(new Event('load'));
        await flush();

        expect(document.body.textContent).not.toContain('Loading the form…');

        wrapper.unmount();
    });
});
