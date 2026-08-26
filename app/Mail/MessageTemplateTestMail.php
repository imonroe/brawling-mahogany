<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\MessageTemplate;
use App\Models\Team;
use App\Support\Mail\BrandedEmail;
use App\Support\Messages\RenderedMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * S46's test send — a template, to its author, and to nobody else.
 *
 * The one thing in this slice that puts a rendered template on a real mail
 * transport, and it is built so that it cannot become a message to a client.
 * The recipient is not the template's `recipient_rule` and is not an address
 * anybody types: `MessageTemplateController::test()` addresses it to the
 * membership of the person who pressed the button.
 *
 * ## The subject says so
 *
 * A rendered template in an inbox is indistinguishable from a real one, and
 * the person who sent it will forward it to a colleague. The `[Test]` prefix
 * is what stops that becoming a client email with no deal behind it.
 *
 * ## The branded layout is S86, not this
 *
 * Same decision `TeamInvitationMail` records: this wraps the rendered body in
 * the plainest possible frame. When #97 lands, a test send inherits the real
 * layout by rendering through it, and this file's frame goes.
 */
class MessageTemplateTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly MessageTemplate $template,
        public readonly RenderedMessage $rendered,
        public readonly Team $team,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Test] '.($this->rendered->subject ?? $this->template->name),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.message-template-test',
            text: 'mail.message-template-test-text',
            with: [
                'brand' => BrandedEmail::for($this->team),
                'teamName' => $this->team->name,
                'templateName' => $this->template->name,
                'bodyHtml' => $this->rendered->bodyHtml,
                'bodyText' => $this->rendered->bodyText,
                /*
                 * The three lists separately, never flattened into one.
                 *
                 * They have three different fixes, and one label for all of
                 * them said the wrong thing about two: a malformed entry is
                 * the literal string `{{`, so the author's own test email read
                 * *"These merge fields had nothing behind them: {{."*
                 */
                'malformed' => $this->rendered->malformed,
                'unknown' => $this->rendered->unknown,
                'unresolved' => $this->rendered->unresolved,
            ],
        );
    }
}
