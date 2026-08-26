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
use App\Support\Mail\SendingIdentity;
use App\Support\Tenancy\TeamContext;
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
         *
         * The **absence** is the half the test name claims, and the first
         * version of this asserted only the two presences — which would have
         * passed just as happily against a layout that also wrote the accent
         * into every heading.
         */
        ->and($html)->toContain('color:'.EmailPalette::TEXT);

    /*
     * A plain `color:` declaration, which `str_contains` cannot ask for:
     * `background-color:#7A2E2E` contains the substring `color:#7A2E2E`, so
     * the naive assertion fails on the correct output. The lookbehind is what
     * separates the fill from the text.
     */
    expect(preg_match('/(?<![-\w])color:#7A2E2E/i', $html))->toBe(0);
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

    /*
     * And the list is **enumerated**, not remembered.
     *
     * A comment saying "add yours here" is a comment; this is what makes it
     * true. `EmailIndependenceTest` already reads `app/Mail` to hold every
     * mailable to an ADR 0003 second door, and the same argument applies to
     * rendering: a mailable added without a render here is a broken view that
     * the whole suite passes over, because `Mail::fake()` never executes one.
     */
    $covered = array_unique(array_map(static fn (object $mailable): string => $mailable::class, $mailables));
    $declared = array_map(
        static fn (string $file): string => 'App\\Mail\\'.basename($file, '.php'),
        glob(app_path('Mail/*.php')) ?: [],
    );

    expect(array_values(array_diff($declared, $covered)))->toBe([]);

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

it('puts the team in the inbox line and the verified identity in the address', function (): void {
    /*
     * PRD §8.5's *"per-team sending identity"*, across the slots that can
     * carry it. The **address** cannot move: it is the one identity SES is
     * authorised to send as, and a From the message is not DKIM-aligned with
     * fails DMARC and lands in spam — the same failure as not sending, except
     * nobody finds out. (Not SPF, which is evaluated against the envelope MAIL
     * FROM rather than this header.) The **display name** is what a person
     * reads, and there the right answer is the agency they have been working
     * with rather than a product they have never heard of.
     */
    $this->team->forceFill([
        'name' => 'Bosart Group',
        'sending_identity_email' => 'emily@bosart.test',
    ])->save();

    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    $instance = ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
    ]);

    Mail::to('client@example.test')->send(
        new AutomatedMessageMail($instance, $instance->rendered(), $this->team),
    );

    $from = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getFrom()[0];

    expect($from->getAddress())->toBe(config()->string('mail.from.address'))
        ->and($from->getName())->toBe('Bosart Group via Goldieflow');
});

it('never signs a client’s email with the pre-rename codename', function (): void {
    /*
     * The literal above rather than `config('app.product_name')` is the whole
     * point of this test, and round 2 of review is why. Both display-name
     * assertions read the config they were testing, so they were true of any
     * value — including `APP_NAME`'s `Brawling Mahogany`, which is what teams
     * were actually sending to their sellers.
     *
     * `APP_NAME` stays the codename deliberately: it is slugged into the
     * session cookie and three cache prefixes, and CLAUDE.md's rename note is
     * explicit that moving one of those orphans a keyspace. So the product
     * name is a **separate key**, and this asserts the separation rather than
     * the value of either.
     */
    $this->team->forceFill([
        'name' => 'Bosart Group',
        'sending_identity_email' => 'emily@bosart.test',
    ])->save();

    expect(config()->string('app.product_name'))->toBe('Goldieflow')
        ->and(SendingIdentity::displayName($this->team))
        ->not->toContain('Brawling')
        ->and(SendingIdentity::displayName($this->team))
        ->not->toContain(config()->string('app.name'));
});

it('uses the sending identity name a team set, when they set one', function (): void {
    $this->team->forceFill([
        'name' => 'Bosart Group',
        'sending_identity_name' => 'Emily Bosart',
    ])->save();

    expect(SendingIdentity::displayName($this->team))
        ->toBe('Emily Bosart via Goldieflow');
});

it('says the product’s name alone rather than “ via Goldieflow”', function (): void {
    /*
     * `headerSafe()` can empty a string that was only ever control characters,
     * and `sending_identity_name` is nullable. A leading " via " is a header
     * that names nobody where the whole point is naming somebody.
     */
    $this->team->forceFill(['name' => "\r\n"])->save();

    expect(SendingIdentity::displayName($this->team))->toBe('Goldieflow');
});

