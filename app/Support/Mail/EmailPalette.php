<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * Design System §12.1's email palette, as literal hex (S86 · issue #97).
 *
 * §12 opens with *"a separate universe"*, and this class is the smallest part
 * of that: the app palette is `oklch()` behind CSS variables, and neither
 * survives an email client. Outlook's word-rendered HTML has no custom
 * properties and no `oklch()`, so an email carries **literal, duplicated**
 * hex — which is a copy, which will drift.
 *
 * So it drifts in one file, and `tests/Unit/EmailPaletteTest.php` reads the
 * §12.1 table out of `docs/Design System.md` and fails when a value here is
 * not the value there. Same treatment as the enums the PRD tables hold.
 *
 * ## The dark column is new, and it is not the app's `.dark` block
 *
 * §2.6 defers dark mode to after v1 *for the app*, where the product controls
 * when the theme applies. Email does not get that choice: iOS Mail and
 * Outlook.com invert a message the day the reader turns dark mode on, whether
 * or not anybody designed for it. §12 already required it (*"where supported,
 * and it must degrade gracefully where not"*) and §12.1 had no values to do it
 * with; these are those values, authored by §2.6's own rule — invert lightness,
 * lift chroma so a state colour survives on a dark ground.
 *
 * ## `PLATE` is `--logo-plate`, one universe over
 *
 * §2.6: *"a raster asset cannot participate in the token layer"*. A team's
 * logo is a PNG with a fixed idea of what is behind it, and a client reading
 * in dark mode gets it on near-black — the exact failure §2.6 names, and the
 * one the issue calls out: *"a logo on a white background becomes a white box
 * on black"*. The answer is the same one `AppLogoIcon` uses: a plate that
 * stays light in both schemes, so the mark always sits on what it was drawn
 * for.
 */
final class EmailPalette
{
    public const PRIMARY = '#1A588F';

    public const TEXT = '#0A0E11';

    public const MUTED_TEXT = '#636A71';

    public const BORDER = '#DFE1E4';

    public const BACKGROUND = '#FFFFFF';

    public const PANEL = '#EFF2F5';

    public const SUCCESS = '#137738';

    public const WARNING = '#905D00';

    public const DANGER = '#C22826';

    /** The page behind the card, which is never the card's own white. */
    public const CANVAS = '#F4F6F8';

    public const DARK_PRIMARY = '#7FB1DC';

    public const DARK_TEXT = '#E7EBEF';

    public const DARK_MUTED_TEXT = '#9BA4AD';

    public const DARK_BORDER = '#333C45';

    public const DARK_BACKGROUND = '#171C21';

    public const DARK_PANEL = '#1F262D';

    public const DARK_SUCCESS = '#5FC383';

    public const DARK_WARNING = '#DCA33F';

    public const DARK_DANGER = '#EE8B88';

    public const DARK_CANVAS = '#0E1216';

    /**
     * The ground a logo is always drawn on, in both schemes.
     *
     * Not `BACKGROUND`, even though they are the same value today: they answer
     * different questions, and the dark block moves one of them and not the
     * other.
     */
    public const PLATE = '#FFFFFF';
}
