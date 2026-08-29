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
 * ## The rule is about a *double* wrap, not about importing a layout
 *
 * `app.ts` gives some page families **no** layout at all, and for those the
 * mistake is impossible: `Status/*` is the client surface, where the frame
 * carries the team's own branding as props (Frontend conventions §2, which
 * records this as the intended design) and S64 deliberately wears no frame at
 * all, because no team has been established yet.
 *
 * So the check reads `app.ts` for the families it exempts rather than carrying
 * a list of its own. An allowlist would drift the day somebody gave `Status/`
 * a layout, and would be the more tempting fix on the day this test failed.
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

/**
 * The page-name prefixes `app.ts` answers with `null`.
 *
 * Read out of `app.ts` rather than repeated here, so the exemption is the
 * *same fact* the resolver states rather than a copy of it — the shape
 * `GateRegistry` and its picker already settled one language over.
 */
function familiesWithNoLayout(): string[] {
    const source = readFileSync(resolve(root, 'resources/js/app.ts'), 'utf8');

    const families: string[] = [];

    /*
     * `case name.startsWith('Status/'): return null;` — the prefix, and the
     * `null` that has to follow it within the next line or two. A `case` whose
     * body returns a layout is not an exemption.
     */
    const pattern =
        /case\s+name\.startsWith\(\s*'([^']+)'\s*\)\s*:\s*return\s+(null|[A-Za-z[])/g;

    let match = pattern.exec(source);

    while (match !== null) {
        if (match[2] === 'null' && match[1]) {
            families.push(match[1]);
        }

        match = pattern.exec(source);
    }

    return families;
}

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

    it('reads the exemptions out of app.ts rather than keeping its own list', () => {
        /*
         * The positive control. A family list that quietly stopped matching
         * would exempt nothing — which looks like a passing test — or, worse,
         * a broken pattern that matched every `case` would exempt everything.
         */
        const families = familiesWithNoLayout();

        expect(families).toContain('Status/');
        expect(families).not.toContain('Settings/');
        expect(families).not.toContain('Admin/');
    });

    it('lets no page import the layout app.ts already wraps it in', () => {
        const exempt = familiesWithNoLayout();

        const offenders = fg
            .sync('**/*.vue', { cwd: pages, absolute: true })
            .filter((file) => IMPORTS_A_LAYOUT.test(readFileSync(file, 'utf8')))
            .map((file) => relative(root, file))
            .filter(
                (file) =>
                    !exempt.some((family) =>
                        file.startsWith(`resources/js/pages/${family}`),
                    ),
            );

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