it('refuses to let a team split the From header', function (): void {
    /*
     * The display name is a mail **header** and the value is typed into a
     * settings form. The same CR/LF strip the subject goes through, in the one
     * other place a tenant string reaches a header.
     */
    $this->team->forceFill(['name' => "Bosart Group\r\nBcc: someone@evil.test"])->save();

    expect(SendingIdentity::displayName($this->team))
        ->not->toContain("\r")
        ->and(SendingIdentity::displayName($this->team))->not->toContain("\n");
});

it('does not attribute the product’s own alert to the team', function (): void {
    /*
     * S91 tells a team that *their* automations are failing. Signing that as
     * the team would be a small lie in the one line a reader trusts most —
     * so the alert, and a template test send, keep the application's name.
     */
    alertFor($this->team);

    $from = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getFrom()[0];

    expect($from->getName())->toBe(config()->string('mail.from.name'));
});

it('gives a client somewhere to reply, which is the half that makes the From honest', function (): void {
    /*
     * Round 1's blocker, and it was a regression: the From line named the
     * agency while a reply went to the product's own mailbox. That is worse
     * than the line it replaced, which said *Goldieflow* and at least matched
     * where replies went.
     *
     * `teams.sending_identity_email` is the field S72 has labelled **"Reply-to
     * address"** since Slice 1, and until now nothing read it into a header at
     * all — `MergeFields::contactBlock()` put it in the body and that was the
     * end of it.
     */
    $this->team->forceFill([
        'name' => 'Bosart Group',
        'sending_identity_email' => 'emily@bosart.test',
    ])->save();

    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    $instance = ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
    ]);

    Mail::to('client@example.test')->send(
        new AutomatedMessageMail($instance, $instance->rendered(), $this->team),
    );

    $replyTo = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getReplyTo();

    expect($replyTo)->toHaveCount(1)
        ->and($replyTo[0]->getAddress())->toBe('emily@bosart.test')
        // The team's own name, without the "via": a reply is going *to* them,
        // so the product has no part in it.
        ->and($replyTo[0]->getName())->toBe('Bosart Group');
});

it('lets a template name a more specific reply address than the team’s', function (): void {
    $this->actingAsPerson($this->owner, $this->team);

    $this->team->forceFill(['sending_identity_email' => 'emily@bosart.test'])->save();

    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    $template = MessageTemplate::factory()->create([
        'team_id' => $this->team->getKey(),
        'from_identity' => 'coordinator@bosart.test',
    ]);
    $instance = ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
        'message_template_id' => $template->getKey(),
    ]);

    Mail::to('client@example.test')->send(
        new AutomatedMessageMail($instance, $instance->rendered(), $this->team),
    );

    $replyTo = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getReplyTo();

    expect($replyTo[0]->getAddress())->toBe('coordinator@bosart.test');
});

it('sends an invitation from the inviting team, and back to the person who sent it', function (): void {
    /*
     * Untested until round 1 found that deleting the `from:` left the entire
     * Feature suite green. An invitation is the one message reaching somebody
     * with no account and every reason to be suspicious of it, so both halves
     * matter: a name they recognise, and somewhere for *"who is this?"* to go.
     */
    $this->team->forceFill([
        'name' => 'Bosart Group',
        'sending_identity_email' => 'emily@bosart.test',
    ])->save();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->getKey(),
        'invited_by_person_id' => $this->owner->getKey(),
    ]);

    Mail::to('newcomer@example.test')->send(
        new TeamInvitationMail($invitation, TeamInvitation::newToken()),
    );

    $message = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();

    expect($message->getFrom()[0]->getName())->toBe('Bosart Group via Goldieflow')
        ->and($message->getFrom()[0]->getAddress())->toBe(config()->string('mail.from.address'))
        // The person who did this, because they are the one who can explain it.
        ->and($message->getReplyTo()[0]->getAddress())->toBe($this->owner->email);
});

it('keeps the product’s own name on a test send', function (): void {
    /*
     * The other half of the four-way split, which had no assertion either. A
     * template test send reaches its own author and the product genuinely is
     * what they are testing.
     */
    $this->actingAsPerson($this->owner, $this->team);

    $template = MessageTemplate::factory()->create(['team_id' => $this->team->getKey()]);
    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    $instance = ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
    ]);

    Mail::to('author@example.test')->send(
        new MessageTemplateTestMail($template, $instance->rendered(), $this->team),
    );

    $from = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getFrom()[0];

    expect($from->getName())->toBe(config()->string('mail.from.name'));
});

