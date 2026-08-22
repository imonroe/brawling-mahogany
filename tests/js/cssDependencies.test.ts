import { existsSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Every package `resources/css/app.css` imports must be declared and installed.
 *
 * This exists because of a failure that is invisible until someone opens a
 * browser. `compose.local.yaml` keeps node_modules in a *named* volume, which
 * Docker seeds when it is first created and never re-seeds on a rebuild — so a
 * dependency added after somebody last ran the stack is simply absent from
 * their container. Tailwind then fails to resolve the `@import`, the dev server
 * serves a 500 for the stylesheet, and the app mounts with every utility class
 * inert: black default text on the near-black background the blade paints
 * inline. It reads as a dead page, and nothing in CI notices, because CI builds
 * the production image where the dependencies are baked in.
 *
 * `make check` runs this suite *inside* the container, against those same
 * volumes, so a stale volume fails here with a message naming the package
 * rather than as a blank screen. The remedy is `make deps`.
 */
const stylesheet = 'resources/css/app.css';

function importedPackages(): string[] {
    const css = readFileSync(resolve(process.cwd(), stylesheet), 'utf8');

    return [...css.matchAll(/^@import\s+['"]([^'"]+)['"]/gm)]
        .map((match) => match[1])
        .filter((specifier) => !specifier.startsWith('.'))
        .map((specifier) => {
            // A deep import names its package in the first segment, or the
            // first two when the package is scoped.
            const segments = specifier.split('/');

            return specifier.startsWith('@')
                ? segments.slice(0, 2).join('/')
                : segments[0];
        });
}

describe(`the packages ${stylesheet} imports`, () => {
    const packages = importedPackages();

    it('finds the imports, so the assertions below are not vacuous', () => {
        // Rename the file or change the @import syntax and every other test
        // here would pass over an empty list.
        expect(packages.length).toBeGreaterThan(0);
    });

    it.each(packages)('declares %s in package.json', (name) => {
        const manifest = JSON.parse(
            readFileSync(resolve(process.cwd(), 'package.json'), 'utf8'),
        );

        const declared = {
            ...(manifest.dependencies ?? {}),
            ...(manifest.devDependencies ?? {}),
        };

        expect(
            Object.keys(declared),
            `${stylesheet} imports "${name}" but package.json does not declare it. ` +
                'The production build would fail too.',
        ).toContain(name);
    });

    it.each(packages)('has %s actually installed', (name) => {
        const require = createRequire(resolve(process.cwd(), 'package.json'));

        let installed = existsSync(
            resolve(process.cwd(), 'node_modules', name, 'package.json'),
        );

        if (!installed) {
            // Some packages are only reachable through their export map.
            try {
                require.resolve(`${name}/package.json`);
                installed = true;
            } catch {
                installed = false;
            }
        }

        expect(
            installed,
            `"${name}" is declared but not installed. Inside the container this ` +
                'means the node_modules volume predates the dependency: run ' +
                '`make deps`. A rebuild alone does not re-seed a named volume.',
        ).toBe(true);
    });
});
