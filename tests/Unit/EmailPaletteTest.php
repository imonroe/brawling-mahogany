<?php

declare(strict_types=1);

use App\Support\Mail\EmailPalette;

/**
 * Design System §12.1's table, held against the class that copies it (#97).
 *
 * §12 requires literal hex in email, because no email client resolves a CSS
 * variable and Outlook's word renderer does not know what `oklch()` is. So the
 * email palette is a **copy** of the app palette, and a copy with nothing
 * checking it drifts — the same reason the enums are read against the PRD's
 * tables rather than trusted.
 *
 * The document is the authority. When one of these fails, the fix is the
 * constant or the table, never this test.
 */
function paletteTable(): array
{
    $document = file_get_contents(base_path('docs/Design System.md'));

    expect($document)->toBeString();

    $section = mb_strstr((string) $document, '### 12.1 Email palette');

    expect($section)->toBeString();

    $rows = [];

    preg_match_all(
        '/^\|\s*([A-Za-z ]+?)\s*\|\s*`(#[0-9A-Fa-f]{6})`\s*\|\s*`(#[0-9A-Fa-f]{6})`\s*\|/m',
        mb_strstr((string) $section, '## 13.', true) ?: (string) $section,
        $matches,
        PREG_SET_ORDER,
    );

    foreach ($matches as $match) {
        $rows[$match[1]] = ['light' => mb_strtoupper($match[2]), 'dark' => mb_strtoupper($match[3])];
    }

    return $rows;
}

it('reads every row of the documented email palette', function (): void {
    /*
     * A regex that matched nothing would make every assertion below pass
     * vacuously, which is the failure mode of every document-reading test.
     * Eleven is the table as §12.1 stands; a row added there fails here until
     * the constant exists, which is the point.
     */
    expect(paletteTable())->toHaveCount(11);
});

it('carries the light values Design System §12.1 documents', function (): void {
    $table = paletteTable();

    expect(EmailPalette::PRIMARY)->toBe($table['Primary']['light'])
        ->and(EmailPalette::TEXT)->toBe($table['Text']['light'])
        ->and(EmailPalette::MUTED_TEXT)->toBe($table['Muted text']['light'])
        ->and(EmailPalette::BORDER)->toBe($table['Border']['light'])
        ->and(EmailPalette::BACKGROUND)->toBe($table['Background']['light'])
        ->and(EmailPalette::PANEL)->toBe($table['Panel']['light'])
        ->and(EmailPalette::SUCCESS)->toBe($table['Success']['light'])
        ->and(EmailPalette::WARNING)->toBe($table['Warning']['light'])
        ->and(EmailPalette::DANGER)->toBe($table['Danger']['light'])
        ->and(EmailPalette::CANVAS)->toBe($table['Canvas']['light'])
        ->and(EmailPalette::PLATE)->toBe($table['Plate']['light']);
});

it('carries the dark values Design System §12.1 documents', function (): void {
    $table = paletteTable();

    expect(EmailPalette::DARK_PRIMARY)->toBe($table['Primary']['dark'])
        ->and(EmailPalette::DARK_TEXT)->toBe($table['Text']['dark'])
        ->and(EmailPalette::DARK_MUTED_TEXT)->toBe($table['Muted text']['dark'])
        ->and(EmailPalette::DARK_BORDER)->toBe($table['Border']['dark'])
        ->and(EmailPalette::DARK_BACKGROUND)->toBe($table['Background']['dark'])
        ->and(EmailPalette::DARK_PANEL)->toBe($table['Panel']['dark'])
        ->and(EmailPalette::DARK_SUCCESS)->toBe($table['Success']['dark'])
        ->and(EmailPalette::DARK_WARNING)->toBe($table['Warning']['dark'])
        ->and(EmailPalette::DARK_DANGER)->toBe($table['Danger']['dark'])
        ->and(EmailPalette::DARK_CANVAS)->toBe($table['Canvas']['dark']);
});

it('keeps every dark value legible against its own dark ground', function (): void {
    /*
     * §2.6's rule is *invert lightness, lift chroma so a state colour survives
     * on a dark ground* — a sentence that is easy to write and easy to author
     * past. These are the pairings a reader in dark mode actually meets, at
     * WCAG's 4.5:1 for body text and 3:1 for the large heading weight the
     * primary is used at.
     */
    $ground = EmailPalette::DARK_BACKGROUND;

    expect(App\Support\Branding\AccentContrast::ratio(EmailPalette::DARK_TEXT, $ground))->toBeGreaterThan(4.5)
        ->and(App\Support\Branding\AccentContrast::ratio(EmailPalette::DARK_MUTED_TEXT, $ground))->toBeGreaterThan(4.5)
        ->and(App\Support\Branding\AccentContrast::ratio(EmailPalette::DARK_SUCCESS, EmailPalette::DARK_PANEL))->toBeGreaterThan(4.5)
        ->and(App\Support\Branding\AccentContrast::ratio(EmailPalette::DARK_WARNING, EmailPalette::DARK_PANEL))->toBeGreaterThan(4.5)
        ->and(App\Support\Branding\AccentContrast::ratio(EmailPalette::DARK_DANGER, EmailPalette::DARK_PANEL))->toBeGreaterThan(4.5)
        ->and(App\Support\Branding\AccentContrast::ratio(EmailPalette::DARK_PRIMARY, $ground))->toBeGreaterThan(3.0);
});

it('keeps every light value legible against its own light ground', function (): void {
    expect(App\Support\Branding\AccentContrast::ratio(EmailPalette::TEXT, EmailPalette::BACKGROUND))->toBeGreaterThan(4.5)
        ->and(App\Support\Branding\AccentContrast::ratio(EmailPalette::MUTED_TEXT, EmailPalette::BACKGROUND))->toBeGreaterThan(4.5)
        ->and(App\Support\Branding\AccentContrast::ratio(EmailPalette::SUCCESS, EmailPalette::PANEL))->toBeGreaterThan(4.5)
        ->and(App\Support\Branding\AccentContrast::ratio(EmailPalette::WARNING, EmailPalette::PANEL))->toBeGreaterThan(4.5)
        ->and(App\Support\Branding\AccentContrast::ratio(EmailPalette::DANGER, EmailPalette::PANEL))->toBeGreaterThan(4.5);
});
