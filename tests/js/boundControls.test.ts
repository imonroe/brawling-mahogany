/**
 * Every `AppSelect` is written to by something.
 *
 * `AppSelect` is props-and-emit rather than `defineModel`, so `:model-value`
 * alone renders a control that displays state and can never change it. There
 * is no type error and no runtime warning — the select opens, the option
 * highlights, and nothing happens.
 *
 * S28's pack filter shipped exactly that: `:model-value="pack"` with no
 * handler, so the `watch(pack, load)` beside it never fired and the filter was
 * decorative. Its own feature test passed, because that test called the
 * endpoint directly.
 *
 * This is the same shape as `SingleMutationPathTest` in the PHP suite: a rule
 * that review walked past is cheaper to hold by reading the source than by
 * remembering.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/** The same recursive walk `tokenDiscipline.test.ts` uses. */
function vueFiles(directory: string): string[] {
    const absolute = resolve(process.cwd(), directory);

    return readdirSync(absolute).flatMap((entry) => {
        const path = join(absolute, entry);

        if (statSync(path).isDirectory()) {
            return vueFiles(join(directory, entry));
        }

        return entry.endsWith('.vue') ? [join(directory, entry)] : [];
    });
}

/**
 * Every `<AppSelect ...>` opening tag, attributes included.
 *
 * Scanned rather than matched, because attribute values contain `>`:
 * `v-if="packs.length > 0"` ends a `[^>]*` match halfway through the tag, and
 * the half it keeps is the half without the handler. That is a test that
 * reports the bug it is looking for whether or not the bug is there.
 */
function selectTags(source: string): string[] {
    const tags: string[] = [];

    for (const match of source.matchAll(/<AppSelect\b/g)) {
        let quote: string | null = null;

        for (let i = match.index; i < source.length; i++) {
            const character = source[i];

            if (quote !== null) {
                if (character === quote) {
                    quote = null;
                }

                continue;
            }

            if (character === '"' || character === "'") {
                quote = character;

                continue;
            }

            if (character === '>') {
                tags.push(source.slice(match.index, i + 1));

                break;
            }
        }
    }

    return tags;
}

describe('bound controls', () => {
    it('never renders an AppSelect nothing can write to', () => {
        const files = vueFiles('resources/js');

        expect(files.length).toBeGreaterThan(0);

        const unbound: string[] = [];

        for (const file of files) {
            // The component's own definition is where the props are declared.
            if (file.endsWith(join('components', 'app', 'AppSelect.vue'))) {
                continue;
            }

            const source = readFileSync(resolve(process.cwd(), file), 'utf8');

            for (const tag of selectTags(source)) {
                const writes =
                    tag.includes('v-model') ||
                    tag.includes('@update:model-value') ||
                    tag.includes('@update:modelValue');

                if (!writes) {
                    unbound.push(`${file}: ${tag.replace(/\s+/g, ' ')}`);
                }
            }
        }

        expect(
            unbound,
            [
                'An AppSelect was given a value and no way to change it.',
                'It is props-and-emit, not defineModel, so `:model-value` alone is',
                'a control that cannot be used. Add `@update:model-value`.',
                ...unbound,
            ].join('\n'),
        ).toEqual([]);
    });
});
