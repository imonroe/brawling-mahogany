/**
 * Just enough colour maths to hold the design system to its own contrast
 * requirements: oklch → sRGB → WCAG relative luminance.
 *
 * Design System §11 requires 4.5:1 for body text and 3:1 for large text and
 * UI boundaries, and §2.3's badge pairs carry 11px and 12px text — so they
 * are held to the 4.5:1 line.
 */

export type Rgb = [number, number, number];

export function parseOklch(value: string): [number, number, number] {
    const match = value
        .trim()
        .match(/^oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*(?:\/\s*[\d.]+\s*)?\)$/);

    if (!match) {
        throw new Error(`Not an oklch value: ${value}`);
    }

    return [Number(match[1]), Number(match[2]), Number(match[3])];
}

/** Linear-light sRGB, clamped to gamut. */
export function oklchToLinearRgb(L: number, C: number, h: number): Rgb {
    const hr = (h * Math.PI) / 180;
    const a = C * Math.cos(hr);
    const b = C * Math.sin(hr);

    const l = (L + 0.3963377774 * a + 0.2158037573 * b) ** 3;
    const m = (L - 0.1055613458 * a - 0.0638541728 * b) ** 3;
    const s = (L - 0.0894841775 * a - 1.291485548 * b) ** 3;

    const rgb: Rgb = [
        4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
        -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
        -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
    ];

    return rgb.map((channel) => Math.min(1, Math.max(0, channel))) as Rgb;
}

export function relativeLuminance([r, g, b]: Rgb): number {
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrastRatio(a: Rgb, b: Rgb): number {
    const [lighter, darker] = [relativeLuminance(a), relativeLuminance(b)].sort((x, y) => y - x);

    return (lighter + 0.05) / (darker + 0.05);
}

export function contrastOfOklch(foreground: string, background: string): number {
    return contrastRatio(
        oklchToLinearRgb(...parseOklch(foreground)),
        oklchToLinearRgb(...parseOklch(background)),
    );
}
