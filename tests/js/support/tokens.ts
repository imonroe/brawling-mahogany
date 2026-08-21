import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Reads the token blocks out of resources/css/app.css.
 *
 * The stylesheet is the source of truth; these tests exist so that editing it
 * carelessly fails the build rather than the eye.
 */
function css(): string {
    return readFileSync(resolve(process.cwd(), 'resources/css/app.css'), 'utf8');
}

function block(selector: string): string {
    const source = css();
    const start = source.indexOf(`\n${selector} {`);

    if (start === -1) {
        throw new Error(`No ${selector} block in app.css`);
    }

    const end = source.indexOf('\n}', start);

    return source.slice(start, end);
}

export function tokens(theme: 'light' | 'dark'): Record<string, string> {
    const declarations = block(theme === 'light' ? ':root' : '.dark');
    const found: Record<string, string> = {};

    for (const match of declarations.matchAll(/(--[a-z0-9-]+):\s*([^;]+);/g)) {
        found[match[1]] = match[2].trim();
    }

    return found;
}

export const TONES = ['neutral', 'info', 'success', 'warning', 'danger'] as const;
