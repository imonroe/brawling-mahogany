<?php

declare(strict_types=1);

namespace App\Support\Links;

/**
 * What may be stored in `external_links.url` (issue #61).
 *
 * A URL a team types is rendered as an `href` on S36, and an `href` is not
 * inert. `javascript:alert(1)` is a valid URL by every generic definition and
 * is script execution in the reader's session; `data:text/html,…` is the same
 * attack with a different spelling. Neither is caught by a `url` validation
 * rule, which asks whether a string parses, not whether following it is safe.
 *
 * So the allowlist is the rule: **http and https, and nothing else.** Not a
 * denylist of the two schemes known to be dangerous — a denylist has to
 * predict the next one, and `vbscript:` was the last time somebody tried.
 *
 * Checked in two places on purpose, and that is not redundancy. The form
 * request is where somebody gets a sentence they can act on; `ExternalLink`'s
 * own saving guard is what holds when the next caller is a seeder, an import,
 * or the deal-side screen in #62. The lesson is #143's: a rule that lives only
 * where the current screen calls it is a rule the next screen is written
 * without.
 */
final class SafeUrl
{
    /** @var list<string> */
    public const SCHEMES = ['http', 'https'];

    /**
     * Whether this string may be stored and rendered as a link.
     *
     * Deliberately strict about the shape as well as the scheme: a value with
     * no host is not a link anybody can follow, and `http:///x` parses.
     */
    public static function permits(?string $url): bool
    {
        $url = trim((string) $url);

        if ($url === '') {
            return false;
        }

        /*
         * Control characters first, before anything parses.
         *
         * `java\nscript:` survives `parse_url` as a relative path and is
         * treated as `javascript:` by more than one browser once the newline
         * is stripped. A URL has no legitimate reason to contain one.
         */
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($scheme) || ! is_string($host) || $host === '') {
            return false;
        }

        return in_array(mb_strtolower($scheme), self::SCHEMES, true);
    }

    /**
     * The value to store, which is the value that was judged.
     *
     * `permits()` trims before it looks, so storing the untrimmed string means
     * the guard and the column disagree about what the URL is. Harmless over
     * HTTP, where `TrimStrings` has already run — and `TrimStrings` is exactly
     * the mechanism the seeder, an import, and #62's screen do not go through,
     * which is the whole reason this class is not only a request rule.
     */
    public static function normalise(mixed $url): string
    {
        return trim((string) (is_scalar($url) ? $url : ''));
    }

    /** The sentence somebody gets when it does not. */
    public static function message(): string
    {
        return 'A link has to start with http:// or https://.';
    }
}
