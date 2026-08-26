<?php

declare(strict_types=1);

use App\Mail\AutomatedMessageMail;
use App\Mail\InternalAlertMail;
use App\Mail\MessageTemplateTestMail;
use App\Mail\TeamInvitationMail;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Models\MessageTemplate;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Support\Branding\TeamLogo;
use App\Support\Mail\BrandedEmail;
use App\Support\Mail\EmailPalette;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;

/**
 * S86 — the base branded email layout (issue #97 · Design System §12).
 *
 * These go through a **real render on a real transport** rather than through
 * `Mail::fake()`. A fake records that a mailable was handed over and never
 * executes its view, so every assertion about the frame — the fallback accent,
 * the embedded logo, the plain-text half — would pass against a layout that
 * throws. `MAIL_MAILER=array` gives the whole MIME message with both parts and
 * every inline attachment on it, which is what the client actually receives.
 */
beforeEach(function (): void {
    /*
     * `TestCase` fakes mail for every test, which is right: nothing should
     * escape a run. A fake records the mailable and never executes its view,
     * though — so here, and only here, the real array transport is put back.
     * It still reaches nobody; it just renders on the way.
     */
    Mail::clearResolvedInstances();
    app()->forgetInstance('mail.manager');
    app()->forgetInstance('mailer');

    Storage::fake(TeamLogo::DISK);

    [$this->team, $this->owner] = $this->teamWithOwner();
    $this->team->forceFill(['name' => 'Ridgeline Realty'])->save();
});

function alertFor(Team $team): Email
{
    Mail::to('someone@example.test')->send(new InternalAlertMail(
        team: $team,
        headline: 'An automated message did not go out',
        detail: 'A message on 12 Oak Lane could not be sent.',
        actionUrl: 'https://example.test/messages/1',
        actionLabel: 'See what happened',
    ));

    $message = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();

    expect($message)->toBeInstanceOf(Email::class);

    return $message;
}

function alertHtml(Team $team): string
{
    return (string) alertFor($team)->getHtmlBody();
}

it('draws every message in one 600px table shell', function (): void {
    $html = alertHtml($this->team);

    expect($html)->toContain('width="600"')
        ->and($html)->toContain('max-width:600px')
        // §12: tables, not flexbox or grid. Neither word may appear at all.
        ->and($html)->not->toContain('display:flex')
        ->and($html)->not->toContain('display:grid')
        // §12: no CSS variables — nothing resolves them in an inbox.
        ->and($html)->not->toContain('var(--')
        ->and($html)->not->toContain('oklch(');
});

it('carries a dark-mode block that can actually override the inline styles', function (): void {
    $html = alertHtml($this->team);

    expect($html)->toContain('prefers-color-scheme: dark')
        ->and($html)->toContain(EmailPalette::DARK_CANVAS)
        ->and($html)->toContain('<meta name="color-scheme" content="light dark">')
        /*
         * The `!important` is the whole point of the block. Every rule it
         * overrides is an inline style, which wins on specificity — so a dark
         * block without it parses cleanly, does nothing, and looks handled.
         */
        ->and($html)->toContain('!important');
});

it('uses the team’s accent as a fill and never as body text', function (): void {
    $this->team->forceFill(['brand_accent_color' => '#7A2E2E'])->save();

    $html = alertHtml($this->team);

    expect($html)->toContain('background-color:#7A2E2E')
        /*
         * Design System §12.1's narrowing of §2.7. A heading in a team's own
         * colour disappears the moment a phone inverts the card behind it, and
         * the darker the brand the worse it gets — so the accent is only ever
         * a ground with a computed foreground on it.
         */
        ->and($html)->toContain('color:'.EmailPalette::TEXT);
});

it('refuses an accent that is not a hex colour, rather than writing it into a style attribute', function (): void {
    /*
     * The value lands in CSS, not in HTML. Blade escapes the quotes, so it
     * cannot break out of the attribute — but it needs no quote at all to
     * rewrite every declaration after it, and `CLAUDE.md`'s rule is that
     * escaping is decided by where a value lands.
     *
     * The team is **not saved**, and that is the test. `brand_accent_color`
     * is `varchar(7)`, so the database refuses a payload this long — which is
     * a real defence and the wrong one to rely on: it is a length, not a
     * grammar, and `red` and `#FFF` fit inside it. The frame has to hold on
     * its own, for the values that fit and for callers with no form in front
     * of them.
     */
    $team = tap($this->team->replicate())->forceFill([
        'id' => $this->team->getKey(),
        'brand_accent_color' => '#fff;font-size:99px',
    ]);

    $brand = BrandedEmail::for($team);

    expect($brand->accent)->toBe(EmailPalette::PRIMARY);
});

it('refuses the short accents that would fit in the column', function (): void {
    foreach (['red', '#FFF', '#12345', 'rgb(1)', ''] as $value) {
        $this->team->forceFill(['brand_accent_color' => $value])->save();

        expect(BrandedEmail::for($this->team)->accent)->toBe(EmailPalette::PRIMARY);
    }
});

