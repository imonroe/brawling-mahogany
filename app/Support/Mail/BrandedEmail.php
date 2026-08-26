<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\Team;
use App\Support\Branding\AccentContrast;
use App\Support\Branding\TeamLogo;

/**
 * One team's branding, resolved for one email (S86 · Design System §2.7, §12).
 *
 * §2.7: team branding applies to client-facing surfaces only, and §12.1
 * narrows it further — *"team branding overrides the primary and the logo
 * only. Everything else stays fixed, so no tenant can accidentally produce an
 * unreadable email."* This class is that sentence made true rather than
 * promised: two things come from the team, both are validated, and everything
 * else the layout draws comes from {@see EmailPalette}.
 *
 * ## The accent is a background, never text — and that is narrower than §2.7
 *
 * §2.7 gives the accent to headings, markers and links. In the app that is
 * safe: the app is light-mode in v1, and the ground under a heading is a
 * colour the product chose. An email has neither guarantee. A reader in dark
 * mode gets `#1A588F` on near-black, and the darker a team's brand, the less
 * of their own heading they can read — a team is most likely to pick a deep
 * colour precisely because it looks right on white.
 *
 * So on this surface the accent only ever appears **as a fill with a computed
 * foreground on it**: the header band and the call-to-action button. Both
 * carry their own ground with them, so neither depends on what the client
 * inverted. Headings and body text take the palette. Design System §12 records
 * the narrowing.
 *
 * ## Which is also why the foreground is computed rather than white
 *
 * `AccentContrast::warningFor()` warns a team owner on S72 that white text on
 * their accent is illegible, and warning is right there: PRD §9 holds the
 * status page to AA and a silently altered colour is an angrier support ticket
 * later. But a warning is advice, and S72 takes the colour anyway — so a team
 * that clicked past it has an accent this class must still put a legible
 * foreground on. §2.7 asks for exactly that (`--brand-foreground: computed for
 * contrast`), and an email has no second chance to be adjusted.
 *
 * The two are not in tension: the owner is told, on the screen, that the
 * *status page* heading will be hard to read, and the email — where nobody is
 * standing to notice — picks the readable one of black and white.
 */
final readonly class BrandedEmail
{
    private function __construct(
        public string $teamName,
        public string $accent,
        public string $accentForeground,
        public ?string $logoBytes,
        public ?string $logoMimeType,
    ) {}

    public static function for(Team $team, ?TeamLogo $logos = null): self
    {
        $logos ??= new TeamLogo;

        $accent = self::accentFor($team);

        $bytes = $logos->contents($team);
        $mime = $bytes === null ? null : $logos->mimeType($team);

        return new self(
            teamName: $team->name,
            accent: $accent,
            accentForeground: self::foregroundFor($accent),
            /*
             * Both or neither. A mime type this class could not name is bytes
             * whose `Content-Type` would be a guess, and an email that embeds
             * a part it cannot label is one a client renders as a broken icon
             * beside the team's name — worse than the wordmark it replaced.
             */
            logoBytes: $mime === null ? null : $bytes,
            logoMimeType: $mime,
        );
    }

    /**
     * The product's own frame, for a message with no team behind it.
     *
     * Not every email this product sends belongs to a tenant — a platform
     * operator's alerts will not — and a layout that required a `Team` would
     * push each of those into hand-rolled HTML, which is how three
     * inconsistent frames existed before this one.
     */
    public static function product(): self
    {
        return new self(
            teamName: (string) config('app.name'),
            accent: EmailPalette::PRIMARY,
            accentForeground: self::foregroundFor(EmailPalette::PRIMARY),
            logoBytes: null,
            logoMimeType: null,
        );
    }

    public function hasLogo(): bool
    {
        return $this->logoBytes !== null && $this->logoMimeType !== null;
    }

    /**
     * A team's accent, or the product blue when there isn't a usable one.
     *
     * The regex is not belt-and-braces over S72's own `regex:/^#[0-9A-Fa-f]{6}$/`.
     * This value lands in a `style` attribute in HTML that is **not escaped
     * the way an attribute needs** — `{{ }}` escapes quotes, so a value could
     * not break out, but `#fff;color:red` needs no quote at all to rewrite
     * every declaration after it. `CLAUDE.md`: escaping is decided by where a
     * value lands, and this one lands in CSS. A column is also not the only
     * door — a seeder, a console tinker, an import — and the layout has no way
     * to ask how a value got there.
     */
    private static function accentFor(Team $team): string
    {
        $accent = $team->brand_accent_color;

        return is_string($accent) && AccentContrast::isHex($accent)
            ? mb_strtoupper($accent)
            : EmailPalette::PRIMARY;
    }

    /**
     * Black or white on the accent, whichever a reader can actually read.
     *
     * WCAG's own ratio, through the class S72 already warns with, so the
     * threshold that decides the email is the threshold the owner was shown.
     */
    private static function foregroundFor(string $accent): string
    {
        $onWhite = AccentContrast::ratio(AccentContrast::FOREGROUND, $accent);
        $onBlack = AccentContrast::ratio(EmailPalette::TEXT, $accent);

        return $onWhite >= $onBlack ? AccentContrast::FOREGROUND : EmailPalette::TEXT;
    }
}
