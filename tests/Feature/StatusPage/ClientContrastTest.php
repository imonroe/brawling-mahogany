<?php

declare(strict_types=1);

use App\Enums\ParticipantRole;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\TeamMembership;
use App\Support\Branding\AccentContrast;
use App\Support\StatusPage\IssueStatusPageLink;
use Inertia\Testing\AssertableInertia;

/**
 * The contrast half of #112's audit (PRD §9 · Design System §15.6).
 *
 * ## Why this is here and not in the axe run
 *
 * `tests/js/clientSurfaceAccessibility.test.ts` runs axe over the client
 * pages, and it disables the colour-contrast rule — jsdom computes no layout
 * and resolves no stylesheet, so axe cannot see a colour to check, and leaving
 * the rule on returns `incomplete`, which reads as a clean run.
 *
 * The question is decided on the server anyway. §15.6 settles
 * warn-versus-adjust **by surface**, and the deciding fact is whether anybody
 * is standing there: S72 warns, because the owner is looking at a preview and
 * can pick again, and a silently altered brand is an angrier support ticket.
 * Email and the client page compute, because there is no second chance and
 * nobody to notice.
 *
 * #112's definition of done is both halves at once: *"a deliberately
 * low-contrast team accent triggers the warning **and still renders
 * legibly**."*
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $this->client = TeamMembership::factory()->create([
        'team_id' => $this->team->getKey(),
        'email' => 'dana@example.test',
    ]);

    DealParticipant::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'team_membership_id' => $this->client->getKey(),
        'participant_role' => ParticipantRole::Seller,
    ]);
});

/** The branding the client's own page would render, for a given accent. */
function brandingFor(?string $accent): array
{
    if ($accent !== null) {
        test()->team->forceFill(['brand_accent_color' => $accent])->save();
        test()->withTeam(test()->team->refresh());
    }

    $issued = app(IssueStatusPageLink::class)->issue(test()->deal, test()->client);

    auth()->logout();

    $session = (string) str(
        test()->get('/s/'.$issued->token)->headers->get('Location'),
    )->afterLast('/s/');

    $branding = [];

    test()->get("/s/{$session}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$branding): void {
            $branding = $page->toArray()['props']['team'];
        });

    return $branding;
}

it('warns the owner about an accent a client could not read', function (): void {
    /*
     * A pale yellow. White on it is around 1.4:1 — nowhere near AA — and S72
     * is where somebody is standing to be told.
     */
    $warning = AccentContrast::warningFor('#F5E663');

    expect($warning)->not->toBeNull()
        // IA §10: say what happened, then what to do.
        ->and($warning)->toContain('Pick a darker shade');

    expect(AccentContrast::warningFor('#1A588F'))->toBeNull();
});

it('still renders that accent legibly on the client’s page', function (): void {
    $branding = brandingFor('#F5E663');

    /*
     * The other half, and the one a warning alone does not deliver. The owner
     * was told and chose to keep their colour — §15.6 lets them — so the page
     * keeps the accent and computes what sits on it.
     */
    expect($branding['accent'])->toBe('#F5E663')
        ->and($branding['accentForeground'])->not->toBeNull();

    $ratio = AccentContrast::ratio($branding['accentForeground'], $branding['accent']);

    expect($ratio)->toBeGreaterThanOrEqual(AccentContrast::MINIMUM_RATIO);
});

it('picks white on a dark accent and near-black on a light one', function (): void {
    // A deep brand, where white is the readable answer.
    expect(brandingFor('#123A5C')['accentForeground'])->toBe('#FFFFFF');

    // A pale one, where it is not.
    expect(brandingFor('#F5E663')['accentForeground'])->not->toBe('#FFFFFF');
});

it('meets AA on every accent the owner was not warned about', function (): void {
    /*
     * Not a sample of nice colours: the corners and the middles of the sRGB
     * cube, which is where a black-or-white rule is closest to the line. A
     * rule that only ever returned white would pass a test built from dark
     * brand colours and fail on the first team that picked lime.
     *
     * The claim is bounded, and the bound is the honest part. **Any accent
     * S72 did not warn about reaches AA on the page** — because S72's own
     * threshold is white against the accent, so passing it means white passes.
     * The band where *neither* white nor near-black reaches 4.5:1 exists and
     * is narrow (mid greys around #777777 are the whole of it), and every
     * colour in it fails S72's check, which means the owner was told before
     * they kept it. §15.6 is explicit that keeping it is their call.
     */
    $accents = [
        '#000000', '#FFFFFF', '#FF0000', '#00FF00', '#0000FF',
        '#FFFF00', '#00FFFF', '#FF00FF', '#808080', '#7F7F00',
        '#767676', '#777777', '#1A588F', '#F5E663',
    ];

    foreach ($accents as $accent) {
        $ratio = AccentContrast::ratio(
            AccentContrast::foregroundFor($accent, '#0A0E11'),
            $accent,
        );

        if (AccentContrast::warningFor($accent) === null) {
            expect($ratio)->toBeGreaterThanOrEqual(
                AccentContrast::MINIMUM_RATIO,
                "An accent nobody was warned about must be readable: {$accent}.",
            );

            continue;
        }

        /*
         * And in the band, it takes the **better** of the two rather than
         * defaulting — which is the whole of what a computed foreground can
         * do once the owner has been warned and kept their colour. Never
         * below AA's large-text and UI-boundary line, which is where the worst
         * case actually sits.
         */
        expect($ratio)->toBeGreaterThanOrEqual(
            3.0,
            "Even a warned accent must reach AA's 3:1 UI line: {$accent}.",
        );

        expect($ratio)->toBe(
            max(
                AccentContrast::ratio(AccentContrast::FOREGROUND, $accent),
                AccentContrast::ratio('#0A0E11', $accent),
            ),
            "The better of white and near-black must win on {$accent}.",
        );
    }
});

it('falls back to no accent rather than to something unreadable', function (): void {
    /*
     * A stored value that is the right *shape* and not a hex — `#ZZZZZZ` from
     * a seeder, a console tinker, or an import. (The column is `varchar(7)`,
     * so anything longer never gets that far, which is a second layer rather
     * than the one being tested here.)
     * The layout has no way to ask how a value got there, so an unusable one
     * means the surface renders in the product's own tokens rather than with a
     * `--brand` that could be anything at all. `BrandedEmail` records the same
     * argument about CSS injection: this value lands in a `style` attribute.
     */
    $branding = brandingFor('#ZZZZZZ');

    expect($branding['accent'])->toBeNull()
        ->and($branding['accentForeground'])->toBeNull();
});
