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
     * A sentence to show the owner, or null when the colour is fine.
     *
     * IA §10: say what happened, then what to do.
     */
    public static function warningFor(?string $hex): ?string
    {
        if ($hex === null || ! self::isHex($hex)) {
            return null;
        }

        $onWhite = self::ratio($hex, '#FFFFFF');
        $onBlack = self::ratio($hex, '#000000');
        $best = max($onWhite, $onBlack);

        if ($best >= self::MINIMUM_RATIO) {
            return null;
        }

        return sprintf(
            'This accent reaches %.1f:1 against both white and black, below the %.1f:1 your clients’ '.
            'status page is held to. Pick something darker or lighter, or text on it will be hard to read.',
            $best,
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

    public static function isHex(string $value): bool
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
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