it('falls back to a team owner when nobody has filled the reply-to field in', function (): void {
    /*
     * Round 2's blocker, and it is round 1's blocker wearing the default case.
     * `sending_identity_email` is nullable and **nothing sets it** — not the
     * migration, not `ProvisionTeam`, not `TeamFactory` — so for every team
     * that exists the chain round 1 added was empty end to end, and the From
     * named the agency over a reply that reached the product's mailbox.
     *
     * A fallback chain whose every link is empty for the ordinary case is
     * CLAUDE.md's *"a default nobody can leave is not a default"* in its other
     * form: a default nobody ever reaches.
     */
    $this->team->forceFill([
        'name' => 'Bosart Group',
        'sending_identity_email' => null,
    ])->save();

    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    $instance = ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
    ]);

    Mail::to('client@example.test')->send(
        new AutomatedMessageMail($instance, $instance->rendered(), $this->team),
    );

    $message = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();

    $ownerEmail = app(TeamContext::class)->runFor(
        $this->team,
        fn (): mixed => $this->team->memberships()
            ->whereNotNull('email')
            ->oldest('id')
            ->value('email'),
    );

    expect($message->getReplyTo())->toHaveCount(1)
        ->and($message->getReplyTo()[0]->getAddress())->toBe($ownerEmail)
        // And the agency's name is still safe to put in the inbox line,
        // because there is now somebody behind it.
        ->and($message->getFrom()[0]->getName())->toBe('Bosart Group via Goldieflow');
});

it('drops the team’s name rather than naming them over a reply that reaches nobody', function (): void {
    /*
     * The backstop, and the reason `SendingIdentity` is one object instead of
     * three static calls: there is no way to obtain the From without also
     * obtaining the Reply-To, so the two cannot drift apart again.
     *
     * A client reading *"Bosart Group"* and reaching nobody has been told
     * something untrue by the one header they act on. The product's own name
     * is at least true of where the message came from.
     */
    $team = Team::factory()->create(['name' => 'Bosart Group']);

    $identity = SendingIdentity::for($team);

    expect($identity->replyTo)->toBe([])
        ->and($identity->from->name)->toBe('Goldieflow')
        ->and($identity->from->name)->not->toContain('Bosart');
});

it('strips a header split out of the reply name too, not only the From', function (): void {
    /*
     * The rule was written into `displayName()` and not into the method beside
     * it — a rule in one caller is a rule the next caller lacks.
     */
    $this->team->forceFill([
        'name' => "Bosart Group\r\nBcc: someone@evil.test",
        'sending_identity_email' => 'emily@bosart.test',
    ])->save();

    $reply = SendingIdentity::for($this->team)->replyTo[0];

    /*
     * The CR/LF, not the words. A display name that reads "Bcc: …" is inert
     * once it cannot break the line — asserting on the text instead would be
     * testing a filter this class deliberately does not apply.
     */
    expect($reply->name)->not->toContain("\r")
        ->and($reply->name)->not->toContain("\n")
        ->and($reply->name)->toStartWith('Bosart Group');
});

it('skips a stored address the transport would throw on, rather than failing the send', function (): void {
    /*
     * `emily(work)@bosart.test` passes Laravel's `email` **and** `email:rfc` —
     * the parenthesised comment is legal RFC 5322 — and throws when Symfony
     * builds an `Address` from it. That throw lands in a queue worker inside
     * `Mail::send()`, after `message_key` is claimed, so the row fails
     * permanently and is never retried: one address typed into a settings form
     * kills every automated message the team sends.
     *
     * `SendableEmailAddress` refuses it on the way in now. This is the other
     * end of that pair, for the rows stored before the rule existed: the bad
     * candidate is skipped and the chain falls through to one that works.
     */
    $this->team->forceFill([
        'name' => 'Bosart Group',
        'sending_identity_email' => 'emily(work)@bosart.test',
    ])->save();

    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    $instance = ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
    ]);

    Mail::to('client@example.test')->send(
        new AutomatedMessageMail($instance, $instance->rendered(), $this->team),
    );

    $replyTo = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getReplyTo();

    expect($replyTo)->toHaveCount(1)
        ->and($replyTo[0]->getAddress())->not->toContain('(work)');
});

it('refuses to send at all when no verified identity is configured', function (): void {
    /*
     * The one value in this class that has no fallback. A message that goes
     * out claiming an identity SES has not authorised is rejected at the API
     * — so the honest failure is here, in a sentence naming the variable, and
     * not four layers down in a queue worker.
     */
    config()->set('mail.from.address', null);

    expect(fn (): mixed => SendingIdentity::for($this->team))
        ->toThrow(RuntimeException::class, 'MAIL_FROM_ADDRESS');
});

it('does not accept a blank sending address as a configured one', function (): void {
    // `config()->string()` would hand this straight through: it is a string.
    config()->set('mail.from.address', '   ');

    expect(fn (): mixed => SendingIdentity::for($this->team))
        ->toThrow(RuntimeException::class);
});
