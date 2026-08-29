<?php

declare(strict_types=1);

namespace App\Support\Branding;

/**
 * Whether a team's accent colour is legible (Design System §15.6, PRD §9).
 *
 * The client status page is held to WCAG 2.1 AA, and an accent chosen for a
 * logo is not always an accent you can put white text on. Design System §15.6
 * left the choice open — warn, or silently adjust — and S72 settles it as
 * **warn**, with a preview of the failing combination.
 *
 * The ratio is WCAG 2.1's own: (L1 + 0.05) / (L2 + 0.05) over relative
 * luminance. 4.5:1 is the AA threshold for body text.
 */
final class AccentContrast
{
    public const MINIMUM_RATIO = 4.5;

    /**
     * The text the accent actually carries.
     *
     * Design System §2.7 puts the team's accent behind `--brand-foreground`,
     * which resolves to `--primary-foreground` — a near-white. Checking the
     * accent against *that* is the honest question, because it is the pairing
     * a client's eye meets.
     *
     * Asking instead whether the accent works against white **or** black
     * would pass almost every colour, since almost every colour is legible
     * under one of them. A warning that never fires is not a warning.
     */
    public const FOREGROUND = '#FFFFFF';

    /**
     * Black or white on this accent, whichever a reader can actually read.
     *
     * ## Warned on one surface, computed on two
     *
     * Design System §15.6 settles the warn-versus-adjust question by surface,
     * and the deciding fact is *whether anybody is standing there*. S72 warns,
     * because the owner is looking at a preview and can pick again — and a
     * silently altered brand is an angrier support ticket later. **Email
     * computes**, because there is no second chance and nobody to notice.
     *
     * The client status page is the second surface with nobody standing there:
     * a client reads it once, on a phone, and a heading they cannot read is a
     * phone call to the agent — which is the outcome the whole surface exists
     * to reduce. §15.6 says it *"inherits S72's answer"*, and it does, for the
     * **colour**: the accent is the one the owner chose and was warned about.
     * What is computed is only what sits *on* it.
     *
     * Promoted out of `BrandedEmail` when the status page became its second
     * caller. Two copies of a contrast rule disagree the first time one of
     * them is tuned.
     *
     * @param  string  $dark  the near-black this surface uses for text — the
     *                        email palette's and the app's are not the same
     *                        value, and each surface passes its own rather
     *                        than this class inventing a third
     */
    public static function foregroundFor(string $accent, string $dark): string
    {
        $onLight = self::ratio(self::FOREGROUND, $accent);
        $onDark = self::ratio($dark, $accent);

        return $onLight >= $onDark ? self::FOREGROUND : $dark;
    }

    /**
     * A sentence to show the owner, or null when the colour is fine.
     *
     * IA §10: say what happened, then what to do.
     */
    public static function warningFor(?string $hex): ?string
    {
        if ($hex === null || ! self::isHex($hex)) {
            return null;
        }

        $ratio = self::ratio($hex, self::FOREGROUND);

        if ($ratio >= self::MINIMUM_RATIO) {
            return null;
        }

        return sprintf(
            'White text on this accent reaches only %.1f:1, below the %.1f:1 your clients’ status page '.
            'is held to. Pick a darker shade, or the heading on their page will be hard to read.',
            $ratio,
            self::MINIMUM_RATIO,
        );
    }

    public static function ratio(string $foreground, string $background): float
    {
        $first = self::luminance($foreground);
        $second = self::luminance($background);

        [$lighter, $darker] = $first >= $second ? [$first, $second] : [$second, $first];

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * `D`, because PCRE's `$` matches before a trailing newline.
     *
     * Without it `"#123456\n"` is a valid hex colour as far as this is
     * concerned. Harmless in a CSS declaration, where a newline is
     * whitespace — and `BrandedEmail` leans on this being *the* thing that
     * decides whether a tenant's string reaches a `style` attribute, so it
     * should be anchored rather than nearly anchored.
     */
    public static function isHex(string $value): bool
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/D', $value) === 1;
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');

        $channels = array_map(
            static function (string $pair): float {
                $value = hexdec($pair) / 255;

                return $value <= 0.03928
                    ? $value / 12.92
                    : (($value + 0.055) / 1.055) ** 2.4;
            },
            [substr($hex, 0, 2), substr($hex, 2, 2), substr($hex, 4, 2)],
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
