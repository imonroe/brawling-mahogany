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
 * S89 — the magic link, to a client (PRD §4.7 F7.1 · IA §9 · issue #110).
 *
 * The only message in the product written for somebody who has never used it
 * and will use it about four times. Screen Inventory gives it three states —
 * the link, the expiry note, and *"you did not request this"* — and all three
 * are here because all three are things a stranger needs.
 *
 * ## The words are the client's, not the team's
 *
 * IA §9 applies to an email as much as to a page: no jargon, no instructions,
 * no alarming words. It does not say *"authenticate"*, it does not say
 * *"token"*, and it does not tell them to do anything except look if they want
 * to.
 *
 * ## The frame is the team's
 *
 * F7.5, and the same `BrandedEmail` every other message wears. A client who
 * gets an email from a product they have never heard of, about their house,
 * deletes it — the name they know is their agent's.
 *
 * ## ADR 0003 applies, and the console command is the second door
 *
 * *"No user flow depends on email alone."* If this never arrives, an agent
 * runs `status-page:link` and reads the URL down the phone, which is the same
 * escape hatch `invitation:link` provides one surface along.
 */
class StatusPageLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Team $team,
        public readonly string $clientName,
        public readonly string $url,
        public readonly int $minutes,
        /** What the page is *about*, in the client's words: "Your Sale". */
        public readonly string $what,
    ) {}

    public function envelope(): Envelope
    {
        /*
         * The team's name, not the product's — and interpolated through
         * nothing clever, because `Envelope` takes a plain string and this is
         * the subject line a stranger decides whether to open.
         *
         * `headerSafe()` is applied by the caller for the *From* name;
         * `Envelope::$subject` is set by the framework and CR/LF-stripped by
         * Symfony's own header encoder, which is the layer that owns it.
         */
        return new Envelope(subject: $this->what.' — a link from '.$this->team->name);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.status-page-link',
            text: 'mail.status-page-link-text',
            with: ['brand' => BrandedEmail::for($this->team)],
        );
    }
}
