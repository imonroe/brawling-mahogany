/**
 * A page never wraps the layout `app.ts` already gave it (#101 · Frontend
 * conventions §2).
 *
 * Inertia's `layout` resolver in `resources/js/app.ts` assigns every page its
 * frame — `AppLayout` by default, `[AppLayout, SettingsLayout]` under
 * `Settings/`, `[AppLayout, DealLayout]` for a deal tab. Those layouts are
 * **persistent**: they are not remounted between visits, which is what keeps
 * the sidebar's scroll position, the notification popover's state, and any
 * open dialog alive across a navigation.
 *
 * A page that *also* imports and renders one draws the whole shell twice —
 * two sidebars, two headers, two notification bells — and, worse, the inner
 * copy is remounted on every visit while the outer one is not, so two
 * components with the same name disagree about what is open. Review found
 * `Notifications/Index` and `Settings/Notifications` doing exactly this.
 *
 * ## Why a source-reading test
 *
 * The same argument the PHP guards make: a runtime test only catches the page
 * it thought to render, and a duplicated shell renders perfectly well in
 * jsdom. The mistake is also the *natural* one — a page looks incomplete
 * without its frame — so it is worth a machine rather than a memory.
 */
import { readFileSync } from 'node:fs';
import { relative, resolve } from 'node:path';
import fg from 'fast-glob';
import { describe, expect, it } from 'vitest';

// `process.cwd()`, as `cssDependencies.test.ts` does: vitest runs from the
// project root and `import.meta.url` is not a file URL under its transform.
const root = process.cwd();
const pages = resolve(root, 'resources/js/pages');

/**
 * Any import of anything under `layouts/`, however it is spelled.
 *
 * Both the alias and a relative path, because `../../layouts/AppLayout.vue`
 * is the same file and the same bug.
 */
const IMPORTS_A_LAYOUT = /from\s+['"][^'"]*\/layouts\/[^'"]+['"]/;

describe('pages and their layouts', () => {
    it('finds the pages it is meant to be reading', () => {
        /*
         * The guard is worth nothing if the glob is wrong, and a glob that
         * matches nothing passes silently — which is the way a source-reading
         * test fails without saying so.
         */
        const files = fg.sync('**/*.vue', { cwd: pages });

        expect(files.length).toBeGreaterThan(20);
        expect(files).toContain('Notifications/Index.vue');
        expect(files).toContain('Settings/Notifications.vue');
    });

    it('lets no page import the layout app.ts already wraps it in', () => {
        const offenders = fg
            .sync('**/*.vue', { cwd: pages, absolute: true })
            .filter((file) => IMPORTS_A_LAYOUT.test(readFileSync(file, 'utf8')))
            .map((file) => relative(root, file));

        expect(offenders).toEqual([]);
    });

    it('catches the shape of the mistake', () => {
        /*
         * The detector, pinned. Both spellings, and one innocent line that
         * must stay quiet — a page naming a layout in prose is not importing
         * one, and flagging it would be answered by an exemption.
         */
        expect(
            IMPORTS_A_LAYOUT.test(
                "import AppLayout from '@/layouts/AppLayout.vue';",
            ),
        ).toBe(true);

        expect(
            IMPORTS_A_LAYOUT.test(
                "import SettingsLayout from '../../layouts/SettingsLayout.vue';",
            ),
        ).toBe(true);

        expect(
            IMPORTS_A_LAYOUT.test(
                '// Rendered inside AppLayout, which app.ts supplies.',
            ),
        ).toBe(false);
    });
});
