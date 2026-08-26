<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Team;
use App\Support\Mail\BrandedEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * S91 — the email that tells a team a client email never arrived (#97 · F5.8).
 *
 * Screen Inventory S91: *"team-facing, so internal vocabulary is fine here…
 * plain, specific, and link straight to S49."* All three are load-bearing.
 *
 * **Plain**, because this is the message somebody reads at 8am on a phone and
 * has to act on. It carries no marketing frame of its own — it wears S86 like
 * everything else, and its content is two sentences and a link.
 *
 * **Specific**, because *"an automation failed"* is a sentence that sends
 * somebody hunting. It names the deal, the reason the transport gave, and how
 * many other messages are in the same state.
 *
 * **Linking to S49**, because the failure detail page is where the words, the
 * recipients and the reason all are, and where a person can act.
 *
 * ## Internal vocabulary, and the one line where that is not obvious
 *
 * IA §9's client rules do not apply here: this goes to the team, and *failed*
 * is the accurate word. What still applies is PRD §9 — the alert never carries
 * the message body or the recipients' addresses. It goes over the same
 * transport that just failed, to an inbox that may be shared, and the failure
 * detail page is one click away for anybody entitled to see them.
 */
class InternalAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Team $team,
        public readonly string $headline,
        public readonly string $detail,
        public readonly string $actionUrl,
        public readonly string $actionLabel,
        public readonly ?string $footnote = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->headline,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.internal-alert',
            text: 'mail.internal-alert-text',
            with: [
                /*
                 * The team's own frame, not the product's. An alert that
                 * arrives looking like a different application is one a
                 * recipient has to place before they can read it, and this is
                 * the message where seconds matter most.
                 */
                'brand' => BrandedEmail::for($this->team),
            ],
        );
    }
}
