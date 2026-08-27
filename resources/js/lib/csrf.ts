/**
 * Laravel's CSRF token, from the cookie it sets rather than from a meta tag.
 *
 * This application renders no `csrf-token` meta — Inertia's client reads the
 * `XSRF-TOKEN` cookie and sends it back as `X-XSRF-TOKEN`, and the value is
 * URL-encoded in the cookie. Adding a meta tag for one `fetch` would be a
 * second source for the same secret, and the two would drift the first time
 * somebody rotated how it is issued.
 *
 * ## Why this is its own file
 *
 * It was private to `lib/pwa.ts`, where the push re-post needed it. S18's
 * cascade preview (#106) is the second caller and it is nothing to do with the
 * service worker, so importing it from there would have made every screen that
 * writes outside Inertia depend on the PWA module. One secret, one reader.
 */
export function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match?.[1] ? decodeURIComponent(match[1]) : null;
}

/**
 * The headers a non-Inertia write needs.
 *
 * `X-Requested-With` is not decoration: Laravel's exception handler uses it to
 * decide whether to answer a failed authorisation with JSON or with a redirect
 * to a login page, and a `fetch` that got the redirect would parse an HTML
 * document as its result.
 */
export function writeHeaders(): Record<string, string> {
    const token = xsrfToken();

    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token === null ? {} : { 'X-XSRF-TOKEN': token }),
    };
}