it('picks the foreground a reader can actually read on the accent', function (): void {
    /*
     * S72 *warns* an owner about a low-contrast accent and then saves it
     * anyway, which is the right call there — a silently altered colour is an
     * angrier support ticket later. An email has no second chance, and nobody
     * is standing in front of it to notice, so this one adjusts.
     */
    $pale = BrandedEmail::for(tap($this->team)->forceFill(['brand_accent_color' => '#FFE9A8']));
    $deep = BrandedEmail::for(tap($this->team)->forceFill(['brand_accent_color' => '#12314F']));

    expect($pale->accentForeground)->toBe(EmailPalette::TEXT)
        ->and($deep->accentForeground)->toBe('#FFFFFF');
});

it('shows the team’s name when there is no logo', function (): void {
    $html = alertHtml($this->team);

    expect($html)->toContain('Ridgeline Realty')
        ->and($html)->not->toContain('<img');
});

it('embeds the logo rather than linking to it, on a plate that survives dark mode', function (): void {
    Storage::disk(TeamLogo::DISK)->put($path = $this->team->getKey().'/branding/mark.png', 'PNG-BYTES');
    $this->team->forceFill(['logo_path' => $path])->save();

    $message = alertFor($this->team);
    $html = (string) $message->getHtmlBody();

    /*
     * `cid:`, not a URL. The bytes are on a private disk and a client reading
     * this has no session to fetch them with, so an `src` pointing at the
     * application renders as a broken image for the one reader it is for.
     */
    expect($html)->toContain('src="cid:')
        ->and($html)->toContain('alt="Ridgeline Realty"')
        // Design System §2.6: a raster mark cannot answer the theme, so it is
        // given a ground that never changes.
        ->and($html)->toContain('background-color:'.EmailPalette::PLATE);

    $attachments = $message->getAttachments();

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]->getBody())->toBe('PNG-BYTES')
        ->and($attachments[0]->getPreparedHeaders()->toString())->toContain('inline');
});

it('falls back to the wordmark when the logo file has gone', function (): void {
    /*
     * A path pointing at bytes that are not there — a restored database, a
     * bucket lifecycle rule, a half-finished migration. A frame that trusted
     * the column would embed nothing and render a broken-image icon beside
     * the team's name, which is worse than the wordmark it replaced.
     */
    $this->team->forceFill(['logo_path' => $this->team->getKey().'/branding/gone.png'])->save();

    $html = alertHtml($this->team);

    expect($html)->not->toContain('<img')
        ->and($html)->toContain('Ridgeline Realty');
});

it('sends a real plain-text alternative, unescaped', function (): void {
    /*
     * §12: *"a real plain-text alternative for every message, not a
     * stripped-tag afterthought."* And unescaped, because Blade's `{{ }}` is
     * `e()` whatever the content type is — a team called O'Brien & Co arrives
     * on the wire as `O&#039;Brien &amp; Co` in the one half a watch and a
     * screen reader read.
     */
    $this->team->forceFill(['name' => "O'Brien & Co"])->save();

    $text = (string) alertFor($this->team)->getTextBody();

    expect($text)->not->toBe('')
        ->and($text)->toContain("Sent by O'Brien & Co.")
        ->and($text)->not->toContain('&#039;')
        ->and($text)->not->toContain('&amp;')
        ->and($text)->toContain('https://example.test/messages/1');
});

it('renders every mailable in the product without throwing', function (): void {
    /*
     * The gap this closes is the one the file's own header names. Every other
     * test of these three mailables runs under `Mail::fake()`, which records
     * the mailable and **never executes its view** — so a broken Blade
     * expression in any of them passes the whole suite and fails in front of a
     * client. There are four, they all extend one layout now, and one of them
     * cannot be reached without a queue, an approval and a rail.
     *
     * A new mailable belongs in this list, beside its entry in
     * `EmailIndependence::FLOWS`.
     */
    $this->actingAsPerson($this->owner, $this->team);

    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    $instance = ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
    ]);
    $template = MessageTemplate::factory()->create(['team_id' => $this->team->getKey()]);
    $invitation = TeamInvitation::factory()->create(['team_id' => $this->team->getKey()]);

    $mailables = [
        new InternalAlertMail($this->team, 'H', 'D', 'https://example.test/m/1', 'Go'),
        new AutomatedMessageMail($instance, $instance->rendered(), $this->team),
        new AutomatedMessageMail($instance, $instance->rendered(), $this->team, redirected: true),
        new MessageTemplateTestMail($template, $instance->rendered(), $this->team),
        new TeamInvitationMail($invitation, TeamInvitation::newToken()),
    ];

    foreach ($mailables as $mailable) {
        Mail::to('someone@example.test')->send($mailable);
    }

    $sent = Mail::mailer()->getSymfonyTransport()->messages();

    expect($sent)->toHaveCount(count($mailables));

    foreach ($sent as $message) {
        $html = (string) $message->getOriginalMessage()->getHtmlBody();
        $text = (string) $message->getOriginalMessage()->getTextBody();

        // Every one of them wears the same frame, and every one of them has a
        // real plain-text half rather than a stripped-tag afterthought.
        expect($html)->toContain('class="gf-shell"')
            ->and($html)->toContain('prefers-color-scheme: dark')
            ->and(trim($text))->not->toBe('')
            ->and($text)->toContain('Sent by');
    }
});
